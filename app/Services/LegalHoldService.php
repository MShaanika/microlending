<?php

namespace App\Services;

use App\Core\Audit;
use App\Models\RetentionPolicy;

/** Part 47 -- a hold overrides a policy's delete-eligibility for one specific record, fully audited on both ends (who placed it and why, who released it and why). */
class LegalHoldService
{
    public static function place(string $table, int $resourceId, string $reason, int $placedBy): int
    {
        $id = (new RetentionPolicy())->placeHold($table, $resourceId, $reason, $placedBy);
        Audit::log('Create', 'Continuity', "Placed legal hold on $table #$resourceId: $reason", ['legal_hold_id' => $id]);
        return $id;
    }

    public static function release(int $holdId, int $releasedBy, string $reason): void
    {
        (new RetentionPolicy())->releaseHold($holdId, $releasedBy, $reason);
        Audit::log('Update', 'Continuity', "Released legal hold #$holdId: $reason", ['legal_hold_id' => $holdId]);
    }
}
