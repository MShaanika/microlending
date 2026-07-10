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
}
