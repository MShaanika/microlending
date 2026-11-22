<?php

namespace App\Services;

use App\Core\Database;
use App\Models\NotificationQueue;
use App\Models\NotificationSetting;
use App\Support\PhoneNumberNormalizer;

/**
 * Texts an applicant what to fix on their application (e.g. "your date of
 * birth"), when staff click "Request correction". The text never contains
 * the values themselves (no ID number, no DOB) -- only WHAT to fix and how
 * to reach Solid Desert -- so a misdirected message leaks nothing.
 *
 * Goes through notification_queue (dispatcher cron sends it, with the
 * existing Twilio sender) so a failed send is retried and visible.
 * Returns ['queued' => bool, 'note' => string]; the note is shown to staff
 * verbatim, so every refusal says exactly why.
 */
final class ApplicantCorrectionSms
{
    public const SOURCE_MODULE = 'Applicant SMS';
    public const KEY_ENABLED = 'SMS_CORRECTION_ENABLED';
    public const KEY_CONTACT = 'SMS_CORRECTION_CONTACT';
    public const KEY_MAX_PER_APPLICATION = 'SMS_CORRECTION_MAX_PER_APPLICATION';
    public const KEY_COOLDOWN_HOURS = 'SMS_CORRECTION_COOLDOWN_HOURS';

    /** code => short phrase used in the SMS */
    public const REASONS = [
        'dob' => 'date of birth',
        'id_number' => 'ID number',
        'first_name' => 'first name',
        'last_name' => 'surname',
        'gender' => 'gender',
        'phone' => 'mobile number',
        'documents' => 'missing documents',
        'payslip' => 'payslip',
        'bank_statement' => 'bank statement',
        'address' => 'address',
        'other' => 'some details',
    ];

    private $setting;

    /** @param null|callable(string,string):string $setting */
    public function __construct(?callable $setting = null)
    {
        $this->setting = $setting ?? static fn (string $key, string $default): string => (string) (new NotificationSetting())->get($key, $default);
    }

    /**
     * @param array<int,string> $reasonCodes keys of self::REASONS
     */
    public function request(int $applicationId, string $applicationNo, string $applicantName, ?string $phone, array $reasonCodes, ?string $note, ?int $staffUserId, ?int $borrowerId = null, string $status = 'Submitted'): array
    {
        try {
            if (($this->setting)(self::KEY_ENABLED, '1') !== '1') {
                return ['queued' => false, 'note' => 'Applicant SMS is switched off in settings, so no text was sent. Phone the applicant instead.'];
            }
            if (in_array($status, ['Approved', 'Converted to Loan', 'Rejected', 'Cancelled'], true)) {
                return ['queued' => false, 'note' => 'This application is already ' . $status . ', so no correction text was sent.'];
            }
            $labels = [];
            foreach ($reasonCodes as $code) {
                if (isset(self::REASONS[$code])) {
                    $labels[self::REASONS[$code]] = true;
                }
            }
            if (!$labels) {
                return ['queued' => false, 'note' => 'Choose at least one thing for the applicant to correct.'];
            }
            $contact = trim(($this->setting)(self::KEY_CONTACT, ''));
            if ($contact === '') {
                return ['queued' => false, 'note' => 'No contact number is set for correction texts (setting SMS_CORRECTION_CONTACT), so no text was sent. Ask the administrator to set it, or phone the applicant.'];
            }
            $e164 = PhoneNumberNormalizer::toE164($phone);
            if ($e164 === null || !preg_match('/^\+\d{9,15}$/', $e164)) {
                return ['queued' => false, 'note' => 'The application has no usable phone number, so no text was sent. Phone or visit the applicant instead.'];
            }

            $db = Database::connection();
            $fingerprint = 'sms_correction:' . substr(md5(implode(',', array_keys($labels)) . '|' . trim((string) $note)), 0, 8);
            $cooldown = max(1, (int) ($this->setting)(self::KEY_COOLDOWN_HOURS, '24'));
            $dup = $db->prepare('SELECT 1 FROM notification_queue WHERE source_module = ? AND source_id = ? AND source_table = ? AND created_at >= (NOW() - INTERVAL ' . $cooldown . ' HOUR) LIMIT 1');
            $dup->execute([self::SOURCE_MODULE, $applicationId, $fingerprint]);
            if ($dup->fetchColumn()) {
                return ['queued' => false, 'note' => 'The same correction text was already sent to this applicant in the last ' . $cooldown . ' hours. Wait, or choose different items.'];
            }
            $total = $db->prepare('SELECT COUNT(*) FROM notification_queue WHERE source_module = ? AND source_id = ? AND source_table LIKE ?');
            $total->execute([self::SOURCE_MODULE, $applicationId, 'sms_correction:%']);
            $max = max(1, (int) ($this->setting)(self::KEY_MAX_PER_APPLICATION, '3'));
            if ((int) $total->fetchColumn() >= $max) {
                return ['queued' => false, 'note' => 'This applicant has already been texted ' . $max . ' times about corrections. Please phone them instead.'];
            }

            $message = self::buildMessage($applicationNo, array_keys($labels), $contact, $note);
            (new NotificationQueue())->create([
                'borrower_id' => $borrowerId ?: null,
                'user_id' => null,
                'channel' => 'SMS',
                'recipient_name' => self::clean($applicantName, 60),
                'recipient_contact' => $e164,
                'subject' => '',
                'message' => $message,
                'source_module' => self::SOURCE_MODULE,
                'source_table' => $fingerprint,
                'source_id' => $applicationId,
                'status' => 'Pending',
                'created_by' => $staffUserId,
            ]);
            return ['queued' => true, 'note' => 'Text queued to the applicant (goes out within about a minute): "' . $message . '"'];
        } catch (\Throwable $e) {
            error_log('ApplicantCorrectionSms: ' . $e->getMessage());
            return ['queued' => false, 'note' => 'The text could not be queued (' . $e->getMessage() . '). Phone the applicant instead.'];
        }
    }

