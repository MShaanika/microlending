<?php

/**
 * General-purpose dispatcher for notification_queue -- the queue table
 * has existed since early in this app's life, but until now nothing
 * processed it automatically except one narrow cron scoped to
 * source_module='Security' (bin/dispatch_security_notifications.php,
 * left untouched and still runs on its own schedule). Every other
 * source_module's queued notification previously required a manual
 * "Send Now" click or was sent synchronously at creation time; this
 * script is the first general sweep, part of the Enterprise Control
 * Architecture's Phase 1 Shared Foundation (Part 67 -- Global
 * Notification Centre).
 *
 * Deliberately excludes source_module='Security' so the two crons never
 * compete for the same rows.
 *
 *   (every 5 min) /usr/bin/php /path/to/bin/dispatch_notifications.php >> storage/logs/notifications.log 2>&1
 *
 * Safe to run more than once -- only ever touches rows still Pending.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Models\NotificationQueue;
use App\Services\EmailSenderService;
use App\Services\SmsSenderService;

$db = Database::connection();
$rows = $db->query(
    "SELECT * FROM notification_queue
     WHERE source_module != 'Security' AND status = 'Pending'
     ORDER BY id ASC LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

$queue = new NotificationQueue();
$sent = 0;
$failed = 0;
$skipped = 0;

foreach ($rows as $row) {
    if ($row['channel'] === 'Email') {
        $result = EmailSenderService::send(
            $row['recipient_contact'],
            $row['subject'] ?: '(no subject)',
            $row['message'],
            $row['recipient_name'] ?: null
        );
    } elseif ($row['channel'] === 'SMS') {
        $result = SmsSenderService::send($row['recipient_contact'], $row['message']);
    } else {
        // An unrecognized channel is left Pending rather than guessed at --
        // a future channel type should be handled explicitly, not silently
        // dropped or mis-sent.
        $skipped++;
        continue;
    }

    if ($result['success']) {
        $queue->updateStatus((int) $row['id'], 'Sent');
        $sent++;
    } else {
        $queue->recordAttemptFailure((int) $row['id'], (string) $result['error']);
        $failed++;
    }
}

$summary = sprintf(
    "[%s] General notifications: %d sent, %d failed, %d skipped (unrecognized channel).\n",
    date('Y-m-d H:i:s'),
    $sent,
    $failed,
    $skipped
);
\App\Core\JobHeartbeat::ping('dispatch_notifications', $summary, 5);
echo $summary;
