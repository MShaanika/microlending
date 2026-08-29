<?php

namespace App\Models;

use App\Core\Model;

class BackupRun extends Model
{
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM backup_runs WHERE id = ?', [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('backup_runs', $data);
    }

    public function updateRun(int $id, array $data): bool
    {
        return $this->update('backup_runs', $data, 'id', $id);
    }

    public function latest(int $limit = 20): array
    {
        return $this->all('SELECT b.*, u.name AS triggered_by_name FROM backup_runs b LEFT JOIN users u ON u.id = b.triggered_by_user ORDER BY b.started_at DESC LIMIT ' . max(1, $limit));
    }

    public function lastSuccessful(): ?array
    {
        return $this->one("SELECT * FROM backup_runs WHERE status = 'SUCCESS' ORDER BY started_at DESC LIMIT 1");
    }

    public function lastRun(): ?array
    {
        return $this->one('SELECT * FROM backup_runs ORDER BY started_at DESC LIMIT 1');
    }

    /** Rows past retention_expires_at -- BackupService deletes both the file and the row for each. */
    public function expired(): array
    {
        return $this->all("SELECT * FROM backup_runs WHERE status = 'SUCCESS' AND retention_expires_at IS NOT NULL AND retention_expires_at < NOW()");
    }

    public function delete(int $id): bool
    {
        return $this->query('DELETE FROM backup_runs WHERE id = ?', [$id])->rowCount() > 0;
    }
}
