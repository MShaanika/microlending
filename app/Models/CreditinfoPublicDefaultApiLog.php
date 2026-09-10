<?php

namespace App\Models;

use App\Core\Model;

/**
 * Every row here is a BLOCKED submission attempt -- PublicDefaultsGateway
 * always throws CreditinfoPublicDefaultsNotConfiguredException before any
 * network call, so this table can never contain a real Creditinfo API
 * request/response. Serves as evidence for the compliance dashboard's
 * "API documentation still required" flag and for anyone auditing why a
 * default is stuck at Awaiting API Submission/Removal.
 */
class CreditinfoPublicDefaultApiLog extends Model
{
    public function record(int $triggeredBy, string $actionAttempted, string $environment, string $blockedReason, ?int $publicDefaultId = null): int
    {
        return $this->insert('creditinfo_public_default_api_logs', [
            'public_default_id' => $publicDefaultId,
            'triggered_by' => $triggeredBy,
            'action_attempted' => $actionAttempted,
            'environment' => $environment,
            'blocked_reason' => $blockedReason,
        ]);
    }

    public function recent(int $limit = 50): array
    {
        return $this->all(
            "SELECT l.*, u.name AS triggered_by_name, pd.listing_reference
             FROM creditinfo_public_default_api_logs l
             LEFT JOIN users u ON u.id = l.triggered_by
             LEFT JOIN creditinfo_public_defaults pd ON pd.id = l.public_default_id
             ORDER BY l.created_at DESC LIMIT " . max(1, $limit)
        );
    }

    public function count(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM creditinfo_public_default_api_logs");
    }
}
