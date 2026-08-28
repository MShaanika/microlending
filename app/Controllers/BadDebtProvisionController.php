<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BadDebt;
use App\Models\BadDebtProvision;
use App\Models\Loan;
use App\Services\ArrearsService;

class BadDebtProvisionController extends Controller
{
    private BadDebtProvision $provisions;
    private BadDebt $badDebts;
    private Loan $loans;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->provisions = new BadDebtProvision();
        $this->badDebts = new BadDebt();
        $this->loans = new Loan();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function index(): void
    {
        Auth::authorize('accounting.provisions');
        $this->view('accounting/bad_debt_provisions/index', [
            'title' => 'Bad Debt Provisioning',
            'runs' => $this->provisions->runsPaginated(),
            'currentBalance' => $this->provisions->currentProvisionBalance(),
        ]);
    }

    public function badDebts(): void
    {
        Auth::authorize('accounting.provisions');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('accounting/bad_debt_provisions/bad_debts', [
            'title' => 'Bad Debts',
            'badDebts' => $this->badDebts->paginated($status),
            'status' => $status,
        ]);
    }

    public function preview(): void
    {
        Auth::authorize('accounting.provisions');
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

        [$loans, $totalRequired] = $this->computeRun($asOfDate);
        $currentBalance = $this->provisions->currentProvisionBalance();
        // $released (cured loans needing a zero snapshot) isn't shown here --
        // they carry no GL impact, so there's nothing for staff to preview
        // or approve; see post() for why they still need a row written.

        $this->view('accounting/bad_debt_provisions/preview', [
            'title' => 'Preview Bad Debt Provisioning',
            'asOfDate' => $asOfDate,
            'loans' => $loans,
            'totalRequired' => $totalRequired,
            'currentBalance' => $currentBalance,
            'delta' => round($totalRequired - $currentBalance, 2),
        ]);
    }

    public function post(): void
    {
        Auth::authorize('accounting.provisions');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bad-debt-provisions');
        }

        $asOfDate = $_POST['as_of_date'] ?? date('Y-m-d');
        $userId = Auth::user()['id'] ?? null;

        [$loans, $totalRequired, $released] = $this->computeRun($asOfDate);
        $currentBalance = $this->provisions->currentProvisionBalance();
        $delta = round($totalRequired - $currentBalance, 2);

