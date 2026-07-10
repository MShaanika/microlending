<?php
namespace App\Core;

/**
 * Borrower self-service portal authentication. Deliberately separate from
 * Auth (staff): uses its own cookie + a borrower_portal_sessions row instead
 * of $_SESSION, so a staff member and a borrower can never collide on the
 * same session identity in the same browser.
 */
class PortalAuth
{
    private const COOKIE_NAME = 'MLS_PORTAL_TOKEN';
    private const TTL_DAYS = 7;

    private static ?array $cachedUser = null;
    private static bool $resolved = false;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$cachedUser;
        }
        self::$resolved = true;

        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            "SELECT pu.id AS portal_user_id, pu.borrower_id, pu.username, pu.email,
                    b.first_name, b.last_name, b.borrower_no
             FROM borrower_portal_sessions s
             JOIN borrower_portal_users pu ON pu.id = s.portal_user_id
             JOIN borrowers b ON b.id = pu.borrower_id
             WHERE s.session_token = ? AND s.expires_at > NOW() AND pu.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        self::$cachedUser = $row ?: null;
        return self::$cachedUser;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . url('/portal/login'));
            exit;
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM borrower_portal_users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$username, $username]);
        $portalUser = $stmt->fetch();

        if (!$portalUser || !password_verify($password, $portalUser['password'])) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TTL_DAYS . ' days'));

        $db->prepare(
            "INSERT INTO borrower_portal_sessions (portal_user_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $portalUser['id'],
            $token,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt,
        ]);

        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + self::TTL_DAYS * 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $db->prepare("UPDATE borrower_portal_users SET last_login = NOW() WHERE id = ?")->execute([$portalUser['id']]);

        self::$resolved = false;
        self::$cachedUser = null;

        return true;
    }

    public static function logout(): void
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (is_string($token) && $token !== '') {
            Database::connection()->prepare("DELETE FROM borrower_portal_sessions WHERE session_token = ?")->execute([$token]);
        }
        setcookie(self::COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => '/']);
        self::$cachedUser = null;
        self::$resolved = true;
    }
}
