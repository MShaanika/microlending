<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Events;
use App\Models\NotificationQueue;
use App\Models\SlaInstance;

/**
 * Fires an SLA policy's configured escalation ladder (Part 20) as an
 * instance crosses each threshold_percent -- reminder, supervisor
 * notification, manager escalation, or a real Exception. Dedup/cooldown
 * (Part 21) is enforced via SlaInstance::alreadyEscalatedAt(): a given
 * threshold fires at most once per instance, checked against sla_events
 * rather than a separate "last notified" timestamp, so the history and
 * the dedup state can never drift apart.
 */
class EscalationService
{
    public static function evaluate(array $instance): void
    {
        $model = new SlaInstance();
        $rules = $model->escalationRulesFor((int) $instance['policy_id']);
        if (empty($rules)) {
            return;
        }
        $percentElapsed = SlaService::percentElapsed($instance);

        foreach ($rules as $rule) {
            if ($percentElapsed < (float) $rule['threshold_percent']) {
                continue;
            }
            if ($model->alreadyEscalatedAt((int) $instance['id'], (int) $rule['threshold_percent'])) {
                continue;
            }

            self::fire($instance, $rule);
            $model->logEvent((int) $instance['id'], 'ESCALATED', (int) $rule['threshold_percent'], $rule['action']);
            $model->bumpEscalationLevel((int) $instance['id']);
        }
    }

    private static function fire(array $instance, array $rule): void
    {
        switch ($rule['action']) {
            case 'REMIND_OWNER':
                if (!empty($instance['owner_user_id'])) {
                    self::notifyUser((int) $instance['owner_user_id'], $instance, 'SLA Reminder', 'This item is approaching its SLA due date.');
                }
                break;

            case 'NOTIFY_SUPERVISOR':
            case 'ESCALATE_MANAGER':
                if (!empty($rule['notify_permission'])) {
                    self::notifyByPermission($rule['notify_permission'], $instance, 'SLA Escalation', 'An item has crossed an SLA escalation threshold and needs attention.');
                }
                break;

            case 'CREATE_EXCEPTION':
                ExceptionService::create(
                    'sla_breach',
                    'SLA',
                    'Operations',
                    $rule['exception_severity'] ?? 'Medium',
                    sprintf('SLA breached for %s #%d (%d%% of allotted time elapsed).', $instance['resource_type'], $instance['resource_id'], (int) SlaService::percentElapsed($instance)),
                    $instance['resource_type'],
                    (int) $instance['resource_id']
                );
                break;
        }

        Events::fire('SlaEscalated', ['sla_instance_id' => $instance['id'], 'action' => $rule['action']]);
    }

    private static function notifyUser(int $userId, array $instance, string $subject, string $message): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT name, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !$user['email']) {
            return;
        }

        (new NotificationQueue())->create([
            'user_id' => $userId,
            'channel' => 'Email',
            'recipient_name' => $user['name'],
            'recipient_contact' => $user['email'],
            'subject' => $subject,
            'message' => $message . ' Reference: ' . $instance['resource_type'] . ' #' . $instance['resource_id'],
            'source_module' => 'Operations',
            'source_table' => 'sla_instances',
            'source_id' => $instance['id'],
            'status' => 'Pending',
        ]);
    }

    /** Notifies every user holding $permissionKey -- capped, so a widely-held permission never becomes a mass-email storm from one escalation. */
    private static function notifyByPermission(string $permissionKey, array $instance, string $subject, string $message): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DISTINCT u.id, u.name, u.email FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_key = ? AND u.email IS NOT NULL AND u.is_active = 1
             LIMIT 10"
        );
        $stmt->execute([$permissionKey]);
        $queue = new NotificationQueue();

        foreach ($stmt->fetchAll() as $user) {
            $queue->create([
                'user_id' => (int) $user['id'],
                'channel' => 'Email',
                'recipient_name' => $user['name'],
                'recipient_contact' => $user['email'],
                'subject' => $subject,
                'message' => $message . ' Reference: ' . $instance['resource_type'] . ' #' . $instance['resource_id'],
                'source_module' => 'Operations',
                'source_table' => 'sla_instances',
                'source_id' => $instance['id'],
                'status' => 'Pending',
            ]);
        }
    }
}
