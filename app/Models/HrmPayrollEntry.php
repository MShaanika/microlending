<?php

namespace App\Models;

use App\Core\Model;

class HrmPayrollEntry extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = pe.employee_id
        LEFT JOIN hrm_departments d ON d.id = e.department_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        e.bank_name, e.account_number, d.department_name
    ";

    public function forPayroll(int $payrollId): array
    {
        return $this->query(
            "SELECT pe.*, " . self::LOOKUP_COLUMNS . " FROM hrm_payroll_entries pe " . self::LOOKUP_JOINS . "
             WHERE pe.payroll_id = ? ORDER BY e.first_name, e.last_name",
            [$payrollId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT pe.*, " . self::LOOKUP_COLUMNS . " FROM hrm_payroll_entries pe " . self::LOOKUP_JOINS . " WHERE pe.id = ?",
            [$id]
        );
    }

    public function existsForPayrollEmployee(int $payrollId, int $employeeId): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_payroll_entries WHERE payroll_id = ? AND employee_id = ?",
            [$payrollId, $employeeId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_payroll_entries', $data);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->update('hrm_payroll_entries', ['status' => $status], 'id', $id);
    }

    public function totalsForPayroll(int $payrollId): array
    {
        $row = $this->one(
            "SELECT COALESCE(SUM(gross_pay),0) AS gross, COALESCE(SUM(total_deductions),0) AS deductions,
                    COALESCE(SUM(net_pay),0) AS net, COUNT(*) AS count
             FROM hrm_payroll_entries WHERE payroll_id = ?",
            [$payrollId]
        );
        return [
            'gross' => round((float) $row['gross'], 2),
            'deductions' => round((float) $row['deductions'], 2),
            'net' => round((float) $row['net'], 2),
            'count' => (int) $row['count'],
        ];
    }
}