        if (abs($delta) < 0.01 && empty($loans) && empty($released)) {
            Session::flash('success', 'No change needed -- the provision already matches the required level (' . format_money($totalRequired) . ').');
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        // A loan that cures still needs its own zero-provision row written
        // even when the PORTFOLIO-level delta nets to ~0 (e.g. one loan
        // cures while another enters arrears for a similar amount) --
        // otherwise provisionForLoan() keeps returning that loan's stale
        // last-nonzero snapshot forever. $journalId stays null when no
        // net GL adjustment is required this run; the zero rows below carry
        // no independent GL impact of their own.
        $journalId = null;
        $badDebtExpenseId = $this->accounts->idByCode('5010');
        $provisionAccountId = $this->accounts->idByCode('1050');

        try {
            if (abs($delta) < 0.01) {
                // fall through with $journalId = null
            } elseif ($delta > 0) {
                $journalId = $this->journal->post(
                    'BAD_DEBT_PROVISION',
                    'bad_debt_provisions',
                    null,
                    generate_reference('PROV'),
                    'Bad debt provision raised as at ' . $asOfDate,
                    [
                        ['account_id' => $badDebtExpenseId, 'debit' => $delta, 'credit' => 0],
                        ['account_id' => $provisionAccountId, 'debit' => 0, 'credit' => $delta],
                    ],
                    $userId,
                    $asOfDate,
                    'Manual'
                );
            } else {
                $release = abs($delta);
                $journalId = $this->journal->post(
                    'BAD_DEBT_PROVISION',
                    'bad_debt_provisions',
                    null,
                    generate_reference('PROV'),
                    'Bad debt provision released as at ' . $asOfDate,
                    [
                        ['account_id' => $provisionAccountId, 'debit' => $release, 'credit' => 0],
                        ['account_id' => $badDebtExpenseId, 'debit' => 0, 'credit' => $release],
                    ],
                    $userId,
                    $asOfDate,
                    'Manual'
                );
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        foreach ($loans as $loan) {
            $badDebt = $this->badDebts->findByLoan((int) $loan['loan_id']);
            if (!$badDebt) {
                $badDebtId = $this->badDebts->create([
                    'loan_id' => $loan['loan_id'],
                    'borrower_id' => $loan['borrower_id'],
                    'branch_id' => $loan['branch_id'],
                    'bad_debt_no' => generate_reference('BD'),
                    'identified_date' => $asOfDate,
                    'outstanding_balance' => $loan['outstanding_balance'],
                    'days_in_arrears' => $loan['days_in_arrears'],
                    'aging_bucket' => $loan['aging_bucket'],
                    'reason' => 'Identified by provisioning run on ' . $asOfDate,
                    'status' => 'Provisioned',
                    'identified_by' => $userId,
                ]);
            } else {
                $badDebtId = (int) $badDebt['id'];
                $this->badDebts->updateRecord($badDebtId, [
                    'outstanding_balance' => $loan['outstanding_balance'],
                    'days_in_arrears' => $loan['days_in_arrears'],
                    'aging_bucket' => $loan['aging_bucket'],
                    'status' => 'Provisioned',
                ]);
            }

            $this->provisions->create([
                'loan_id' => $loan['loan_id'],
                'borrower_id' => $loan['borrower_id'],
                'branch_id' => $loan['branch_id'],
                'bad_debt_id' => $badDebtId,
                'provision_no' => generate_reference('PRVL'),
                'provision_date' => $asOfDate,
                'outstanding_balance' => $loan['outstanding_balance'],
                'aging_days' => $loan['days_in_arrears'],
                'provision_rate' => $loan['provision_rate'],
                'provision_amount' => $loan['provision_amount'],
                'status' => 'Posted',
                'journal_id' => $journalId,
                'posted_by' => $userId,
                'posted_at' => date('Y-m-d H:i:s'),
            ]);

            ArrearsService::refreshLoanStatus((int) $loan['loan_id'], $asOfDate, $userId, 'Sweep', 'PROVISION_RUN:' . $asOfDate);
        }

        // Cured loans: no GL impact of their own (provision_amount = 0), but
        // still need an explicit snapshot row so provisionForLoan() stops
        // returning their stale last-nonzero value and credit_status can
        // revert. bad_debts.aging_bucket is left untouched here -- its ENUM
        // only accepts the overdue buckets (30-59/60-89/90+), so a cured
        // loan (back to Current/1-29) has no valid value to write into it.
        foreach ($released as $loan) {
            $badDebt = $this->badDebts->findByLoan((int) $loan['loan_id']);
            if ($badDebt) {
                $this->badDebts->updateRecord((int) $badDebt['id'], ['status' => 'Open']);
            }

            $this->provisions->create([
                'loan_id' => $loan['loan_id'],
                'borrower_id' => $loan['borrower_id'],
                'branch_id' => $loan['branch_id'],
                'bad_debt_id' => $badDebt ? (int) $badDebt['id'] : null,
                'provision_no' => generate_reference('PRVL'),
                'provision_date' => $asOfDate,
                'outstanding_balance' => 0,
                'aging_days' => 0,
                'provision_rate' => 0,
                'provision_amount' => 0,
                'status' => 'Posted',
                'journal_id' => $journalId,
                'posted_by' => $userId,
                'posted_at' => date('Y-m-d H:i:s'),
            ]);

            ArrearsService::refreshLoanStatus((int) $loan['loan_id'], $asOfDate, $userId, 'Sweep', 'PROVISION_RUN:' . $asOfDate);
        }

        Audit::log('Create', 'Accounting', 'Posted bad debt provisioning run as at ' . $asOfDate . ' (' . format_money($delta) . ' adjustment)');
        Session::flash('success', 'Provisioning posted: ' . format_money($delta) . ' adjustment across ' . count($loans) . ' loan(s) in arrears' . (count($released) > 0 ? ', ' . count($released) . ' loan(s) released.' : '.'));
        $this->redirect('/accounting/bad-debt-provisions');
    }

    public function show(string $provisionDate): void
    {
        Auth::authorize('accounting.provisions');
        $this->view('accounting/bad_debt_provisions/show', [
            'title' => 'Provisioning Run - ' . $provisionDate,
            'provisionDate' => $provisionDate,
            'lines' => $this->provisions->forRun($provisionDate),
        ]);
    }

    /**
     * @return array{0: array, 1: float, 2: array}
     */
    private function computeRun(string $asOfDate): array
    {
        $loans = array_filter(
            ArrearsService::overdueLoans($asOfDate),
            fn ($l) => $l['aging_bucket'] !== 'Current' && $l['aging_bucket'] !== '1-29' && $l['provision_amount'] > 0
        );
        $loans = array_values($loans);
        $totalRequired = round(array_sum(array_column($loans, 'provision_amount')), 2);

        // Loans that cure (return to Current/1-29, or pay off entirely)
        // simply drop out of overdueLoans() -- find every loan whose most
        // recent Posted provision snapshot is still nonzero but is no
        // longer in the set above, so post() can write an explicit release
        // row for it (see the loop over $released there).
        $stillProvisioned = array_map('intval', array_column($loans, 'loan_id'));
        $released = [];
        foreach ($this->provisions->loanIdsWithNonzeroPostedProvision() as $loanId) {
            if (in_array($loanId, $stillProvisioned, true)) {
                continue;
            }
            $loanRow = $this->loans->find($loanId);
            if (!$loanRow) {
                continue;
            }
            $released[] = [
                'loan_id' => $loanId,
                'borrower_id' => (int) $loanRow['borrower_id'],
                'branch_id' => (int) $loanRow['branch_id'],
            ];
        }

        return [$loans, $totalRequired, $released];
    }
}
