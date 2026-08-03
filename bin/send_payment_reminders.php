<?php

/**
 * Daily background job: texts borrowers whose installment is due in 3 days
 * (once, ever) or already overdue (repeating every 7 days while unpaid).
 * Meant to run once a day via cron, e.g.:
 *
 *   0 7 * * * /usr/bin/php /path/to/bin/send_payment_reminders.php >> storage/logs/payment_reminders.log 2>&1
 *
 * Safe to run more than once on the same day -- payment_reminder_sends
 * tracks what's already gone out, same pattern as
 * process_recurring_journals.php.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\PaymentReminderService;

$summary = PaymentReminderService::run();

echo sprintf(
    "[%s] Due-soon: %d sent, %d skipped. Overdue: %d sent, %d skipped.\n",
    date('Y-m-d H:i:s'),
    $summary['due_soon_sent'],
    $summary['due_soon_skipped'],
    $summary['overdue_sent'],
    $summary['overdue_skipped']
);
