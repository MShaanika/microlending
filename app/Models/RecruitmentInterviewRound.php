<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentInterviewRound extends Model
{
    public function forJob(int $jobId): array
    {
        return $this->query(
            "SELECT * FROM recruitment_interview_rounds WHERE job_id = ? ORDER BY sequence_number",
            [$jobId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_interview_rounds WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_interview_rounds', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_interview_rounds', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_interview_rounds WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
