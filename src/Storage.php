<?php
namespace NxMultiTool;

use RuntimeException;

final class Storage
{
    public static function directory(): string
    {
        $path = BASE_PATH . '/storage/extensions/nx_Multi_tool';
        if (is_link(BASE_PATH . '/storage') || is_link(BASE_PATH . '/storage/extensions') || is_link($path)) {
            throw new RuntimeException('保存先にリンクは使用できません。');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Multi Toolの保存先を作成できません。');
        }
        if (!is_file($path . '/.htaccess')) {
            file_put_contents($path . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $path;
    }
    public static function transaction(string $name, callable $callback)
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $name)) { throw new RuntimeException('保存名が不正です。'); }
        $path = self::directory() . '/' . $name . '.json';
        if (is_link($path) || is_link($path . '.lock')) { throw new RuntimeException('保存先が不正です。'); }
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('保存先をロックできません。'); }
        try {
            $data = [];
            if (is_file($path)) {
                if (filesize($path) > 8 * 1024 * 1024) { throw new RuntimeException('保存データが上限を超えています。'); }
                $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) { throw new RuntimeException('保存データが壊れています。'); }
            }
            [$next, $result] = $callback($data);
            if ($next !== null) {
                $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($json) > 8 * 1024 * 1024) { throw new RuntimeException('保存上限に達しました。'); }
                $temp = tempnam(dirname($path), '.nxmt-');
                try {
                    if ($temp === false || file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $path)) {
                        throw new RuntimeException('設定を保存できません。');
                    }
                    @chmod($path, 0600);
                } finally { if (is_string($temp) && is_file($temp)) { unlink($temp); } }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function read(string $name): array { return self::transaction($name, static fn($data) => [null, $data]); }
    public static function delete(string $name): void
    {
        self::transaction($name, static function ($old) use ($name) {
            $path = self::directory() . '/' . $name . '.json';
            if (is_file($path) && !unlink($path)) { throw new RuntimeException('一時データを削除できません。'); }
            return [null, null];
        });
    }
}
