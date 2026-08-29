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

    /** Whitelist of sortable columns -- $sort comes from the query string, never interpolate it directly. */
    private const SORTABLE = [
        'title' => 'j.title',
        'job_type' => 't.name',
        'location' => 'l.name',
        'candidates' => 'candidate_count',
        'published' => 'j.is_published',
        'status' => 'j.status',
        'created_at' => 'j.created_at',
    ];

    /** Flat, unpaginated list -- for dropdowns (Candidate/Interview forms etc.), never for a list-view table. */
    public function allPostings(): array
    {
        return $this->query(
            "SELECT j.*, " . self::LOOKUP_COLUMNS . " FROM recruitment_job_postings j " . self::LOOKUP_JOINS . " ORDER BY j.title"
        )->fetchAll();
    }

    /**
     * @return array{rows: array, total: int, totalPages: int}
     */
    public function paginated(array $filters = [], string $search = '', string $sort = 'created_at', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'j.status = ?';
            $params[] = $filters['status'];
        }
        if ($search !== '') {
            $where[] = '(j.title LIKE ? OR j.posting_code LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM recruitment_job_postings j" . $whereSql, $params);

        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['created_at'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT j.*, " . self::LOOKUP_COLUMNS . ",
                (SELECT COUNT(*) FROM recruitment_candidates c WHERE c.job_id = j.id) AS candidate_count
                FROM recruitment_job_postings j " . self::LOOKUP_JOINS
                . $whereSql . " ORDER BY $orderCol $orderDir LIMIT $perPage OFFSET $offset";

        return [
            'rows' => $this->query($sql, $params)->fetchAll(),
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
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
            "SELECT j.*, " . self::LOOKUP_COLUMNS . ", u.name AS created_by_name
             FROM recruitment_job_postings j " . self::LOOKUP_JOINS . "
             LEFT JOIN users u ON u.id = j.created_by
             WHERE j.id = ?",
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
