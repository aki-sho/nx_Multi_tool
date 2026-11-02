<?php
namespace NxMultiTool;

final class HttpClient
{
    public static function base(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
            || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || isset($p['port']) && !in_array($p['port'], [80, 443], true)) {
            throw new \InvalidArgumentException('公開HTTP/HTTPSのサイトURLを指定してください。認証情報・クエリ・特殊ポートは指定できません。');
        }
        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim(self::path($p['path'] ?? '/'), '/');
    }
    public static function publicIp(string $ip): bool
    {
        $ip = Monitor::normalizeIp($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) { return false; }
        if (str_contains($ip, ':')) {
            $bytes = inet_pton($ip);
            return (ord($bytes[0]) & 0xe0) === 0x20 && !str_starts_with($ip, '2001:db8:')
                && !str_starts_with($ip, '2001:0:') && !str_starts_with($ip, '2002:');
        }
        $number = (int)sprintf('%u', ip2long($ip));
        foreach ([['0.0.0.0',8],['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as [$network,$bits]) {
            $mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
            if (($number & $mask) === ((int)sprintf('%u',ip2long($network)) & $mask)) { return false; }
        }
        return true;
    }
    private static function origin(array $p): string
    {
        $scheme = strtolower($p['scheme'] ?? '');
        return $scheme . '://' . strtolower($p['host'] ?? '') . ':' . ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
    public static function path(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($parts); } else { $parts[] = $part; }
        }
        return '/' . implode('/', $parts) . ($parts && str_ends_with($path, '/') ? '/' : '');
    }
    public static function resolve(string $candidate, string $from, string $base): ?string
    {
        $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($candidate === '' || strlen($candidate) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $candidate) || str_starts_with($candidate, '#')) { return null; }
        $root = parse_url(self::base($base)); $parent = parse_url($from);
        if (str_starts_with($candidate, '//')) { $candidate = $root['scheme'] . ':' . $candidate; }
        elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $candidate)) {
            $origin = $root['scheme'] . '://' . $root['host'] . (isset($root['port']) ? ':' . $root['port'] : '');
            $candidate = $origin . ($candidate[0] === '/' ? $candidate : ($candidate[0] === '?' ? ($parent['path'] ?? '/') : rtrim(str_replace('\\', '/', dirname($parent['path'] ?? '/')), '/') . '/') . $candidate);
        }
        $p = parse_url($candidate);
        if (!$p || self::origin($p) !== self::origin($root) || isset($p['user']) || isset($p['pass'])) { return null; }
        $path = self::path($p['path'] ?? '/');
        $decoded = rawurldecode($path);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || preg_match('#(?:^|/)\.\.(?:/|$)#', $decoded)) { return null; }
        $prefix = rtrim($root['path'] ?? '', '/');
        if ($prefix !== '' && $decoded !== $prefix && !str_starts_with($decoded, $prefix . '/')) { return null; }
        $query = '';
        if (!empty($p['query'])) {
            parse_str($p['query'], $values); $safe = [];
            foreach (array_slice($values, 0, 20, true) as $key => $value) {
                $safe[$key] = in_array($key, ['attachment_id', 'page', 'id'], true) && is_scalar($value) && preg_match('/^\d{1,12}$/D', (string)$value) ? $value : '[redacted]';
            }
            $query = '?' . http_build_query($safe);
        }
        return $root['scheme'] . '://' . $root['host'] . (isset($root['port']) ? ':' . $root['port'] : '') . $path . $query;
    }
    public static function protectedUrl(string $url, string $base): bool
    {
        $prefix = rtrim((string)parse_url($base, PHP_URL_PATH), '/');
        $path = (string)parse_url($url, PHP_URL_PATH);
        for ($i = 0; $i < 3; $i++) { $next = rawurldecode($path); if ($next === $path) { break; } $path = $next; }
        $path = self::path($path);
        if ($prefix !== '' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) { return true; }
        $relative = substr($path, strlen($prefix));
        return (bool)preg_match('#^/(?:public/)?(?:admin|install|nx-multi-tool|app|config|database|storage|extensions|tests|update-package|dist|vendor|node_modules)(?:/|$)|(?:^|/)\.#i', $relative);
    }
    public static function fetch(string $url, string $base, bool $head = false, int $redirects = 0, bool $range = false, int &$requests = 0, array $history = []): array
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('HTTP取得にはPHP cURLが必要です。'); }
        $safe = self::resolve($url, $base . '/', $base);
        if ($safe === null || str_contains($safe, '?') || $redirects > 3) { throw new \RuntimeException('このURLは取得できません。'); }
        $p = parse_url($safe);
        if (self::protectedUrl($safe, $base)) { throw new \RuntimeException('管理・認証・内部領域のURLは取得しません。'); }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!$ips) { throw new \RuntimeException('公開IPを解決できません。'); }
        foreach ($ips as $ip) { if (!self::publicIp($ip)) { throw new \RuntimeException('内部・予約IPへの通信は許可されていません。'); } }
        $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
        $headers = []; $body = ''; $size = 0; $limit = $head ? 0 : ($range ? 512 : 1024 * 1024); $overflow = false;
        $ch = curl_init($safe);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0])],
            CURLOPT_USERAGENT => 'NX-Multi-Tool/1.0.1 (+site administrator initiated)', CURLOPT_NOBODY => $head,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers) {
                if (preg_match('#^HTTP/#i', $line)) { $headers = []; }
                if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))] = substr(trim($value), 0, 4096); }
                return strlen($line);
            }, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$size, &$overflow, $limit) {
                $size += strlen($chunk);
                if ($size > $limit) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        if ($range) { curl_setopt($ch, CURLOPT_RANGE, '0-511'); }
        if ($requests >= 8) { curl_close($ch); throw new \RuntimeException('転送確認の通信上限に達しました。'); }
        $requests++;
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($overflow) { throw new \RuntimeException('応答が1MiBを超えました。'); }
        if ($ok === false) { throw new \RuntimeException('公開URLの取得に失敗しました。'); }
        $history[] = ['url' => $safe, 'status' => $status];
        if (in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location'])) {
            $next = self::resolve($headers['location'], $safe, $base);
            if ($next === null || self::protectedUrl($next, $base) || $redirects >= 3) {
                return ['url' => $safe, 'status' => $status, 'headers' => $headers, 'body' => '', 'redirected' => $redirects > 0,
                    'requests' => $requests, 'history' => $history, 'blocked' => true, 'blocked_to' => $next ?? '外部または不正な転送先'];
            }
            $result = self::fetch($next, $base, $head, $redirects + 1, $range, $requests, $history); $result['redirected'] = true; return $result;
        }
        if ($head && in_array($status, [405, 501], true)) { return self::fetch($safe, $base, false, $redirects, true, $requests, $history); }
        return ['url' => $safe, 'status' => $status, 'headers' => $headers, 'body' => $body, 'redirected' => $redirects > 0, 'requests' => $requests, 'history' => $history];
    }
}
