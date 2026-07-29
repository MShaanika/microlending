<?php

namespace App\Models;

use App\Core\Model;

class HrmDocumentType extends Model
{
    public function allTypes(): array
    {
        return $this->query("SELECT * FROM hrm_document_types ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_document_types WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM hrm_document_types WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_document_types WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_document_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_document_types', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar(
            "SELECT
                (SELECT COUNT(*) FROM hrm_employee_documents WHERE document_type_id = ?) +
                (SELECT COUNT(*) FROM hrm_staff_loan_documents WHERE document_type_id = ?)",
            [$id, $id]
        );
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_document_types WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
