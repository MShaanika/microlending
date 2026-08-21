<?php

namespace App\Models;

use App\Core\Model;

class Training extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN training_types tt ON tt.id = t.training_type_id
        LEFT JOIN trainers tr ON tr.id = t.trainer_id
        LEFT JOIN branches b ON b.id = t.branch_id
        LEFT JOIN hrm_departments d ON d.id = t.department_id
    ";
    private const LOOKUP_COLUMNS = "
        tt.name AS training_type_name, tr.name AS trainer_name,
        b.branch_name AS branch_name, d.department_name AS department_name
    ";

    private const SORTABLE = [
        'title' => 't.title',
        'training_type' => 'tt.name',
        'trainer' => 'tr.name',
        'start_date' => 't.start_date',
        'department' => 'd.department_name',
        'status' => 't.status',
    ];

    public function allTrainings(array $filters = []): array
    {
        $sql = "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainings t " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['training_type_id'])) {
            $where[] = 't.training_type_id = ?';
            $params[] = $filters['training_type_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 't.department_id = ?';
            $params[] = $filters['department_id'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.start_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $search = '', string $sort = 'start_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = 't.title LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['training_type_id'])) {
            $where[] = 't.training_type_id = ?';
            $params[] = $filters['training_type_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 't.department_id = ?';
            $params[] = $filters['department_id'];
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM trainings t " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 't.start_date';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainings t " . self::LOOKUP_JOINS . "{$whereSql}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainings t " . self::LOOKUP_JOINS . " WHERE t.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('trainings', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('trainings', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM trainings WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
