<?php

namespace App\Core;

/**
 * Records a row to security_events -- the input signal for
 * SecurityRuleEngine, distinct from Audit::log()'s by-user/module
 * compliance trail (see database/security_monitoring_module.sql's
 * header comment for why these are two separate tables). Some call
 * sites (e.g. login) reasonably call both, for different reasons.
 *
 * Swallow-safe like Audit::log() -- a security-event write must never be
 * able to break the request that triggered it -- but unlike Audit::log(),
 * also error_log()s the throwable, since a fully-silent failure here would
 * blind the rules engine with no trace at all.
 */
class SecurityEvent
{
    /**
     * $context keys: user_id, attempted_login, description, metadata
     * (array), risk_score, ip (overrides ClientIp::resolve() if already
     * known by the caller).
     */
    public static function record(string $eventType, string $severity, array $context = []): int
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                "INSERT INTO security_events
                    (event_type, severity, user_id, attempted_login, ip_address, user_agent, request_path, description, metadata, correlation_id, risk_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $eventType,
                $severity,
                $context['user_id'] ?? null,
                isset($context['attempted_login']) ? self::normalizeLogin((string) $context['attempted_login']) : null,
                $context['ip'] ?? ClientIp::resolve(),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
                substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
                $context['description'] ?? null,
                isset($context['metadata']) ? json_encode($context['metadata']) : null,
                Correlation::id(),
                (int) ($context['risk_score'] ?? 0),
            ]);
            return (int) $db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('SecurityEvent::record failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** Trim+lowercase, applied consistently everywhere a login string is stored or compared, so a case/whitespace variant can't dodge a block. */
    public static function normalizeLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }
}
