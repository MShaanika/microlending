<?php

/**
 * Daily background job for Recurring Journals: checks every Active
 * template's Next Run date and, for anything due, posts a standard journal
 * entry from the template and advances the schedule. Meant to run once a
 * day via cron, e.g.:
 *
 *   0 1 * * * /usr/bin/php /path/to/bin/process_recurring_journals.php >> storage/logs/recurring_journals.log 2>&1
 *
 * Safe to run more than once on the same day -- a template only fires
 * again once its next_run_date has moved past today.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\RecurringJournalService;

$results = RecurringJournalService::processDue();

if (empty($results)) {
    echo "[" . date('Y-m-d H:i:s') . "] No recurring journals due today.\n";
    exit(0);
}

foreach ($results as $r) {
    echo sprintf(
        "[%s] Template %s -> journal #%d posted. Next run: %s (status: %s)\n",
        date('Y-m-d H:i:s'),
        $r['template_no'],
        $r['journal_id'],
        $r['next_run_date'],
        $r['status']
    );
}

echo "[" . date('Y-m-d H:i:s') . "] Processed " . count($results) . " recurring journal(s).\n";
