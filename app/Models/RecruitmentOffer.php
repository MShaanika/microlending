<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentOffer extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN recruitment_candidates c ON c.id = o.candidate_id
        LEFT JOIN recruitment_job_postings j ON j.id = o.job_id
        LEFT JOIN hrm_departments d ON d.id = o.department_id
        LEFT JOIN users u ON u.id = o.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(c.first_name, ' ', c.last_name) AS candidate_name, c.email AS candidate_email, c.status AS candidate_status,
        j.title AS job_title, d.department_name AS department_name, u.name AS approved_by_name
    ";

    public function allOffers(): array
    {
        return $this->query(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_offers o " . self::LOOKUP_JOINS . "
             ORDER BY o.created_at DESC"
        )->fetchAll();
    }

    public function forCandidate(int $candidateId): array
    {
        return $this->query(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_offers o " . self::LOOKUP_JOINS . "
             WHERE o.candidate_id = ? ORDER BY o.created_at DESC",
            [$candidateId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_offers o " . self::LOOKUP_JOINS . " WHERE o.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_offers', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_offers', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_offers WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
