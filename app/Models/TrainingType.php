<?php

namespace App\Models;

use App\Core\Model;

class TrainingType extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN branches b ON b.id = t.branch_id
        LEFT JOIN hrm_departments d ON d.id = t.department_id
    ";
    private const LOOKUP_COLUMNS = "b.branch_name AS branch_name, d.department_name AS department_name";

    public function allTypes(): array
    {
        return $this->query("SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM training_types t " . self::LOOKUP_JOINS . " ORDER BY t.name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM training_types t " . self::LOOKUP_JOINS . " WHERE t.id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('training_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('training_types', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM training_types WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM trainings WHERE training_type_id = ?", [$id]);
    }
}
