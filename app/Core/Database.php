<?php
namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;
    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = require ROOT_PATH . '/config/database.php';
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset={$c['charset']}";
        try {
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
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
}
