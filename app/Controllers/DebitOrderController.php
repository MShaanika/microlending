<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\DebitOrderCancellation;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
use App\Models\Loan;
use App\Services\CollexiaCollectionStopper;
use App\Services\MandateCollectionCheck;
use App\Services\MandateReplacementService;
use App\Support\CollexiaCodes;
use App\Support\SplitLanePlanner;

/**
 * Registers a borrower's recurring debit order mandate against a loan. This
 * is a lightweight mandate record only -- batch bank-file collection
 * processing (debit_order_runs/debit_order_run_lines) is a separate, much
 * larger integration and is out of scope here.
 */
class DebitOrderController extends Controller
{
    private DebitOrder $debitOrders;
    private DebitOrderCancellation $cancellations;
    private DebitOrderSplitLeg $splitLegs;
    private DebitOrderSplitLegInstallment $laneInstallments;
    private Loan $loans;
    private Borrower $borrowers;
    private CollexiaSetting $collexiaSettings;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->cancellations = new DebitOrderCancellation();
        $this->splitLegs = new DebitOrderSplitLeg();
        $this->laneInstallments = new DebitOrderSplitLegInstallment();
        $this->loans = new Loan();
        $this->borrowers = new Borrower();
        $this->collexiaSettings = new CollexiaSetting();
    }

    /** Hard scope -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow the list via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the record's loan belongs to another branch and the viewer isn't Super Admin. */
    private function assertBranchAccess(?array $record, string $notFoundRedirect = '/debit-orders'): void
    {
        if (!$record || Auth::isSuperAdmin()) {
            return;
        }
        $recordBranchId = $record['loan_branch_id'] ?? $record['branch_id'] ?? 0;
        if ((int) $recordBranchId !== (int) Auth::branchId()) {
            Session::flash('error', 'Record not found.');
            $this->redirect($notFoundRedirect);
        }
    }

    public function index(): void
    {
        Auth::authorize('collections.debit_orders');
        $status = trim((string) ($_GET['status'] ?? ''));
        $fromDate = trim((string) ($_GET['from_date'] ?? ''));
        $toDate = trim((string) ($_GET['to_date'] ?? ''));
        $this->view('debit_orders/index', [
            'title' => 'Debit Orders',
            'debitOrders' => $this->debitOrders->paginated($status, $this->indexBranchId(), $fromDate ?: null, $toDate ?: null),
            'outOfStepAll' => $this->debitOrders->outOfStepAll($this->indexBranchId()),
            'status' => $status,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);
    }

    /** Active loans that have never had a debit order registered -- linked from the dashboard's "Missing" quick link. */
    public function missing(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('debit_orders/missing', [
            'title' => 'Loans Without a Debit Order',
            'loans' => $this->debitOrders->activeLoansMissingDebitOrder($this->indexBranchId()),
        ]);
    }

    public function create(string $loanId): void
    {
        Auth::authorize('collections.debit_orders');
        $loan = $this->loans->find((int) $loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }
        $this->assertBranchAccess($loan, '/loans');

        $existing = $this->debitOrders->liveForLoan((int) $loanId);
        if ($existing) {
            Session::flash('error', 'This loan already has a ' . strtolower($existing['status']) . ' debit order (' . $existing['debit_order_no'] . '). Cancel or suspend it before registering a new one -- every active mandate collects independently, so a second one means the client gets deducted twice.');
            $this->redirect('/debit-orders/' . $existing['id']);
            return;
        }

        $firstDueDate = $this->loans->firstScheduleDueDate((int) $loanId);
        $old = $this->prefillFromBankDetails((int) $loan['borrower_id']);
        if ($firstDueDate) {
            // Default Start Date to when the loan's first installment is
            // actually due, not today -- a mandate registered a day (or a
            // week) after disbursement previously defaulted to "today" here,
            // which Collexia then rounds to the nearest matching debit_day,
            // silently landing a full cycle earlier than the real schedule.
            $old['start_date'] = $firstDueDate;
        }

        $this->view('debit_orders/create', [
            'title' => 'Register Debit Order - ' . $loan['loan_no'],
            'loan' => $loan,
            'old' => $old,
            'firstDueDate' => $firstDueDate,
            'installments' => $this->debitOrders->unpaidInstallments((int) $loanId),
            'paidAhead' => $this->debitOrders->paidAheadInstallments((int) $loanId),
            'collexiaReady' => $this->collexiaApiReady(),
            'errors' => [],
            'banks' => CollexiaCodes::BANKS,
            'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
        ]);
    }

    /**
     * Pulls the borrower's bank details (captured at intake -- either typed
     * on the borrower profile's Banking tab, or copied over automatically
     * when an approved application was converted to a borrower) into the
     * same $old array the view already reads via old($field, $old) for
     * validation-failure repopulation, so staff don't have to retype
     * information that's already on file. account_name/account_number/
     * branch_code copy over directly; bank_code and account_type are stored
     * as free text at intake but the form needs Collexia's fixed codes, so
     * they're normalized here with a graceful fallback to blank/default
     * when nothing matches (staff can still pick manually).
     */
    private function prefillFromBankDetails(int $borrowerId): array
    {
        $bank = $this->borrowers->bankDetails($borrowerId);
        if (!$bank) {
            return [];
        }

        $old = [
            'account_name' => $bank['account_name'] ?? '',
            'account_number' => $bank['account_number'] ?? '',
            'branch_code' => $bank['branch_code'] ?? '',
        ];

        $bankName = trim((string) ($bank['bank_name'] ?? ''));
        if ($bankName !== '') {
            foreach (CollexiaCodes::BANKS as $code => $label) {
                if (stripos($label, $bankName) !== false || stripos($bankName, $label) !== false) {
                    $old['bank_code'] = $code;
                    break;
                }
            }
        }

        $accountType = trim((string) ($bank['account_type'] ?? ''));
        if ($accountType !== '') {
            $old['account_type'] = stripos($accountType, 'saving') !== false ? '2' : '1';
        }

        return $old;
    }

    public function store(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }
        $this->assertBranchAccess($loan, '/loans');

        $existing = $this->debitOrders->liveForLoan($loanId);
        if ($existing) {
            Session::flash('error', 'This loan already has a ' . strtolower($existing['status']) . ' debit order (' . $existing['debit_order_no'] . '). Cancel or suspend it before registering a new one.');
            $this->redirect('/debit-orders/' . $existing['id']);
            return;
        }

        // With the Collexia API connected there is only one shape: every
        // installment in one mandate per split part (see storePerInstallment()).
        // The single-recurring-mandate / one-installment-split form below stays
        // only for tenants without the API, whose mandates go out in the manual
        // debit order batch file instead.
        if ($this->collexiaApiReady() || ($_POST['mode'] ?? '') === 'per_installment') {
            $this->storePerInstallment($loan);
            return;
        }

        $errors = [];
        foreach (['bank_code', 'account_number', 'debit_day', 'debit_amount', 'start_date'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (!empty($_POST['bank_code']) && !isset(CollexiaCodes::BANKS[$_POST['bank_code']])) {
            $errors['bank_code'] = 'Select a valid bank.';
        }
        // Collexia rejects a mandate load whose collection date isn't in the
        // future (error 10567) -- catching it here saves a round trip to
        // Collexia and matches the site's own date picker restriction.
        if (empty($errors['start_date']) && !empty($_POST['start_date']) && $_POST['start_date'] < date('Y-m-d')) {
            $errors['start_date'] = 'Start Date must be today or a future date.';
        }

        $splitEnabled = !empty($_POST['split_enabled']);
        $splitCount = 0;
        $splitAmounts = [];
        if ($splitEnabled && empty($errors['debit_amount'])) {
            $splitCount = (int) ($_POST['split_count'] ?? 0);
            $splitAmounts = array_map(fn ($a) => round((float) $a, 2), array_values($_POST['split_amounts'] ?? []));
            $totalAmount = round((float) $_POST['debit_amount'], 2);
            $splitSum = round(array_sum($splitAmounts), 2);

            if ($splitCount < 1 || $splitCount > 10) {
                $errors['split_count'] = 'Enter a number of splits between 1 and 10.';
            } elseif (count($splitAmounts) !== $splitCount || in_array(true, array_map(fn ($a) => $a <= 0, $splitAmounts), true)) {
                $errors['split_amounts'] = 'Enter a positive amount for every split.';
            } elseif (abs($splitSum - $totalAmount) > 0.01) {
                $errors['split_amounts'] = 'The split amounts (' . format_money($splitSum) . ') must add up to the debit amount (' . format_money($totalAmount) . ') exactly.';
            }
        }

        if (!empty($errors)) {
            $this->view('debit_orders/create', [
                'title' => 'Register Debit Order - ' . $loan['loan_no'],
                'loan' => $loan,
                'old' => $_POST,
                'firstDueDate' => $this->loans->firstScheduleDueDate($loanId),
                'installments' => $this->debitOrders->unpaidInstallments($loanId),
                'paidAhead' => $this->debitOrders->paidAheadInstallments($loanId),
                'collexiaReady' => $this->collexiaApiReady(),
                'errors' => $errors,
                'banks' => CollexiaCodes::BANKS,
                'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
            ]);
            return;
        }

        $trackingDays = max(1, min(14, (int) ($_POST['no_of_days_tracking'] ?? 3)));

        $debitOrderId = $this->debitOrders->create([
            'borrower_id' => $loan['borrower_id'],
            'loan_id' => $loanId,
            'debit_order_no' => generate_reference('DO'),
            'bank_name' => CollexiaCodes::BANKS[$_POST['bank_code']],
            'account_name' => trim($_POST['account_name'] ?? '') ?: null,
            'account_number' => trim($_POST['account_number']),
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'debit_day' => (int) $_POST['debit_day'],
            'debit_amount' => (float) $_POST['debit_amount'],
            'start_date' => $_POST['start_date'],
            'status' => 'Active',
            // Collexia's EnDo IDType is always 1 (ID Number) for this business.
            'id_type' => 1,
            'account_type' => (int) ($_POST['account_type'] ?? 1),
            'bank_code' => $_POST['bank_code'],
            'no_of_days_tracking' => $trackingDays,
            'split_enabled' => $splitEnabled ? 1 : 0,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        // Merchant System Contract No forms part of Collexia's 30-char bank
        // statement reference and must be <=10 chars -- derived from our own
        // PK so it's guaranteed unique without asking staff to invent one.
        $this->debitOrders->updateRecord($debitOrderId, [
            'merchant_system_contract_no' => sprintf('SD%08d', $debitOrderId),
        ]);

        if ($splitEnabled) {
            // This first batch of splits is for the loan's own first
            // unpaid installment -- debit_amount is one installment's
            // worth, divided across legs, not a per-loan total. See
            // DebitOrderSplitLeg's own docblock for why every split leg
            // needs its target schedule_id recorded directly, not inferred
            // later from Collexia's own (always-1, per-leg) installmentNo.
            $firstScheduleId = $this->debitOrders->orderedUnpaidScheduleIds($loanId)[0] ?? null;
            foreach ($splitAmounts as $i => $amount) {
                $this->splitLegs->upsert($debitOrderId, $i + 1, $amount, $splitCount, $firstScheduleId);
            }
            Audit::log('Create', 'Debit Orders', 'Registered debit order #' . $debitOrderId . ' for loan ' . $loan['loan_no']
                . ' split into ' . $splitCount . ' transaction(s): ' . implode(', ', array_map(fn ($a) => format_money($a), $splitAmounts)));
        } else {
            Audit::log('Create', 'Debit Orders', 'Registered debit order #' . $debitOrderId . ' for loan ' . $loan['loan_no']);
        }

        Session::flash('success', 'Debit order registered.');
        $this->redirect('/debit-orders/' . $debitOrderId);
    }

    /**
     * Where the loan now disagrees with what its live mandates will still
     * collect: an upcoming installment whose balance changed since the
     * mandate was placed (a part payment, a reschedule, a top-up), or one that
     * is already paid but is still scheduled to collect. A mandate is fixed at
     * the moment it is placed and never follows later changes to the loan, so
     * staff need to see this rather than discover it as a wrong collection.
     * Only upcoming installments are compared -- one whose collect-on date has
     * passed has already been processed by Collexia.
     *
     * @return string[] human-readable findings, empty when everything agrees
     */
    private function scheduleDrift(array $loanSchedule, array $carriers): array
    {
        $today = date('Y-m-d');
        $findings = [];
        foreach ($loanSchedule as $row) {
            $live = array_filter($carriers[(int) $row['id']] ?? [], function ($c) use ($today) {
                $date = $c['collexia_date'] ?: $c['planned_date'];
                return in_array($c['status'], ['Registered', 'Load Pending'], true) && $date && $date > $today && empty($c['cancelled_at']);
            });
            if (!$live) {
                continue;
            }
            $collects = round(array_sum(array_column($live, 'amount')), 2);
            $owes = round(max(0, (float) $row['total_due'] - (float) $row['total_paid']), 2);
            if ($row['status'] === 'Paid' || $owes < 0.005) {
                $findings[] = 'Installment #' . (int) $row['installment_no'] . ' is already paid but its mandate is still scheduled to collect ' . format_money($collects) . '.';
            } elseif (abs($collects - $owes) > 0.01) {
                $findings[] = 'Installment #' . (int) $row['installment_no'] . ' now owes ' . format_money($owes) . ' but its mandate(s) will collect ' . format_money($collects) . '.';
            }
        }
        return $findings;
    }

    /** True when this tenant places mandates through the Collexia API (enabled and configured). */
    private function collexiaApiReady(): bool
    {
        return $this->collexiaSettings->isEnabled() && $this->collexiaSettings->isConfigured();
    }

    /**
     * Every selected unpaid installment goes into ONE Collexia mandate, and
     * each part of a split installment is simply one more installment of that
     * same mandate on the same collection date: three installments with one of
     * them split in two is a single mandate of four installments. The mandate
     * is stored as one split-leg row with no schedule_id of its own; the
     * installments it carries (and the exact date/amount each should collect)
     * live in debit_order_split_leg_installments, and several of them may
     * point at the same loan installment (its parts).
     *
     * Collexia loads a mandate with a monthly rule and one amount, so once it
     * confirms registration CollexiaLaneScheduleService moves each installment
     * to its true date and amount. If Collexia REFUSES that (for example two
     * installments of one mandate on the same date) the controller re-places
     * the loan as one mandate per split part. With place_now set, everything is
     * sent to Collexia straight away.
     */
    private function storePerInstallment(array $loan): void
    {
        $loanId = (int) $loan['id'];
        $installments = $this->debitOrders->unpaidInstallments($loanId);
        $byId = [];
        foreach ($installments as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $errors = [];
        foreach (['bank_code', 'account_number'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (!empty($_POST['bank_code']) && !isset(CollexiaCodes::BANKS[$_POST['bank_code']])) {
            $errors['bank_code'] = 'Select a valid bank.';
        }

        // A date Collexia can accept: strictly in the future (it rejects
        // anything else with 10567). An installment due today or earlier
        // can't be collected on its due date any more, so it defaults to
        // the earliest valid date instead and the form says so.
        $earliest = date('Y-m-d', strtotime('+1 day'));
        $selectedIds = array_values(array_unique(array_map('intval', (array) ($_POST['installment_ids'] ?? []))));
        $planRows = [];
        foreach ($selectedIds as $scheduleId) {
            if (!isset($byId[$scheduleId])) {
                continue;
            }
            $row = $byId[$scheduleId];
            $posted = trim((string) (($_POST['collection_dates'][$scheduleId] ?? '')));
            $date = $posted !== '' ? $posted : max((string) $row['due_date'], $earliest);
            $parsed = \DateTime::createFromFormat('Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                $errors['installments'] = 'Installment #' . (int) $row['installment_no'] . ' has an invalid collection date.';
                break;
            }
            if ($date < $earliest) {
                $errors['installments'] = 'Installment #' . (int) $row['installment_no'] . ' must be collected on a future date (' . date('d M Y', strtotime($earliest)) . ' or later).';
                break;
            }
            // Optionally divide this installment into parts. Part N of every
            // installment travels in the same mandate (lane N), so each part is
            // collected on this same installment date.
            $outstanding = round((float) $row['outstanding'], 2);
            $parts = max(1, (int) ($_POST['split_counts'][$scheduleId] ?? 1));
            if ($parts > 10) {
                $errors['installments'] = 'Installment #' . (int) $row['installment_no'] . ' can be split into at most 10 parts.';
                break;
            }
            if ($parts === 1) {
                $amounts = [$outstanding];
            } else {
                $amounts = array_map(fn ($a) => round((float) $a, 2), array_values((array) ($_POST['split_amounts'][$scheduleId] ?? [])));
                if (count($amounts) !== $parts || in_array(true, array_map(fn ($a) => $a <= 0, $amounts), true)) {
                    $errors['installments'] = 'Installment #' . (int) $row['installment_no'] . ': enter a positive amount for every part.';
                    break;
                }
                if (abs(round(array_sum($amounts), 2) - $outstanding) > 0.01) {
                    $errors['installments'] = 'Installment #' . (int) $row['installment_no'] . ': the parts (' . format_money(round(array_sum($amounts), 2)) . ') must add up to the installment amount (' . format_money($outstanding) . ') exactly.';
                    break;
                }
            }
            $planRows[] = ['schedule_id' => $scheduleId, 'loan_installment_no' => (int) $row['installment_no'], 'date' => $date, 'amounts' => $amounts, 'outstanding' => $outstanding];
        }
        // Installments already paid by hand, ticked to ride in the mandate and be cancelled automatically once Collexia confirms it.
        // Only ones the system itself lists as eligible (fully paid, future-dated) are taken, whatever the form posted; they are never
        // split and carry their original amount, so the mandate's numbering follows the loan.
        $paidAheadById = [];
        foreach ($this->debitOrders->paidAheadInstallments($loanId) as $row) {
            $paidAheadById[(int) $row['id']] = $row;
        }
        $paidTicked = array_values(array_unique(array_map('intval', (array) ($_POST['paid_installment_ids'] ?? []))));
        $autoCancelCount = 0;
        foreach ($paidTicked as $scheduleId) {
            if (!isset($paidAheadById[$scheduleId])) {
                continue;
            }
            $row = $paidAheadById[$scheduleId];
            $date = (string) $row['due_date'];
            if ((int) date('N', strtotime($date)) === 7) {
                $date = date('Y-m-d', strtotime($date . ' +1 day')); // Collexia does not process Sundays
            }
            $planRows[] = [
                'schedule_id' => $scheduleId,
                'loan_installment_no' => (int) $row['installment_no'],
                'date' => $date,
                'amounts' => [round((float) $row['amount'], 2)],
                'outstanding' => 0.0,
                'auto_cancel' => true,
            ];
            $autoCancelCount++;
        }
        if (empty($errors['installments'])) {
            if (!$planRows) {
                $errors['installments'] = 'Tick at least one installment to collect.';
            } elseif (count($planRows) === $autoCancelCount) {
                $errors['installments'] = 'Tick at least one unpaid installment to collect -- installments already paid by hand can only be sent along with one that will actually be collected.';
            }
        }

        if (!empty($errors)) {
            $this->view('debit_orders/create', [
                'title' => 'Register Debit Order - ' . $loan['loan_no'],
                'loan' => $loan,
                'old' => $_POST,
                'firstDueDate' => $this->loans->firstScheduleDueDate($loanId),
                'installments' => $installments,
                'paidAhead' => $this->debitOrders->paidAheadInstallments($loanId),
                'collexiaReady' => $this->collexiaApiReady(),
                'errors' => $errors,
                'banks' => CollexiaCodes::BANKS,
                'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
            ]);
            return;
        }

        usort($planRows, fn ($a, $b) => $a['loan_installment_no'] <=> $b['loan_installment_no']);
        $first = $planRows[0];
        $firstCollected = array_values(array_filter($planRows, fn ($r) => empty($r['auto_cancel'])))[0];
        // ONE mandate carrying every installment, split parts included (each
        // part is another installment of the same mandate on the same date).
        // If Collexia refuses that, DebitOrderCollexiaController falls back to
        // one mandate per part (SplitLanePlanner::plan()) by itself.
        $lanes = SplitLanePlanner::planSingle($planRows);
        $trackingDays = max(1, min(14, (int) ($_POST['no_of_days_tracking'] ?? 3)));

        $debitOrderId = $this->debitOrders->create([
            'borrower_id' => $loan['borrower_id'],
            'loan_id' => $loanId,
            'debit_order_no' => generate_reference('DO'),
            'bank_name' => CollexiaCodes::BANKS[$_POST['bank_code']],
            'account_name' => trim($_POST['account_name'] ?? '') ?: null,
            'account_number' => trim($_POST['account_number']),
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'debit_day' => (int) date('j', strtotime($first['date'])),
            'debit_amount' => $firstCollected['outstanding'],
            'start_date' => $first['date'],
            'status' => 'Active',
            'id_type' => 1,
            'account_type' => (int) ($_POST['account_type'] ?? 1),
            'bank_code' => $_POST['bank_code'],
            'no_of_days_tracking' => $trackingDays,
            'split_enabled' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]);
        $this->debitOrders->updateRecord($debitOrderId, [
            'merchant_system_contract_no' => sprintf('SD%08d', $debitOrderId),
        ]);

        // One leg per lane = one Collexia mandate carrying every installment
        // that has that part. The leg itself has no single schedule_id --
        // each of its installments is mapped to its loan installment in
        // debit_order_split_leg_installments instead.
        $summary = [];
        foreach ($lanes as $lane) {
            $legId = $this->splitLegs->upsert($debitOrderId, $lane['lane'], $lane['amount'], count($lanes), null, $lane['first_date']);
            $items = [];
            foreach ($lane['installments'] as $item) {
                $this->laneInstallments->add($legId, $item['installment_no'], $item['schedule_id'], $item['date'], $item['amount'], !empty($item['auto_cancel']));
                $items[] = '#' . $item['loan_installment_no'] . ' ' . format_money($item['amount']) . ' on ' . $item['date'] . (!empty($item['auto_cancel']) ? ' (paid by hand -- to be cancelled)' : '');
            }
            $summary[] = 'lane ' . $lane['lane'] . ' [' . implode(', ', $items) . ']';
        }

        $debitOrderNo = $this->debitOrders->find($debitOrderId)['debit_order_no'] ?? ('#' . $debitOrderId);
        Audit::log('Create', 'Debit Orders', 'Registered debit order #' . $debitOrderId . ' for loan ' . $loan['loan_no']
            . ' as ' . count($lanes) . ' mandate(s), each carrying every installment: ' . implode('; ', $summary), ['loan_id' => $loanId, 'debit_order_id' => $debitOrderId]);
        $this->loans->logStatus($loanId, $loan['loan_status'], $loan['loan_status'], Auth::user()['id'] ?? null,
            'Debit order ' . $debitOrderNo . ' registered: ' . count($lanes) . ' mandate(s) covering ' . count($planRows) . ' installment(s).');

        $collexia = new CollexiaSetting();
        if (!empty($_POST['place_now']) && $collexia->isEnabled() && $collexia->isConfigured()) {
            Session::flash('success', 'Debit order registered: ' . count($lanes) . ' mandate(s) covering ' . count($planRows) . ' installment(s)' . ($autoCancelCount ? ', ' . $autoCancelCount . ' of them already paid by hand and cancelled automatically once Collexia confirms' : '') . '. Sending to Collexia now.');
            // Same POST, same CSRF token -- placeMandate() validates it
            // itself, places every leg, then redirects to the debit order.
            (new DebitOrderCollexiaController())->placeMandate((string) $debitOrderId);
            return;
        }

        Session::flash('success', 'Debit order registered: ' . count($lanes) . ' mandate(s) covering ' . count($planRows) . ' installment(s)' . ($autoCancelCount ? ', ' . $autoCancelCount . ' of them already paid by hand and cancelled automatically once Collexia confirms' : '') . '. Use Place Mandate to send them to Collexia.');
        $this->redirect('/debit-orders/' . $debitOrderId);
    }

    /**
     * Step 1 of adding splits for the loan's NEXT not-yet-covered
     * installment, under the SAME debit order -- a second debit order on
     * this loan is blocked (liveForLoan()'s guard, would double-collect),
     * so a multi-month split loan needs each month's splits added here
     * once the previous month's are already placed, rather than a whole
     * new registration.
     */
    public function addNextInstallmentSplitsForm(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->debitOrders->find((int) $id);
        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        if (empty($debitOrder['split_enabled'])) {
            Session::flash('error', 'This debit order was not registered as a split.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        $scheduleId = $this->splitLegs->nextUncoveredScheduleId((int) $id, (int) $debitOrder['loan_id']);
        if (!$scheduleId) {
            Session::flash('error', 'Every remaining installment on this loan already has split transactions set up.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        $this->view('debit_orders/add_installment_splits', [
            'title' => 'Add Splits for Next Installment - ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'schedule' => $this->loans->scheduleRow($scheduleId),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function addNextInstallmentSplitsStore(string $id): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-orders/' . $id . '/next-installment-splits');
            return;
        }

        $debitOrder = $this->debitOrders->find((int) $id);
        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        if (empty($debitOrder['split_enabled'])) {
            Session::flash('error', 'This debit order was not registered as a split.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        // Re-derived, not trusted from a hidden form field -- the loan's
        // unpaid/covered state could have moved on since the form loaded.
        $scheduleId = $this->splitLegs->nextUncoveredScheduleId((int) $id, (int) $debitOrder['loan_id']);
        if (!$scheduleId) {
            Session::flash('error', 'Every remaining installment on this loan already has split transactions set up.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }
        $schedule = $this->loans->scheduleRow($scheduleId);

        $splitCount = (int) ($_POST['split_count'] ?? 0);
        $splitAmounts = array_map(fn ($a) => round((float) $a, 2), array_values($_POST['split_amounts'] ?? []));
        $totalAmount = round((float) $schedule['total_due'] - (float) $schedule['total_paid'], 2);
        $splitSum = round(array_sum($splitAmounts), 2);

        $errors = [];
        if ($splitCount < 1 || $splitCount > 10) {
            $errors['split_count'] = 'Enter a number of splits between 1 and 10.';
        } elseif (count($splitAmounts) !== $splitCount || in_array(true, array_map(fn ($a) => $a <= 0, $splitAmounts), true)) {
            $errors['split_amounts'] = 'Enter a positive amount for every split.';
        } elseif (abs($splitSum - $totalAmount) > 0.01) {
            $errors['split_amounts'] = 'The split amounts (' . format_money($splitSum) . ') must add up to the outstanding amount for this installment (' . format_money($totalAmount) . ') exactly.';
        }

        if (!empty($errors)) {
            $this->view('debit_orders/add_installment_splits', [
                'title' => 'Add Splits for Next Installment - ' . $debitOrder['debit_order_no'],
                'debitOrder' => $debitOrder,
                'schedule' => $schedule,
                'errors' => $errors,
                'old' => $_POST,
            ]);
            return;
        }

        $startingSplitNo = $this->splitLegs->nextSplitNo((int) $id);
        foreach ($splitAmounts as $i => $amount) {
            // total_splits is scoped to THIS installment's own split set
            // (count = $splitCount), not a running total across every
            // month -- see DebitOrderSplitLeg::liveForSchedule()'s own
            // docblock for why.
            $this->splitLegs->upsert((int) $id, $startingSplitNo + $i, $amount, $splitCount, $scheduleId);
        }

        Audit::log('Create', 'Debit Orders', 'Added ' . $splitCount . ' split transaction(s) for debit order #' . $id
            . ' installment #' . $schedule['installment_no'] . ' (due ' . $schedule['due_date'] . '): ' . implode(', ', array_map(fn ($a) => format_money($a), $splitAmounts)));
        Session::flash('success', 'Splits added for installment #' . $schedule['installment_no'] . '. Use Place Mandate to send them to Collexia.');
        $this->redirect('/debit-orders/' . $id . '/split-transactions');
    }

    /**
     * The guided "Replace mandate" page: what the old mandate will still collect, what the loan actually owes now, and the two steps
     * (cancel the old mandate, then register the new one). Cancelling is never automatic -- it is one deliberate, confirmed button.
     */
    public function replaceForm(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->debitOrders->find((int) $id);
        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        $loan = $this->loans->find((int) $debitOrder['loan_id']);
        $today = date('Y-m-d');
        $stillCollects = [];
        if ($debitOrder['split_enabled']) {
            foreach ($this->laneInstallments->carriersBySchedule((int) $id) as $scheduleId => $list) {
                foreach ($list as $c) {
                    $date = $c['collexia_date'] ?: $c['planned_date'];
                    if (in_array($c['status'], ['Registered', 'Load Pending'], true) && $date && $date >= $today && empty($c['cancelled_at'])) {
                        $stillCollects[] = ['date' => $date, 'amount' => (float) $c['amount'], 'mandate' => (int) $c['split_no']];
                    }
                }
            }
            usort($stillCollects, fn ($a, $b) => strcmp($a['date'], $b['date']));
        }

        $owed = [];
        foreach ($this->loans->schedule((int) $debitOrder['loan_id']) as $row) {
            $outstanding = round((float) $row['total_due'] - (float) $row['total_paid'], 2);
            if ($outstanding > 0.004) {
                $owed[] = ['installment_no' => (int) $row['installment_no'], 'date' => $row['due_date'], 'amount' => $outstanding];
            }
        }

        $this->view('debit_orders/replace', [
            'title' => 'Replace Mandate - ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'loan' => $loan,
            'isLive' => in_array($debitOrder['status'], ['Active', 'Suspended'], true),
            'outOfStep' => $this->debitOrders->outOfStepForLoan((int) $debitOrder['loan_id']),
            'stillCollects' => $stillCollects,
            'owed' => $owed,
        ]);
    }

    /**
     * Stops collections at Collexia that a manual payment has made unnecessary: either ONE already-paid installment of a lane mandate, or
     * the surplus last collections of a single recurring mandate. Deliberate and confirmed; every attempt, successful or not, is written
     * to the audit trail (with the loan and debit order ids) and to the loan's own history.
     */
    public function stopCollection(string $loanId): void
    {
        Auth::authorize('collections.debit_orders');
        $back = '/loans/' . (int) $loanId;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect($back);
            return;
        }
        $loan = $this->loans->find((int) $loanId);
        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }
        $this->assertBranchAccess($loan, '/loans');

        $mandate = $this->debitOrders->liveMandateForLoan((int) $loanId);
        if (!$mandate) {
            Session::flash('error', 'This loan has no live debit order, so there is nothing to stop.');
            $this->redirect($back);
            return;
        }

        $configured = $this->collexiaSettings->frontEndUsername();
        $user = $configured !== '' ? $configured : (string) (Auth::user()['username'] ?? '');
        $stopper = new CollexiaCollectionStopper();
        $surplus = !empty($_POST['surplus']);
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);

        $result = $surplus ? $stopper->stopSurplus($mandate, $user) : $stopper->stopInstallment($mandate, $scheduleId, $user);
        $what = $surplus ? 'the surplus scheduled collections of recurring mandate' : 'the scheduled collection of loan installment id ' . $scheduleId . ' on mandate';
        $summary = 'Stop collection after a manual payment: ' . ($result['cancelled'] > 0 ? $result['cancelled'] . ' cancelled' : 'nothing cancelled')
            . ' (' . $what . ' ' . $mandate['debit_order_no'] . '). ' . implode(' ', $result['messages']);

        Audit::log('Update', 'Debit Orders', $summary, [
            'loan_id' => (int) $loanId, 'debit_order_id' => (int) $mandate['id'], 'schedule_id' => $surplus ? null : $scheduleId,
            'event' => 'stop_collection_after_manual_payment', 'cancelled' => $result['cancelled'],
        ]);
        $this->loans->logStatus((int) $loanId, $loan['loan_status'], $loan['loan_status'], Auth::user()['id'] ?? null, substr($summary, 0, 1000));

        Session::flash($result['ok'] ? 'success' : 'error', trim(implode(' ', $result['messages'])) ?: ($result['ok'] ? 'Done.' : 'Nothing was stopped.'));
        $this->redirect($back);
    }

    /** Step 1 of replacing a mandate: cancel the old one at Collexia, after an explicit confirmation and a written reason. */
    public function replaceCancel(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $back = '/debit-orders/' . (int) $id . '/replace';

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect($back);
            return;
        }
        $debitOrder = $this->debitOrders->find((int) $id);
        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (empty($_POST['confirm']) || mb_strlen($reason) < 5) {
            Session::flash('error', 'Tick the confirmation box and give a reason (at least 5 characters) to cancel the old mandate.');
            $this->redirect($back);
            return;
        }

        $result = (new MandateReplacementService())->cancelForReplacement($debitOrder, $reason, Auth::user()['id'] ?? null);
        if (!$result['success']) {
            Session::flash('error', $result['message']);
            $this->redirect($back);
            return;
        }

        Session::flash('success', $result['message']);
        $this->redirect('/loans/' . (int) $debitOrder['loan_id'] . '/debit-orders/create');
    }

    public function show(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->debitOrders->find((int) $id);

        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        $splitLegs = $debitOrder['split_enabled'] ? $this->splitLegs->activeForDebitOrder((int) $id) : [];

        // Split orders can now be placed incrementally (e.g. only a
        // newly-merged split still needs placing while its siblings are
        // already Registered), so button visibility can't rely on the
        // single rolled-up collexia_api_status the way a non-split order
        // does -- it's computed directly from the real split rows instead.
        $splitFlags = [
            'anyUnsent' => (bool) array_filter($splitLegs, fn ($s) => in_array($s['collexia_api_status'], ['Not Placed', 'Load Failed'], true)),
            'anyLoadPending' => (bool) array_filter($splitLegs, fn ($s) => $s['collexia_api_status'] === 'Load Pending'),
            'anyLive' => (bool) array_filter($splitLegs, fn ($s) => in_array($s['collexia_api_status'], ['Registered', 'Load Pending'], true)),
            'hasNextInstallment' => $debitOrder['split_enabled']
                ? $this->splitLegs->nextUncoveredScheduleId((int) $id, (int) $debitOrder['loan_id']) !== null
                : false,
        ];

        $carriers = $debitOrder['split_enabled'] ? $this->laneInstallments->carriersBySchedule((int) $id) : [];

        $this->view('debit_orders/show', [
            'title' => 'Debit Order ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'pendingCancellation' => $this->cancellations->findPendingForDebitOrder((int) $id),
            'collexiaEnabled' => $this->collexiaSettings->isEnabled(),
            'collexiaConfigured' => $this->collexiaSettings->isConfigured(),
            'editable' => DebitOrder::isEditable($debitOrder),
            'splitLegs' => $splitLegs,
            'splitFlags' => $splitFlags,
            // The loan's own schedule -- shown as an "Installments Covered"
            // breakdown on this page so staff can see every installment
            // this one mandate's noOfInstallments collects (e.g. this
            // month AND next), the same way the loan's own Amortization
            // Schedule does, without needing a second debit order (which
            // would double-collect -- see liveForLoan()'s own guard).
            'loanSchedule' => $this->loans->schedule((int) $debitOrder['loan_id']),
            // Which mandate(s) carry each installment -- the page links each
            // installment to them (and from there to every installment the
            // mandate carries).
            'carriers' => $carriers,
            'outOfStep' => $this->debitOrders->outOfStepForLoan((int) $debitOrder['loan_id']),
            'collectionCheck' => (new MandateCollectionCheck())->forLoan((int) $debitOrder['loan_id']),
            'loan' => $this->loans->find((int) $debitOrder['loan_id']),
            'scheduleDrift' => $this->scheduleDrift($this->loans->schedule((int) $debitOrder['loan_id']), $carriers),
        ]);
    }

    public function edit(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->debitOrders->find((int) $id);

        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        if (!DebitOrder::isEditable($debitOrder)) {
            Session::flash('error', 'This mandate has already been placed and can no longer be edited here -- use Reschedule Installment for amount/date changes, or cancel and re-register for bank/account changes.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        $this->view('debit_orders/edit', [
            'title' => 'Edit Debit Order ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'old' => [],
            'errors' => [],
            'banks' => CollexiaCodes::BANKS,
            'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-orders/' . $id . '/edit');
            return;
        }

        $debitOrder = $this->debitOrders->find((int) $id);
        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        if (!DebitOrder::isEditable($debitOrder)) {
            Session::flash('error', 'This mandate has already been placed and can no longer be edited here.');
            $this->redirect('/debit-orders/' . $id);
            return;
        }

        $errors = [];
        foreach (['bank_code', 'account_number', 'debit_day', 'debit_amount', 'start_date'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (!empty($_POST['bank_code']) && !isset(CollexiaCodes::BANKS[$_POST['bank_code']])) {
            $errors['bank_code'] = 'Select a valid bank.';
        }
        if (empty($errors['start_date']) && !empty($_POST['start_date']) && $_POST['start_date'] < date('Y-m-d')) {
            $errors['start_date'] = 'Start Date must be today or a future date.';
        }

        if (!empty($errors)) {
            $this->view('debit_orders/edit', [
                'title' => 'Edit Debit Order ' . $debitOrder['debit_order_no'],
                'debitOrder' => array_merge($debitOrder, $_POST),
                'old' => $_POST,
                'errors' => $errors,
                'banks' => CollexiaCodes::BANKS,
                'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
            ]);
            return;
        }

        $trackingDays = max(1, min(14, (int) ($_POST['no_of_days_tracking'] ?? 3)));

        $this->debitOrders->updateRecord((int) $id, [
            'bank_name' => CollexiaCodes::BANKS[$_POST['bank_code']],
            'account_name' => trim($_POST['account_name'] ?? '') ?: null,
            'account_number' => trim($_POST['account_number']),
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'debit_day' => (int) $_POST['debit_day'],
            'debit_amount' => (float) $_POST['debit_amount'],
            'start_date' => $_POST['start_date'],
            'account_type' => (int) ($_POST['account_type'] ?? 1),
            'bank_code' => $_POST['bank_code'],
            'no_of_days_tracking' => $trackingDays,
        ]);

        Audit::log('Update', 'Debit Orders', 'Updated debit order #' . $id . ' for loan ' . $debitOrder['loan_no']);
        Session::flash('success', 'Debit order updated.');
        $this->redirect('/debit-orders/' . $id);
    }
}
