<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\HrmEmployee;
use App\Models\HrmPayroll;
use App\Models\HrmPayrollEntry;
use App\Models\StaffLoan;
use App\Models\StaffLoanRepayment;
use DateTime;

/**
 * Turns one payroll run's pay period into a per-employee payslip
 * (hrm_payroll_entries row), ported from the reference workdo/Hrm
 * module's algorithm with one remaining deliberate scope cut:
 * "manual overtime" (a separate ad-hoc override entity) is left out --
 * overtime here is only what Phase 2's Attendance already computed per
 * clock-in/out record.
 *
 * Staff loan repayments (0% interest, principal split into equal
 * installments) are deducted automatically here: each Active loan with
 * a remaining balance and start_date on/before this run's pay period
 * end has MIN(installment_amount, outstanding_balance) subtracted from
 * net pay and logged as a hrm_staff_loan_repayments row, self-correcting
 * any rounding remainder on the final installment and flipping the loan
 * to Completed once its balance reaches zero.
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

        // HrmPayrollController::run() blocks calling this again once status
        // is Completed, but that's a UI-layer guard, not a data-layer one --
        // postPayrollAccounting() has its own idempotency check (mirrors
        // DebitOrderCollection::alreadyPosted()'s pattern) so a second call
        // to this method for the same payroll (direct service call, or a
        // race between two concurrent requests) can never double-post.
        // Guarded on gross > 0, not just count > 0 -- an employee with no
        // basic_salary set (e.g. a commission-only agent processed through
        // payroll by mistake) produces an all-zero payslip; posting that
        // would write a journal header with no lines (AccountingJournal::
        // post() skips zero-value lines), which is meaningless GL noise.
        if ($totals['gross'] > 0) {
            self::postPayrollAccounting($payroll, $totals, $userId);
        }

        return ['new_entries' => $newEntriesCount, 'total_entries' => $totals['count']];
    }

    /**
     * Dr Salary Expense (gross) / Cr Bank (net paid out) / Cr Payroll
     * Deductions Payable (external deductions) / Cr Staff Loan
     * Receivable (repayments deducted this run) -- balances because
     * gross = net + deductions + staff_loans (see processEmployee()).
     * "Completed" is treated as "already paid" (confirmed with the
     * user), same timing model as loan disbursement -- no Net Pay
     * Payable clearing account or separate payment step.
     */
    private static function postPayrollAccounting(array $payroll, array $totals, ?int $userId): void
    {
        $alreadyPosted = Database::connection()->prepare(
            "SELECT 1 FROM accounting_journal_entries WHERE source_table = 'hrm_payrolls' AND source_id = ? LIMIT 1"
        );
        $alreadyPosted->execute([(int) $payroll['id']]);
        if ($alreadyPosted->fetchColumn()) {
            return;
        }

        $accounts = new AccountingAccount();

        $bankGlAccountId = $accounts->idByCode('1010');
        $bankLabel = 'Bank Account';
        if (!empty($payroll['bank_account_id'])) {
            $bankAccount = (new BankAccount())->find((int) $payroll['bank_account_id']);
            if ($bankAccount) {
                $bankGlAccountId = (int) $bankAccount['account_id'];
                $bankLabel = $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'];
            }
        }

        $lines = [
            [
                'account_id' => $accounts->idByCode('5040'),
                'debit' => $totals['gross'],
                'credit' => 0,
                'description' => 'Salary expense for ' . $payroll['title'],
            ],
            [
                'account_id' => $bankGlAccountId,
                'debit' => 0,
                'credit' => $totals['net'],
                'description' => 'Net pay disbursed from ' . $bankLabel . ' for ' . $payroll['title'],
            ],
        ];
        if ($totals['deductions'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('2060'),
                'debit' => 0,
                'credit' => $totals['deductions'],
                'description' => 'Payroll deductions withheld for ' . $payroll['title'],
            ];
        }
        if ($totals['staff_loans'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('1080'),
                'debit' => 0,
                'credit' => $totals['staff_loans'],
                'description' => 'Staff loan repayments deducted for ' . $payroll['title'],
            ];
        }

        (new AccountingJournal())->post(
            'PAYROLL_RUN',
            'hrm_payrolls',
            (int) $payroll['id'],
            generate_reference('PAY'),
            'Payroll processed: ' . $payroll['title'],
            $lines,
            $userId,
            $payroll['pay_date'] ?? null
        );
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
        $staffLoanData = self::calculateStaffLoans($employeeId, $periodEnd);

        $halfDayDeduction = round($perDaySalary * ($attendanceData['half_days'] * 0.5), 2);
        $absentDayDeduction = round($perDaySalary * $attendanceData['absent_days'], 2);
        $unpaidLeaveDeduction = round($perDaySalary * $leaveData['unpaid_leave_days'], 2);
        $totalLeaveSalaryDeductions = round($halfDayDeduction + $absentDayDeduction + $unpaidLeaveDeduction, 2);

        $totalEarnings = $basicSalary + $allowanceData['total'];
        $grossPay = round($totalEarnings - $totalLeaveSalaryDeductions + $attendanceData['overtime_amount'], 2);
        $netPay = round($grossPay - $deductionData['total'] - $staffLoanData['total'], 2);

        $entryId = (new HrmPayrollEntry())->create([
            'payroll_id' => $payrollId,
            'employee_id' => $employeeId,
            'basic_salary' => $basicSalary,
            'total_allowances' => $allowanceData['total'],
            'total_deductions' => $deductionData['total'],
            'total_staff_loans' => $staffLoanData['total'],
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
            'staff_loans_breakdown' => json_encode($staffLoanData['breakdown']),
            'created_by' => $userId,
        ]);

        self::recordStaffLoanRepayments($payrollId, $entryId, $periodEnd, $staffLoanData['repayments']);
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

    private static function calculateStaffLoans(int $employeeId, string $periodEnd): array
    {
        $loans = (new StaffLoan())->activeForEmployeeAsOf($employeeId, $periodEnd);
        $breakdown = [];
        $repayments = [];
        $total = 0.0;
        foreach ($loans as $loan) {
            $amount = min((float) $loan['installment_amount'], (float) $loan['outstanding_balance']);
            if ($amount <= 0) {
                continue;
            }
            $breakdown[$loan['title']] = $amount;
            $repayments[] = ['loan' => $loan, 'amount' => $amount];
            $total += $amount;
        }
        return ['breakdown' => $breakdown, 'total' => round($total, 2), 'repayments' => $repayments];
    }

    private static function recordStaffLoanRepayments(int $payrollId, int $entryId, string $repaymentDate, array $repayments): void
    {
        $loans = new StaffLoan();
        $ledger = new StaffLoanRepayment();

        foreach ($repayments as $item) {
            $loan = $item['loan'];
            $loanId = (int) $loan['id'];
            if ($ledger->existsForLoanPayroll($loanId, $payrollId)) {
                continue;
            }

            $ledger->create([
                'staff_loan_id' => $loanId,
                'payroll_id' => $payrollId,
                'payroll_entry_id' => $entryId,
                'amount' => $item['amount'],
                'repayment_date' => $repaymentDate,
            ]);

            $newBalance = round((float) $loan['outstanding_balance'] - $item['amount'], 2);
            $loans->updateRecord($loanId, [
                'outstanding_balance' => max(0, $newBalance),
                'status' => $newBalance <= 0 ? 'Completed' : 'Active',
            ]);
        }
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
