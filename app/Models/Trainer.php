<?php

namespace App\Models;

use App\Core\Model;

class Trainer extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN branches b ON b.id = t.branch_id
        LEFT JOIN hrm_departments d ON d.id = t.department_id
    ";
    private const LOOKUP_COLUMNS = "b.branch_name AS branch_name, d.department_name AS department_name";
    private const SORTABLE = ['name' => 't.name', 'department' => 'd.department_name', 'status' => 't.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE t.name LIKE ? OR t.email LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM trainers t " . self::LOOKUP_JOINS . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? 't.name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainers t " . self::LOOKUP_JOINS . "{$where}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function allTrainers(): array
    {
        return $this->query("SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainers t " . self::LOOKUP_JOINS . " ORDER BY t.name")->fetchAll();
    }

    public function activeTrainers(): array
    {
        return $this->query("SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainers t " . self::LOOKUP_JOINS . " WHERE t.status = 'Active' ORDER BY t.name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM trainers t " . self::LOOKUP_JOINS . " WHERE t.id = ?", [$id]);
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM trainers WHERE email = ? AND id != ?", [$email, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM trainers WHERE email = ?", [$email]);
    }

    public function create(array $data): int
    {
        return $this->insert('trainers', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('trainers', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM trainers WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM trainings WHERE trainer_id = ?", [$id]);
    }
}
