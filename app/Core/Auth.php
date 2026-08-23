<?php
namespace App\Core;

class Auth
{
    private const REMEMBER_COOKIE = 'mls_remember';
    private const REMEMBER_DAYS = 30;

    public static function check(): bool { return (bool) Session::get('user'); }
    public static function user(): ?array { return Session::get('user'); }
    public static function requireLogin(): void { if (!self::check()) { header('Location: ' . url('/login')); exit; } }

    public static function can(string $permissionKey): bool
    {
        $user = self::user();
        return $user && in_array($permissionKey, $user['permissions'] ?? [], true);
    }

    /**
     * A marketing/commission agent's home is their referrals portal, not
     * the staff dashboard -- used for the post-login redirect and every
     * "take me home" link in the shared layout (logo, sidebar Dashboard
     * item, breadcrumb Home) so an agent is never one click away from a
     * staff-only screen they have no permissions to actually use.
     */
    public static function homePath(): string
    {
        $employee = (new \App\Models\HrmEmployee())->findByUserId((int) (self::user()['id'] ?? 0));
        if ($employee && (int) $employee['is_commission_agent'] === 1) {
            return '/my/referrals';
        }
        return '/dashboard';
    }

    /**
     * Only Super Admin bypasses branch data scoping -- deliberately
     * narrower than ipAllowed()'s Super Admin + Admin exemption, which is
     * a separate, unrelated gate (network access vs. data visibility).
     */
    public static function isSuperAdmin(): bool
    {
        $user = self::user();
        return $user && ($user['user_type'] ?? '') === 'Super Admin';
    }

    /**
     * A developer's active support session (see startSupportSession/
     * clearSupportSession) overrides the user's real branch_id here, so
     * every existing scopeBranchId()/indexBranchId() helper across the app
     * -- all of which call this method -- picks up the granted branch
     * automatically with no further code changes. isSuperAdmin() is
     * untouched, so a Developer is never treated as unrestricted; they are
     * only ever scoped to the single branch of their active session.
     */
    public static function branchId(): ?int
    {
        $session = self::activeSupportSession();
        if ($session !== null) {
            return (int) $session['branch_id'];
        }
        $user = self::user();
        return $user['branch_id'] ?? null;
    }

    /**
     * Expiry is computed here at read time by comparing against now --
     * deliberately no cron/cleanup job. A session past its expires_at with
     * no ended_at is simply treated as inactive.
     */
    public static function activeSupportSession(): ?array
    {
        $session = Session::get('support_session');
        if (!$session) {
            return null;
        }
        if (strtotime($session['expires_at']) <= time()) {
            return null;
        }
        return $session;
    }

    public static function setSupportSession(array $session): void
    {
        Session::put('support_session', $session);
    }

