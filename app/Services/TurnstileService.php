<?php

namespace App\Services;

/**
 * Cloudflare Turnstile verification for the login/forgot-password forms.
 * Site key is safe to expose client-side; the secret key stays server-side
 * only and is read from config/services.php (real value applied directly
 * on production, never committed -- same handling as the DB password).
 */
class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function siteKey(): string
    {
        return self::config()['site_key'] ?? '';
    }

    public static function verify(?string $token, ?string $remoteIp = null): bool
    {
        $secret = self::config()['secret_key'] ?? '';
        if (!$token || $token === '' || !$secret) {
            return false;
        }

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('Turnstile verification request failed: ' . $curlError);
            return false;
        }

        $data = json_decode($response, true);
        return !empty($data['success']);
    }

    private static function config(): array
    {
        $config = require ROOT_PATH . '/config/services.php';
        return $config['turnstile'] ?? [];
    }
}
