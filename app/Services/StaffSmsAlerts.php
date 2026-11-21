<?php

namespace App\Services;

use App\Core\Database;
use App\Models\NotificationQueue;
use App\Models\NotificationSetting;
use App\Support\PhoneNumberNormalizer;

/**
 * Text messages (SMS) to named staff, queued in notification_queue on the SMS channel (bin/dispatch_notifications.php sends them through the
 * SMS provider every 5 minutes, exactly like the e-mails).
 *
 *   - newApplication(): one SMS to every person in SMS_ALERT_NEW_APPLICATION_USER_IDS the moment an online application is submitted;
 *   - approved():       one SMS to every person in SMS_ALERT_APPROVED_USER_IDS when somebody ELSE approves a loan, so they can disburse it.
 *                       "Approved" can happen in three places (the application approved, the loan approved, "Yes" in Loan Questions);
 *                       each can be switched off, and a client only ever produces ONE approval SMS however many of those steps happen.
 *
 * Who gets what is a setting (Notification Settings, channel SMS) holding user ids, so it can change without code. The person who did the approving
 * is never texted about their own approval. Nothing here ever throws into the caller: an applicant's submission or a staff member's click must
 * never fail because of an alert.
 */
final class StaffSmsAlerts
{
    public const SOURCE_MODULE = 'Staff SMS';
    public const KEY_ENABLED = 'SMS_ALERTS_ENABLED';
    public const KEY_NEW_APPLICATION_USERS = 'SMS_ALERT_NEW_APPLICATION_USER_IDS';
    public const KEY_APPROVED_USERS = 'SMS_ALERT_APPROVED_USER_IDS';
    public const KEY_NEW_APPLICATION_DAILY_LIMIT = 'SMS_ALERT_NEW_APPLICATION_DAILY_LIMIT';
    /** trigger name => setting that switches it on or off ("1" = on, the default). */
    public const APPROVAL_TRIGGERS = [
        'application' => 'SMS_ALERT_APPROVED_ON_APPLICATION',
        'loan' => 'SMS_ALERT_APPROVED_ON_LOAN',
        'loan_question' => 'SMS_ALERT_APPROVED_ON_LOAN_QUESTION',
    ];

    /** @var callable(string,string):string reads a setting: (key, default) => value */
    private $setting;
    /** @var callable(array<int,int>):array<int, array{id:int,name:string,phone:string}> active users by id */
    private $usersById;

    /**
     * @param (callable(string,string):string)|null $setting
     * @param (callable(array<int,int>):array<int, array{id:int,name:string,phone:string}>)|null $usersById
     */
    public function __construct(?callable $setting = null, ?callable $usersById = null)
    {
        $this->setting = $setting ?? static fn (string $key, string $default): string => (new NotificationSetting())->get($key, $default);
        $this->usersById = $usersById ?? [self::class, 'loadUsers'];
    }

