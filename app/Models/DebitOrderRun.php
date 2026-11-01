<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderRun extends Model
{
    /** @param array $filter optional ListFilter keys: from/to (run date) and min/max (total amount) */
    public function paginated(string $status = '', ?int $branchId = null, string $search = '', array $filter = [], int $limit = 200): array
    {
        $sql = "SELECT r.*, br.branch_name FROM debit_order_runs r
                JOIN branches br ON br.id = r.branch_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        if ($branchId !== null) {
            $sql .= " AND r.branch_id = ?";
            $params[] = $branchId;
        }
        $sql .= \App\Support\ListFilter::searchSql(['r.run_no', 'br.branch_name'], $search, $params);
        $sql .= \App\Support\ListFilter::dateSql('r.run_date', $filter['from'] ?? null, $filter['to'] ?? null, $params);
        $sql .= \App\Support\ListFilter::amountSql('r.total_amount', $filter['min'] ?? null, $filter['max'] ?? null, $params);
        $sql .= " ORDER BY r.id DESC LIMIT " . (int) $limit;
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, br.branch_name, gu.name AS generated_by_name, pu.name AS posted_by_name
             FROM debit_order_runs r
             JOIN branches br ON br.id = r.branch_id
             LEFT JOIN users gu ON gu.id = r.generated_by
             LEFT JOIN users pu ON pu.id = r.posted_by
             WHERE r.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_runs', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_order_runs', $data, 'id', $id);
    }
}
