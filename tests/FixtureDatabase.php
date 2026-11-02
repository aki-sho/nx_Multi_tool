<?php
namespace app\Core;
/** Test adapter: only the connection is replaced; models, router, views and permissions are the real CMS classes. */
final class Database
{
    private static ?\PDO $pdo = null;
    public static function connect(): \PDO
    {
        if (!self::$pdo) {
            self::$pdo = new \PDO('sqlite:' . BASE_PATH . '/fixture.sqlite', null, null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            self::$pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        }
        return self::$pdo;
    }
    public static function table(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new \RuntimeException('Invalid fixture table'); }
        return '`test_' . $name . '`';
    }
}
