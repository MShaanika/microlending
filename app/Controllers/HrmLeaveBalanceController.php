<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\HrmEmployee;
use App\Models\HrmLeaveApplication;
use App\Models\HrmLeaveType;

class HrmLeaveBalanceController extends Controller
{
    private HrmEmployee $employees;
    private HrmLeaveType $leaveTypes;
    private HrmLeaveApplication $applications;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->leaveTypes = new HrmLeaveType();
        $this->applications = new HrmLeaveApplication();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $year = (int) ($_GET['year'] ?? date('Y'));
        $employeeId = !empty($_GET['employee_id']) ? (int) $_GET['employee_id'] : null;

        $employees = $employeeId
            ? array_filter([$this->employees->find($employeeId)])
            : $this->employees->allEmployees(['status' => 'Active']);

        $leaveTypes = $this->leaveTypes->allLeaveTypes(true);

        $balances = [];
        foreach ($employees as $employee) {
            $rows = [];
            foreach ($leaveTypes as $leaveType) {
                $used = $this->applications->usedDaysForYear((int) $employee['id'], (int) $leaveType['id'], $year);
                $rows[] = [
                    'leave_type_name' => $leaveType['name'],
                    'leave_type_color' => $leaveType['color'],
                    'total_days' => (int) $leaveType['max_days_per_year'],
                    'used_days' => $used,
                    'available_days' => max(0, (int) $leaveType['max_days_per_year'] - $used),
                ];
            }
            $balances[] = [
                'employee_id' => $employee['id'],
                'employee_name' => $employee['first_name'] . ' ' . $employee['last_name'],
                'employee_no' => $employee['employee_no'],
                'leave_types' => $rows,
            ];
        }

        $this->view('hrm/leave-balance/index', [
            'title' => 'Leave Balance',
            'balances' => $balances,
            'employees' => $this->employees->allEmployees(),
            'year' => $year,
            'selectedEmployeeId' => $employeeId,
        ]);
    }
}
