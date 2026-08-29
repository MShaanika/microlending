<?php

namespace App\Services;

use App\Core\Correlation;
use App\Core\Events;
use App\Models\SlaInstance;

/**
 * Starts, tracks, and closes SLA instances against admin-configured
 * policies (Part 16-19). Mirrors ApprovalService's null-if-no-active-
 * policy contract: start() returns null (not an exception) when no
 * active policy matches, so a caller can unconditionally try to start
 * an SLA clock without needing to know whether one is configured yet.
 */
class SlaService
{
    public static function start(string $policyKey, string $resourceType, int $resourceId, ?int $ownerUserId = null): ?int
    {
        $model = new SlaInstance();
        $policy = $model->findActivePolicy($policyKey);
        if (!$policy) {
            return null;
        }

        $startedAt = new \DateTimeImmutable();
        $dueAt = $policy['business_hours_aware']
            ? BusinessHoursService::addBusinessMinutes($startedAt, (int) $policy['duration_minutes'])
            : $startedAt->modify('+' . (int) $policy['duration_minutes'] . ' minutes');

        $instanceId = $model->create([
            'policy_id' => $policy['id'],
            'correlation_id' => Correlation::id(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'owner_user_id' => $ownerUserId,
            'status' => 'ON_TRACK',
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
        ]);
        $model->logEvent($instanceId, 'STARTED');

        return $instanceId;
    }

    public static function completeForResource(string $resourceType, int $resourceId): void
    {
        $model = new SlaInstance();
        $instance = $model->findOpenByResource($resourceType, $resourceId);
        if (!$instance) {
            return;
        }
        $model->updateStatus((int) $instance['id'], 'COMPLETED');
        $model->logEvent((int) $instance['id'], 'COMPLETED');
    }

    public static function cancelForResource(string $resourceType, int $resourceId, ?string $reason = null): void
    {
        $model = new SlaInstance();
        $instance = $model->findOpenByResource($resourceType, $resourceId);
        if (!$instance) {
            return;
        }
        $model->updateStatus((int) $instance['id'], 'CANCELLED');
        $model->logEvent((int) $instance['id'], 'CANCELLED', null, $reason);
    }

    public static function pause(int $instanceId, ?string $reason = null): void
    {
        $model = new SlaInstance();
        $model->pause($instanceId);
        $model->logEvent($instanceId, 'PAUSED', null, $reason);
    }

    public static function resume(int $instanceId): void
    {
        $model = new SlaInstance();
        $model->resume($instanceId);
        $model->logEvent($instanceId, 'RESUMED');
    }

    /**
     * Recomputes ON_TRACK/AT_RISK/BREACHED for one instance against the
     * current time -- called by bin/evaluate_sla.php for every open
     * instance on each sweep. Only writes (and logs an event) when the
     * status actually changes, and fires SlaBreached exactly once, at the
     * moment of the transition into BREACHED.
     */
    public static function refreshStatus(array $instance): string
    {
        $model = new SlaInstance();
        $now = time();
        $due = strtotime($instance['due_at']);
        $percentElapsed = self::percentElapsed($instance);

        $newStatus = match (true) {
            $now > $due => 'BREACHED',
            $percentElapsed >= (float) $instance['at_risk_threshold_percent'] => 'AT_RISK',
            default => 'ON_TRACK',
        };

        if ($newStatus !== $instance['status']) {
            $model->updateStatus((int) $instance['id'], $newStatus);
            if (in_array($newStatus, ['AT_RISK', 'BREACHED'], true)) {
                $model->logEvent((int) $instance['id'], $newStatus);
            }
            if ($newStatus === 'BREACHED') {
                Events::fire('SlaBreached', ['sla_instance_id' => $instance['id']]);
            }
        }

        return $newStatus;
    }

    /** % of the instance's total allotted time that has elapsed as of now -- shared by status computation, escalation evaluation, and the UI's "1h 42m remaining" / "Breached by 3h 15m" badge. */
    public static function percentElapsed(array $instance): float
    {
        $started = strtotime($instance['started_at']);
        $due = strtotime($instance['due_at']);
        $totalMinutes = max(1, ($due - $started) / 60);
        $elapsedMinutes = (time() - $started) / 60;
        return ($elapsedMinutes / $totalMinutes) * 100;
    }
}
