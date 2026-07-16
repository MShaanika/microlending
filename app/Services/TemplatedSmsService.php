<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;

/**
 * Sends a single templated SMS for a one-time system event (application
 * approved/rejected, refund claim approved, etc.) -- merges the named
 * notification_templates row against the given context, sends it, and logs
 * the result to notification_logs so it shows up in the same history as
 * manually-composed notifications. Never throws: a missing/inactive
 * template or blank phone degrades to a "not sent" note the caller can
 * report, matching the existing graceful-skip convention used around loan
 * application approval.
 */
class TemplatedSmsService
{
    /**
     * @return array{sent: bool, note: string}
     */
    public static function send(string $templateCode, string $phone, array $context, ?int $borrowerId, ?int $userId): array
    {
        if ($phone === '') {
            return ['sent' => false, 'note' => 'no phone number on file -- SMS not sent'];
        }

        $template = (new NotificationTemplate())->findByCode($templateCode);
        if (!$template) {
            return ['sent' => false, 'note' => "SMS template $templateCode is missing or inactive -- SMS not sent"];
        }

        $message = NotificationMergeService::render($template['message_body'], $context);
        $result = SmsSenderService::send($phone, $message);

        (new NotificationLog())->create([
            'notification_id' => null,
            'borrower_id' => $borrowerId,
            'user_id' => $userId,
            'channel' => 'SMS',
            'recipient_contact' => $phone,
            'message' => $message,
            'status' => $result['success'] ? 'Sent' : 'Failed',
            'provider_reference' => $result['providerReference'],
            'response_message' => $result['error'],
            'sent_at' => $result['success'] ? date('Y-m-d H:i:s') : null,
        ]);

        return [
            'sent' => $result['success'],
            'note' => $result['success'] ? ('SMS sent to ' . $phone) : ('SMS not sent (' . $result['error'] . ')'),
        ];
    }
}
