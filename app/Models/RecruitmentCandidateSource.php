<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentCandidateSource extends Model
{
    public function allSources(): array
    {
        return $this->query("SELECT * FROM recruitment_candidate_sources ORDER BY name")->fetchAll();
    }

    public function activeSources(): array
    {
        return $this->query("SELECT * FROM recruitment_candidate_sources WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_candidate_sources WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_candidate_sources', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_candidate_sources', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_candidate_sources WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM recruitment_candidates WHERE source_id = ?", [$id]);
    }
}
