<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentInterview extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN recruitment_candidates c ON c.id = i.candidate_id
        LEFT JOIN recruitment_job_postings j ON j.id = i.job_id
        LEFT JOIN recruitment_interview_rounds r ON r.id = i.round_id
        LEFT JOIN recruitment_interview_types t ON t.id = i.interview_type_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name, j.title AS job_title,
        r.name AS round_name, t.name AS interview_type_name
    ";

    public function allInterviews(): array
    {
        return $this->query(
            "SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_interviews i " . self::LOOKUP_JOINS . "
             ORDER BY i.scheduled_date DESC"
        )->fetchAll();
    }

    public function forCandidate(int $candidateId): array
    {
        return $this->query(
            "SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_interviews i " . self::LOOKUP_JOINS . "
             WHERE i.candidate_id = ? ORDER BY i.scheduled_date DESC",
            [$candidateId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_interviews i " . self::LOOKUP_JOINS . " WHERE i.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_interviews', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_interviews', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_interviews WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
