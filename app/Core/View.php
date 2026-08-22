<?php
namespace App\Core;

class View
{
    public static function render(string $view, array $data = []): void
    {
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
        extract($data, EXTR_SKIP);
        $file = VIEW_PATH . '/' . $view . '.php.content';
        if (!is_file($file)) { echo "Fragment not found: " . htmlspecialchars($view); return; }
        require $file;
    }
}
