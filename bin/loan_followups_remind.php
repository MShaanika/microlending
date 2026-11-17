<?php

/**
 * Morning reminder for the Loan Questions: queues one e-mail per person who answers them, listing every question still waiting (nothing is queued when nothing
 * is waiting, and nobody is sent it twice on the same day). The e-mails themselves are sent by bin/dispatch_notifications.php.
 *
 *   (every day, 07:30) php bin/loan_followups_remind.php
 */

require __DIR__ . '/../bootstrap/app.php';

$queued = (new App\Services\LoanFollowupAlerts())->dailyReminder(date('Y-m-d H:i:s'));
$summary = '[' . date('Y-m-d H:i:s') . '] Loan Questions reminder: ' . $queued . " e-mail(s) queued.\n";
\App\Core\JobHeartbeat::ping('loan_followups_remind', $summary, 1440);
echo $summary;
