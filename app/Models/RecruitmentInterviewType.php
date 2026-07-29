<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentInterviewType extends Model
{
    public function allTypes(): array
    {
        return $this->query("SELECT * FROM recruitment_interview_types ORDER BY name")->fetchAll();
    }

    public function activeTypes(): array
    {
        return $this->query("SELECT * FROM recruitment_interview_types WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_interview_types WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_interview_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_interview_types', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_interview_types WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM recruitment_interviews WHERE interview_type_id = ?", [$id]);
    }
}
