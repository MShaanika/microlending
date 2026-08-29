<?php

namespace App\Core;

/**
 * Central exception/error/fatal handler (Part 4-6). Registered once from
 * bootstrap/app.php, replacing PHP's own display_errors output entirely --
 * this is deliberately the ONLY place deciding what a broken request shows.
 *
 * Every method here must survive the DB/session/anything else already
 * being broken, since that's exactly the situation it's most likely to run
 * in -- each layer is wrapped so a secondary failure degrades to plainer
 * output instead of leaking PHP's own raw fatal error.
 */
class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** @return bool true = handled, suppress PHP's own reporting for this error */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return true;
        }

        if (in_array($severity, [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_STRICT, E_USER_DEPRECATED], true)) {
            try {
                ErrorTrackingService::capture(new \ErrorException($message, 0, $severity, $file, $line), 'Low');
            } catch (\Throwable $e) {
                error_log('ErrorHandler::handleError tracking failed: ' . $e->getMessage());
            }
            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(\Throwable $e): void
    {
        self::respond($e, 'High');
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::respond(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), 'Critical');
        }
    }

    private static function respond(\Throwable $e, string $severity): void
    {
        $correlationId = Correlation::id();

        try {
            ErrorTrackingService::capture($e, $severity);
        } catch (\Throwable $inner) {
            error_log('ErrorHandler: capture failed: ' . $inner->getMessage());
        }

        if (headers_sent()) {
            return;
        }

        http_response_code(500);

        $mode = 'detailed';
        try {
            $mode = (new \App\Models\SystemSetting())->get('error_display_mode', 'detailed') ?? 'detailed';
        } catch (\Throwable $inner) {
            error_log('ErrorHandler: could not read error_display_mode, defaulting to detailed: ' . $inner->getMessage());
        }

        try {
            if ($mode === 'safe') {
                self::renderSafe($correlationId);
            } else {
                self::renderDetailed($e);
            }
        } catch (\Throwable $inner) {
            error_log('ErrorHandler: render failed: ' . $inner->getMessage());
            echo 'A server error occurred. Reference: ' . htmlspecialchars($correlationId, ENT_QUOTES);
        }
    }

    /** No dependency on Session/View/helpers -- must render even if bootstrap itself is what broke. */
    private static function renderSafe(string $correlationId): void
    {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Something went wrong</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:60px;color:#333;">'
            . '<h2>Something went wrong while processing this request.</h2>'
            . '<p>Please try again, or contact support with this reference:</p>'
            . '<p><strong>' . htmlspecialchars($correlationId, ENT_QUOTES) . '</strong></p>'
            . '</body></html>';
    }

    private static function renderDetailed(\Throwable $e): void
    {
        echo '<pre style="white-space:pre-wrap;">' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
    }
}