    public static function clearSupportSession(): void
    {
        Session::forget('support_session');
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

    /**
     * Governs the "Login As" feature (Settings > Users list): who may swap
     * into which target identity. users.login_as_any (bootstrap-granted to
     * Super Admin, see database/user_impersonation.sql) is unrestricted --
     * a real Developer role gets this via Settings > Roles. Everyone else,
     * including Super Admin/Admin without that permission, may only step
     * into a Marketing Agent ("Sales Agent") account, never another staff
     * member's.
     */
    public static function canLoginAsUser(array $target): bool
    {
        $actor = self::user();
        if (!$actor || (int) ($target['id'] ?? 0) === (int) $actor['id']) {
            return false;
        }
        if (self::can('users.login_as_any')) {
            return true;
        }
        if (self::isSuperAdmin() || ($actor['user_type'] ?? '') === 'Admin') {
            return ($target['user_type'] ?? '') === 'Marketing Agent';
        }
        return false;
    }

    /**
     * Swaps the active session's identity to $targetUserId, stashing the
     * real user under 'impersonator' so stopImpersonation() can restore it.
     * Nested impersonation is refused -- an impersonated session can't
     * itself start impersonating a third user.
     */
    public static function startImpersonation(int $targetUserId): bool
    {
        $actor = self::user();
        if (!$actor || self::isImpersonating()) {
            return false;
        }

        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if (!$target || !self::canLoginAsUser($target)) {
            return false;
        }

        Audit::log('Impersonate', 'Security', "Logged in as {$target['name']} (#{$target['id']})");
        Session::put('impersonator', $actor);
        self::loginSession($target);
        return true;
    }

    /** Restores the real user saved by startImpersonation(). No-op (returns false) if not currently impersonating. */
    public static function stopImpersonation(): bool
    {
        $original = Session::get('impersonator');
        if (!$original) {
            return false;
        }

        $current = self::user();
        Audit::log('Impersonate', 'Security', 'Returned to own account, ending login-as of ' . ($current['name'] ?? ''));
        Session::forget('impersonator');
        Session::put('user', $original);
        return true;
    }

    public static function isImpersonating(): bool
    {
        return (bool) Session::get('impersonator');
    }

    public static function impersonator(): ?array
    {
        return Session::get('impersonator');
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

        self::loginSession($user);
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        Audit::log('Login', 'Security', 'User logged in successfully');
        return true;
    }

    /** Shared by attempt() and attemptRememberLogin() so both populate the exact same session shape. */
    private static function loginSession(array $user): void
    {
        session_regenerate_id(true);
        Session::put('user', [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'username' => $user['username'],
            'user_type' => $user['user_type'],
            'branch_id' => $user['branch_id'] !== null ? (int) $user['branch_id'] : null,
            'permissions' => self::permissions((int)$user['id'])
        ]);
    }

    /**
     * Issues a persistent "remember me" cookie for the currently logged-in
     * user, valid for REMEMBER_DAYS after the PHP session itself expires.
     * Selector/validator pair (not one bare token): the selector is looked
     * up directly and cheap, the validator is only ever compared via
     * hash_equals() against its stored hash -- so neither a slow table scan
     * nor a timing attack is possible, and a leaked DB dump alone can't be
     * replayed as a cookie. Call only right after a successful attempt().
     */
    public static function remember(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::REMEMBER_DAYS . ' days'));

        (new \App\Models\UserRememberToken())->create($userId, $selector, hash('sha256', $validator), $expiresAt);
        self::setRememberCookie($selector, $validator);
    }

    /**
     * Called once per request, before routing (see bootstrap/app.php) --
     * a no-op if a session is already active or no remember cookie is
     * present. On success, re-establishes the session exactly as attempt()
     * would and rotates the token (old row deleted, new one issued) so a
     * stolen cookie value stops working the moment the real owner's
     * browser uses it again.
     */
    public static function attemptRememberLogin(): void
    {
        if (self::check()) {
            return;
        }

        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!str_contains($cookie, '.')) {
            return;
        }
        [$selector, $validator] = explode('.', $cookie, 2);

        $tokens = new \App\Models\UserRememberToken();
        $record = $tokens->findValidBySelector($selector);
        if (!$record || !hash_equals($record['validator_hash'], hash('sha256', $validator))) {
            self::clearRememberCookie();
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$record['user_id']]);
        $user = $stmt->fetch();

        $tokens->deleteBySelector($selector);
        if (!$user) {
            self::clearRememberCookie();
            return;
        }

        self::loginSession($user);
        self::remember((int) $user['id']);
        Audit::log('Login', 'Security', 'User logged in via remember-me cookie');
    }

    private static function setRememberCookie(string $selector, string $validator): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        setcookie(self::REMEMBER_COOKIE, $selector . '.' . $validator, [
            'expires' => time() + self::REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        if (!isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }
        unset($_COOKIE[self::REMEMBER_COOKIE]);
        setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
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

    /** Only this device's remember token is revoked -- other devices the user chose to stay logged in on are untouched. */
    public static function logout(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (str_contains($cookie, '.')) {
            [$selector] = explode('.', $cookie, 2);
            (new \App\Models\UserRememberToken())->deleteBySelector($selector);
        }
        self::clearRememberCookie();
        self::clearSupportSession();
        Audit::log('Logout', 'Security', 'User logged out');
        Session::destroy();
    }
}
