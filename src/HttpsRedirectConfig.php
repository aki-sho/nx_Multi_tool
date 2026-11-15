<?php
namespace NxMultiTool;

final class HttpsRedirectConfig
{
    private const BEGIN = '# BEGIN NX Multi Tool HTTPS Redirect';
    private const END = '# END NX Multi Tool HTTPS Redirect';
    private const MAX_BYTES = 256 * 1024;

    public static function prepare(string $site): array
    {
        $server = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (!preg_match('~^(Apache|LiteSpeed|OpenLiteSpeed)(?:[ /]|$)~i', $server)
            || (function_exists('apache_get_modules')
                && !array_intersect(['mod_rewrite', 'rewrite_module'], apache_get_modules()))) {
            throw new \RuntimeException('Webサーバーが .htaccess の転送ルールを使えるか確認できません。');
        }
        if (strtolower((string)($_SERVER['HTTPS'] ?? '')) !== 'on') {
            throw new \RuntimeException('HTTPSで管理画面を開いてください。HTTPSで開いている場合は、Webサーバー側の転送設定を確認してください。');
        }
        $host = (string)parse_url($site, PHP_URL_HOST);
        if (!preg_match('/^[a-z0-9.-]+$/iD', $host) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \RuntimeException('転送先に使用するサイトのドメインを確認できません。');
        }
        $base = realpath(BASE_PATH);
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $publicPath = rawurldecode((string)parse_url($site, PHP_URL_PATH));
        $directory = $root !== '' ? realpath(rtrim($root, '/\\') . $publicPath) : false;
        if ($base === false || $directory === false || !in_array($directory, [$base, $base . DIRECTORY_SEPARATOR . 'public'], true)) {
            throw new \RuntimeException('対象サイトの公開フォルダと適用先の .htaccess を特定できません。');
        }
        $path = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('対象サイトで使用する .htaccess を確認できません。');
        }
        $snapshot = self::read($path);
        if (!is_writable($path) || !is_writable($directory)) {
            throw new \RuntimeException('NexaPressに .htaccess または設置フォルダへ書き込む権限がありません。');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && posix_geteuid() !== $snapshot['owner']) {
            throw new \RuntimeException('元のファイル所有者を保ったまま .htaccess を置き換える権限がありません。');
        }
        $contents = $snapshot['contents'];
        if (str_contains($contents, self::BEGIN) || str_contains($contents, self::END)) {
            throw new \RuntimeException('NX Multi Toolの転送ルールがファイルに残っています。公開応答と設定ファイルを確認してください。');
        }
        // Find NexaPress's front-controller rule and place the redirect before its entire condition group.
        $rules = preg_match_all('~^[ \t]*RewriteRule[ \t]+\S+[ \t]+index\.php[ \t]+\[[^\]\r\n]+\][ \t]*\r?$~mi',
            $contents, $routes, PREG_OFFSET_CAPTURE);
        if ($rules !== 1 || !preg_match('~^[ \t]*RewriteEngine[ \t]+On[ \t]*\r?$~mi', $contents)) {
            throw new \RuntimeException('既存の .htaccess で安全な追記位置を特定できません。');
        }
        $ruleOffset = $routes[0][0][1];
        $above = substr($contents, 0, $ruleOffset);
        preg_match('~(?:^[ \t]*(?:RewriteCond[ \t]+[^\r\n]*|#[^\r\n]*)?[ \t]*\r?\n)+\z~mi', $above, $group, PREG_OFFSET_CAPTURE);
        $conditions = $group[0][0] ?? '';
        if (!preg_match('~^[ \t]*RewriteCond[ \t]+%{REQUEST_FILENAME}[ \t]+!-f[ \t]*\r?$~mi', $conditions)
            || !preg_match('~^[ \t]*RewriteCond[ \t]+%{REQUEST_FILENAME}[ \t]+!-d[ \t]*\r?$~mi', $conditions)
            || !preg_match('~^[ \t]*RewriteCond[ \t]+~mi', $conditions, $first, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('既存の .htaccess で安全な追記位置を特定できません。');
        }
        $offset = $group[0][1] + $first[0][1];
        $relative = $directory === $base ? '.htaccess' : 'public/.htaccess';
        return $snapshot + compact('path', 'relative', 'host', 'offset');
    }

    public static function withRule(array $target, int $status): string
    {
        $newline = str_contains($target['contents'], "\r\n") ? "\r\n" : "\n";
        $rules = [
            self::BEGIN,
            'RewriteCond %{HTTPS} !=on',
            'RewriteCond %{HTTP_HOST} ^' . preg_quote($target['host'], '/') . '(?::80)?$ [NC]',
            'RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/',
            'RewriteCond %{THE_REQUEST} "^\S+\s+(/[^\s?#]*)"',
            'RewriteRule ^ https://' . $target['host'] . '%1 [R=' . $status . ',L,NE]',
            self::END,
            '',
            '',
        ];
        $contents = substr_replace($target['contents'], implode($newline, $rules), $target['offset'], 0);
        if (strlen($contents) > self::MAX_BYTES) {
            throw new \RuntimeException('転送ルールを追加できる .htaccess のサイズを超えています。');
        }
        return $contents;
    }

    private static function read(string $path): array
    {
        clearstatcache(true, $path);
        $metadata = is_link($path) ? false : @stat($path);
        $contents = $metadata && is_file($path) && is_readable($path)
            ? @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1) : false;
        if (!is_string($contents) || strlen($contents) > self::MAX_BYTES) {
            throw new \RuntimeException('NexaPressから .htaccess の内容を読み取れません。');
        }
        return ['contents' => $contents, 'mode' => $metadata['mode'] & 0777, 'owner' => $metadata['uid'], 'group' => $metadata['gid']];
    }

    public static function replace(array $target, array $expected, string $contents): void
    {
        $path = $target['path'];
        $current = self::checkSnapshot($target, $expected);
        if ($current['contents'] === $contents) { return; }
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.nxmt-');
        try {
            if ($temporary === false || realpath(dirname($temporary)) !== $directory
                || @file_put_contents($temporary, $contents) !== strlen($contents)
                || !@chmod($temporary, $target['mode'])
                || (fileowner($temporary) !== $target['owner'] && !@chown($temporary, $target['owner']))
                || (filegroup($temporary) !== $target['group'] && !@chgrp($temporary, $target['group']))) {
                throw new \RuntimeException('元の権限と所有者を保ったまま .htaccess を保存できません。');
            }
            self::checkSnapshot($target, $expected);
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('.htaccess を保存できません。');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
        }
    }

    private static function checkSnapshot(array $target, array $expected): array
    {
        $current = self::read($target['path']);
        if ($current['mode'] !== $target['mode'] || $current['owner'] !== $target['owner']
            || $current['group'] !== $target['group'] || !in_array($current['contents'], $expected, true)) {
            throw new \RuntimeException('別の操作で .htaccess が変更されました。');
        }
        return $current;
    }
}
