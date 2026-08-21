<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentCandidateAssessment extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN users u ON u.id = a.conducted_by
        LEFT JOIN recruitment_candidates c ON c.id = a.candidate_id";
    private const LOOKUP_COLUMNS = "u.name AS conducted_by_name, CONCAT(c.first_name, ' ', c.last_name) AS candidate_name";
    private const SORTABLE = [
        'candidate' => 'c.first_name',
        'assessment_name' => 'a.assessment_name',
        'status' => 'a.pass_fail_status',
        'assessment_date' => 'a.assessment_date',
    ];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'assessment_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = " WHERE a.assessment_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?";
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM recruitment_candidate_assessments a " . self::LOOKUP_JOINS . $where,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 'a.assessment_date';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_candidate_assessments a " . self::LOOKUP_JOINS . "{$where}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

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
