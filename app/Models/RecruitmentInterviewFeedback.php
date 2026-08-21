<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentInterviewFeedback extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN recruitment_interviews i ON i.id = f.interview_id
        LEFT JOIN recruitment_candidates c ON c.id = i.candidate_id
        LEFT JOIN users u ON u.id = f.created_by";
    private const LOOKUP_COLUMNS = "CONCAT(c.first_name, ' ', c.last_name) AS candidate_name, u.name AS submitted_by_name";
    private const SORTABLE = [
        'candidate' => 'c.first_name',
        'overall_rating' => 'f.overall_rating',
        'recommendation' => 'f.recommendation',
        'created_at' => 'f.created_at',
    ];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'created_at', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = " WHERE CONCAT(c.first_name, ' ', c.last_name) LIKE ?";
            $params[] = '%' . $search . '%';
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM recruitment_interview_feedbacks f " . self::LOOKUP_JOINS . $where,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 'f.created_at';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT f.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_interview_feedbacks f " . self::LOOKUP_JOINS . "{$where}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

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
