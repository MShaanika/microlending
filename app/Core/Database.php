<?php
namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;
    /** When the shared connection was last handed out (unix time) -- see connection(). */
    private static int $lastUse = 0;
    /** After this many quiet seconds the connection is pinged before it is reused: a slow call (AI analysis, a bureau or bank API) can outlast MySQL's wait_timeout. */
    private const PING_AFTER_SECONDS = 15;

    public static function connection(): PDO
    {
        if (self::$pdo) {
            if (time() - self::$lastUse > self::PING_AFTER_SECONDS && !self::$pdo->inTransaction()) {
                try {
                    self::$pdo->query('SELECT 1');
                } catch (\PDOException $e) {
                    if (!self::isGoneAway($e)) {
                        throw $e;
                    }
                    self::$pdo = null; // dropped while idle: open a fresh one below
                }
            }
            if (self::$pdo) {
                self::$lastUse = time();
                return self::$pdo;
            }
        }
        $c = require ROOT_PATH . '/config/database.php';
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname=" . (getenv('MLS_DB_NAME') ?: $c['database']) . ";charset={$c['charset']}";
        try {
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$lastUse = time();
            return self::$pdo;
        } catch (PDOException $e) {
            $debug = defined('APP_DEBUG') && APP_DEBUG;
            $message = 'Database connection failed. Check config/database.php and import database/schema.sql.';
            if ($debug) {
                $message .= ' Underlying error: ' . $e->getMessage();
            }
            throw new \RuntimeException($message, (int) $e->getCode(), $e);
        }
    }

    /** Drops the shared connection and opens a new one. Never call inside a transaction: the transaction is gone with the old connection. */
    public static function reconnect(): PDO
    {
        self::$pdo = null;
        return self::connection();
    }

    /** Is this the "MySQL server has gone away" (2006) / "Lost connection" (2013) kind of failure -- the connection died, the query itself was fine. */
    public static function isGoneAway(\Throwable $e): bool
    {
        $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        return in_array($code, [2006, 2013], true)
            || stripos($e->getMessage(), 'gone away') !== false
            || stripos($e->getMessage(), 'lost connection') !== false;
    }
}
