<?php
/**
 * EduCore — Database  v5.5
 * Singleton PDO connection. Reads from .env / $_ENV.
 */
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $host = $_ENV['DB_HOST']     ?? 'localhost';
        $db   = $_ENV['DB_NAME']     ?? 'educore';
        $user = $_ENV['DB_USER']     ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        try {
            self::$pdo = new PDO(
                "mysql:host={$host};dbname={$db};charset=utf8mb4",
                $user, $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_FOUND_ROWS   => true,
                ]
            );
        } catch (PDOException $e) {
            error_log('[EduCore DB] ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Database unavailable. Please try again later.']);
            exit;
        }

        return self::$pdo;
    }

    // Alias for legacy callers that use (new Database())->getConnection()
    public function getConnection(): PDO { return self::connect(); }
    private function __construct() {}
}
