<?php
/**
 * Database Connection Singleton (PDO)
 * Uses prepared statements (ATTR_EMULATE_PREPARES = false) and strict exception error mode.
 * Logs technical database errors to logs/app.log without leaking credentials to end users.
 */

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = require __DIR__ . '/../config/config.php';

        $host    = $config['db_host'] ?? '127.0.0.1';
        $port    = (int)($config['db_port'] ?? 3306);
        $socket  = $config['db_socket'] ?? '';
        $dbName  = $config['db_name'] ?? 'swiftdrive_rentals';
        $charset = $config['db_charset'] ?? 'utf8mb4';
        $user    = $config['db_user'] ?? 'root';
        $pass    = $config['db_pass'] ?? '';

        if ($socket === '' && $port === 8889 && file_exists('/Applications/MAMP/tmp/mysql/mysql.sock')) {
            $socket = '/Applications/MAMP/tmp/mysql/mysql.sock';
        }

        if ($socket !== '' && file_exists($socket)) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $dbName, $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $options);
            return self::$instance;
        } catch (PDOException $e) {
            // If default port fails and XAMPP/MAMP alternate port is available locally, log & show friendly message
            self::logError('Database connection error: ' . $e->getMessage());
            http_response_code(500);
            die('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Service Temporarily Unavailable - SwiftDrive Rentals</title>'
                . '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0b192c;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1.5rem}'
                . '.card{background:#132742;border:1px solid #264166;border-radius:12px;max-width:560px;padding:2rem;box-shadow:0 15px 35px rgba(0,0,0,.35)}'
                . 'h1{color:#ff6b2c;margin-top:0;font-size:1.5rem}code{background:#0b192c;padding:2px 6px;border-radius:4px;color:#ffd166}</style></head><body>'
                . '<div class="card"><h1>Database Connection Required</h1>'
                . '<p>SwiftDrive Rentals could not connect to the MySQL database.</p>'
                . '<p>Please ensure your local MySQL service (XAMPP or MAMP) is running, import <code>sql/swiftdrive_rentals.sql</code>, and verify database settings in <code>config/config.local.php</code> (copied from <code>config/config.example.php</code>).</p>'
                . '</div></body></html>');
        }
    }

    public static function logError(string $message): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/app.log';
        $entry = sprintf("[%s] %s%s", date('Y-m-d H:i:s'), $message, PHP_EOL);
        @file_put_contents($logFile, $entry, FILE_APPEND);
    }
}

