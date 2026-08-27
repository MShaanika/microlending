<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\InterestAccrual;
use App\Services\InterestAccrualService;

class InterestAccrualController extends Controller
{
    private InterestAccrual $accruals;

    public function __construct()
    {
        $this->accruals = new InterestAccrual();
    }

    public function index(): void
    {
        Auth::authorize('accounting.view');
        $this->view('accounting/interest_accruals/index', [
            'title' => 'Interest Accruals',
            'runs' => $this->accruals->runsPaginated(),
        ]);
    }

    public function preview(): void
    {
        Auth::authorize('accounting.view');
        $asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
        $installments = InterestAccrualService::accruableInstallments($asOfDate);
        $total = round(array_sum(array_column($installments, 'interest_amount')), 2);

        $this->view('accounting/interest_accruals/preview', [
            'title' => 'Preview Interest Accrual',
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
            $this->redirect('/accounting/interest-accruals');
            return;
        }

        $asOfDate = $_POST['as_of_date'] ?? date('Y-m-d');
        $userId = Auth::user()['id'] ?? null;

        try {
            $installments = InterestAccrualService::accrue($asOfDate, $userId);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/interest-accruals');
            return;
        }

        if (empty($installments)) {
            Session::flash('success', 'No new interest to accrue as at ' . $asOfDate . '.');
            $this->redirect('/accounting/interest-accruals');
            return;
        }

        $total = round(array_sum(array_column($installments, 'interest_amount')), 2);

        Audit::log('Create', 'Accounting', 'Posted interest accrual as at ' . $asOfDate . ' (' . format_money($total) . ' across ' . count($installments) . ' installment(s))');
        Session::flash('success', 'Interest accrual posted: ' . format_money($total) . ' across ' . count($installments) . ' installment(s).');
        $this->redirect('/accounting/interest-accruals');
    }

    public function show(string $accrualDate): void
    {
        Auth::authorize('accounting.view');
        $this->view('accounting/interest_accruals/show', [
            'title' => 'Interest Accrual Run - ' . $accrualDate,
            'accrualDate' => $accrualDate,
            'lines' => $this->accruals->forRun($accrualDate),
        ]);
    }
}
