<?php

namespace App\Models;

use App\Core\Model;

class Loan extends Model
{
    public function paginated(string $search = '', string $status = '', int $limit = 100): array
    {
        $sql = "SELECT l.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, p.product_name
                FROM loans l
                JOIN borrowers b ON b.id = l.borrower_id
                JOIN loan_products p ON p.id = l.product_id
                WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (l.loan_no LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($status !== '') {
            $sql .= " AND l.loan_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY l.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT l.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.phone AS borrower_phone,
                    p.product_name, p.interest_method, pl.plan_name
             FROM loans l
             JOIN borrowers b ON b.id = l.borrower_id
             JOIN loan_products p ON p.id = l.product_id
             JOIN loan_plans pl ON pl.id = l.plan_id
             WHERE l.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('loans', $data);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('loans', $data, 'id', $id);
    }

    public function insertScheduleRows(int $loanId, array $rows): void
    {
        foreach ($rows as $row) {
            $this->insert('loan_schedules', [
                'loan_id' => $loanId,
                'installment_no' => $row['installment_no'],
                'due_date' => $row['due_date'],
                'opening_balance' => $row['opening_balance'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'fees_due' => $row['fees_due'],
                'namfisa_levy_due' => $row['namfisa_levy_due'] ?? 0,
                'duty_stamp_due' => $row['duty_stamp_due'] ?? 0,
                'penalty_due' => $row['penalty_due'],
                'total_due' => $row['total_due'],
                'closing_balance' => $row['closing_balance'],
                'status' => 'Pending',
            ]);
        }
    }

    public function schedule(int $loanId): array
    {
        return $this->all("SELECT * FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no", [$loanId]);
    }

    public function updateScheduleRow(int $scheduleId, array $data): bool
    {
        return $this->update('loan_schedules', $data, 'id', $scheduleId);
    }

    public function logStatus(int $loanId, ?string $old, string $new, ?int $userId, string $notes = ''): void
    {
        $this->insert('loan_status_history', [
            'loan_id' => $loanId,
            'old_status' => $old,
            'new_status' => $new,
            'notes' => $notes ?: null,
            'changed_by' => $userId,
        ]);
    }

    public function createDisbursement(array $data): int
    {
        return $this->insert('loan_disbursements', $data);
    }

    public function counts(): array
    {
        return [
            'total' => (int) $this->scalar("SELECT COUNT(*) FROM loans"),
            'active' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE loan_status IN ('Active','Current','Released')"),
            'pending' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE loan_status = 'Pending Approval'"),
            'completed' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE loan_status = 'Completed'"),
            'principal_outstanding' => (float) ($this->scalar(
                "SELECT COALESCE(SUM(total_due - total_paid),0) FROM loan_schedules ls
                 JOIN loans l ON l.id = ls.loan_id WHERE l.loan_status IN ('Active','Current','Released')"
            ) ?: 0),
        ];
    }

    public function arrearsCount(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(DISTINCT loan_id) FROM loan_schedules WHERE status = 'In Arrears'"
        );
    }

    /**
     * Borrower-scoped variants for the self-service portal — every query
     * filters by borrower_id so a borrower can never see another's loan.
     */
    public function forBorrower(int $borrowerId): array
    {
        return $this->all(
            "SELECT l.*, p.product_name FROM loans l
             JOIN loan_products p ON p.id = l.product_id
             WHERE l.borrower_id = ? ORDER BY l.id DESC",
            [$borrowerId]
        );
    }

    public function findForBorrower(int $loanId, int $borrowerId): ?array
    {
        return $this->one(
            "SELECT l.*, p.product_name, p.interest_method, pl.plan_name
             FROM loans l
             JOIN loan_products p ON p.id = l.product_id
             JOIN loan_plans pl ON pl.id = l.plan_id
             WHERE l.id = ? AND l.borrower_id = ?",
            [$loanId, $borrowerId]
        );
    }
}
