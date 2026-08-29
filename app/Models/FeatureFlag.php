<?php

namespace App\Models;

use App\Core\Model;

class FeatureFlag extends Model
{
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM feature_flags WHERE id = ?', [$id]);
    }

    public function findByKey(string $flagKey): ?array
    {
        return $this->one('SELECT * FROM feature_flags WHERE flag_key = ?', [$flagKey]);
    }

    public function allFlags(): array
    {
        return $this->all('SELECT f.*, cu.name AS created_by_name, uu.name AS updated_by_name
            FROM feature_flags f
            LEFT JOIN users cu ON cu.id = f.created_by
            LEFT JOIN users uu ON uu.id = f.updated_by
            ORDER BY f.name');
    }

    public function create(array $data): int
    {
        return $this->insert('feature_flags', $data);
    }

    public function updateFlag(int $id, array $data): bool
    {
        return $this->update('feature_flags', $data, 'id', $id);
    }
}
