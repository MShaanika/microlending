<?php
namespace App\Core;

class Audit
{
    /**
     * $metadata/$referenceKey are optional and additive -- every existing
     * 3-arg call site keeps working unchanged. $referenceKey is the
     * idempotency key / draft UUID an event relates to, for fast
     * cross-referencing without parsing $metadata's JSON.
     */
    public static function log(string $action, string $module, string $description, array $metadata = [], ?string $referenceKey = null): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, module_name, description, metadata, reference_key, correlation_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                Session::get('user')['id'] ?? null, $action, $module, $description,
                $metadata ? json_encode($metadata) : null, $referenceKey, Correlation::id(),
                ClientIp::resolve(), $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (\Throwable $e) { /* keep app alive */ }
    }
}
