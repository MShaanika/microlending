<?php

namespace App\Models;

use App\Core\Model;

class AgentCommissionEntry extends Model
{
    public function create(array $data): int
    {
        return $this->insert('agent_commission_entries', $data);
    }

    public function forCommission(int $agentCommissionId): array
    {
        return $this->all(
            "SELECT e.*, ba.bank_name, ba.account_name AS bank_account_name
             FROM agent_commission_entries e
             LEFT JOIN accounting_bank_accounts ba ON ba.id = e.bank_account_id
             WHERE e.agent_commission_id = ? ORDER BY e.id ASC",
            [$agentCommissionId]
        );
    }
}
