<?php

namespace App\Models;

use App\Core\Model;

/** One default's own status-transition timeline (item 9's "AUDIT TRAIL" section, item 12's action list) -- distinct from the global audit_logs table, which also gets an entry per action via Audit::log(). */
class CreditinfoPublicDefaultAction extends Model
{
    public function log(int $publicDefaultId, string $action, ?int $actorUserId, ?string $oldStatus, ?string $newStatus, ?string $reason = null, ?string $apiReference = null): int
    {
        return $this->insert('creditinfo_public_default_actions', [
            'public_default_id' => $publicDefaultId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'api_reference' => $apiReference,
        ]);
    }

    public function timeline(int $publicDefaultId): array
    {
        return $this->all(
            "SELECT a.*, u.name AS actor_name
             FROM creditinfo_public_default_actions a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.public_default_id = ? ORDER BY a.created_at ASC",
            [$publicDefaultId]
        );
    }
}
