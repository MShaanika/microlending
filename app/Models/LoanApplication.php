<?php

namespace App\Models;

use App\Core\Model;

class LoanApplication extends Model
{
    protected string $table = 'loan_applications';

    public function paginated(string $status = '', string $source = '', int $limit = 100): array
    {
        $sql = "SELECT a.*, s.source_name, b.borrower_no
                FROM loan_applications a
                LEFT JOIN intake_sources s ON s.id = a.intake_source_id
                LEFT JOIN borrowers b ON b.id = a.borrower_id
                WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }

        if ($source !== '') {
            $sql .= " AND s.source_code = ?";
            $params[] = $source;
        }

        $sql .= " ORDER BY a.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, s.source_name, s.source_code, b.borrower_no
             FROM loan_applications a
             LEFT JOIN intake_sources s ON s.id = a.intake_source_id
             LEFT JOIN borrowers b ON b.id = a.borrower_id
             WHERE a.id = ?",
            [$id]
        );
    }

    public function findByApplicationNo(string $applicationNo): ?array
    {
        return $this->one("SELECT * FROM loan_applications WHERE application_no = ?", [$applicationNo]);
    }

    public function create(array $data): int
    {
        return $this->insert('loan_applications', $data);
    }

    public function updateStatus(int $id, string $status, array $extra = []): bool
    {
        return $this->update('loan_applications', array_merge(['status' => $status], $extra), 'id', $id);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('loan_applications', $data, 'id', $id);
    }

    public function pendingCount(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM loan_applications WHERE status = 'Submitted'");
    }

    public function addStatusHistory(int $applicationId, ?string $oldStatus, string $newStatus, ?int $changedBy, ?string $notes = null): int
    {
        return $this->insert('loan_application_status_history', [
            'application_id' => $applicationId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'changed_by' => $changedBy,
        ]);
    }

    public function statusHistory(int $applicationId): array
    {
        return $this->all("SELECT * FROM loan_application_status_history WHERE application_id = ? ORDER BY id ASC", [$applicationId]);
    }

    public function addDocument(array $data): int
    {
        return $this->insert('loan_application_documents', $data);
    }

    public function documents(int $applicationId): array
    {
        return $this->all("SELECT * FROM loan_application_documents WHERE application_id = ? ORDER BY id ASC", [$applicationId]);
    }

    public function findDocument(int $applicationId, int $documentId): ?array
    {
        return $this->one("SELECT * FROM loan_application_documents WHERE id = ? AND application_id = ?", [$documentId, $applicationId]);
    }

    public function addRejection(array $data): int
    {
        return $this->insert('rejected_applications', $data);
    }
}
