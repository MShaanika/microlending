<?php
namespace App\Core;

class Audit
{
    public static function log(string $action, string $module, string $description): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, module_name, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                Session::get('user.id'), $action, $module, $description,
                $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (\Throwable $e) { /* keep app alive */ }
    }
}
