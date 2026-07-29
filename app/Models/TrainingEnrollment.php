<?php

namespace App\Models;

use App\Core\Model;

class TrainingEnrollment extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = en.employee_id
    ";
    private const LOOKUP_COLUMNS = "CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no";

    public function forTraining(int $trainingId): array
    {
        return $this->query(
            "SELECT en.*, " . self::LOOKUP_COLUMNS . " FROM training_enrollments en " . self::LOOKUP_JOINS . "
             WHERE en.training_id = ? ORDER BY e.first_name",
            [$trainingId]
        )->fetchAll();
    }

    public function forEmployee(int $employeeId): array
    {
        return $this->query(
            "SELECT en.*, t.title AS training_title, t.start_date, t.end_date, t.status AS training_status
             FROM training_enrollments en JOIN trainings t ON t.id = en.training_id
             WHERE en.employee_id = ? ORDER BY t.start_date DESC",
            [$employeeId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT en.*, " . self::LOOKUP_COLUMNS . " FROM training_enrollments en " . self::LOOKUP_JOINS . " WHERE en.id = ?",
            [$id]
        );
    }

    public function exists(int $trainingId, int $employeeId): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM training_enrollments WHERE training_id = ? AND employee_id = ?",
            [$trainingId, $employeeId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('training_enrollments', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('training_enrollments', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM training_enrollments WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
