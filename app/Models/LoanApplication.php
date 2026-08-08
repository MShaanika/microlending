<?php

namespace App\Models;

use App\Core\Model;

class LoanApplication extends Model
{
    protected string $table = 'loan_applications';

    /**
     * @param ?int $branchId When set, includes that branch's applications PLUS
     *  unassigned ones (branch_id IS NULL) rather than an exact match -- an
     *  application nobody has attributed to a branch yet is still everyone's
     *  to triage, not stranded outside every branch's inbox.
     */
    public function paginated(string $status = '', string $source = '', int $limit = 100, ?int $branchId = null): array
    {
        $sql = "SELECT a.*, s.source_name, b.borrower_no, br.branch_name
                FROM loan_applications a
                LEFT JOIN intake_sources s ON s.id = a.intake_source_id
                LEFT JOIN borrowers b ON b.id = a.borrower_id
                LEFT JOIN branches br ON br.id = a.branch_id
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

        if ($branchId !== null) {
            $sql .= " AND (a.branch_id = ? OR a.branch_id IS NULL)";
            $params[] = $branchId;
        }

        $sql .= " ORDER BY a.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, s.source_name, s.source_code, b.borrower_no, br.branch_name
             FROM loan_applications a
             LEFT JOIN intake_sources s ON s.id = a.intake_source_id
             LEFT JOIN borrowers b ON b.id = a.borrower_id
             LEFT JOIN branches br ON br.id = a.branch_id
             WHERE a.id = ?",
            [$id]
        );
    }

    public function findByApplicationNo(string $applicationNo): ?array
    {
        return $this->one("SELECT * FROM loan_applications WHERE application_no = ?", [$applicationNo]);
    }

    public function allForAgent(int $agentId): array
    {
        return $this->all(
            "SELECT a.*, b.borrower_no FROM loan_applications a
             LEFT JOIN borrowers b ON b.id = a.borrower_id
             WHERE a.agent_id = ? ORDER BY a.id DESC",
            [$agentId]
        );
    }

    /**
     * Admin-facing view of every application a marketing agent has ever
     * submitted, collapsed into one lifecycle status that spans both this
     * table and the loan it may have become -- 'Pending'/'Approved'/
     * 'Rejected' come from the application before conversion; once a loan
     * exists, 'Disbursed'/'Paid'/'Defaulted' come from the loan instead
     * (the two are mutually exclusive per row, never both consulted).
     */
    public function agentSubmissions(array $filters = []): array
    {
        $sql = "SELECT a.id AS application_id, a.application_no, a.applicant_first_name, a.applicant_last_name,
                       a.requested_amount, a.created_at AS submitted_at, a.status AS application_status,
                       e.id AS agent_employee_id, CONCAT(e.first_name,' ',e.last_name) AS agent_name,
                       l.id AS loan_id, l.loan_no, l.loan_status,
                       CASE
                         WHEN l.id IS NULL THEN
                           CASE
                             WHEN a.status IN ('Submitted','Screening','Documents Required') THEN 'Pending'
                             WHEN a.status = 'Approved' THEN 'Approved'
                             WHEN a.status IN ('Rejected','Cancelled') THEN 'Rejected'
                             ELSE 'Pending'
                           END
                         ELSE
                           CASE
                             WHEN l.loan_status IN ('Draft','Pending Approval','Approved') THEN 'Approved'
                             WHEN l.loan_status IN ('Released','Active','Current') THEN 'Disbursed'
                             WHEN l.loan_status = 'Completed' THEN 'Paid'
                             WHEN l.loan_status = 'Written Off' THEN 'Defaulted'
                             WHEN l.loan_status IN ('Denied','Cancelled') THEN 'Rejected'
                             ELSE 'Approved'
                           END
                       END AS lifecycle_status
                FROM loan_applications a
                JOIN hrm_employees e ON e.id = a.agent_id
                LEFT JOIN loans l ON l.application_id = a.id
                WHERE a.agent_id IS NOT NULL";
        $params = [];

        if (!empty($filters['agent_employee_id'])) {
            $sql .= " AND a.agent_id = ?";
            $params[] = $filters['agent_employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND a.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND a.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['status'])) {
            $sql .= " HAVING lifecycle_status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY a.id DESC";

        return $this->all($sql, $params);
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
