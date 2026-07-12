<?php

namespace App\Services;

use App\Models\NotificationSetting;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends via SMTP using whatever's saved in notification_settings (channel
 * Email). Never throws -- returns success:false with a real error message
 * so callers (NotificationController::sendNow()) can fall back to the
 * existing manual mark-as-sent/failed flow instead of crashing, same
 * graceful-failure shape as DocumentGenerationService.
 */
class EmailSenderService
{
    /**
     * @return array{success: bool, providerReference: ?string, error: ?string}
     */
    public static function send(string $to, string $subject, string $body, ?string $toName = null): array
    {
        $settings = new NotificationSetting();
        $host = $settings->get('SMTP_HOST');

        if ($host === '') {
            return ['success' => false, 'providerReference' => null, 'error' => 'Email is not configured yet -- add SMTP settings first.'];
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) $settings->get('SMTP_PORT', '587');
            $mail->SMTPAuth = true;
            $mail->Username = $settings->get('SMTP_USERNAME');
            $mail->Password = $settings->get('SMTP_PASSWORD');

            $encryption = $settings->get('SMTP_ENCRYPTION', 'tls');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'none') {
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom(
                $settings->get('SMTP_FROM_EMAIL') ?: $settings->get('SMTP_USERNAME'),
                $settings->get('SMTP_FROM_NAME', 'DesertLedger')
            );
            $mail->addAddress($to, $toName ?? '');
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);

            $mail->send();

            return ['success' => true, 'providerReference' => null, 'error' => null];
        } catch (PHPMailerException $e) {
            return ['success' => false, 'providerReference' => null, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
        }
    }
}
