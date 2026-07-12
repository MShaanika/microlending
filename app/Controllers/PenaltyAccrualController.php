<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Penalty;
use App\Services\PenaltyAccrualService;

class PenaltyAccrualController extends Controller
{
    private Penalty $penalties;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->penalties = new Penalty();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function index(): void
    {
        Auth::authorize('accounting.view');
        $this->view('accounting/penalty_accruals/index', [
            'title' => 'Penalty Accruals',
            'runs' => $this->penalties->runsPaginated(),
        ]);
    }

    public function preview(): void
    {
        Auth::authorize('accounting.view');
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
        $installments = PenaltyAccrualService::chargeableInstallments($asOfDate);
        $total = round(array_sum(array_column($installments, 'penalty_amount')), 2);

        $this->view('accounting/penalty_accruals/preview', [
            'title' => 'Preview Penalty Accrual',
            'asOfDate' => $asOfDate,
            'installments' => $installments,
            'total' => $total,
        ]);
    }

    public function post(): void
    {
        Auth::authorize('accounting.view');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/penalty-accruals');
            return;
        }

        $asOfDate = $_POST['as_of_date'] ?? date('Y-m-d');
        $userId = Auth::user()['id'] ?? null;

        $installments = PenaltyAccrualService::chargeableInstallments($asOfDate);
        $total = round(array_sum(array_column($installments, 'penalty_amount')), 2);

        if ($total <= 0) {
            Session::flash('success', 'No new penalties to charge as at ' . $asOfDate . '.');
            $this->redirect('/accounting/penalty-accruals');
            return;
        }

        $penaltyReceivableId = $this->accounts->idByCode('1040');
        $deferredPenaltyIncomeId = $this->accounts->idByCode('2050');

        try {
            $journalId = $this->journal->post(
                'PENALTY_ACCRUAL',
                'penalties',
                null,
                generate_reference('PEN'),
                'Penalty charges raised as at ' . $asOfDate,
                [
                    ['account_id' => $penaltyReceivableId, 'debit' => $total, 'credit' => 0],
                    ['account_id' => $deferredPenaltyIncomeId, 'debit' => 0, 'credit' => $total],
                ],
                $userId,
                $asOfDate,
                'Manual'
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/penalty-accruals');
            return;
        }

        $loans = new Loan();

        foreach ($installments as $line) {
            $this->penalties->create([
                'loan_id' => $line['loan_id'],
                'borrower_id' => $line['borrower_id'],
                'schedule_id' => $line['schedule_id'],
                'penalty_no' => generate_reference('PNL'),
                'penalty_date' => $asOfDate,
                'base_amount' => $line['base_amount'],
                'penalty_rate' => $line['penalty_rate'],
                'penalty_amount' => $line['penalty_amount'],
                'reason' => 'Installment #' . $line['installment_no'] . ' ' . $line['days_overdue'] . ' days overdue as at ' . $asOfDate,
                'status' => 'Charged',
                'charged_by' => $userId,
            ]);

            // penalty_due is only ever set here, so it is guaranteed 0 going
            // into this run -- no need to re-fetch the row first.
            $loans->updateScheduleRow((int) $line['schedule_id'], [
                'penalty_due' => $line['penalty_amount'],
                'total_due' => round((float) $line['total_due'] + $line['penalty_amount'], 2),
            ]);
        }

        Audit::log('Create', 'Accounting', 'Posted penalty accrual as at ' . $asOfDate . ' (' . format_money($total) . ' across ' . count($installments) . ' installment(s))');
        Session::flash('success', 'Penalty accrual posted: ' . format_money($total) . ' across ' . count($installments) . ' installment(s).');
        $this->redirect('/accounting/penalty-accruals');
    }

    public function show(string $penaltyDate): void
    {
        Auth::authorize('accounting.view');
        $this->view('accounting/penalty_accruals/show', [
            'title' => 'Penalty Accrual Run - ' . $penaltyDate,
            'penaltyDate' => $penaltyDate,
            'lines' => $this->penalties->forRun($penaltyDate),
        ]);
    }
}
