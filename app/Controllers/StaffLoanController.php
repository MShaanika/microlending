<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\StaffLoan;
use App\Models\StaffLoanRepayment;
use App\Models\StaffLoanType;

class StaffLoanController extends Controller
{
    private const STATUSES = ['Pending', 'Active', 'Completed', 'Cancelled', 'Rejected'];

    private StaffLoan $loans;
    private StaffLoanType $types;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->loans = new StaffLoan();
        $this->types = new StaffLoanType();
        $this->employees = new HrmEmployee();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];

        $this->view('hrm/staff-loans/index', [
            'title' => 'Staff Loans',
            'loans' => $this->loans->allLoans($filters),
            'employees' => $this->employees->allEmployees(),
            'statuses' => self::STATUSES,
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/staff-loans/create', [
            'title' => 'New Staff Loan',
            'employees' => $this->employees->allEmployees(),
            'types' => $this->types->allTypes(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/staff-loans/create', [
                'title' => 'New Staff Loan',
                'employees' => $this->employees->allEmployees(),
                'types' => $this->types->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['installment_amount'] = round($data['principal_amount'] / $data['number_of_installments'], 2);
        $data['outstanding_balance'] = $data['principal_amount'];
        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->loans->create($data);

        Audit::log('Create', 'HRM', 'Staff loan #' . $id . ' created - ' . $data['title']);
        Session::flash('success', 'Staff loan submitted for approval.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $loan = $this->loans->find($id);
        if (!$loan) {
            Session::flash('error', 'Staff loan not found.');
            $this->redirect('/hrm/staff-loans');
            return;
        }
        $this->view('hrm/staff-loans/show', [
            'title' => 'Staff Loan',
            'loan' => $loan,
            'repayments' => (new StaffLoanRepayment())->forLoan($id),
        ]);
    }

    public function approve(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || $loan['status'] !== 'Pending') {
            Session::flash('error', 'Only pending loans can be approved.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->updateRecord($id, ['status' => 'Active', 'approved_by' => Auth::user()['id'] ?? null]);

        Audit::log('Update', 'HRM', 'Staff loan #' . $id . ' approved');
        Session::flash('success', 'Staff loan approved. It will be deducted starting from its start date.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function reject(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || $loan['status'] !== 'Pending') {
            Session::flash('error', 'Only pending loans can be rejected.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->updateRecord($id, ['status' => 'Rejected', 'approved_by' => Auth::user()['id'] ?? null]);

        Audit::log('Update', 'HRM', 'Staff loan #' . $id . ' rejected');
        Session::flash('success', 'Staff loan rejected.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function cancel(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || !in_array($loan['status'], ['Pending', 'Active'], true)) {
            Session::flash('error', 'Only pending or active loans can be cancelled.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->updateRecord($id, ['status' => 'Cancelled']);

        Audit::log('Update', 'HRM', 'Staff loan #' . $id . ' cancelled');
        Session::flash('success', 'Staff loan cancelled. No further deductions will be made.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || in_array($loan['status'], ['Active', 'Completed'], true)) {
            Session::flash('error', 'Active or completed loans (with repayment history) cannot be deleted -- cancel instead.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted staff loan #' . $id);
        Session::flash('success', 'Staff loan deleted.');
        $this->redirect('/hrm/staff-loans');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $title = trim($post['title'] ?? '');
        $principalAmount = isset($post['principal_amount']) && $post['principal_amount'] !== '' ? (float) $post['principal_amount'] : null;
        $numberOfInstallments = isset($post['number_of_installments']) && $post['number_of_installments'] !== '' ? (int) $post['number_of_installments'] : null;
        $startDate = trim($post['start_date'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($principalAmount === null || $principalAmount <= 0) {
            $errors['principal_amount'] = 'Enter a principal amount greater than 0.';
        }
        if ($numberOfInstallments === null || $numberOfInstallments < 1) {
            $errors['number_of_installments'] = 'Enter at least 1 installment.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'staff_loan_type_id' => !empty($post['staff_loan_type_id']) ? (int) $post['staff_loan_type_id'] : null,
            'title' => $title,
            'principal_amount' => $principalAmount ?? 0,
            'number_of_installments' => $numberOfInstallments ?? 1,
            'start_date' => $startDate ?: null,
            'reason' => trim($post['reason'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
