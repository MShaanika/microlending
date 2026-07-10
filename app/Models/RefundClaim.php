<?php

namespace App\Models;

use App\Core\Model;

class RefundClaim extends Model
{
    public function forBorrower(int $borrowerId): array
    {
        return $this->all(
            "SELECT r.*, l.loan_no FROM refund_claims r LEFT JOIN loans l ON l.id = r.loan_id
             WHERE r.borrower_id = ? ORDER BY r.id DESC",
            [$borrowerId]
        );
    }

    public function paginated(string $status = '', int $limit = 100): array
    {
        $sql = "SELECT r.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, l.loan_no
                FROM refund_claims r
                JOIN borrowers b ON b.id = r.borrower_id
                LEFT JOIN loans l ON l.id = r.loan_id
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
            "SELECT r.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.id AS borrower_id, l.loan_no
             FROM refund_claims r
             JOIN borrowers b ON b.id = r.borrower_id
             LEFT JOIN loans l ON l.id = r.loan_id
             WHERE r.id = ?",
            [$id]
        );
    }

    public function findForBorrower(int $id, int $borrowerId): ?array
    {
        return $this->one("SELECT * FROM refund_claims WHERE id = ? AND borrower_id = ?", [$id, $borrowerId]);
    }

    public function create(array $data): int
    {
        return $this->insert('refund_claims', $data);
    }

    public function attachDocument(array $data): int
    {
        return $this->insert('refund_claim_documents', $data);
    }

    public function documentsFor(int $claimId): array
    {
        return $this->all("SELECT * FROM refund_claim_documents WHERE refund_claim_id = ? ORDER BY id", [$claimId]);
    }

    public function updateStatus(int $id, string $status, ?int $staffId, ?string $rejectionReason = null, ?float $approvedAmount = null): bool
    {
        $data = ['status' => $status, 'reviewed_by' => $staffId, 'reviewed_at' => date('Y-m-d H:i:s')];

        if ($status === 'Approved') {
            $data['approved_by'] = $staffId;
            $data['approved_at'] = date('Y-m-d H:i:s');
            if ($approvedAmount !== null) {
                $data['approved_amount'] = $approvedAmount;
            }
        }
        if ($status === 'Paid') {
            $data['paid_by'] = $staffId;
            $data['paid_at'] = date('Y-m-d H:i:s');
        }
        if ($status === 'Rejected') {
            $data['rejection_reason'] = $rejectionReason;
        }

        return $this->update('refund_claims', $data, 'id', $id);
    }
}
