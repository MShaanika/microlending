<?php

namespace App\Models;

use App\Core\Model;

class LoanRequest extends Model
{
    public function forBorrower(int $borrowerId): array
    {
        return $this->all("SELECT * FROM loan_requests WHERE borrower_id = ? ORDER BY id DESC", [$borrowerId]);
    }

    public function paginated(string $status = '', int $limit = 100): array
    {
        $sql = "SELECT r.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.phone, b.borrower_no,
                       el.loan_no AS existing_loan_no,
                       (SELECT COUNT(*) FROM loan_request_documents d WHERE d.loan_request_id = r.id) AS document_count
                FROM loan_requests r
                JOIN borrowers b ON b.id = r.borrower_id
                LEFT JOIN loans el ON el.id = r.existing_loan_id
                WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY r.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.id AS borrower_id, b.phone, b.branch_id
             FROM loan_requests r JOIN borrowers b ON b.id = r.borrower_id WHERE r.id = ?",
            [$id]
        );
    }

    public function findForBorrower(int $id, int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM loan_requests WHERE id = ? AND borrower_id = ?", [$id, $borrowerId]);
    }

    public function create(array $data): int
    {
        return $this->insert('loan_requests', $data);
    }

    public function updateStatus(int $id, string $status, ?int $reviewerId, ?string $notes = null): bool
    {
        return $this->update('loan_requests', [
            'status' => $status,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_notes' => $notes,
        ], 'id', $id);
    }

    public function pendingCount(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM loan_requests WHERE status = 'Pending'");
    }

    public function attachDocument(array $data): int
    {
        return $this->insert('loan_request_documents', $data);
    }

    public function documentsFor(int $requestId): array
    {
        return $this->all("SELECT * FROM loan_request_documents WHERE loan_request_id = ? ORDER BY id", [$requestId]);
    }
}
