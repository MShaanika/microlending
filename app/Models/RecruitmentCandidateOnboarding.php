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

    private const SORTABLE = [
        'candidate' => 'candidate_name',
        'checklist' => 'cl.name',
        'start_date' => 'o.start_date',
        'buddy' => 'buddy_name',
        'status' => 'o.status',
    ];

    public function allOnboardings(): array
    {
        return $this->query(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_onboardings o " . self::LOOKUP_JOINS . "
             ORDER BY o.start_date DESC"
        )->fetchAll();
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'start_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = " WHERE (c.first_name LIKE ? OR c.last_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM recruitment_candidate_onboardings o " . self::LOOKUP_JOINS . $where,
            $params
        );

        $orderCol = self::SORTABLE[$sort] ?? 'o.start_date';
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT o.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_onboardings o " . self::LOOKUP_JOINS
                . $where . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
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
