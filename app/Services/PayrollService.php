<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Company;
use App\Models\HrmEmployee;
use App\Models\HrmPayroll;
use App\Models\HrmPayrollEntry;
use DateTime;

/**
 * Turns one payroll run's pay period into a per-employee payslip
 * (hrm_payroll_entries row), ported from the reference workdo/Hrm
 * module's algorithm with two deliberate scope cuts:
 *  - "Manual overtime" (a separate ad-hoc override entity) is left for
 *    a later phase -- overtime here is only what Phase 2's Attendance
 *    already computed per clock-in/out record.
 *  - Staff-loan deductions are left for a later phase (total_loans is
 *    not tracked yet) -- net_pay = gross_pay - total_deductions only.
 *
 * Re-running an already-processed payroll only creates entries for
 * employees that don't have one yet (existing entries are left as-is),
 * matching the reference module's own idempotent re-run behaviour.
 */
class PayrollService
{
    public static function run(int $payrollId, ?int $userId): array
    {
        $payrolls = new HrmPayroll();
        $entries = new HrmPayrollEntry();
        $employees = new HrmEmployee();

        $payroll = $payrolls->find($payrollId);
        if (!$payroll) {
            throw new \RuntimeException('Payroll not found.');
        }

        $company = (new Company())->primary();
        $workingDayIndices = array_filter(array_map('intval', explode(',', $company['working_days'] ?? '1,2,3,4,5')));

        $start = new DateTime($payroll['pay_period_start']);
        $end = new DateTime($payroll['pay_period_end']);
        $workingDaysCount = self::countWorkingDays($start, $end, $workingDayIndices);

        $payrolls->updateRecord($payrollId, ['status' => 'Processing']);

        $newEntriesCount = 0;
        foreach ($employees->allEmployees(['status' => 'Active']) as $employee) {
            if ($entries->existsForPayrollEmployee($payrollId, (int) $employee['id'])) {
                continue;
            }
            self::processEmployee($payrollId, $employee, $workingDaysCount, $payroll['pay_period_start'], $payroll['pay_period_end'], $userId);
            $newEntriesCount++;
        }

        $totals = $entries->totalsForPayroll($payrollId);
        $payrolls->updateRecord($payrollId, [
            'status' => 'Completed',
            'total_gross_pay' => $totals['gross'],
            'total_deductions' => $totals['deductions'],
            'total_net_pay' => $totals['net'],
            'employee_count' => $totals['count'],
        ]);

        return ['new_entries' => $newEntriesCount, 'total_entries' => $totals['count']];
    }

    private static function countWorkingDays(DateTime $start, DateTime $end, array $workingDayIndices): int
    {
        $count = 0;
        $cursor = clone $start;
        while ($cursor <= $end) {
            if (in_array((int) $cursor->format('w'), $workingDayIndices, true)) {
                $count++;
            }
            $cursor->modify('+1 day');
        }
        return $count;
    }

    private static function processEmployee(int $payrollId, array $employee, int $workingDaysCount, string $periodStart, string $periodEnd, ?int $userId): void
    {
        $employeeId = (int) $employee['id'];
        $basicSalary = (float) ($employee['basic_salary'] ?? 0);
        $perDaySalary = $workingDaysCount > 0 ? round($basicSalary / $workingDaysCount, 2) : 0.0;

        $allowanceData = self::calculateAllowances($employeeId, $basicSalary);
        $deductionData = self::calculateDeductions($employeeId, $basicSalary);
        $attendanceData = self::calculateAttendance($employeeId, $periodStart, $periodEnd, (float) ($employee['rate_per_hour'] ?? 0));
        $leaveData = self::calculateLeave($employeeId, $periodStart, $periodEnd);

        $halfDayDeduction = round($perDaySalary * ($attendanceData['half_days'] * 0.5), 2);
        $absentDayDeduction = round($perDaySalary * $attendanceData['absent_days'], 2);
        $unpaidLeaveDeduction = round($perDaySalary * $leaveData['unpaid_leave_days'], 2);
        $totalLeaveSalaryDeductions = round($halfDayDeduction + $absentDayDeduction + $unpaidLeaveDeduction, 2);

        $totalEarnings = $basicSalary + $allowanceData['total'];
        $grossPay = round($totalEarnings - $totalLeaveSalaryDeductions + $attendanceData['overtime_amount'], 2);
        $netPay = round($grossPay - $deductionData['total'], 2);

        (new HrmPayrollEntry())->create([
            'payroll_id' => $payrollId,
            'employee_id' => $employeeId,
            'basic_salary' => $basicSalary,
            'total_allowances' => $allowanceData['total'],
            'total_deductions' => $deductionData['total'],
            'gross_pay' => $grossPay,
            'net_pay' => $netPay,
            'per_day_salary' => $perDaySalary,
            'working_days' => $workingDaysCount,
            'present_days' => $attendanceData['present_days'],
            'half_days' => $attendanceData['half_days'],
            'half_day_deduction' => $halfDayDeduction,
            'absent_days' => $attendanceData['absent_days'],
            'absent_day_deduction' => $absentDayDeduction,
            'paid_leave_days' => $leaveData['paid_leave_days'],
            'unpaid_leave_days' => $leaveData['unpaid_leave_days'],
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'overtime_hours' => $attendanceData['overtime_hours'],
            'overtime_rate' => $employee['rate_per_hour'] ?? 0,
            'overtime_amount' => $attendanceData['overtime_amount'],
            'status' => 'Unpaid',
            'allowances_breakdown' => json_encode($allowanceData['breakdown']),
            'deductions_breakdown' => json_encode($deductionData['breakdown']),
            'created_by' => $userId,
        ]);
    }

