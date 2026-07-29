<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentInterviewFeedback extends Model
{
    public function forInterview(int $interviewId): array
    {
        return $this->query(
            "SELECT * FROM recruitment_interview_feedbacks WHERE interview_id = ? ORDER BY created_at DESC",
            [$interviewId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_interview_feedbacks WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_interview_feedbacks', $data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_interview_feedbacks WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