    /** Pure and testable. Always plain GSM-7 text and at most 160 characters; never cut mid-word. */
    public static function buildMessage(string $applicationNo, array $labels, string $contact, ?string $note = null): string
    {
        $no = self::clean($applicationNo, 24);
        $contact = self::clean($contact, 20);
        $join = static function (array $l): string {
            $last = array_pop($l);
            return $l ? implode(', ', $l) . ' and ' . $last : (string) $last;
        };
        $noteText = $note !== null && trim($note) !== '' ? ' (' . self::clean($note, 40) . ')' : '';
        $build = static fn (string $what, string $n): string => 'Solid Desert: application ' . $no . ' needs a fix: ' . $what . $n . '. Call ' . $contact . '.';

        $list = array_values($labels);
        foreach ([$noteText, ''] as $n) {
            $msg = $build($join($list), $n);
            if (strlen($msg) <= 160) {
                return $msg;
            }
        }
        // Too many items: keep the first ones and say "more", then finally fall back to a generic sentence.
        for ($keep = count($list) - 1; $keep >= 1; $keep--) {
            $msg = $build($join(array_merge(array_slice($list, 0, $keep), ['more'])), '');
            if (strlen($msg) <= 160) {
                return $msg;
            }
        }
        $msg = 'Solid Desert: application ' . $no . ' needs corrections to your details. Call ' . $contact . '.';
        return strlen($msg) <= 160 ? $msg : 'Solid Desert: your application needs corrections. Call ' . $contact . '.';
    }

    /** Strip control characters/URLs, transliterate to plain ASCII (keeps the SMS in one GSM-7 segment), cap length. */
    public static function clean(string $text, int $max): string
    {
        $text = preg_replace('~https?://\S+|www\.\S+~i', '', $text) ?? '';
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = $t !== false ? $t : preg_replace('/[^\x20-\x7E]/', '', $text);
        $text = preg_replace('/[^A-Za-z0-9 .,\-\/()+:]/', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return substr($text, 0, $max);
    }
}
