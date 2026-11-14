<?php
namespace NxMultiTool;

final class SiteInfo
{
    public static function publicUrl(): ?string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $path = \url();
        if ($host === '' || preg_match('~[\x00-\x20\x7f/]~', $host) || strpbrk($host, '\\@?#') !== false
            || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $secure = in_array($https, ['on', '1'], true)
            || strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $url = ($secure ? 'https://' : 'http://') . strtolower($host) . $path;

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
