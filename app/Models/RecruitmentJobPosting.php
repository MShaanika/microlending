<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentJobPosting extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN recruitment_job_types t ON t.id = j.job_type_id
        LEFT JOIN recruitment_job_locations l ON l.id = j.location_id
    ";
    private const LOOKUP_COLUMNS = "t.name AS job_type_name, l.name AS location_name";

    public function allPostings(array $filters = []): array
    {
        $sql = "SELECT j.*, " . self::LOOKUP_COLUMNS . ",
                (SELECT COUNT(*) FROM recruitment_candidates c WHERE c.job_id = j.id) AS candidate_count
                FROM recruitment_job_postings j " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'j.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY j.created_at DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function publishedActive(): array
    {
        return $this->query(
            "SELECT j.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_job_postings j " . self::LOOKUP_JOINS . "
             WHERE j.status = 'Active' AND j.is_published = 1
             AND (j.application_deadline IS NULL OR j.application_deadline >= CURDATE())
             ORDER BY j.is_featured DESC, j.created_at DESC"
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT j.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_job_postings j " . self::LOOKUP_JOINS . " WHERE j.id = ?",
            [$id]
        );
    }

    public function findByCode(string $code): ?array
    {
        return $this->one(
            "SELECT j.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_job_postings j " . self::LOOKUP_JOINS . " WHERE j.posting_code = ?",
            [$code]
        );
    }

    public function findPublicByCode(string $code): ?array
    {
        return $this->one(
            "SELECT j.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_job_postings j " . self::LOOKUP_JOINS . "
             WHERE j.posting_code = ? AND j.status = 'Active' AND j.is_published = 1",
            [$code]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_job_postings', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_job_postings', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_job_postings WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function codeExists(string $code): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM recruitment_job_postings WHERE posting_code = ?", [$code]);
    }
}
