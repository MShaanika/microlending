<?php

namespace App\Core;

use App\Models\SystemError;

/**
 * Deduplicated technical error tracking (Part 5-6). Fingerprint =
 * exception class + source file + line, mirroring
 * SecurityIncident::createOrAppend()'s already-proven "reuse the
 * existing row, bump a counter" pattern in this codebase -- a bug
 * thrown by 500 requests in a row becomes one row with
 * occurrence_count=500, not 500 rows.
 *
 * Swallow-safe like Audit::log()/SecurityEvent::record() -- capturing
 * an error must never itself throw and mask the original problem --
 * but also error_log()s on failure, since a silently-broken error
 * tracker would be worse than useless.
 */
class ErrorTrackingService
{
    /** @return array{error_uuid: ?string, is_new: bool} */
    public static function capture(\Throwable $e, string $severity = 'High'): array
    {
        try {
            $fingerprint = self::fingerprint($e);
            $model = new SystemError();
            $existing = $model->findByFingerprint($fingerprint);
            $correlationId = Correlation::id();

            if ($existing) {
                $model->bumpOccurrence((int) $existing['id'], $correlationId);
                return ['error_uuid' => $existing['error_uuid'], 'is_new' => false];
            }

            $errorUuid = self::uuid();
            $errorId = $model->create([
                'error_uuid' => $errorUuid,
                'fingerprint' => $fingerprint,
                'correlation_id' => $correlationId,
                'user_id' => self::currentUserId(),
                'module' => self::guessModule(),
                'route' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'error_type' => self::classifyType($e),
                'exception_class' => get_class($e),
                'safe_message' => substr($e->getMessage(), 0, 2000),
                'source_file' => $e->getFile(),
                'source_line' => $e->getLine(),
                'environment' => php_sapi_name() === 'cli' ? 'cli' : 'web',
                'severity' => $severity,
            ]);

            if ($severity === 'Critical') {
                self::escalateToException($errorId, $e);
            }

            return ['error_uuid' => $errorUuid, 'is_new' => true];
        } catch (\Throwable $inner) {
            error_log('ErrorTrackingService::capture failed: ' . $inner->getMessage());
            return ['error_uuid' => null, 'is_new' => false];
        }
    }

    private static function escalateToException(int $errorId, \Throwable $e): void
    {
        try {
            $exceptionId = \App\Services\ExceptionService::create(
                'system_error',
                'Technical',
                self::guessModule() ?? 'Platform',
                'Critical',
                'Critical system error: ' . substr($e->getMessage(), 0, 500),
                'system_error',
                $errorId
            );
            (new SystemError())->linkException($errorId, $exceptionId);
        } catch (\Throwable $inner) {
            error_log('ErrorTrackingService::escalateToException failed: ' . $inner->getMessage());
        }
    }

    private static function fingerprint(\Throwable $e): string
    {
        return hash('sha256', get_class($e) . '|' . $e->getFile() . '|' . $e->getLine());
    }

    private static function classifyType(\Throwable $e): string
    {
        return match (true) {
            $e instanceof \PDOException => 'DATABASE',
            $e instanceof \TypeError, $e instanceof \ArgumentCountError => 'TYPE',
            $e instanceof \ErrorException => 'RUNTIME',
            default => 'EXCEPTION',
        };
    }

    /** Best-effort module guess from the request path's first segment -- e.g. /loans/123 -> "Loans". Deliberately approximate; source_file/line are the precise reference for an admin investigating. */
    private static function guessModule(): ?string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        $segments = array_values(array_filter(explode('/', $path)));
        // Drop a leading project-base segment if this app isn't at domain root.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $baseSegments = array_values(array_filter(explode('/', dirname(dirname($script)))));
        $segments = array_slice($segments, count($baseSegments));

        return isset($segments[0]) ? ucfirst($segments[0]) : null;
    }

    private static function currentUserId(): ?int
    {
        try {
            $user = Session::get('user');
            return $user['id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
