<?php

namespace App\Models;

use App\Core\Model;

class HrmPayroll extends Model
{
    public function allPayrolls(): array
    {
        return $this->query(
            "SELECT p.*, b.bank_name, b.account_name
             FROM hrm_payrolls p
             LEFT JOIN accounting_bank_accounts b ON b.id = p.bank_account_id
             ORDER BY p.pay_period_start DESC"
        )->fetchAll();
    }

    private const SORTABLE = ['title' => 'p.title', 'pay_period_start' => 'p.pay_period_start', 'frequency' => 'p.payroll_frequency', 'employee_count' => 'p.employee_count', 'total_net_pay' => 'p.total_net_pay', 'status' => 'p.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'pay_period_start', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = 'p.title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_payrolls p" . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['pay_period_start'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT p.*, b.bank_name, b.account_name
             FROM hrm_payrolls p
             LEFT JOIN accounting_bank_accounts b ON b.id = p.bank_account_id"
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT p.*, b.bank_name, b.account_name
             FROM hrm_payrolls p
             LEFT JOIN accounting_bank_accounts b ON b.id = p.bank_account_id
             WHERE p.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_payrolls', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_payrolls', $data, 'id', $id);
    }
}
