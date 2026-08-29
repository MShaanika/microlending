<?php
namespace App\Core;

class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf_token'])) $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }
    public static function verifyCsrf(?string $token): bool
    {
        $valid = is_string($token) && hash_equals($_SESSION['_csrf_token'] ?? '', $token);
        if (!$valid) {
            // The one true chokepoint for this signal -- 115 controllers call
            // this function directly, none call each other, so instrumenting
            // it here is the only way to observe CSRF failures centrally.
            SecurityEvent::record('CSRF_FAILURE', 'Low', [
                'user_id' => Session::get('user')['id'] ?? null,
                'description' => 'Invalid or missing CSRF token',
            ]);
        }
        return $valid;
    }
    public static function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
