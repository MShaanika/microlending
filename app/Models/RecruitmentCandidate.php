<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentCandidate extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN recruitment_job_postings j ON j.id = c.job_id
        LEFT JOIN recruitment_candidate_sources s ON s.id = c.source_id
    ";
    private const LOOKUP_COLUMNS = "j.title AS job_title, j.posting_code, s.name AS source_name";

    public function allCandidates(array $filters = []): array
    {
        $sql = "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['job_id'])) {
            $where[] = 'c.job_id = ?';
            $params[] = $filters['job_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.application_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS . " WHERE c.id = ?",
            [$id]
        );
    }

    public function findByTrackingId(string $trackingId): ?array
    {
        return $this->one(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS . " WHERE c.tracking_id = ?",
            [$trackingId]
        );
    }

    public function forJob(int $jobId): array
    {
        return $this->query(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS . "
             WHERE c.job_id = ? ORDER BY c.application_date DESC",
            [$jobId]
        )->fetchAll();
    }

    /** Candidates currently at status 'Interview' -- eligible for scheduling an interview. */
    public function interviewStage(): array
    {
        return $this->query(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS . "
             WHERE c.status = 'Interview' ORDER BY c.first_name"
        )->fetchAll();
    }

    /**
     * Candidates eligible for an offer: a Pass assessment, or a Hire/Strong
     * Hire interview recommendation, and no already-converted offer.
     */
    public function offerEligible(): array
    {
        return $this->query(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS . "
             WHERE (
                EXISTS (SELECT 1 FROM recruitment_candidate_assessments a WHERE a.candidate_id = c.id AND a.pass_fail_status = 'Pass')
                OR EXISTS (
                    SELECT 1 FROM recruitment_interview_feedbacks f
                    JOIN recruitment_interviews i ON i.id = f.interview_id
                    WHERE i.candidate_id = c.id AND f.recommendation IN ('Strong Hire', 'Hire')
                )
             )
             AND NOT EXISTS (SELECT 1 FROM recruitment_offers o WHERE o.candidate_id = c.id AND o.converted_to_employee = 1)
             ORDER BY c.first_name"
        )->fetchAll();
    }

    /** Candidates at status 'Hired' with no onboarding record yet. */
    public function hiredWithoutOnboarding(): array
    {
        return $this->query(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidates c " . self::LOOKUP_JOINS . "
             WHERE c.status = 'Hired' AND NOT EXISTS (SELECT 1 FROM recruitment_candidate_onboardings o WHERE o.candidate_id = c.id)
             ORDER BY c.first_name"
        )->fetchAll();
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_candidates', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_candidates', $data, 'id', $id);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->update('recruitment_candidates', ['status' => $status], 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_candidates WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function trackingIdExists(string $trackingId): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM recruitment_candidates WHERE tracking_id = ?", [$trackingId]);
    }
}
