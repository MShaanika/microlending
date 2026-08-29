<?php

namespace App\Models;

use App\Core\Model;

class SecurityRule extends Model
{
    /** Active rules for a given event_type -- what SecurityRuleEngine::evaluate() actually iterates. */
    public function activeForEventType(string $eventType): array
    {
        return $this->all(
            "SELECT * FROM security_rules WHERE event_type = ? AND is_active = 1",
            [$eventType]
        );
    }

    public function allWithUpdater(): array
    {
        return $this->all("SELECT r.*, u.name AS updated_by_name FROM security_rules r LEFT JOIN users u ON u.id = r.updated_by ORDER BY r.rule_name");
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM security_rules WHERE id = ?", [$id]);
    }

    public function markTriggered(int $id): void
    {
        $this->update('security_rules', ['last_triggered_at' => date('Y-m-d H:i:s')], 'id', $id);
    }

    public function updateConfig(int $id, array $data): bool
    {
        return $this->update('security_rules', $data, 'id', $id);
    }
}
