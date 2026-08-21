<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentInterviewRound extends Model
{
    private const SORTABLE = ['name' => 'r.name', 'job' => 'j.title', 'sequence' => 'r.sequence_number', 'status' => 'r.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'job', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE r.name LIKE ? OR j.title LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM recruitment_interview_rounds r LEFT JOIN recruitment_job_postings j ON j.id = r.job_id" . $where,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 'j.title';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT r.*, j.title AS job_title FROM recruitment_interview_rounds r
             LEFT JOIN recruitment_job_postings j ON j.id = r.job_id{$where}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function forJob(int $jobId): array
    {
        return $this->query(
            "SELECT * FROM recruitment_interview_rounds WHERE job_id = ? ORDER BY sequence_number",
            [$jobId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM recruitment_interview_rounds WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('recruitment_interview_rounds', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('recruitment_interview_rounds', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM recruitment_interview_rounds WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
