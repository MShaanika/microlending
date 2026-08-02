<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAttendance;
use App\Models\HrmEmployee;
use App\Models\HrmLeaveApplication;
use App\Models\HrmLeaveType;
use App\Models\HrmPayroll;
use App\Models\HrmPayrollEntry;
use DateTime;

/**
 * "My HR" self-service screens -- every action here is gated only by
 * Auth::requireLogin() + a linked employee record, not the hrm.view/
 * hrm.manage permissions, since this is every employee looking at their
 * OWN data (mirrors the guard HrmAttendanceController::clockIn()/
 * clockOut() already uses). Never accepts an employee_id from the
 * request -- always resolved server-side from the logged-in user.
 */
class EmployeeSelfServiceController extends Controller
{
    private HrmEmployee $employees;
    private HrmLeaveApplication $leaveApplications;
    private HrmLeaveType $leaveTypes;
    private HrmPayrollEntry $payrollEntries;
    private HrmPayroll $payrolls;
    private HrmAttendance $attendances;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->leaveApplications = new HrmLeaveApplication();
        $this->leaveTypes = new HrmLeaveType();
        $this->payrollEntries = new HrmPayrollEntry();
        $this->payrolls = new HrmPayroll();
        $this->attendances = new HrmAttendance();
    }

    /** Resolves the employee record linked to the logged-in user, or redirects with a flash message. */
    private function resolveEmployee(): ?array
    {
        Auth::requireLogin();
        $employee = $this->employees->findByUserId((int) (Auth::user()['id'] ?? 0));
        if (!$employee) {
            Session::flash('error', 'Your account is not linked to an employee record.');
            $this->redirect('/dashboard');
            return null;
        }
        return $employee;
    }

    public function leaveIndex(): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        $this->view('my/leave/index', [
            'title' => 'My Leave',
            'employee' => $employee,
            'applications' => $this->leaveApplications->allApplications(['employee_id' => $employee['id']]),
        ]);
    }

    public function leaveCreate(): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        $this->view('my/leave/create', [
            'title' => 'Apply for Leave',
            'employee' => $employee,
            'leaveTypes' => $this->leaveTypes->allLeaveTypes(true),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function leaveStore(): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/my/leave/create');
            return;
        }

        [$data, $errors] = $this->validateLeave($_POST, (int) $employee['id']);

        if (!empty($errors)) {
            $this->view('my/leave/create', [
                'title' => 'Apply for Leave',
                'employee' => $employee,
                'leaveTypes' => $this->leaveTypes->allLeaveTypes(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['employee_id'] = (int) $employee['id'];
        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->leaveApplications->create($data);

        Audit::log('Create', 'HRM', 'Leave application #' . $id . ' submitted via self-service by employee #' . $employee['id']);
        Session::flash('success', 'Leave application submitted.');
        $this->redirect('/my/leave');
    }

    public function leaveBalance(): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        $year = (int) ($_GET['year'] ?? date('Y'));
        $rows = [];
        foreach ($this->leaveTypes->allLeaveTypes(true) as $leaveType) {
            $used = $this->leaveApplications->usedDaysForYear((int) $employee['id'], (int) $leaveType['id'], $year);
            $rows[] = [
                'leave_type_name' => $leaveType['name'],
                'leave_type_color' => $leaveType['color'],
                'total_days' => (int) $leaveType['max_days_per_year'],
                'used_days' => $used,
                'available_days' => max(0, (int) $leaveType['max_days_per_year'] - $used),
            ];
        }

        $this->view('my/leave/balance', [
            'title' => 'My Leave Balance',
            'employee' => $employee,
            'rows' => $rows,
            'year' => $year,
        ]);
    }

    public function payslips(): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        $this->view('my/payslips/index', [
            'title' => 'My Payslips',
            'employee' => $employee,
            'entries' => $this->payrollEntries->forEmployee((int) $employee['id']),
        ]);
    }

    public function payslip(int $entryId): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        $entry = $this->payrollEntries->find($entryId);
        if (!$entry || (int) $entry['employee_id'] !== (int) $employee['id']) {
            Session::flash('error', 'Payslip not found.');
            $this->redirect('/my/payslips');
            return;
        }
        $payroll = $this->payrolls->find((int) $entry['payroll_id']);

        $this->view('my/payslips/show', [
            'title' => 'Payslip',
            'employee' => $employee,
            'payroll' => $payroll,
            'entry' => $entry,
            'allowances' => json_decode($entry['allowances_breakdown'] ?? '[]', true) ?: [],
            'deductions' => json_decode($entry['deductions_breakdown'] ?? '[]', true) ?: [],
            'staffLoans' => json_decode($entry['staff_loans_breakdown'] ?? '[]', true) ?: [],
        ]);
    }

    public function attendance(): void
    {
        $employee = $this->resolveEmployee();
        if (!$employee) {
            return;
        }

        $dateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
        $dateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-t')));

        $this->view('my/attendance/index', [
            'title' => 'My Attendance',
            'employee' => $employee,
            'records' => $this->attendances->allAttendances([
                'employee_id' => $employee['id'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    private function validateLeave(array $post, int $employeeId): array
    {
        $errors = [];
        $leaveTypeId = !empty($post['leave_type_id']) ? (int) $post['leave_type_id'] : null;
        $startDate = trim($post['start_date'] ?? '');
        $endDate = trim($post['end_date'] ?? '');
        $reason = trim($post['reason'] ?? '');

        if (!$leaveTypeId) {
            $errors['leave_type_id'] = 'Select a leave type.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($endDate === '') {
            $errors['end_date'] = 'End date is required.';
        }
        if ($reason === '') {
            $errors['reason'] = 'A reason is required.';
        }

        $totalDays = 0;
        if ($startDate !== '' && $endDate !== '') {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            if ($end < $start) {
                $errors['end_date'] = 'End date cannot be before the start date.';
            } else {
                $totalDays = (int) $start->diff($end)->days + 1;
                if ($this->leaveApplications->overlapsExisting($employeeId, $startDate, $endDate)) {
                    $errors['start_date'] = 'You already have a pending or approved leave application overlapping these dates.';
                }
            }
        }

        $data = [
            'leave_type_id' => $leaveTypeId,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'total_days' => $totalDays,
            'reason' => $reason,
        ];

        return [$data, $errors];
    }
}
