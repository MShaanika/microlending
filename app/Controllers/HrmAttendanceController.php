<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAttendance;
use App\Models\HrmEmployee;
use App\Models\HrmShift;

class HrmAttendanceController extends Controller
{
    private HrmAttendance $attendances;
    private HrmEmployee $employees;
    private HrmShift $shifts;

    public function __construct()
    {
        $this->attendances = new HrmAttendance();
        $this->employees = new HrmEmployee();
        $this->shifts = new HrmShift();
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

        $this->view('hrm/attendance/index', [
            'title' => 'Attendance',
            'records' => $this->attendances->allAttendances($filters),
            'employees' => $this->employees->allEmployees(),
            'filters' => $filters,
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
