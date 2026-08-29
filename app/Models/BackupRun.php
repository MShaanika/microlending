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

    /**
     * completed_at/retention_expires_at are set from MySQL's own
     * NOW(), not a PHP-computed date string -- keeps every timestamp
     * on MySQL's clock, immune to any PHP/MySQL timezone mismatch
     * (see HealthCheckResult::heartbeats()). $data should not include
     * completed_at.
     */
    public function markSuccessful(int $id, array $data, int $retentionDays): bool
    {
        $columns = array_keys($data);
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $sql = "UPDATE backup_runs SET $set, completed_at = NOW(), retention_expires_at = NOW() + INTERVAL :retention_days DAY WHERE id = :__id";
        $stmt = $this->query($sql, array_merge($data, ['retention_days' => $retentionDays, '__id' => $id]));
        return $stmt->rowCount() > 0;
    }

    /** Same MySQL-clock reasoning as markSuccessful() -- $data should not include completed_at. */
    public function markFailed(int $id, array $data): bool
    {
        $columns = array_keys($data);
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $sql = "UPDATE backup_runs SET $set, completed_at = NOW() WHERE id = :__id";
        $stmt = $this->query($sql, array_merge($data, ['__id' => $id]));
        return $stmt->rowCount() > 0;
    }

    public function latest(int $limit = 20): array
    {
        return $this->all('SELECT b.*, u.name AS triggered_by_name FROM backup_runs b LEFT JOIN users u ON u.id = b.triggered_by_user ORDER BY b.started_at DESC LIMIT ' . max(1, $limit));
    }

    public function lastSuccessful(): ?array
    {
        return $this->one("SELECT * FROM backup_runs WHERE status = 'SUCCESS' ORDER BY started_at DESC LIMIT 1");
    }

    /** MySQL-side age (TIMESTAMPDIFF against MySQL's own NOW()) -- avoids the PHP/MySQL timezone mismatch that mixing time()/strtotime() with a stored timestamp is exposed to. Null if no successful backup exists. */
    public function ageHoursOfLastSuccess(): ?int
    {
        $result = $this->scalar(
            "SELECT TIMESTAMPDIFF(HOUR, COALESCE(completed_at, started_at), NOW())
             FROM backup_runs WHERE status = 'SUCCESS' ORDER BY started_at DESC LIMIT 1"
        );
        return $result === false ? null : (int) $result;
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
