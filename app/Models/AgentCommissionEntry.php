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
            "SELECT * FROM agent_commission_entries WHERE agent_commission_id = ? ORDER BY id ASC",
            [$agentCommissionId]
        );
    }
}
