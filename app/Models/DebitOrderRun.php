<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderRun extends Model
{
    public function paginated(string $status = ''): array
    {
        $sql = "SELECT r.*, br.branch_name FROM debit_order_runs r
                JOIN branches br ON br.id = r.branch_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY r.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, br.branch_name FROM debit_order_runs r JOIN branches br ON br.id = r.branch_id WHERE r.id = ?",
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
