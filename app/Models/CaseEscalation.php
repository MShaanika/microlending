<?php

namespace App\Models;

use App\Core\Model;

class CaseEscalation extends Model
{
    public function forLoan(int $loanId): array
    {
        return $this->all(
            "SELECT ce.*, u1.name AS escalated_by_name, u2.name AS resolved_by_name
             FROM case_escalations ce
             LEFT JOIN users u1 ON u1.id = ce.escalated_by
             LEFT JOIN users u2 ON u2.id = ce.resolved_by
             WHERE ce.loan_id = ? ORDER BY ce.escalated_at DESC",
            [$loanId]
        );
    }

    public function latestOpenForLoan(int $loanId): ?array
    {
        return $this->one(
            "SELECT * FROM case_escalations WHERE loan_id = ? AND status = 'Open' ORDER BY escalated_at DESC LIMIT 1",
            [$loanId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM case_escalations WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('case_escalations', $data);
    }

    public function resolve(int $id, int $userId, string $notes): bool
    {
        return $this->update('case_escalations', [
            'status' => 'Resolved',
            'resolved_by' => $userId,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolution_notes' => $notes,
        ], 'id', $id);
    }
}
