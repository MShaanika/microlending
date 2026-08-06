<?php

namespace App\Models;

use App\Core\Model;

class Branch extends Model
{
    public function all(string $sql = "SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name", array $params = []): array
    {
        return parent::all($sql, $params);
    }

    public function allBranches(): array
    {
        return parent::all("SELECT * FROM branches ORDER BY branch_name");
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM branches WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM branches WHERE branch_name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM branches WHERE branch_name = ?", [$name]);
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM branches WHERE branch_code = ? AND id != ?", [$code, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM branches WHERE branch_code = ?", [$code]);
    }

    public function create(array $data): int
    {
        return $this->insert('branches', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('branches', $data, 'id', $id);
    }
}
