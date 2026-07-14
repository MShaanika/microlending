<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Penalty;
use App\Services\PenaltyAccrualService;

class PenaltyAccrualController extends Controller
{
    private Penalty $penalties;

    public function __construct()
    {
        $this->penalties = new Penalty();
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

        try {
            $installments = PenaltyAccrualService::accrue($asOfDate, $userId);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/penalty-accruals');
            return;
        }

        if (empty($installments)) {
            Session::flash('success', 'No new penalties to charge as at ' . $asOfDate . '.');
            $this->redirect('/accounting/penalty-accruals');
            return;
        }

        $total = round(array_sum(array_column($installments, 'penalty_amount')), 2);

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
