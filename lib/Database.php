<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $constant) {
            if (!defined($constant) || trim((string) constant($constant)) === '') {
                throw new RuntimeException('config.php의 데이터베이스 설정을 먼저 입력해주세요: ' . $constant);
            }
        }

        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, $charset);

        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $charset,
        ]);
        self::$pdo->exec('SET NAMES ' . $charset);

        return self::$pdo;
    }
}
