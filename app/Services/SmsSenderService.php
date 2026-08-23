<?php

namespace App\Services;

use App\Models\NotificationSetting;

/**
 * Sends SMS using a Twilio Messaging Service.
 *
 * Twilio automatically selects the best sender from the Sender Pool
 * (e.g. SOLIDDESERT Alpha Sender ID or a phone number if required).
 */
class SmsSenderService
{
    /**
     * @return array{
     *     success: bool,
     *     providerReference: ?string,
     *     error: ?string
     * }
     */
    public static function send(string $toPhone, string $message): array
    {
        $settings = new NotificationSetting();

        $sid = trim((string)$settings->get('TWILIO_ACCOUNT_SID'));
        $token = trim((string)$settings->get('TWILIO_AUTH_TOKEN'));
        $messagingServiceSid = trim((string)$settings->get('TWILIO_MESSAGING_SERVICE_SID'));

        if ($sid === '' || $token === '' || $messagingServiceSid === '') {
            return [
                'success' => false,
                'providerReference' => null,
                'error' => 'SMS is not configured correctly. Please configure your SMS provider credentials under Notification Settings.'
            ];
        }

        // Convert Namibian numbers to international format if needed
        $toPhone = self::formatPhoneNumber($toPhone);

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $sid . ':' . $token,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $toPhone,
                'MessagingServiceSid' => $messagingServiceSid,
                'Body' => $message,
            ]),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'providerReference' => null,
                'error' => 'Could not reach the SMS provider: ' . $curlError
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($data['sid'])) {
            return [
                'success' => true,
                'providerReference' => $data['sid'],
                'error' => null
            ];
        }

        $error = $data['message'] ?? ('Twilio returned HTTP ' . $httpCode);

        if (isset($data['code'])) {
            $error .= ' (Code ' . $data['code'] . ')';
        }

        return [
            'success' => false,
            'providerReference' => null,
            'error' => $error
        ];
    }

    /**
     * Converts phone numbers to E.164 format.
     * Examples:
     * 0813913464     -> +264813913464
     * 264813913464   -> +264813913464
     * +264813913464  -> +264813913464
     */
    private static function formatPhoneNumber(string $phone): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (strpos($phone, '+') === 0) {
            return $phone;
        }

        if (strpos($phone, '264') === 0) {
            return '+' . $phone;
        }

        if (strpos($phone, '0') === 0) {
            return '+264' . substr($phone, 1);
        }

        return $phone;
    }
}