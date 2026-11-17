<?php

namespace App\Services;

use App\Core\Database;
use App\Models\LoanFollowup;
use App\Models\NotificationQueue;
use App\Models\User;

/**
 * Tells the staff who answer the Loan Questions that something is waiting for them. The Loan Questions page only shows a question when somebody opens it,
 * so without this nobody is told. Two kinds of e-mail, both put in the notification queue (bin/dispatch_notifications.php sends them, every 5 minutes):
 *
 *   - one for each new online application, the moment it is submitted;
 *   - one each morning (bin/loan_followups_remind.php) listing every question still waiting, only when there is something waiting.
 *
 * Whoever holds the permission loans.followups is told. Nothing here ever throws into the caller: an applicant's submission must never fail because of an alert.
 */
final class LoanFollowupAlerts
{
    public const SOURCE_MODULE = 'Loan Questions';
    public const PERMISSION = 'loans.followups';
    /** The most questions named in a morning e-mail; the rest are only counted. */
    public const LIST_LIMIT = 30;

    /** @var callable():array<int, array{id:int,name:string,email:string}> */
    private $recipients;

    /** @param (callable():array<int, array{id:int,name:string,email:string}>)|null $recipients people to tell (default: everybody active who holds the permission) */
    public function __construct(?callable $recipients = null)
    {
        $this->recipients = $recipients ?? static fn (): array => (new User())->usersWithPermission(self::PERMISSION);
    }

    /** Queues the "new application" e-mail for everybody who answers Loan Questions. Returns how many e-mails were queued (0 on any problem). */
    public function newApplication(int $applicationId, string $applicationNo, string $applicantName): int
    {
        try {
            $subject = 'New loan application ' . $applicationNo;
            $message = "A new online loan application has come in.\n\n"
                . 'Application: ' . $applicationNo . "\n"
                . 'Applicant: ' . $this->clean($applicantName) . "\n\n"
                . "Please open Loan Questions (menu: Lending > Loans > Loan Questions) and answer the question for it: did you approve this loan?\n"
                . "The system will keep asking until it is answered.";
            return $this->queue($subject, $message, 'loan_applications', $applicationId);
        } catch (\Throwable $e) {
            error_log('LoanFollowupAlerts::newApplication failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The morning e-mail: every question that is due now. Sent at most once per person per day, and only when at least one question is waiting.
     *
     * @param string $now Y-m-d H:i:s
     * @return int how many e-mails were queued
     */
    public function dailyReminder(string $now): int
    {
        try {
            (new LoanFollowup())->syncApplications();
            $due = $this->dueQuestions($now);
            if (!$due) {
                return 0;
            }
            $lines = [];
            foreach (array_slice($due, 0, self::LIST_LIMIT) as $d) {
                $lines[] = '- ' . $d['application_no'] . ' (' . $d['name'] . '): ' . $d['question'];
            }
            $more = count($due) - count($lines);
            $message = 'These loan questions are waiting for an answer (' . count($due) . "):\n\n" . implode("\n", $lines)
                . ($more > 0 ? "\n...and " . $more . ' more.' : '')
                . "\n\nPlease open Loan Questions (menu: Lending > Loans > Loan Questions) and answer them. The answers feed the Creditinfo files.";
            return $this->queue('Loan questions waiting: ' . count($due), $message, 'loan_followups_daily', (int) date('Ymd', strtotime($now)));
        } catch (\Throwable $e) {
            error_log('LoanFollowupAlerts::dailyReminder failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The cards with a question due right now, the ones asked many times first and then the oldest application. Same rule as the Loan Questions page.
     *
     * @return array{cards: array<int, array{f: array<string,mixed>, q: array<string,mixed>, instalments: array<int, array<string,mixed>>}>, waiting: int}
     */
    public function cards(string $now): array
    {
        $model = new LoanFollowup();
        $flow = new LoanFollowupFlow();
        $instalments = $model->instalmentsByFollowup();
        $cards = [];
        $waiting = 0;
        foreach ($model->openWithApplications() as $f) {
            $rows = $instalments[(int) $f['id']] ?? [];
            $q = $flow->nextQuestion($f, $rows, $now);
            if ($q === null) {
                $waiting++;
                continue;
            }
            $cards[] = ['f' => $f, 'q' => $q, 'instalments' => $rows];
        }
        usort($cards, static fn (array $a, array $b): int => [$b['q']['attention'], $a['f']['applied_at']] <=> [$a['q']['attention'], $b['f']['applied_at']]);
        return ['cards' => $cards, 'waiting' => $waiting];
    }

    /**
     * What the pop-up shows on any page: the first question that is due and how many are due in all. Null when nothing is due or anything goes wrong
     * (a pop-up must never break the page it appears on).
     *
     * @return array{card: array<string,mixed>, count: int}|null
     */
    public function popup(string $now): ?array
    {
        try {
            $model = new LoanFollowup();
            $model->syncApplications();
            $cards = $this->cards($now)['cards'];
            return $cards ? ['card' => $cards[0], 'count' => count($cards)] : null;
        } catch (\Throwable $e) {
            error_log('LoanFollowupAlerts::popup failed: ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<int, array{application_no:string, name:string, question:string}> */
    public function dueQuestions(string $now): array
    {
        $out = [];
        foreach ($this->cards($now)['cards'] as $c) {
            $f = $c['f'];
            $name = trim(($f['applicant_first_name'] ?? '') . ' ' . ($f['applicant_last_name'] ?? ''));
            $out[] = ['application_no' => (string) $f['application_no'], 'name' => $this->clean($name), 'question' => (string) $c['q']['text']];
        }
        return $out;
    }

    /** One queued e-mail per person; the same (source table, id) is never queued twice for the same person. */
    private function queue(string $subject, string $message, string $sourceTable, int $sourceId): int
    {
        $db = Database::connection();
        $exists = $db->prepare('SELECT 1 FROM notification_queue WHERE user_id = ? AND source_module = ? AND source_table = ? AND source_id = ? LIMIT 1');
        $queue = new NotificationQueue();
        $count = 0;
        foreach (($this->recipients)() as $user) {
            if (trim((string) ($user['email'] ?? '')) === '') {
                continue;
            }
            $exists->execute([(int) $user['id'], self::SOURCE_MODULE, $sourceTable, $sourceId]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $queue->create([
                'user_id' => (int) $user['id'],
                'channel' => 'Email',
                'recipient_name' => $user['name'],
                'recipient_contact' => $user['email'],
                'subject' => $subject,
                'message' => $message,
                'source_module' => self::SOURCE_MODULE,
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'status' => 'Pending',
            ]);
            $count++;
        }
        return $count;
    }

    /** Names come from a public form: no line breaks or control characters may reach an e-mail. */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }
}
