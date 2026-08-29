<?php

/**
 * Sends queued Medium/Low-severity security incident alert emails.
 *
 * SecurityNotificationService::notifyIncident() sends High/Critical alerts
 * synchronously and leaves Medium/Low as Pending in notification_queue
 * (source_module='Security') for this cron to pick up -- nothing else
 * processes that queue automatically. Deliberately scoped to
 * source_module='Security' only; this is not a general queue dispatcher.
 *
 *   (every 5 min) /usr/bin/php /path/to/bin/dispatch_security_notifications.php >> storage/logs/security_notifications.log 2>&1
 *
 * Safe to run more than once -- only ever touches rows still Pending.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Models\NotificationQueue;
use App\Services\EmailSenderService;

$db = Database::connection();
$rows = $db->query(
    "SELECT * FROM notification_queue WHERE source_module = 'Security' AND channel = 'Email' AND status = 'Pending' ORDER BY id ASC LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

$queue = new NotificationQueue();
$sent = 0;
$failed = 0;

foreach ($rows as $row) {
    $result = EmailSenderService::send(
        $row['recipient_contact'],
        $row['subject'],
        $row['message'],
        $row['recipient_name'] ?: null
    );

    if ($result['success']) {
        $queue->updateStatus((int) $row['id'], 'Sent');
        $sent++;
    } else {
        $queue->recordAttemptFailure((int) $row['id'], (string) $result['error']);
        $failed++;
    }
}

$summary = sprintf(
    "[%s] Security notifications: %d sent, %d failed.\n",
    date('Y-m-d H:i:s'),
    $sent,
    $failed
);
\App\Core\JobHeartbeat::ping('dispatch_security_notifications', $summary, 5);
echo $summary;
