<?php

namespace App\Services;

use App\Core\Database;
use App\Models\PaymentReminderLog;

/**
 * Automated borrower payment reminders -- finds installments due soon or
 * already overdue and texts the borrower via the PAYMENT_REMINDER_SMS /
 * ARREARS_NOTICE_SMS templates (both were already seeded but never actually
 * sent by anything). Meant to run once a day via cron
 * (bin/send_payment_reminders.php).
 *
 * A due-soon reminder is sent at most once per installment, ever -- there's
 * only one "3 days out" moment. An overdue reminder repeats every
 * OVERDUE_RESEND_AFTER_DAYS while the installment stays unpaid, so a
 * borrower is nudged periodically rather than nagged daily or forgotten
 * after a single notice.
 */
class PaymentReminderService
{
    private const DUE_SOON_DAYS_AHEAD = 3;
    private const OVERDUE_RESEND_AFTER_DAYS = 7;
    private const ACTIVE_LOAN_STATUSES = "('Active','Current')";

    /**
     * @return array{due_soon_sent: int, due_soon_skipped: int, overdue_sent: int, overdue_skipped: int}
     */
    public static function run(?int $userId = null): array
    {
        $log = new PaymentReminderLog();
        $summary = ['due_soon_sent' => 0, 'due_soon_skipped' => 0, 'overdue_sent' => 0, 'overdue_skipped' => 0];

        foreach (self::dueSoonInstallments() as $row) {
            if ($log->find((int) $row['id'], 'DueSoon')) {
                $summary['due_soon_skipped']++;
                continue;
            }

            $result = TemplatedSmsService::send(
                'PAYMENT_REMINDER_SMS',
                (string) $row['phone'],
                [
                    'borrower_full_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'amount_due' => format_money((float) $row['amount_due']),
                    'due_date' => date('d M Y', strtotime($row['due_date'])),
                ],
                (int) $row['borrower_id'],
                $userId
            );

            if ($result['sent']) {
                $log->recordSend((int) $row['id'], 'DueSoon');
                $summary['due_soon_sent']++;
            } else {
                $summary['due_soon_skipped']++;
            }
        }

        foreach (self::overdueInstallments() as $row) {
            if ($log->wasSentWithin((int) $row['id'], 'Overdue', self::OVERDUE_RESEND_AFTER_DAYS)) {
                $summary['overdue_skipped']++;
                continue;
            }

            $result = TemplatedSmsService::send(
                'ARREARS_NOTICE_SMS',
                (string) $row['phone'],
                [
                    'borrower_full_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'arrears_amount' => format_money((float) $row['amount_due']),
                ],
                (int) $row['borrower_id'],
                $userId
            );

            if ($result['sent']) {
                $log->recordSend((int) $row['id'], 'Overdue');
                $summary['overdue_sent']++;
            } else {
                $summary['overdue_skipped']++;
            }
        }

        return $summary;
    }

    private static function dueSoonInstallments(): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT ls.id, ls.due_date, (ls.total_due - ls.total_paid) AS amount_due,
                    l.borrower_id, b.first_name, b.last_name, b.phone
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE ls.status IN ('Pending','Partial')
               AND ls.due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND l.loan_status IN " . self::ACTIVE_LOAN_STATUSES
        );
        $stmt->execute([self::DUE_SOON_DAYS_AHEAD]);
        return $stmt->fetchAll();
    }

    private static function overdueInstallments(): array
    {
        $db = Database::connection();
        return $db->query(
            "SELECT ls.id, ls.due_date, (ls.total_due - ls.total_paid) AS amount_due,
                    l.borrower_id, b.first_name, b.last_name, b.phone
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE ls.status IN ('Pending','Partial','In Arrears')
               AND ls.due_date < CURDATE()
               AND l.loan_status IN " . self::ACTIVE_LOAN_STATUSES
        )->fetchAll();
    }
}
