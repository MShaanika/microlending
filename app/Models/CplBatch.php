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

    /** legacy_summary of a batch made by the automatic submission carries this origin (see CplAutoSubmissionService::ORIGIN). */
    private const AUTO_MARK = '%"origin":"Automatic%';

    /**
     * The dashboard's "Recent Batches" list: manual batches and the monthly files, but NOT the automatic daily files (one every day would flood it; they
     * have their own list, see autoFiles()).
     */
    public function recentExcludingAutoDaily(int $limit = 12, bool $isUatTest = false): array
    {
        return $this->all(
            "SELECT b.*, gu.name AS generated_by_name FROM cpl_batches b
             LEFT JOIN users gu ON gu.id = b.generated_by
             WHERE b.is_uat_test = ? AND NOT (b.batch_type = 'Daily' AND COALESCE(b.legacy_summary, '') LIKE ?)
             ORDER BY b.month_end DESC, b.id DESC LIMIT " . max(1, $limit),
            [$isUatTest ? 1 : 0, self::AUTO_MARK]
        );
    }

    /** One page of the automatic files (daily and monthly), newest first. */
    public function autoFiles(int $limit = 30, int $offset = 0): array
    {
        return $this->all(
            "SELECT b.* FROM cpl_batches b WHERE b.is_uat_test = 0 AND b.legacy_summary LIKE ?
             ORDER BY b.month_end DESC, b.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [self::AUTO_MARK]
        );
    }

    public function countAutoFiles(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM cpl_batches WHERE is_uat_test = 0 AND legacy_summary LIKE ?", [self::AUTO_MARK]);
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
