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
use App\Services\ArrearsService;

class BadDebtProvisionController extends Controller
{
    private BadDebtProvision $provisions;
    private BadDebt $badDebts;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->provisions = new BadDebtProvision();
        $this->badDebts = new BadDebt();
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

        [$loans, $totalRequired] = $this->computeRun($asOfDate);
        $currentBalance = $this->provisions->currentProvisionBalance();
        $delta = round($totalRequired - $currentBalance, 2);

        if (abs($delta) < 0.01) {
            Session::flash('success', 'No change needed -- the provision already matches the required level (' . format_money($totalRequired) . ').');
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        $badDebtExpenseId = $this->accounts->idByCode('5010');
        $provisionAccountId = $this->accounts->idByCode('1050');

        try {
            if ($delta > 0) {
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
        }

        Audit::log('Create', 'Accounting', 'Posted bad debt provisioning run as at ' . $asOfDate . ' (' . format_money($delta) . ' adjustment)');
        Session::flash('success', 'Provisioning posted: ' . format_money($delta) . ' adjustment across ' . count($loans) . ' loan(s) in arrears.');
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
     * @return array{0: array, 1: float}
     */
    private function computeRun(string $asOfDate): array
    {
        $loans = array_filter(
            ArrearsService::overdueLoans($asOfDate),
            fn ($l) => $l['aging_bucket'] !== 'Current' && $l['aging_bucket'] !== '1-30' && $l['provision_amount'] > 0
        );
        $loans = array_values($loans);
        $totalRequired = round(array_sum(array_column($loans, 'provision_amount')), 2);
        return [$loans, $totalRequired];
    }
}
