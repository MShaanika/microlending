<?php

namespace App\Models;

use App\Core\Model;

class HrmEmployeeDocument extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_document_types t ON t.id = d.document_type_id
        LEFT JOIN users u ON u.id = d.uploaded_by
    ";
    private const LOOKUP_COLUMNS = "t.name AS type_name, u.name AS uploaded_by_name";

    public function forEmployee(int $employeeId): array
    {
        return $this->query(
            "SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employee_documents d " . self::LOOKUP_JOINS . "
             WHERE d.employee_id = ? ORDER BY d.created_at DESC",
            [$employeeId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employee_documents d " . self::LOOKUP_JOINS . " WHERE d.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_employee_documents', $data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_employee_documents WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
