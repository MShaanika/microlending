<?php

namespace App\Services;

use App\Models\NotificationSetting;

/**
 * Sends via Twilio's REST API using raw curl (Basic Auth), rather than
 * pulling in the twilio/sdk package, to match this app's existing
 * minimal-dependency style. Never throws -- returns success:false with
 * Twilio's real error text so callers can fall back to the manual queue.
 */
class SmsSenderService
{
    /**
     * @return array{success: bool, providerReference: ?string, error: ?string}
     */
    public static function send(string $toPhone, string $message): array
    {
        $settings = new NotificationSetting();
        $sid = $settings->get('TWILIO_ACCOUNT_SID');
        $token = $settings->get('TWILIO_AUTH_TOKEN');
        $from = $settings->get('TWILIO_FROM_NUMBER');

        if ($sid === '' || $token === '') {
            return ['success' => false, 'providerReference' => null, 'error' => 'SMS is not configured yet -- add Twilio settings first.'];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $sid . ':' . $token,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $toPhone,
                'From' => $from,
                'Body' => $message,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'providerReference' => null, 'error' => 'Could not reach Twilio: ' . $curlError];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && isset($data['sid'])) {
            return ['success' => true, 'providerReference' => $data['sid'], 'error' => null];
        }

        $error = $data['message'] ?? ('Twilio returned HTTP ' . $httpCode);
        if (isset($data['code'])) {
            $error .= ' (code ' . $data['code'] . ')';
        }

        return ['success' => false, 'providerReference' => null, 'error' => $error];
    }
}