    private static function calculateAllowances(int $employeeId, float $basicSalary): array
    {
        $rows = (new \App\Models\HrmAllowance())->allForEmployee($employeeId);
        $breakdown = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $amount = $row['type'] === 'Percentage' ? round($basicSalary * (float) $row['amount'] / 100, 2) : (float) $row['amount'];
            $breakdown[$row['type_name']] = $amount;
            $total += $amount;
        }
        return ['breakdown' => $breakdown, 'total' => round($total, 2)];
    }

    private static function calculateDeductions(int $employeeId, float $basicSalary): array
    {
        $rows = (new \App\Models\HrmDeduction())->allForEmployee($employeeId);
        $breakdown = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $amount = $row['type'] === 'Percentage' ? round($basicSalary * (float) $row['amount'] / 100, 2) : (float) $row['amount'];
            $breakdown[$row['type_name']] = $amount;
            $total += $amount;
        }
        return ['breakdown' => $breakdown, 'total' => round($total, 2)];
    }

    private static function calculateAttendance(int $employeeId, string $start, string $end, float $ratePerHour): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT status, overtime_hours FROM hrm_attendances WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
        );
        $stmt->execute([$employeeId, $start, $end]);
        $rows = $stmt->fetchAll();

        $presentDays = 0;
        $halfDays = 0;
        $absentDays = 0;
        $overtimeHours = 0.0;
        foreach ($rows as $row) {
            if ($row['status'] === 'Present') {
                $presentDays++;
            } elseif ($row['status'] === 'Half Day') {
                $halfDays++;
            } else {
                $absentDays++;
            }
            $overtimeHours += (float) $row['overtime_hours'];
        }

        return [
            'present_days' => $presentDays,
            'half_days' => $halfDays,
            'absent_days' => $absentDays,
            'overtime_hours' => round($overtimeHours, 2),
            'overtime_amount' => round($overtimeHours * $ratePerHour, 2),
        ];
    }

    private static function calculateLeave(int $employeeId, string $start, string $end): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT la.start_date, la.end_date, lt.is_paid
             FROM hrm_leave_applications la
             JOIN hrm_leave_types lt ON lt.id = la.leave_type_id
             WHERE la.employee_id = ? AND la.status = 'Approved'
               AND la.start_date <= ? AND la.end_date >= ?"
        );
        $stmt->execute([$employeeId, $end, $start]);
        $rows = $stmt->fetchAll();

        $paidLeaveDays = 0;
        $unpaidLeaveDays = 0;
        foreach ($rows as $row) {
            $days = (new DateTime($row['start_date']))->diff(new DateTime($row['end_date']))->days + 1;
            if ((int) $row['is_paid'] === 1) {
                $paidLeaveDays += $days;
            } else {
                $unpaidLeaveDays += $days;
            }
        }

        return ['paid_leave_days' => $paidLeaveDays, 'unpaid_leave_days' => $unpaidLeaveDays];
    }
}
