<?php

namespace App\Models;

use App\Core\Model;

class StaffLoanDocument extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_document_types t ON t.id = d.document_type_id
        LEFT JOIN users u ON u.id = d.uploaded_by
    ";
    private const LOOKUP_COLUMNS = "t.name AS type_name, u.name AS uploaded_by_name";

    public function forLoan(int $staffLoanId): array
    {
        return $this->query(
            "SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_staff_loan_documents d " . self::LOOKUP_JOINS . "
             WHERE d.staff_loan_id = ? ORDER BY d.created_at DESC",
            [$staffLoanId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_staff_loan_documents d " . self::LOOKUP_JOINS . " WHERE d.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_staff_loan_documents', $data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_staff_loan_documents WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
