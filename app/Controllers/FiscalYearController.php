<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;

class FiscalYearController extends Controller
{
    private FiscalYear $fiscalYears;
    private AccountingPeriod $periods;

    public function __construct()
    {
        $this->fiscalYears = new FiscalYear();
        $this->periods = new AccountingPeriod();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $this->view('accounting/fiscal_years/index', [
            'title' => 'Fiscal Years',
            'fiscalYears' => $this->fiscalYears->allYears(),
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $this->view('accounting/fiscal_years/create', [
            'title' => 'Add Fiscal Year',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/fiscal-years/create');
        }

        $financialYear = trim($_POST['financial_year'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';

        $errors = [];
        if ($financialYear === '') {
            $errors['financial_year'] = 'Name is required (e.g. FY2026).';
        }
        if ($startDate === '' || $endDate === '') {
            $errors['start_date'] = 'Start and end dates are required.';
        } elseif (strtotime($startDate) > strtotime($endDate)) {
            $errors['start_date'] = 'Start date cannot be after end date.';
        }

        if (empty($errors) && $this->fiscalYears->nameExists($financialYear)) {
            $errors['financial_year'] = 'A fiscal year with this name already exists.';
        }
        if (empty($errors) && $this->fiscalYears->overlaps($startDate, $endDate)) {
            $errors['start_date'] = 'This date range overlaps with an existing fiscal year.';
        }

        if (!empty($errors)) {
            $this->view('accounting/fiscal_years/create', [
                'title' => 'Add Fiscal Year',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->fiscalYears->create([
            'financial_year' => $financialYear,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'Open',
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        $this->periods->generateForFiscalYear($id, $startDate, $endDate);

        Audit::log('Create', 'Accounting', 'Created fiscal year ' . $financialYear . ' with monthly periods');
        Session::flash('success', 'Fiscal year created with monthly periods.');
        $this->redirect('/accounting/fiscal-years/' . $id);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $fiscalYear = $this->fiscalYears->find((int) $id);

        if (!$fiscalYear) {
            Session::flash('error', 'Fiscal year not found.');
            $this->redirect('/accounting/fiscal-years');
        }

        $this->view('accounting/fiscal_years/show', [
            'title' => $fiscalYear['financial_year'],
            'fiscalYear' => $fiscalYear,
            'periods' => $this->periods->forFiscalYear((int) $id),
        ]);
    }

    public function close(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/fiscal-years/' . $id);
        }

        $this->fiscalYears->setStatus($id, 'Closed');
        $this->periods->closeAllForFiscalYear($id, Auth::user()['id'] ?? null);

        Audit::log('Close', 'Accounting', 'Closed fiscal year #' . $id . ' and all its periods');
        Session::flash('success', 'Fiscal year and all its periods closed.');
        $this->redirect('/accounting/fiscal-years/' . $id);
    }

    public function open(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/fiscal-years/' . $id);
        }

        $this->fiscalYears->setStatus($id, 'Open');
        $this->periods->reopenAllForFiscalYear($id);

        Audit::log('Open', 'Accounting', 'Reopened fiscal year #' . $id . ' and all its periods');
        Session::flash('success', 'Fiscal year and all its periods reopened.');
        $this->redirect('/accounting/fiscal-years/' . $id);
    }

    public function closePeriod(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/fiscal-years');
        }

        $period = $this->periods->find($id);
        if (!$period) {
            Session::flash('error', 'Period not found.');
            $this->redirect('/accounting/fiscal-years');
        }

        $this->periods->close($id, Auth::user()['id'] ?? null);

        Audit::log('Close', 'Accounting', 'Closed accounting period #' . $id . ' (' . $period['period_name'] . ')');
        Session::flash('success', 'Period closed. No more postings can be made to it.');
        $this->redirect('/accounting/fiscal-years/' . $period['fiscal_year_id']);
    }

    public function reopenPeriod(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/fiscal-years');
        }

        $period = $this->periods->find($id);
        if (!$period) {
            Session::flash('error', 'Period not found.');
            $this->redirect('/accounting/fiscal-years');
        }

        $this->periods->reopen($id);

        Audit::log('Reopen', 'Accounting', 'Reopened accounting period #' . $id . ' (' . $period['period_name'] . ')');
        Session::flash('success', 'Period reopened.');
        $this->redirect('/accounting/fiscal-years/' . $period['fiscal_year_id']);
    }
}
