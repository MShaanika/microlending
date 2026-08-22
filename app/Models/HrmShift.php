<?php

namespace App\Models;

use App\Core\Model;

class HrmShift extends Model
{
    public function allShifts(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM hrm_shifts";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY shift_name";

        return $this->query($sql)->fetchAll();
    }

    private const SORTABLE = ['name' => 'shift_name', 'start_time' => 'start_time', 'end_time' => 'end_time', 'status' => 'is_active'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE shift_name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM hrm_shifts" . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['name'];
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM hrm_shifts{$where} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_shifts WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_shifts WHERE shift_name = ? AND id != ?",
                [$name, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_shifts WHERE shift_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_shifts', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_shifts', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE shift_id = ?", [$id]);
    }
}
