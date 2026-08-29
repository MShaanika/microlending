<?php

/**
 * Keeps delegations.status current for display/audit purposes --
 * Scheduled -> Active once starts_at is reached, Active/Scheduled ->
 * Expired once ends_at passes (Part 15: "Automatically activate...
 * Automatically expire"). NOT the source of truth for whether a
 * delegation currently grants authority -- DelegationService checks
 * starts_at/ends_at/status in real time at the moment a delegated
 * permission is actually used, so authorization is correct even in the
 * minutes before this sweep next runs.
 *
 *   0 0 * * * /usr/bin/php /path/to/bin/expire_delegations.php >> storage/logs/delegations.log 2>&1
 *
 * Safe to run more than once -- only ever touches rows whose window has
 * genuinely opened/closed.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\Delegation;

$model = new Delegation();

$activated = 0;
foreach ($model->dueToActivate() as $row) {
    $model->markActive((int) $row['id']);
    $activated++;
}

$expired = 0;
foreach ($model->dueToExpire() as $row) {
    $model->markExpired((int) $row['id']);
    $expired++;
    \App\Core\Events::fire('DelegationExpired', ['delegation_id' => (int) $row['id'], 'reason' => 'ended']);
}

$summary = sprintf(
    "[%s] Delegations: %d activated, %d expired.\n",
    date('Y-m-d H:i:s'),
    $activated,
    $expired
);
\App\Core\JobHeartbeat::ping('expire_delegations', $summary, 1440);
echo $summary;
