<?php

namespace App\Models;

use App\Core\Model;

class Loan extends Model
{
    /**
     * "Arrear", "Bad Debt", and "Bad Debt Recovery" are pseudo-statuses for
     * the $status filter -- they are NOT loans.loan_status values (which
     * stays the canonical lifecycle: Draft -> ... -> Active/Current ->
     * Completed, or -> Written Off/Cancelled/Denied). A loan can be
     * "Active" AND in arrears at the same time, so encoding arrears into
     * that single-value column would be a category error -- it's already
     * computed live by ArrearsService (see that class's docblock for why:
     * nothing else in the app maintains a stored arrears flag, deliberately).
     * Similarly "Bad Debt"/"Bad Debt Recovery" already have their own,
     * richer status machine on the bad_debts table
     * (Open/Under Recovery/Provisioned/Written Off/Recovered/Closed) --
     * this filters by it rather than duplicating it onto loans.loan_status.
     *
     * is_in_arrears / bad_debt_status are always included in every row
     * (not just when filtering by one of these) so the list can show an
     * "In Arrears" / bad-debt badge alongside the loan's normal status,
     * regardless of which filter is active.
     */
    public function paginated(string $search = '', string $status = '', int $limit = 100, ?int $branchId = null): array
    {
        $sql = "SELECT l.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, p.product_name,
                       EXISTS (
                           SELECT 1 FROM loan_schedules ls
                           WHERE ls.loan_id = l.id AND ls.due_date <= CURDATE() AND ls.total_due > ls.total_paid
                       ) AS is_in_arrears,
                       (SELECT bd.status FROM bad_debts bd WHERE bd.loan_id = l.id ORDER BY bd.id DESC LIMIT 1) AS bad_debt_status
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

        if ($status === 'Arrear') {
            // Same criteria as ArrearsService::overdueLoans().
            $sql .= " AND l.loan_status IN ('Active','Current','Released')
                      AND EXISTS (
                          SELECT 1 FROM loan_schedules ls2
                          WHERE ls2.loan_id = l.id AND ls2.due_date <= CURDATE() AND ls2.total_due > ls2.total_paid
                      )";
        } elseif ($status === 'Bad Debt') {
            $sql .= " AND EXISTS (SELECT 1 FROM bad_debts bd2 WHERE bd2.loan_id = l.id AND bd2.status IN ('Open','Provisioned'))";
        } elseif ($status === 'Bad Debt Recovery') {
            $sql .= " AND EXISTS (SELECT 1 FROM bad_debts bd2 WHERE bd2.loan_id = l.id AND bd2.status = 'Under Recovery')";
        } elseif ($status !== '') {
            $sql .= " AND l.loan_status = ?";
            $params[] = $status;
        }

        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
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
                'opening_balance' => $row['opening_balance'] ?? 0,
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'fees_due' => $row['fees_due'],
                'namfisa_levy_due' => $row['namfisa_levy_due'] ?? 0,
                'duty_stamp_due' => $row['duty_stamp_due'] ?? 0,
                'penalty_due' => $row['penalty_due'],
                'total_due' => $row['total_due'],
                'closing_balance' => $row['closing_balance'] ?? 0,
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

    public function counts(?int $branchId = null): array
    {
        $where = $branchId !== null ? " AND branch_id = " . (int) $branchId : "";
        $scheduleWhere = $branchId !== null ? " AND l.branch_id = " . (int) $branchId : "";
        return [
            'total' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE 1=1{$where}"),
            'active' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE loan_status IN ('Active','Current','Released'){$where}"),
            'pending' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE loan_status = 'Pending Approval'{$where}"),
            'completed' => (int) $this->scalar("SELECT COUNT(*) FROM loans WHERE loan_status = 'Completed'{$where}"),
            'principal_outstanding' => (float) ($this->scalar(
                "SELECT COALESCE(SUM(total_due - total_paid),0) FROM loan_schedules ls
                 JOIN loans l ON l.id = ls.loan_id WHERE l.loan_status IN ('Active','Current','Released'){$scheduleWhere}"
            ) ?: 0),
        ];
    }

    public function arrearsCount(?int $branchId = null): int
    {
        if ($branchId !== null) {
            return (int) $this->scalar(
                "SELECT COUNT(DISTINCT ls.loan_id) FROM loan_schedules ls
                 JOIN loans l ON l.id = ls.loan_id WHERE ls.status = 'In Arrears' AND l.branch_id = ?",
                [$branchId]
            );
        }
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

    /**
     * Agent-scoped variants for the marketing agent self-service portal --
     * every query filters through the loan's originating application's
     * agent_id, so an agent only ever sees loans for clients they personally
     * referred (not other agents', and not walk-in/other-channel clients).
     */
    public function forAgent(int $agentId): array
    {
        return $this->all(
            "SELECT l.*, p.product_name, CONCAT(b.first_name,' ',b.last_name) AS borrower_name,
                    (SELECT ls.status FROM loan_schedules ls
                     WHERE ls.loan_id = l.id AND MONTH(ls.due_date) = MONTH(CURDATE()) AND YEAR(ls.due_date) = YEAR(CURDATE())
                     ORDER BY ls.installment_no LIMIT 1) AS current_month_status,
                    (SELECT ls.due_date FROM loan_schedules ls
                     WHERE ls.loan_id = l.id AND ls.status IN ('Pending','Partial','In Arrears')
                     ORDER BY ls.due_date LIMIT 1) AS next_due_date
             FROM loans l
             JOIN loan_applications a ON a.id = l.application_id
             JOIN loan_products p ON p.id = l.product_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE a.agent_id = ?
             ORDER BY l.id DESC",
            [$agentId]
        );
    }

    public function findForAgent(int $loanId, int $agentId): ?array
    {
        return $this->one(
            "SELECT l.*, p.product_name, p.interest_method, pl.plan_name, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loans l
             JOIN loan_applications a ON a.id = l.application_id
             JOIN loan_products p ON p.id = l.product_id
             JOIN loan_plans pl ON pl.id = l.plan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE l.id = ? AND a.agent_id = ?",
            [$loanId, $agentId]
        );
    }

    /**
     * Active/Current loans grouped by borrower, for the Top-up "existing
     * active loan" picker on loan creation.
     */
    public function activeLoansForTopup(?int $branchId = null): array
    {
        $sql = "SELECT l.id, l.loan_no, l.borrower_id, l.principal_amount, l.start_date
             FROM loans l
             WHERE l.loan_status IN ('Active','Current')
               AND NOT EXISTS (
                   SELECT 1 FROM loan_reschedules r WHERE r.loan_id = l.id AND r.status = 'Implemented'
               )";
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND l.branch_id = ?";
            $params[] = $branchId;
        }
        $sql .= " ORDER BY l.id DESC";

        return $this->all($sql, $params);
    }

    public function topupsOf(int $loanId): array
    {
        return $this->all(
            "SELECT l.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loans l
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE l.topup_of_loan_id = ? ORDER BY l.id DESC",
            [$loanId]
        );
    }
}
