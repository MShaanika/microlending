<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\HrmPayroll;
use App\Models\HrmPayrollEntry;
use App\Services\PayrollService;
use DateTime;

class HrmPayrollController extends Controller
{
    private const STATUSES = ['Draft', 'Processing', 'Completed', 'Cancelled'];

    private HrmPayroll $payrolls;
    private HrmPayrollEntry $entries;
    private BankAccount $bankAccounts;

    public function __construct()
    {
        $this->payrolls = new HrmPayroll();
        $this->entries = new HrmPayrollEntry();
        $this->bankAccounts = new BankAccount();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $filters = [
            'status' => $_GET['status'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'pay_period_start');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->payrolls->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Payroll',
            'payrolls' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'statuses' => self::STATUSES,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/payrolls/index', $data);
            return;
        }
        $this->view('hrm/payrolls/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'New Payroll Run',
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/payrolls/create', $data);
            return;
        }
        $this->view('hrm/payrolls/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/payrolls/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/payrolls/create', [
                'title' => 'New Payroll Run',
                'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Draft';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->payrolls->create($data);

        Audit::log('Create', 'HRM', 'Created payroll run #' . $id . ' - ' . $data['title']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Payroll run created. Click "Run Payroll" to generate payslips.');
        }
        Session::flash('success', 'Payroll run created. Click "Run Payroll" to generate payslips.');
        $this->redirect('/hrm/payrolls/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $payroll = $this->payrolls->find($id);
        if (!$payroll) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Payroll run not found.'], 404);
            }
            Session::flash('error', 'Payroll run not found.');
            $this->redirect('/hrm/payrolls');
            return;
        }
        $data = [
            'title' => $payroll['title'],
            'payroll' => $payroll,
            'entries' => $this->entries->forPayroll($id),
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/payrolls/show', $data);
            return;
        }
        $this->view('hrm/payrolls/show', $data);
    }

    public function run(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/payrolls/' . $id);
            return;
        }

        $payroll = $this->payrolls->find($id);
        if (!$payroll) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Payroll run not found.'], 404);
            }
            Session::flash('error', 'Payroll run not found.');
            $this->redirect('/hrm/payrolls');
            return;
        }
        if ($payroll['status'] === 'Completed') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This payroll has already been run. Delete and recreate it to run again from scratch.'], 422);
            }
            Session::flash('error', 'This payroll has already been run. Delete and recreate it to run again from scratch.');
            $this->redirect('/hrm/payrolls/' . $id);
            return;
        }

        try {
            $result = PayrollService::run($id, Auth::user()['id'] ?? null);
            Audit::log('Update', 'HRM', 'Ran payroll #' . $id . ' -- ' . $result['new_entries'] . ' new payslips');
            $message = $result['new_entries'] > 0
                ? "Payroll processed. {$result['new_entries']} new payslips created (total: {$result['total_entries']})."
                : "Payroll already processed for all {$result['total_entries']} employees.";
            if ($this->isAjax()) {
                $this->jsonSuccess($message, '/hrm/payrolls/' . $id);
            }
            Session::flash('success', $message);
        } catch (\Throwable $e) {
            $this->payrolls->updateRecord($id, ['status' => 'Draft']);
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Failed to process payroll: ' . $e->getMessage()]);
            }
            Session::flash('error', 'Failed to process payroll: ' . $e->getMessage());
        }

        $this->redirect('/hrm/payrolls/' . $id);
    }

    public function payslip(int $payrollId, int $entryId): void
    {
        Auth::authorize('hrm.view');

        $payroll = $this->payrolls->find($payrollId);
        $entry = $this->entries->find($entryId);
        if (!$payroll || !$entry || (int) $entry['payroll_id'] !== $payrollId) {
            Session::flash('error', 'Payslip not found.');
            $this->redirect('/hrm/payrolls/' . $payrollId);
            return;
        }

        $this->view('hrm/payrolls/payslip', [
            'title' => 'Payslip - ' . $entry['employee_name'],
            'payroll' => $payroll,
            'entry' => $entry,
            'allowances' => json_decode($entry['allowances_breakdown'] ?? '[]', true) ?: [],
            'deductions' => json_decode($entry['deductions_breakdown'] ?? '[]', true) ?: [],
            'staffLoans' => json_decode($entry['staff_loans_breakdown'] ?? '[]', true) ?: [],
        ]);
    }

    public function markEntryPaid(int $payrollId, int $entryId): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/payrolls/' . $payrollId);
            return;
        }

        $this->entries->updateStatus($entryId, 'Paid');
        Audit::log('Update', 'HRM', 'Marked payslip #' . $entryId . ' as paid');

        if ($this->isAjax()) {
            $this->jsonSuccess('Payslip marked as paid.', '/hrm/payrolls/' . $payrollId);
        }
        Session::flash('success', 'Payslip marked as paid.');
        $this->redirect('/hrm/payrolls/' . $payrollId);
    }

    private function validate(array $post): array
    {
        $errors = [];
        $title = trim($post['title'] ?? '');
        $start = trim($post['pay_period_start'] ?? '');
        $end = trim($post['pay_period_end'] ?? '');

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($start === '') {
            $errors['pay_period_start'] = 'Period start is required.';
        }
        if ($end === '') {
            $errors['pay_period_end'] = 'Period end is required.';
        }
        if ($start && $end && (new DateTime($end)) < (new DateTime($start))) {
            $errors['pay_period_end'] = 'Period end cannot be before period start.';
        }

        $frequency = in_array($post['payroll_frequency'] ?? '', ['Weekly', 'Biweekly', 'Monthly'], true)
            ? $post['payroll_frequency'] : 'Monthly';

        $data = [
            'title' => $title,
            'payroll_frequency' => $frequency,
            'pay_period_start' => $start ?: null,
            'pay_period_end' => $end ?: null,
            'pay_date' => trim($post['pay_date'] ?? '') ?: null,
            'notes' => trim($post['notes'] ?? '') ?: null,
            'bank_account_id' => !empty($post['bank_account_id']) ? (int) $post['bank_account_id'] : null,
        ];

        return [$data, $errors];
    }
}
