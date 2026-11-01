<?php
/**
 * Database Connection Singleton using PDO
 * Connects to MySQL with utf8mb4 and strict error reporting.
 */

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $dbname = getenv('DB_DATABASE') ?: 'ideapad_seed_fund_mysql';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'LocalDbRoot_Pass_2026!');
        $charset = 'utf8mb4';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbname,
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            self::$instance = new PDO($dsn, $username, $password, $options);
            self::$instance->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+05:30'");
            return self::$instance;
        } catch (PDOException $e) {
            error_log('[DB Error] Connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }
}
