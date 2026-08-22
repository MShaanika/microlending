<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAttendance;
use App\Models\HrmEmployee;
use App\Models\HrmHoliday;
use App\Models\HrmLeaveApplication;
use App\Models\HrmShift;
use App\Services\AttendanceGridService;

class HrmAttendanceController extends Controller
{
    private HrmAttendance $attendances;
    private HrmEmployee $employees;
    private HrmShift $shifts;
    private HrmLeaveApplication $leaveApplications;
    private HrmHoliday $holidays;

    public function __construct()
    {
        $this->attendances = new HrmAttendance();
        $this->employees = new HrmEmployee();
        $this->shifts = new HrmShift();
        $this->leaveApplications = new HrmLeaveApplication();
        $this->holidays = new HrmHoliday();
    }

    /**
     * Monthly grid (employee rows x day-of-month columns) with a status
     * icon per cell -- Present/Half Day/Absent/On Leave/Holiday/Day Off/
     * Pending, plus Late/Early/Overtime flags. Search, per-page, sort, and
     * export are handled by the page's standard DataTables auto-init (no
     * server-side pagination needed) -- only Month/Year change what data
     * gets fetched, so those are the only real filters here.
     */
    public function report(): void
    {
        Auth::authorize('hrm.view');

        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        $employees = $this->employees->allEmployees(['status' => 'Active']);
        $attendanceMap = $this->attendances->forRange($monthStart, $monthEnd);
        $leaveMap = $this->leaveApplications->approvedInRange($monthStart, $monthEnd);
        $holidays = $this->holidays->inRange($monthStart, $monthEnd);

        $grid = AttendanceGridService::build($employees, $year, $month, $attendanceMap, $leaveMap, $holidays);

        $this->view('hrm/attendance/report', [
            'title' => 'Attendance Report',
            'grid' => $grid,
            'daysInMonth' => $daysInMonth,
            'year' => $year,
            'month' => $month,
        ]);
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'status' => $_GET['status'] ?? '',
        ];
        $sort = (string) ($_GET['sort'] ?? 'attendance_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->attendances->paginated($filters, $sort, $dir, $page, $perPage);

        $this->view('hrm/attendance/index', [
            'title' => 'Attendance',
            'records' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'employees' => $this->employees->allEmployees(),
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/attendance/create', [
            'title' => 'Add Attendance',
            'employees' => $this->employees->allEmployees(),
            'shifts' => $this->shifts->allShifts(true),
            'today' => date('Y-m-d'),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/attendance/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/attendance/create', [
                'title' => 'Add Attendance',
                'employees' => $this->employees->allEmployees(),
                'shifts' => $this->shifts->allShifts(true),
                'today' => date('Y-m-d'),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->attendances->create($data);

        Audit::log('Create', 'HRM', 'Recorded attendance #' . $id . ' for employee #' . $data['employee_id'] . ' on ' . $data['attendance_date']);
        Session::flash('success', 'Attendance recorded.');
        $this->redirect('/hrm/attendance');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $record = $this->attendances->find($id);
        if (!$record) {
            Session::flash('error', 'Attendance record not found.');
            $this->redirect('/hrm/attendance');
            return;
        }
        $this->view('hrm/attendance/edit', [
            'title' => 'Edit Attendance',
            'record' => $record,
            'employees' => $this->employees->allEmployees(),
            'shifts' => $this->shifts->allShifts(true),
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/attendance/' . $id . '/edit');
            return;
        }

        $record = $this->attendances->find($id);
        if (!$record) {
            Session::flash('error', 'Attendance record not found.');
            $this->redirect('/hrm/attendance');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/attendance/edit', [
                'title' => 'Edit Attendance',
                'record' => array_merge($record, $_POST),
                'employees' => $this->employees->allEmployees(),
                'shifts' => $this->shifts->allShifts(true),
                'errors' => $errors,
            ]);
            return;
        }

        $this->attendances->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated attendance #' . $id);
        Session::flash('success', 'Attendance updated.');
        $this->redirect('/hrm/attendance');
    }

    /**
     * Self-service clock in/out for the logged-in user's own linked
     * employee record -- separate from the admin-entered flow above
     * (hrm.manage), since any staff member with a linked employee should
     * be able to clock themselves in without HR-management rights.
     */
    public function clockIn(): void
    {
        Auth::requireLogin();

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/dashboard');
            return;
        }

        $employee = $this->employees->findByUserId((int) (Auth::user()['id'] ?? 0));
        if (!$employee) {
            Session::flash('error', 'Your account is not linked to an employee record.');
            $this->redirect('/dashboard');
            return;
        }

        $today = date('Y-m-d');
        if ($this->attendances->findForEmployeeDate((int) $employee['id'], $today)) {
            Session::flash('error', 'You have already clocked in today.');
            $this->redirect('/dashboard');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $shift = !empty($employee['shift_id']) ? $this->shifts->find((int) $employee['shift_id']) : null;
        $calc = $this->attendances->calculate($now, null, $shift, $employee);

        $this->attendances->create(array_merge($calc, [
            'employee_id' => (int) $employee['id'],
            'shift_id' => $employee['shift_id'] ?? null,
            'attendance_date' => $today,
            'clock_in' => $now,
            'clock_out' => null,
            'created_by' => Auth::user()['id'] ?? null,
        ]));

        Audit::log('Create', 'HRM', 'Clocked in for ' . $today);
        Session::flash('success', 'Clocked in at ' . date('H:i') . '.');
        $this->redirect('/dashboard');
    }

    public function clockOut(): void
    {
        Auth::requireLogin();

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/dashboard');
            return;
        }

        $employee = $this->employees->findByUserId((int) (Auth::user()['id'] ?? 0));
        if (!$employee) {
            Session::flash('error', 'Your account is not linked to an employee record.');
            $this->redirect('/dashboard');
            return;
        }

        $today = date('Y-m-d');
        $record = $this->attendances->findForEmployeeDate((int) $employee['id'], $today);
        if (!$record) {
            Session::flash('error', 'You have not clocked in today.');
            $this->redirect('/dashboard');
            return;
        }
        if (!empty($record['clock_out'])) {
            Session::flash('error', 'You have already clocked out today.');
            $this->redirect('/dashboard');
            return;
        }

        $now = date('Y-m-d H:i:s');
        $shift = !empty($record['shift_id']) ? $this->shifts->find((int) $record['shift_id']) : null;
        $calc = $this->attendances->calculate($record['clock_in'], $now, $shift, $employee);

        $this->attendances->updateRecord((int) $record['id'], array_merge($calc, ['clock_out' => $now]));

        Audit::log('Update', 'HRM', 'Clocked out for ' . $today);
        Session::flash('success', 'Clocked out at ' . date('H:i') . '.');
        $this->redirect('/dashboard');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $date = trim($post['attendance_date'] ?? '');
        $clockIn = trim($post['clock_in'] ?? '');
        $clockOut = trim($post['clock_out'] ?? '') ?: null;
        $shiftId = !empty($post['shift_id']) ? (int) $post['shift_id'] : null;

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($date === '') {
            $errors['attendance_date'] = 'Date is required.';
        }
        if ($clockIn === '') {
            $errors['clock_in'] = 'Clock in time is required.';
        }
        if ($employeeId && $date !== '' && $this->attendances->existsForEmployeeDate($employeeId, $date, $excludeId)) {
            $errors['attendance_date'] = 'This employee already has an attendance record for that date.';
        }

        $data = [
            'employee_id' => $employeeId,
            'shift_id' => $shiftId,
            'attendance_date' => $date ?: null,
            'clock_in' => $date && $clockIn ? $date . ' ' . $clockIn : null,
            'clock_out' => $date && $clockOut ? $date . ' ' . $clockOut : null,
            'notes' => trim($post['notes'] ?? '') ?: null,
        ];

        if (empty($errors)) {
            $shift = $shiftId ? (new HrmShift())->find($shiftId) : null;
            $employee = (new HrmEmployee())->find($employeeId);
            $calc = $this->attendances->calculate($data['clock_in'], $data['clock_out'], $shift, $employee);
            $data = array_merge($data, $calc);
        }

        return [$data, $errors];
    }
}
