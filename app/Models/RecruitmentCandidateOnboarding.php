<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentCandidateOnboarding extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN recruitment_candidates c ON c.id = o.candidate_id
        LEFT JOIN recruitment_onboarding_checklists cl ON cl.id = o.checklist_id
        LEFT JOIN hrm_employees e ON e.id = o.buddy_employee_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name, cl.name AS checklist_name,
        CONCAT(e.first_name, ' ', e.last_name) AS buddy_name
    ";

    public function allOnboardings(): array
    {
        return $this->query(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_onboardings o " . self::LOOKUP_JOINS . "
             ORDER BY o.start_date DESC"
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_onboardings o " . self::LOOKUP_JOINS . " WHERE o.id = ?",
            [$id]
        );
    }

    public function findByCandidate(int $candidateId): ?array
    {
        return $this->one(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_onboardings o " . self::LOOKUP_JOINS . " WHERE o.candidate_id = ?",
            [$candidateId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_candidate_onboardings', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_candidate_onboardings', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_candidate_onboardings WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
