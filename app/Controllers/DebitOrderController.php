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
use App\Models\Loan;
use App\Support\CollexiaCodes;

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
    private Loan $loans;
    private Borrower $borrowers;
    private CollexiaSetting $collexiaSettings;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->cancellations = new DebitOrderCancellation();
        $this->splitLegs = new DebitOrderSplitLeg();
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

        if (($_POST['mode'] ?? '') === 'per_installment') {
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
     * "One mandate per scheduled installment" registration: every selected
     * unpaid installment becomes its own one-time Collexia mandate
     * (a split leg with total_splits=1 and its own schedule_id), collected
     * on that installment's own amortization date -- not one recurring
     * mandate whose monthly rule only approximates the schedule. Reuses the
     * split-leg machinery end to end, so placement, status sync,
     * cancellation and payment reconciliation (by schedule_id) already
     * work unchanged. With place_now set, the legs are sent to Collexia
     * straight away.
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
        $legs = [];
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
            $legs[] = ['schedule_id' => $scheduleId, 'installment_no' => (int) $row['installment_no'], 'amount' => round((float) $row['outstanding'], 2), 'date' => $date];
        }
        if (empty($errors['installments'])) {
            if (!$legs) {
                $errors['installments'] = 'Tick at least one installment to collect.';
            } elseif (count($legs) > 26) {
                $errors['installments'] = 'At most 26 installments can be placed on one debit order.';
            }
        }

        if (!empty($errors)) {
            $this->view('debit_orders/create', [
                'title' => 'Register Debit Order - ' . $loan['loan_no'],
                'loan' => $loan,
                'old' => $_POST,
                'firstDueDate' => $this->loans->firstScheduleDueDate($loanId),
                'installments' => $installments,
                'errors' => $errors,
                'banks' => CollexiaCodes::BANKS,
                'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
            ]);
            return;
        }

        usort($legs, fn ($a, $b) => $a['installment_no'] <=> $b['installment_no']);
        $first = $legs[0];
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
            'debit_amount' => $first['amount'],
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

        $summary = [];
        foreach ($legs as $i => $leg) {
            $this->splitLegs->upsert($debitOrderId, $i + 1, $leg['amount'], 1, $leg['schedule_id'], $leg['date']);
            $summary[] = '#' . $leg['installment_no'] . ' ' . format_money($leg['amount']) . ' on ' . $leg['date'];
        }

        Audit::log('Create', 'Debit Orders', 'Registered debit order #' . $debitOrderId . ' for loan ' . $loan['loan_no']
            . ' as one mandate per scheduled installment: ' . implode('; ', $summary));

        $collexia = new CollexiaSetting();
        if (!empty($_POST['place_now']) && $collexia->isEnabled() && $collexia->isConfigured()) {
            Session::flash('success', 'Debit order registered with ' . count($legs) . ' installment(s). Sending them to Collexia now.');
            // Same POST, same CSRF token -- placeMandate() validates it
            // itself, places every leg, then redirects to the debit order.
            (new DebitOrderCollexiaController())->placeMandate((string) $debitOrderId);
            return;
        }

        Session::flash('success', 'Debit order registered with ' . count($legs) . ' installment(s). Use Place Mandate to send them to Collexia.');
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
