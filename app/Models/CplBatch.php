<?php

namespace App\Models;

use App\Core\Model;

class CplBatch extends Model
{
    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT b.*, gu.name AS generated_by_name, au.name AS approved_by_name, ru.name AS rejected_by_name
             FROM cpl_batches b
             LEFT JOIN users gu ON gu.id = b.generated_by
             LEFT JOIN users au ON au.id = b.approved_by
             LEFT JOIN users ru ON ru.id = b.rejected_by
             WHERE b.id = ?",
            [$id]
        );
    }

    public function findByTypeAndMonth(string $batchType, string $monthEnd, bool $isUatTest = false): ?array
    {
        return $this->one(
            "SELECT * FROM cpl_batches WHERE batch_type = ? AND month_end = ? AND is_uat_test = ?",
            [$batchType, $monthEnd, $isUatTest ? 1 : 0]
        );
    }

    public function recent(int $limit = 12, bool $isUatTest = false): array
    {
        return $this->all(
            "SELECT b.*, gu.name AS generated_by_name FROM cpl_batches b
             LEFT JOIN users gu ON gu.id = b.generated_by
             WHERE b.is_uat_test = ?
             ORDER BY b.month_end DESC LIMIT " . max(1, $limit),
            [$isUatTest ? 1 : 0]
        );
    }

    /** Most recent batch of this type regardless of month -- used by the sign-off dashboard, which cares about "latest attempt", not a specific month. */
    public function latestOfType(string $batchType, bool $isUatTest): ?array
    {
        return $this->one(
            "SELECT * FROM cpl_batches WHERE batch_type = ? AND is_uat_test = ? ORDER BY id DESC LIMIT 1",
            [$batchType, $isUatTest ? 1 : 0]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('cpl_batches', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('cpl_batches', $data, 'id', $id);
    }
}
