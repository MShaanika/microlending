<?php

namespace App\Models;

use App\Core\Model;

/** One generated Public Defaults submission FILE -- see database/creditinfo_public_defaults_submission_file.sql's header comment. */
class CreditinfoPublicDefaultBatch extends Model
{
    public function create(array $data): int
    {
        return $this->insert('creditinfo_public_default_batches', $data);
    }

    private function baseSelect(): string
    {
        return "SELECT b.*, gu.name AS generated_by_name, ru.name AS reviewed_by_name,
                       du.name AS downloaded_by_name, mu.name AS marked_submitted_by_name
                FROM creditinfo_public_default_batches b
                LEFT JOIN users gu ON gu.id = b.generated_by
                LEFT JOIN users ru ON ru.id = b.reviewed_by
                LEFT JOIN users du ON du.id = b.downloaded_by
                LEFT JOIN users mu ON mu.id = b.marked_submitted_by";
    }

    public function find(int $id): ?array
    {
        return $this->one($this->baseSelect() . ' WHERE b.id = ?', [$id]);
    }

    /** Row lock for a status-guarded write inside a transaction -- see Model::transaction(). */
    public function findForUpdate(int $id): ?array
    {
        return $this->one('SELECT * FROM creditinfo_public_default_batches WHERE id = ? FOR UPDATE', [$id]);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('creditinfo_public_default_batches', $data, 'id', $id);
    }

    public function forDirection(string $direction, int $limit = 50): array
    {
        return $this->all($this->baseSelect() . ' WHERE b.direction = ? ORDER BY b.generated_at DESC LIMIT ' . max(1, $limit), [$direction]);
    }

    /**
     * True if this public default is already sitting in an OPEN batch
     * (one not yet Rejected) -- a record must never be selectable into two
     * simultaneous submission files, since Creditinfo would then receive
     * it twice. A Rejected batch releases its items back to the pool.
     */
    public function hasOpenBatchForPublicDefault(int $publicDefaultId): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM creditinfo_public_default_batch_items i
             JOIN creditinfo_public_default_batches b ON b.id = i.batch_id
             WHERE i.public_default_id = ? AND b.status != 'Rejected'",
            [$publicDefaultId]
        );
    }
}
