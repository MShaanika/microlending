<?php
namespace App\Core;

class Auth
{
    public static function check(): bool { return (bool) Session::get('user'); }
    public static function user(): ?array { return Session::get('user'); }
    public static function requireLogin(): void { if (!self::check()) { header('Location: ' . url('/login')); exit; } }

    /**
     * Not yet enforced anywhere -- existing controllers still gate on
     * requireLogin() alone. Available for callers that want to start
     * checking a specific permission_key from the catalog managed under
     * Settings > Roles.
     */
    public static function can(string $permissionKey): bool
    {
        $user = self::user();
        return $user && in_array($permissionKey, $user['permissions'] ?? [], true);
    }

    public static function attempt(string $login, string $password): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) return false;
        session_regenerate_id(true);
        Session::put('user', [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'username' => $user['username'],
            'permissions' => self::permissions((int)$user['id'])
        ]);
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        Audit::log('Login', 'Security', 'User logged in successfully');
        return true;
    }

    private static function permissions(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT p.permission_key FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id=p.id INNER JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?");
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(), 'permission_key');
    }

    public static function logout(): void { Audit::log('Logout', 'Security', 'User logged out'); Session::destroy(); }
}
