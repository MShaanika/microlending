<?php

namespace App\Models;

use App\Core\Model;

class StaffLoanRepayment extends Model
{
    public function forLoan(int $staffLoanId): array
    {
        return $this->query(
            "SELECT r.*, p.title AS payroll_title FROM hrm_staff_loan_repayments r
             LEFT JOIN hrm_payrolls p ON p.id = r.payroll_id
             WHERE r.staff_loan_id = ? ORDER BY r.repayment_date DESC",
            [$staffLoanId]
        )->fetchAll();
    }

    public function existsForLoanPayroll(int $staffLoanId, int $payrollId): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_staff_loan_repayments WHERE staff_loan_id = ? AND payroll_id = ?",
            [$staffLoanId, $payrollId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_staff_loan_repayments', $data);
    }
}
