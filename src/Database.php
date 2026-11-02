<?php
namespace NxMultiTool;

use PDO;

final class Database
{
    private static bool $ready = false;
    public static function connection(): PDO { return \app\Core\Database::connect(); }
    public static function table(string $name): string { return \app\Core\Database::table('nxmt_' . $name); }
    public static function ensure(): void
    {
        if (self::$ready) { return; }
        $pdo = self::connection();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $identity = $mysql ? (string)$pdo->query('SELECT DATABASE()')->fetchColumn() : 'sqlite';
        $key = hash('sha256', $identity . self::table('logs'));
        $schema = Storage::read('schema');
        if (($schema['key'] ?? '') !== $key || ($schema['version'] ?? 0) !== 1) {
            $id = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::table('logs') . " (
                id $id, created_at BIGINT NOT NULL, user_id BIGINT NOT NULL DEFAULT 0,
                method VARCHAR(8) NOT NULL, status INTEGER NOT NULL, ip VARCHAR(45) NOT NULL,
                url TEXT NOT NULL, referrer TEXT NOT NULL, ua TEXT NOT NULL
                " . ($mysql ? ', INDEX nxmt_log_time(created_at), INDEX nxmt_log_ip(ip, created_at)' : '') . ")$suffix");
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::table('comments') . " (
                id $id, content_type VARCHAR(8) NOT NULL, content_id BIGINT NOT NULL,
                name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL, body TEXT NOT NULL,
                ip VARCHAR(45) NOT NULL, status VARCHAR(12) NOT NULL, created_at BIGINT NOT NULL
                " . ($mysql ? ', INDEX nxmt_comment_content(content_type, content_id, status), INDEX nxmt_comment_ip(ip, created_at)' : '') . ")$suffix");
            if (!$mysql) {
                $pdo->exec('CREATE INDEX IF NOT EXISTS nxmt_log_time ON ' . self::table('logs') . '(created_at)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS nxmt_log_ip ON ' . self::table('logs') . '(ip, created_at)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS nxmt_comment_content ON ' . self::table('comments') . '(content_type, content_id, status)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS nxmt_comment_ip ON ' . self::table('comments') . '(ip, created_at)');
            }
            Storage::transaction('schema', static fn($old) => [['key' => $key, 'version' => 1], null]);
        }
        self::$ready = true;
    }
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        self::ensure(); $stmt = self::connection()->prepare($sql); $stmt->execute($params); return $stmt;
    }
}
