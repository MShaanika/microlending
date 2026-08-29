<?php

namespace App\Models;

use App\Core\Model;

class ContinuityPlan extends Model
{
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT p.*, ru.name AS last_reviewed_by_name FROM continuity_plans p
             LEFT JOIN users ru ON ru.id = p.last_reviewed_by WHERE p.id = ?',
            [$id]
        );
    }

    public function allPlans(): array
    {
        return $this->all('SELECT p.*, ru.name AS last_reviewed_by_name FROM continuity_plans p LEFT JOIN users ru ON ru.id = p.last_reviewed_by ORDER BY p.is_active DESC, p.plan_name');
    }

    public function create(array $data): int
    {
        return $this->insert('continuity_plans', $data);
    }

    public function updatePlan(int $id, array $data): bool
    {
        return $this->update('continuity_plans', $data, 'id', $id);
    }

    public function markReviewed(int $id, int $userId): bool
    {
        return $this->update('continuity_plans', [
            'last_reviewed_at' => date('Y-m-d H:i:s'),
            'last_reviewed_by' => $userId,
        ], 'id', $id);
    }
}
