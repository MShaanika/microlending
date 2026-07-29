<?php

namespace App\Models;

use App\Core\Model;

class TrainingFeedback extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = f.employee_id
        LEFT JOIN training_tasks tk ON tk.id = f.training_task_id
    ";
    private const LOOKUP_COLUMNS = "CONCAT(e.first_name, ' ', e.last_name) AS employee_name, tk.title AS task_title";

    public function forTask(int $taskId): array
    {
        return $this->query(
            "SELECT f.*, " . self::LOOKUP_COLUMNS . " FROM training_feedback f " . self::LOOKUP_JOINS . "
             WHERE f.training_task_id = ? ORDER BY f.created_at DESC",
            [$taskId]
        )->fetchAll();
    }

    public function forTraining(int $trainingId): array
    {
        return $this->query(
            "SELECT f.*, " . self::LOOKUP_COLUMNS . " FROM training_feedback f " . self::LOOKUP_JOINS . "
             WHERE tk.training_id = ? ORDER BY f.created_at DESC",
            [$trainingId]
        )->fetchAll();
    }

    public function create(array $data): int
    {
        return $this->insert('training_feedback', $data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM training_feedback WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
