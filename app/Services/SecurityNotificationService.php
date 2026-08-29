<?php

namespace App\Services;

use App\Models\NotificationQueue;
use App\Models\SecurityIncident;
use App\Models\SystemSetting;

/**
 * Queues (and, for High/Critical severity, immediately sends) an email
 * alert for a newly-created security incident -- reuses the existing
 * notification_queue table (source_module='Security') rather than a new
 * table; nothing processes that queue automatically today, so High/
 * Critical is dispatched synchronously here and Medium/Low is picked up
 * by the scoped bin/dispatch_security_notifications.php cron.
 *
 * Called only when SecurityIncident::createOrAppend() reports a genuinely
 * NEW incident (see SecurityRuleEngine) -- never on a re-trigger of an
 * already-open one, so a sustained attack sends one alert, not one per
 * event.
 */
class SecurityNotificationService
{
    public static function notifyIncident(int $incidentId, string $severity): void
    {
        try {
            $recipient = trim((string) (new SystemSetting())->get('security_alert_recipient_email', ''));
            if ($recipient === '') {
                return; // nothing configured -- the incident is still visible on the dashboard either way
            }

            $incident = (new SecurityIncident())->find($incidentId);
            if (!$incident) {
                return;
            }

            $subject = strtoupper($severity) . ': ' . $incident['title'];
            $message = "A security rule was triggered.\n\n"
                . "Incident: " . $incident['title'] . "\n"
                . "Severity: " . $incident['severity'] . "\n"
                . "Events so far: " . $incident['event_count'] . "\n"
                . "First seen: " . $incident['first_event_at'] . "\n"
                . "Last seen: " . $incident['last_event_at'] . "\n\n"
                . "Review: " . url('/security/incidents/' . $incidentId);

            $queue = new NotificationQueue();
            $queueId = $queue->create([
                'channel' => 'Email',
                'recipient_name' => 'Security Administrator',
                'recipient_contact' => $recipient,
                'subject' => $subject,
                'message' => $message,
                'source_module' => 'Security',
                'source_table' => 'security_incidents',
                'source_id' => $incidentId,
                'status' => 'Pending',
            ]);

            if (in_array($severity, ['High', 'Critical'], true)) {
                $result = EmailSenderService::send($recipient, $subject, $message, 'Security Administrator');
                if ($result['success']) {
                    $queue->updateStatus($queueId, 'Sent');
                } else {
                    $queue->recordAttemptFailure($queueId, (string) $result['error']);
                }
            }
        } catch (\Throwable $e) {
            error_log('SecurityNotificationService::notifyIncident failed: ' . $e->getMessage());
        }
    }
}
