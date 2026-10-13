<?php
namespace App\Core;

class View
{
    /**
     * Every dynamic page goes through here with zero cache-prevention
     * headers until now -- on a host with page-level caching enabled
     * (e.g. LiteSpeed Cache, present on this app's production hosting),
     * or simply via a browser's own back/forward cache, a per-resource
     * admin page like /settings/users/{id}/edit could be served stale:
     * staff navigates to a different user's Edit page, the browser/host
     * serves back an old cached render for a DIFFERENT id instead of
     * fetching fresh, the form's own action URL (baked into that stale
     * HTML) still points at the old id, and saving silently updates the
     * wrong user. Root-caused 2026-10-13 from a real "edit saves to a
     * different user" report with no reproducible bug anywhere in
     * UserController/User model/the edit views themselves -- this was the
     * one place common to every page that had no explicit opt-out.
     */
    private static function sendNoCacheHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public static function render(string $view, array $data = []): void
    {
        self::sendNoCacheHeaders();
        extract($data, EXTR_SKIP);
        $file = VIEW_PATH . '/' . $view . '.php';
        if (!is_file($file)) { echo "View not found: " . htmlspecialchars($view); return; }
        require $file;
    }

    /**
     * Renders a view's .php.content fragment directly (no .php wrapper, so
     * no layout chrome) -- the AJAX-modal counterpart to render(). Naming
     * mirrors the existing convention exactly: {view}.php.content, not
     * {view}.content.php.
     */
    public static function renderFragment(string $view, array $data = []): void
    {
        self::sendNoCacheHeaders();
        extract($data, EXTR_SKIP);
        $file = VIEW_PATH . '/' . $view . '.php.content';
        if (!is_file($file)) { echo "Fragment not found: " . htmlspecialchars($view); return; }
        require $file;
    }
}
