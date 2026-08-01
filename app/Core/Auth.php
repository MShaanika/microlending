<?php
namespace App\Core;

class Auth
{
    public static function check(): bool { return (bool) Session::get('user'); }
    public static function user(): ?array { return Session::get('user'); }
    public static function requireLogin(): void { if (!self::check()) { header('Location: ' . url('/login')); exit; } }

    public static function can(string $permissionKey): bool
    {
        $user = self::user();
        return $user && in_array($permissionKey, $user['permissions'] ?? [], true);
    }

    /**
     * requireLogin() + a permission_key check, in one call. Redirects to
     * the dashboard with a flash error rather than a dedicated 403 page,
     * matching this app's existing flash-then-redirect convention.
     */
    public static function authorize(string $permissionKey): void
    {
        self::requireLogin();
        if (!self::can($permissionKey)) {
            Session::flash('error', 'You do not have permission to do that.');
            header('Location: ' . url('/dashboard'));
            exit;
        }
    }

    public static function attempt(string $login, string $password): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) return false;

        if (!self::ipAllowed($user)) {
            Session::flash('error', 'You cannot log in from this location. Contact your administrator if you believe this is a mistake.');
            return false;
        }

        $employee = (new \App\Models\HrmEmployee())->findByUserId((int) $user['id']);
        if ($employee && (new \App\Models\HrmLeaveApplication())->isOnApprovedLeave((int) $employee['id'], date('Y-m-d'))) {
            Session::flash('error', 'You are on approved leave and cannot log in until it ends.');
            return false;
        }

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

    /**
     * Branch-based login IP allow-listing. Super Admin/Admin and anyone
     * flagged bypass_ip_restriction are always allowed (an admin's own
     * network is never restricted, and that flag is how an admin grants
     * a specific user "login from anywhere"). A user with no branch, or
     * whose branch has zero configured ranges, is unrestricted -- IP
     * restriction only activates once an admin deliberately adds at
     * least one range for that branch.
     */
    private static function ipAllowed(array $user): bool
    {
        if (in_array($user['user_type'] ?? '', ['Super Admin', 'Admin'], true)) {
            return true;
        }
        if (!empty($user['bypass_ip_restriction'])) {
            return true;
        }
        if (empty($user['branch_id'])) {
            return true;
        }

        $ranges = (new \App\Models\BranchLoginIpRange())->forBranch((int) $user['branch_id']);
        if (empty($ranges)) {
            return true;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        return \App\Services\IpAddressMatcher::matchesAny($clientIp, $ranges);
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
