<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentJobLocation extends Model
{
    public function allLocations(): array
    {
        return $this->query("SELECT * FROM recruitment_job_locations ORDER BY name")->fetchAll();
    }

    public function activeLocations(): array
    {
        return $this->query("SELECT * FROM recruitment_job_locations WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_job_locations WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_job_locations', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_job_locations', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_job_locations WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM recruitment_job_postings WHERE location_id = ?", [$id]);
    }
}
