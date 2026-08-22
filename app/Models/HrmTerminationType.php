<?php

namespace App\Models;

use App\Core\Model;

class HrmTerminationType extends Model
{
    public function allTypes(): array
    {
        return $this->query("SELECT * FROM hrm_termination_types ORDER BY name")->fetchAll();
    }

    private const SORTABLE = ['name' => 'name'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE name LIKE ? OR description LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM hrm_termination_types" . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['name'];
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM hrm_termination_types{$where} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_termination_types WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM hrm_termination_types WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_termination_types WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_termination_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_termination_types', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_terminations WHERE termination_type_id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_termination_types WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
