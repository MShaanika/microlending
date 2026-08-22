<?php

namespace App\Models;

use App\Core\Model;

class HrmDesignation extends Model
{
    public function allDesignations(bool $activeOnly = false): array
    {
        $sql = "SELECT g.*, b.branch_name, d.department_name
                FROM hrm_designations g
                LEFT JOIN branches b ON b.id = g.branch_id
                LEFT JOIN hrm_departments d ON d.id = g.department_id";
        if ($activeOnly) {
            $sql .= " WHERE g.is_active = 1";
        }
        $sql .= " ORDER BY g.designation_name";

        return $this->query($sql)->fetchAll();
    }

    private const SORTABLE = ['name' => 'g.designation_name', 'department' => 'd.department_name', 'branch' => 'b.branch_name', 'status' => 'g.is_active'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE g.designation_name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_designations g" . $where,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['name'];
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT g.*, b.branch_name, d.department_name
             FROM hrm_designations g
             LEFT JOIN branches b ON b.id = g.branch_id
             LEFT JOIN hrm_departments d ON d.id = g.department_id"
            . $where . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT g.*, b.branch_name, d.department_name
             FROM hrm_designations g
             LEFT JOIN branches b ON b.id = g.branch_id
             LEFT JOIN hrm_departments d ON d.id = g.department_id
             WHERE g.id = ?",
            [$id]
        );
    }

    public function byDepartment(int $departmentId): array
    {
        return $this->query(
            "SELECT * FROM hrm_designations WHERE department_id = ? AND is_active = 1 ORDER BY designation_name",
            [$departmentId]
        )->fetchAll();
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_designations WHERE designation_name = ? AND id != ?",
                [$name, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_designations WHERE designation_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_designations', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_designations', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE designation_id = ?", [$id]);
    }
}
