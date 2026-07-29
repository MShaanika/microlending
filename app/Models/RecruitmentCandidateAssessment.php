<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentCandidateAssessment extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN users u ON u.id = a.conducted_by";
    private const LOOKUP_COLUMNS = "u.name AS conducted_by_name";

    public function forCandidate(int $candidateId): array
    {
        return $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_assessments a " . self::LOOKUP_JOINS . "
             WHERE a.candidate_id = ? ORDER BY a.assessment_date DESC",
            [$candidateId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_assessments a " . self::LOOKUP_JOINS . " WHERE a.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_candidate_assessments', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_candidate_assessments', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_candidate_assessments WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
