<?php

namespace App\Services;

use App\Models\AccountingJournal;
use App\Models\RecurringJournalTemplate;

/**
 * Automates repetitive transactions: the daily background job
 * (bin/process_recurring_journals.php) calls processDue() once a day. For
 * every Active template whose next_run_date has arrived, it posts a
 * standard journal entry from the template and advances next_run_date --
 * or flips the template to Expired if the next occurrence would fall past
 * its end_date.
 */
class RecurringJournalService
{
    private const FREQUENCY_MODIFIER = [
        'Weekly' => '+1 week',
        'Monthly' => '+1 month',
        'Quarterly' => '+3 months',
        'Annually' => '+1 year',
    ];

    /**
     * @return array<int, array{template_id:int, template_no:string, journal_id:int, next_run_date:string, status:string}>
     */
    public static function processDue(?string $date = null): array
    {
        $date = $date ?: date('Y-m-d');
        $templates = new RecurringJournalTemplate();
        $journal = new AccountingJournal();
        $results = [];

        foreach ($templates->dueOn($date) as $t) {
            $journalId = $journal->post(
                'RECURRING_JOURNAL',
                'recurring_journal_templates',
                (int) $t['id'],
                $t['template_no'],
                $t['description'],
                [
                    ['account_id' => (int) $t['debit_account_id'], 'debit' => (float) $t['amount'], 'credit' => 0.0],
                    ['account_id' => (int) $t['credit_account_id'], 'debit' => 0.0, 'credit' => (float) $t['amount']],
                ],
                null,
                $date,
                'Recurring'
            );

            $nextRun = self::advance($t['next_run_date'], $t['frequency']);
            $newStatus = ($t['end_date'] && $nextRun > $t['end_date']) ? 'Expired' : 'Active';

            $templates->updateFields((int) $t['id'], [
                'next_run_date' => $nextRun,
                'status' => $newStatus,
                'last_run_at' => date('Y-m-d H:i:s'),
            ]);

            $results[] = [
                'template_id' => (int) $t['id'],
                'template_no' => $t['template_no'],
                'journal_id' => $journalId,
                'next_run_date' => $nextRun,
                'status' => $newStatus,
            ];
        }

        return $results;
    }

    private static function advance(string $date, string $frequency): string
    {
        $modifier = self::FREQUENCY_MODIFIER[$frequency] ?? '+1 month';
        return date('Y-m-d', strtotime($date . ' ' . $modifier));
    }
}
