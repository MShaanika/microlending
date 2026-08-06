<?php

namespace App\Models;

use App\Core\Model;

class AgentCommission extends Model
{
    private const LOOKUP_JOINS = "
        JOIN loans l ON l.id = c.loan_id
        JOIN hrm_employees e ON e.id = c.agent_employee_id
        JOIN borrowers b ON b.id = c.borrower_id
    ";

    private const LOOKUP_COLUMNS = "
        l.loan_no, CONCAT(e.first_name,' ',e.last_name) AS agent_name,
        CONCAT(b.first_name,' ',b.last_name) AS borrower_name
    ";

    public function create(array $data): int
    {
        return $this->insert('agent_commissions', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('agent_commissions', $data, 'id', $id);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM agent_commissions c " . self::LOOKUP_JOINS . " WHERE c.id = ?",
            [$id]
        );
    }

    public function findByLoanId(int $loanId): ?array
    {
        return $this->one("SELECT * FROM agent_commissions WHERE loan_id = ?", [$loanId]);
    }

    /**
     * @param array{agent_employee_id?: int, status?: string, date_from?: string, date_to?: string} $filters
     */
    public function paginated(array $filters = [], int $limit = 200): array
    {
        $sql = "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM agent_commissions c " . self::LOOKUP_JOINS . " WHERE 1=1";
        $params = [];

        if (!empty($filters['agent_employee_id'])) {
            $sql .= " AND c.agent_employee_id = ?";
            $params[] = $filters['agent_employee_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND c.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND c.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND c.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= " ORDER BY c.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function allForAgent(int $agentEmployeeId): array
    {
        return $this->all(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM agent_commissions c " . self::LOOKUP_JOINS . "
             WHERE c.agent_employee_id = ? ORDER BY c.id DESC",
            [$agentEmployeeId]
        );
    }

    /** Sum of what's earned-but-unpaid across every agent -- the number the owner actually cares about. */
    public function totalOutstanding(): float
    {
        return (float) ($this->scalar("SELECT COALESCE(SUM(earned_amount - paid_amount), 0) FROM agent_commissions") ?: 0);
    }
}
