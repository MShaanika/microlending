<?php

/**
 * Sweeps every open SLA instance (Part 17-21): recomputes ON_TRACK/
 * AT_RISK/BREACHED against the current time, then evaluates that
 * policy's escalation ladder for anything newly due. This is what
 * actually makes an SLA "live" -- without this running periodically, an
 * instance's status only ever reflects whatever it was at creation.
 *
 *   (every 5 min) /usr/bin/php /path/to/bin/evaluate_sla.php >> storage/logs/sla.log 2>&1
 *
 * Safe to run more than once -- status recomputation is idempotent, and
 * escalation dedup is enforced against sla_events (see
 * EscalationService), not this script's own run state.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\SlaInstance;
use App\Services\EscalationService;
use App\Services\SlaService;

$model = new SlaInstance();
$instances = $model->activeInstances();

$statusChanges = 0;
$escalationsChecked = 0;

foreach ($instances as $instance) {
    $before = $instance['status'];
    $after = SlaService::refreshStatus($instance);
    if ($after !== $before) {
        $statusChanges++;
    }

    $instance['status'] = $after;
    EscalationService::evaluate($instance);
    $escalationsChecked++;
}

$summary = sprintf(
    "[%s] SLA sweep: %d instance(s) checked, %d status change(s).\n",
    date('Y-m-d H:i:s'),
    $escalationsChecked,
    $statusChanges
);
\App\Core\JobHeartbeat::ping('evaluate_sla', $summary, 5);
echo $summary;
