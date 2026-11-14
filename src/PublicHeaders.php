<?php
namespace NxMultiTool;

final class PublicHeaders
{
    private const STORAGE = 'public-headers';
    private const MAX_BYTES = 8192;
    public const NAMES = [
        'hsts' => 'Strict-Transport-Security',
        'csp' => 'Content-Security-Policy',
        'frame' => 'X-Frame-Options',
        'nosniff' => 'X-Content-Type-Options',
        'referrer' => 'Referrer-Policy',
        'permissions' => 'Permissions-Policy',
    ];
    public const DEFAULTS = [
        'hsts' => 'max-age=300',
        'csp' => "default-src 'self'",
        'frame' => 'SAMEORIGIN',
        'nosniff' => 'nosniff',
        'referrer' => 'strict-origin-when-cross-origin',
        'permissions' => 'camera=(), microphone=()',
    ];
    public const OPTIONS = [
        'frame' => ['SAMEORIGIN', 'DENY'],
        'referrer' => ['strict-origin-when-cross-origin', 'strict-origin', 'no-referrer'],
    ];
    public const FEATURES = ['camera' => 'カメラ', 'microphone' => 'マイク', 'geolocation' => '位置情報'];

    public static function name(string $id, string $mode): string
    {
        return self::NAMES[$id] . ($mode === 'report' ? '-Report-Only' : '');
    }

    public static function settings(): array
    {
        // Storage::transaction replaces the JSON atomically. A public request can
        // read that snapshot without creating a directory or an exclusive lock.
        $directory = BASE_PATH . '/storage/extensions/nx_Multi_tool';
        $path = $directory . '/' . self::STORAGE . '.json';
        if (!is_file($path)) { return []; }
        if (is_link($path) || is_link($directory) || is_link(dirname($directory)) || is_link(dirname($directory, 2))) {
            throw new \RuntimeException('公開ヘッダーの保存先を確認してください。');
        }
        $json = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        $saved = is_string($json) && strlen($json) <= self::MAX_BYTES ? json_decode($json, true, 8) : null;
        if (!is_array($saved)) { throw new \RuntimeException('公開ヘッダーの保存内容を確認できません。'); }
        $policies = [];
        foreach (self::NAMES as $id => $name) {
            if (!isset($saved[$id])) { continue; }
            $rule = $saved[$id];
            if (!is_array($rule)) { throw new \RuntimeException('公開ヘッダーの保存内容を確認できません。'); }
            $policies[$id] = self::rule($id, $rule['value'] ?? null, $rule['mode'] ?? null);
        }
        return $policies;
    }

    public static function set(string $id, ?array $input): void
    {
        if (!isset(self::NAMES[$id])) { throw new \InvalidArgumentException('適用するヘッダーを確認してください。'); }
        $rule = null;
        if ($input !== null) {
            if ($id === 'hsts' && parse_url(SiteInfo::publicUrl() ?? '', PHP_URL_SCHEME) !== 'https') {
                throw new \InvalidArgumentException('HSTSはHTTPSで管理画面を開いて適用してください。');
            }
            $value = $input['value'] ?? self::DEFAULTS[$id];
            if ($id === 'permissions') {
                $features = $input['features'] ?? [];
                if (!is_array($features) || array_filter($features, static fn($feature) =>
                    !is_string($feature) || !isset(self::FEATURES[$feature]))) {
                    throw new \InvalidArgumentException('制限するブラウザー機能を確認してください。');
                }
                $value = implode(', ', array_map(static fn($feature) => $feature . '=()',
                    array_intersect(array_keys(self::FEATURES), $features)));
            }
            $rule = self::rule($id, $value, $id === 'csp' ? ($input['mode'] ?? 'report') : 'enforce');
        }

        Storage::transaction(self::STORAGE, static function (array $saved) use ($id, $rule): array {
            if ($rule === null) { unset($saved[$id]); }
            else { $saved[$id] = $rule; }
            return [$saved, null];
        });
    }