    /** Queues the "new application" SMS. Returns how many SMS were queued (0 on any problem). */
    public function newApplication(int $applicationId, string $applicationNo, string $applicantName): int
    {
        try {
            if (!$this->enabled()) {
                return 0;
            }
            $message = 'Solid Desert: new loan application ' . $this->clean($applicationNo, 30) . ' from ' . $this->clean($applicantName, 28) . '. Please open Loan Questions and answer it.';
            // the form is public: a flood of fake applications must not flood staff phones (and the SMS bill), so each person gets at most N a day
            $cap = max(1, (int) (($this->setting)(self::KEY_NEW_APPLICATION_DAILY_LIMIT, '30') ?: 30));
            return $this->queue($this->recipients(self::KEY_NEW_APPLICATION_USERS, null), $message, 'sms_new_application', $applicationId, $cap);
        } catch (\Throwable $e) {
            error_log('StaffSmsAlerts::newApplication failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Queues the "approved - please disburse" SMS.
     *
     * @param string   $trigger        'application', 'loan' or 'loan_question'
     * @param int|null $applicationId  the online application, when the approval is about one (it identifies the client)
     * @param int|null $loanId         the loan, used to identify the client only when there is no application
     * @param int|null $approverUserId the person who approved; never texted about it
     */
    public function approved(string $trigger, ?int $applicationId, ?int $loanId, string $reference, string $clientName, ?int $approverUserId, string $approverName = ''): int
    {
        try {
            if (!$this->enabled() || !isset(self::APPROVAL_TRIGGERS[$trigger])
                || ($this->setting)(self::APPROVAL_TRIGGERS[$trigger], '1') !== '1') {
                return 0;
            }
            if ($applicationId !== null && $applicationId > 0) {
                [$table, $sourceId] = ['sms_approved', $applicationId];
            } elseif ($loanId !== null && $loanId > 0) {
                [$table, $sourceId] = ['sms_approved_loan', $loanId];
            } else {
                return 0;
            }
            $by = trim($approverName) !== '' ? ' by ' . $this->clean($approverName, 20) : '';
            $message = 'Solid Desert: loan APPROVED for ' . $this->clean($clientName, 28) . ' (' . $this->clean($reference, 30) . ')' . $by . '. Please disburse.';
            return $this->queue($this->recipients(self::KEY_APPROVED_USERS, $approverUserId), $message, $table, $sourceId);
        } catch (\Throwable $e) {
            error_log('StaffSmsAlerts::approved failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function enabled(): bool
    {
        return ($this->setting)(self::KEY_ENABLED, '1') === '1';
    }

    /** @return array<int, array{id:int,name:string,phone:string}> the people in the setting who are active, have a usable phone number, and are not $except */
    private function recipients(string $settingKey, ?int $except): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', ($this->setting)($settingKey, ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $id = (int) $raw;
            if ($id > 0 && $id !== $except) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (($this->usersById)(array_values($ids)) as $u) {
            $phone = PhoneNumberNormalizer::toE164((string) ($u['phone'] ?? ''));
            if ($phone === null || strlen($phone) < 9) {
                error_log('StaffSmsAlerts: user #' . (int) $u['id'] . ' has no usable phone number, no SMS was queued for them.');
                continue;
            }
            $out[] = ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'phone' => $phone];
        }
        return $out;
    }

    /** One queued SMS per person; the same (source table, id) is never queued twice for the same person. */
    private function queue(array $recipients, string $message, string $sourceTable, int $sourceId, ?int $dailyCap = null): int
    {
        $db = Database::connection();
        $exists = $db->prepare('SELECT 1 FROM notification_queue WHERE user_id = ? AND source_module = ? AND source_table = ? AND source_id = ? LIMIT 1');
        $today = $db->prepare('SELECT COUNT(*) FROM notification_queue WHERE user_id = ? AND source_module = ? AND source_table = ? AND created_at >= CURDATE()');
        $queue = new NotificationQueue();
        $count = 0;
        foreach ($recipients as $user) {
            $exists->execute([$user['id'], self::SOURCE_MODULE, $sourceTable, $sourceId]);
            if ($exists->fetchColumn()) {
                continue;
            }
            if ($dailyCap !== null) {
                $today->execute([$user['id'], self::SOURCE_MODULE, $sourceTable]);
                if ((int) $today->fetchColumn() >= $dailyCap) {
                    error_log('StaffSmsAlerts: daily limit of ' . $dailyCap . ' reached for user #' . $user['id'] . ', no further SMS today (the e-mail alerts continue).');
                    continue;
                }
            }
            $queue->create([
                'user_id' => $user['id'],
                'channel' => 'SMS',
                'recipient_name' => $user['name'],
                'recipient_contact' => $user['phone'],
                'subject' => '',
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

    /** @param array<int,int> $ids */
    private static function loadUsers(array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $s = Database::connection()->prepare("SELECT id, name, phone FROM users WHERE is_active = 1 AND id IN ($marks) ORDER BY id");
        $s->execute($ids);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Names and numbers come from public forms: no line breaks or control characters, and a bounded length (an SMS is short). */
    private function clean(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
        $text = (string) preg_replace('/\s+/', ' ', $text);
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '.' : $text;
    }
}
