<?php

namespace App\Models;

use App\Core\Model;

class HrmDepartment extends Model
{
    public function allDepartments(bool $activeOnly = false): array
    {
        $sql = "SELECT d.*, b.branch_name
                FROM hrm_departments d
                LEFT JOIN branches b ON b.id = d.branch_id";
        if ($activeOnly) {
            $sql .= " WHERE d.is_active = 1";
        }
        $sql .= " ORDER BY d.department_name";

        return $this->query($sql)->fetchAll();
    }

    private const SORTABLE = ['name' => 'd.department_name', 'branch' => 'b.branch_name', 'status' => 'd.is_active'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE d.department_name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_departments d" . $where,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['name'];
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT d.*, b.branch_name FROM hrm_departments d LEFT JOIN branches b ON b.id = d.branch_id"
            . $where . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, b.branch_name
             FROM hrm_departments d
             LEFT JOIN branches b ON b.id = d.branch_id
             WHERE d.id = ?",
            [$id]
        );
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_departments WHERE department_name = ? AND id != ?",
                [$name, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_departments WHERE department_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_departments', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_departments', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        $employees = (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE department_id = ?", [$id]);
        $designations = (int) $this->scalar("SELECT COUNT(*) FROM hrm_designations WHERE department_id = ?", [$id]);
        return $employees + $designations;
    }
}
