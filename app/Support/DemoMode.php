<?php

namespace App\Support;

use App\Core\Auth;

/**
 * Sales-demo instance switch (config/app.php 'demo_mode'). Off everywhere
 * except the demo site.
 *
 * In demo mode the shared prospect login can use every business screen but
 * never sees anything that exposes integration credentials, API tokens,
 * backups or user/role administration -- those screens are removed from the
 * menu and refused if reached by URL. Only the "owner" login keeps them.
 */
final class DemoMode
{
    public const MESSAGE = 'Disabled in the demo environment -- no external services are contacted.';

    public const OWNER_USERNAME = 'owner';

    /** Path fragments hidden from everyone except the owner login. */
    private const HIDDEN_PATHS = [
        '/collexia/settings',
        '/creditinfo/settings',
        '/creditinfo/public-defaults/settings',
        '/notifications/settings',
        '/settings/intake-sources',
        '/settings/ai',
        '/hrm/zoom-settings',
        '/settings/users',
        '/settings/roles',
        '/settings/permissions',
        '/continuity/backup-now',
        '/profile/password',
    ];

    public static function enabled(): bool
    {
        static $enabled = null;
        if ($enabled === null) {
            $config = require ROOT_PATH . '/config/app.php';
            $enabled = !empty($config['demo_mode']) || getenv('MLS_DEMO_MODE') === '1';
        }
        return $enabled;
    }

    /**
     * The demo may only talk to a vendor's test environment. A production
     * URL (live debit orders / real bureau enquiries) is refused outright.
     */
    public static function isSandboxUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (['uat', 'stage', 'staging', 'sandbox', 'test', 'demo'] as $marker) {
            if (str_contains($host, $marker)) {
                return true;
            }
        }
        return false;
    }

    public static function isOwner(): bool
    {
        return (Auth::user()['username'] ?? null) === self::OWNER_USERNAME;
    }

    public static function isPathHidden(string $path): bool
    {
        if (!self::enabled() || self::isOwner()) {
            return false;
        }
        foreach (self::HIDDEN_PATHS as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }
        return false;
    }
}