    private static function rule(string $id, $value, $mode): array
    {
        if (!is_string($value) || !in_array($mode, $id === 'csp' ? ['report', 'enforce'] : ['enforce'], true)) {
            throw new \InvalidArgumentException('ヘッダーの値と適用方法を確認してください。');
        }
        if ($id === 'csp') {
            $value = trim($value, ' ');
            if (strlen($value) > 2048 || preg_match('/[^\x20-\x7e]|,|\x27nonce-/i', $value)) {
                throw new \InvalidArgumentException('CSPは改行と固定のnonceを含まない、1行のポリシーを指定してください。');
            }
            $directives = [];
            foreach (explode(';', rtrim($value, '; ')) as $part) {
                if (!preg_match('/^([a-z][a-z0-9-]*)(?: .+)?$/iD', trim($part), $match)
                    || isset($directives[strtolower($match[1])])) {
                    throw new \InvalidArgumentException('CSPの指示名と値を確認してください。同じ指示は一度指定できます。');
                }
                $directives[strtolower($match[1])] = true;
            }
        } elseif ($id === 'permissions') {
            $features = explode(', ', $value);
            $allowed = array_map(static fn($feature) => $feature . '=()', array_keys(self::FEATURES));
            if (!$value || array_diff($features, $allowed) || count($features) !== count(array_unique($features))) {
                throw new \InvalidArgumentException('制限するブラウザー機能を選択してください。');
            }
        } elseif (!in_array($value, self::OPTIONS[$id] ?? [self::DEFAULTS[$id]], true)) {
            throw new \InvalidArgumentException('ヘッダーの値を確認してください。');
        }
        return ['value' => $value, 'mode' => $mode];
    }

    public static function boot(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) { return; }
        $site = SiteInfo::publicUrl();
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($site === null || !is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || HttpClient::protectedUrl($path, $site, '/admin/login') || headers_sent()) {
            return;
        }
        $policies = self::settings();
        if (!$policies || !Settings::enabled('security_check')) { return; }

        // Apply after the controller and cache have supplied their own headers.
        $https = parse_url($site, PHP_URL_SCHEME) === 'https';
        header_register_callback(static function () use ($policies, $site, $path, $https): void {
            $status = http_response_code();
            if ($status !== false && $status !== 200 && $status !== 304) { return; }
            try {
                if (HttpClient::protectedUrl($path, $site)) { return; }
            } catch (\Throwable $e) {
                return;
            }
            $sent = [];
            foreach (headers_list() as $header) {
                [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
                $sent[strtolower(trim($name))][] = trim($value);
            }
            $mime = strtolower(trim(explode(';', $sent['content-type'][0] ?? (string)ini_get('default_mimetype'))[0]));
            if (!in_array($mime, ['text/html', 'application/xhtml+xml'], true)
                || isset($sent['content-disposition']) || isset($sent['www-authenticate']) || isset($sent['location'])) {
                return;
            }
            foreach ($policies as $id => $rule) {
                $name = self::name($id, $rule['mode']);
                if (isset($sent[strtolower($name)]) || ($id === 'hsts' && !$https)) { continue; }
                if ($id === 'frame' && preg_match('/(?:^|[;,])\s*frame-ancestors\b/i', implode(',', $sent['content-security-policy'] ?? []))) { continue; }
                header($name . ': ' . $rule['value']);
                $sent[strtolower($name)] = [$rule['value']];
            }
        });
    }

    public static function observation(string $id, array $rule, array $headers): string
    {
        $values = $headers[strtolower(self::name($id, $rule['mode']))] ?? [];
        if (!$values) { return 'この応答では送信を確認できませんでした。'; }
        if (count($values) > 1) { return 'この応答に同じヘッダーが複数あります。配信側の設定を確認してください。'; }
        return $values[0] === $rule['value'] ? '設定した値を公開応答で確認しました。'
            : '公開応答と設定した値が異なります。配信側の設定を確認してください。';
    }
}
