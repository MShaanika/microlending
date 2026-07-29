<?php

namespace App\Models;

use App\Core\Model;

class TrainingTask extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = tk.assigned_to
    ";
    private const LOOKUP_COLUMNS = "CONCAT(e.first_name, ' ', e.last_name) AS assigned_to_name, e.employee_no";

    public function forTraining(int $trainingId): array
    {
        return $this->query(
            "SELECT tk.*, " . self::LOOKUP_COLUMNS . " FROM training_tasks tk " . self::LOOKUP_JOINS . "
             WHERE tk.training_id = ? ORDER BY tk.due_date IS NULL, tk.due_date, tk.id",
            [$trainingId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT tk.*, " . self::LOOKUP_COLUMNS . " FROM training_tasks tk " . self::LOOKUP_JOINS . " WHERE tk.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('training_tasks', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('training_tasks', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM training_tasks WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
