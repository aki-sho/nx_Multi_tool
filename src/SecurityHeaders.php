<?php
namespace NxMultiTool;

final class SecurityHeaders
{
    private const RULES = [
        'hsts' => ['HTTPS接続をブラウザーに指示', 25, 'HTTPSの応答で、有効期間（max-age）が0より大きい。', 15],
        'csp' => ['スクリプトの読み込みと実行を制限', 30, '実際に適用されるCSPでスクリプトを制限。報告専用は0点。', 4],
        'frame' => ['他のページへの埋め込みを制限', 20, 'DENY・SAMEORIGIN、または適用されるCSPの frame-ancestors で制限。', 14],
        'nosniff' => ['ブラウザーによるファイル形式の推測を抑制', 10, '値が nosniff。', 4],
        'referrer' => ['送信する参照元URLの範囲を指定', 10, '他のサイトに参照元のパスを送らない方針。', 16],
        'permissions' => ['ブラウザー機能の利用を制限', 5, 'カメラ・マイク・位置情報のいずれかに利用範囲の制限がある。', null],
    ];

    public static function inspect(?string $url, bool $requested, array $policies = [], bool $settingsChanged = false): array
    {
        $status = null;
        $headers = null;
        $url = $url ?? '';
        $message = $settingsChanged ? '「再チェック」で変更後の公開応答を確認できます。'
            : '「公開応答」を選択して再チェックすると、公開トップの応答を取得します。';
        if ($requested && $url === '') {
            $message = '現在のCMSの公開URLを確認できませんでした。';
        } elseif ($requested) {
            try {
                $response = HttpClient::fetch($url, $url, includeHeaderValues: true);
                $status = $response['status'];
                $classified = ResponseClassifier::classify($response);
                $mime = strtolower(trim(explode(';', $response['headers']['content-type'] ?? '')[0]));
                if ($status === 200 && !$classified['issue']
                    && in_array($mime, ['text/html', 'application/xhtml+xml'], true)) {
                    $url = $response['url'];
                    $headers = $response['header_values'];
                    $message = '未ログインで取得した公開ページの応答を確認しました。';
                } else {
                    $message = '公開ページの正常なHTML応答を確認できませんでした。';
                }
            } catch (\Throwable $exception) {
                $message = '公開ページの応答を取得できませんでした。';
            }
        }

        $observed = [];
        if ($headers !== null) {
            foreach ($policies as $id => $policy) {
                $observed[$id] = PublicHeaders::observation($id, $policy, $headers);
            }
        }
        return ['url' => $url, 'http' => $status, 'message' => $message, 'collected' => $headers !== null, 'observed' => $observed]
            + self::evaluate($headers, parse_url($url, PHP_URL_SCHEME) === 'https', session_name() ?: '');
    }

    /** Receives the response header values. Only the observations below are returned to the UI. */
    public static function evaluate(?array $headers, bool $https, string $sessionName): array
    {
        $collected = $headers !== null;
        $headers ??= [];
        $policies = self::cspPolicies($headers['content-security-policy'] ?? []);
        $results = $collected ? [
            'hsts' => self::hsts($headers['strict-transport-security'] ?? [], $https),
            'csp' => self::scriptPolicy($policies, isset($headers['content-security-policy-report-only'])),
            'frame' => self::framePolicy($policies, $headers['x-frame-options'] ?? []),
            'nosniff' => self::nosniff($headers['x-content-type-options'] ?? []),
            'referrer' => self::referrer($headers['referrer-policy'] ?? []),
            'permissions' => self::permissions($headers['permissions-policy'] ?? []),
        ] : [];
        $rows = [];
        $score = 0;
        $complete = true;
        foreach (self::RULES as $key => [$purpose, $max, $rule, $category]) {
            $name = PublicHeaders::NAMES[$key];
            [$state, $detail] = $results[$key] ?? ['unknown', '公開応答は未確認です。'];
            $points = $state === 'unknown' ? null : ($state === 'present' ? $max : 0);
            $complete = $complete && $points !== null;
            $score += $points ?? 0;
            $rows[$key] = compact('name', 'purpose', 'state', 'detail', 'max', 'rule', 'points', 'category');
        }

        $supplement = [
            'cookie' => ['Set-Cookie', '公開トップで発行されたセッションCookieの属性', 12, self::cookie($headers['set-cookie'] ?? [], $sessionName)],
            'cache' => ['Cache-Control', '公開応答を保存・再利用する条件', 19, self::cache($headers['cache-control'] ?? [])],
            'cors' => ['Access-Control-Allow-Origin', '公開トップのCORS応答ヘッダー', 20, self::cors($headers)],
            'server' => ['Server / X-Powered-By', 'サーバー・実行環境情報の送信状況', 16, self::server($headers)],
        ];
        foreach ($supplement as $key => [$name, $purpose, $category, [$state, $detail]]) {
            if (!$collected) { $state = 'unknown'; $detail = '公開応答は未確認です。'; }
            $supplement[$key] = compact('name', 'purpose', 'state', 'detail', 'category');
        }

        $references = [];
        foreach ([
            'Cross-Origin-Embedder-Policy' => ['他のオリジンのリソースの読み込み条件', ['unsafe-none', 'require-corp', 'credentialless']],
            'Cross-Origin-Opener-Policy' => ['他のオリジンのウィンドウとの関係', ['unsafe-none', 'same-origin-allow-popups', 'same-origin', 'noopener-allow-popups']],
            'Cross-Origin-Resource-Policy' => ['他のオリジンから利用できるリソースの範囲', ['same-site', 'same-origin', 'cross-origin']],
        ] as $name => [$purpose, $tokens]) {
            $values = $headers[strtolower($name)] ?? [];
            $detail = !$collected ? '公開応答は未確認です。' : 'この応答にヘッダーの送信はありません。';
            if ($values) {
                $detail = count($values) === 1 && in_array($values[0], $tokens, true)
                    ? $values[0] : '送信あり。値は未確認です。';
            }
            $references[] = compact('name', 'purpose', 'detail');
        }
        return ['rows' => $rows, 'supplement' => $supplement, 'references' => $references, 'score' => $complete ? $score : null];
    }

    private static function hsts(array $values, bool $https): array
    {
        if (!$values) { return ['absent', 'この応答にHSTSはありません。']; }
        if (!$https) { return ['absent', 'HSTSが有効になるにはHTTPSでの応答が必要です。']; }
        $directives = [];
        // Browsers process the first HSTS field in an HTTPS response.
        foreach (explode(';', $values[0]) as $directive) {
            $directive = trim($directive);
            if ($directive === '') { continue; }
            if (!preg_match('/^([a-z][a-z0-9_-]*)(?:\s*=\s*(.+))?$/iD', $directive, $parts)
                || array_key_exists(strtolower($parts[1]), $directives)) {
                return ['unknown', 'HSTSの設定値を判定できませんでした。'];
            }
            $directives[strtolower($parts[1])] = $parts[2] ?? null;
        }
        if (!preg_match('/^(?:([0-9]+)|"([0-9]+)")$/D', $directives['max-age'] ?? '', $age)) {
            return ['unknown', 'HSTSの有効期間を判定できませんでした。'];
        }
        $seconds = ltrim($age[1] !== '' ? $age[1] : ($age[2] ?? ''), '0');
        return $seconds === '' ? ['absent', 'max-age=0 のためHSTSは無効です。']
            : ['present', 'HTTPS応答で max-age=' . substr($seconds, 0, 20) . (strlen($seconds) > 20 ? '…' : '') . ' を確認しました。'];
    }

    private static function cspPolicies(array $values): array
    {
        $policies = [];
        foreach (explode(',', implode(',', $values)) as $policy) {
            $directives = [];
            foreach (explode(';', $policy) as $directive) {
                $parts = preg_split('/\s+/', trim($directive), 2, PREG_SPLIT_NO_EMPTY);
                if (!$parts) { continue; }
                $name = strtolower($parts[0]);
                if (!array_key_exists($name, $directives)) { $directives[$name] = $parts[1] ?? ''; }
            }
            if ($directives) { $policies[] = $directives; }
        }
        return $policies;
    }

    /** Recognize restrictive source lists without treating an unfamiliar policy as enabled. */
    private static function restrictedSources(string $value, bool $frame = false): ?bool
    {
        $parts = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === ["'none'"]) { return true; }
        if (!$parts) { return null; }
        $sources = 0;
        $hasKey = false;
        foreach ($parts as $part) {
            if ($part === "'self'" || preg_match('~^https://[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::[0-9]+)?$~iD', $part)) {
                $sources++;
            } elseif (!$frame && preg_match("~^'(?:nonce|sha256|sha384|sha512)-[a-zA-Z0-9+/_-]+={0,2}'$~D", $part)) {
                $sources++;
                $hasKey = true;
            } elseif (!$frame && in_array($part, ["'strict-dynamic'", "'report-sample'"], true)) {
                continue;
            } elseif ($part === '*' || preg_match('/^[a-z][a-z0-9+.-]*:$/iD', $part)
                || in_array($part, ["'unsafe-inline'", "'unsafe-eval'", "'wasm-unsafe-eval'"], true)) {
                return false;
            } else {
                return null;
            }
        }
        return $sources > 0 && (!in_array("'strict-dynamic'", $parts, true) || $hasKey) ? true : null;
    }

    private static function scriptPolicy(array $policies, bool $reportOnly): array
    {
        $unrecognized = false;
        foreach ($policies as $policy) {
            $fallback = $policy['script-src'] ?? $policy['default-src'] ?? null;
            $elements = $policy['script-src-elem'] ?? $fallback;
            $attributes = $policy['script-src-attr'] ?? $fallback;
            if ($elements === null || $attributes === null) { continue; }
            if (preg_match("/(?:^|\s)'(?:wasm-)?unsafe-eval'(?:\s|$)/", $fallback ?? '')) { continue; }
            $elementRule = self::restrictedSources($elements);
            $attributeRule = self::restrictedSources($attributes);
            if ($elementRule === true && $attributeRule === true) {
                return ['present', '適用されるCSPでスクリプトの読み込みとインライン実行の制限を確認しました。'];
            }
            $unrecognized = $unrecognized || $elementRule === null || $attributeRule === null;
        }
        if ($unrecognized) { return ['unknown', 'CSPのスクリプト制限を判定できませんでした。']; }
        return ['absent', !$policies && $reportOnly ? '報告専用のCSPが確認されました。' : '採点条件を満たすスクリプトの制限は確認できませんでした。'];
    }

    private static function framePolicy(array $policies, array $values): array
    {
        $frameRules = [];
        foreach ($policies as $policy) {
            if (!array_key_exists('frame-ancestors', $policy)) { continue; }
            $frameRules[] = self::restrictedSources($policy['frame-ancestors'], true);
            if (end($frameRules) === true) { return ['present', 'CSPの frame-ancestors による埋め込み制限を確認しました。']; }
        }
        if (in_array(null, $frameRules, true)) { return ['unknown', 'CSPの frame-ancestors を判定できませんでした。']; }
        if ($frameRules) { return ['absent', '適用される frame-ancestors による埋め込み先の制限がありません。']; }
        if (!$values) { return ['absent', '埋め込みを制限するヘッダーがありません。']; }
        $tokens = array_unique(array_map('strtoupper', $values));
        if (count($tokens) !== 1) { return ['unknown', '複数の X-Frame-Options の値が一致しません。']; }
        $value = reset($tokens);
        return in_array($value, ['DENY', 'SAMEORIGIN'], true) ? ['present', $value]
            : ['absent', 'X-Frame-Options に有効な制限値がありません。'];
    }

    private static function nosniff(array $values): array
    {
        if (!$values) { return ['absent', 'この応答にヘッダーの送信はありません。']; }
        $tokens = array_unique(array_map('strtolower', $values));
        if (count($tokens) !== 1) { return ['unknown', '複数の値が一致しません。']; }
        return reset($tokens) === 'nosniff' ? ['present', 'nosniff'] : ['absent', 'nosniff が設定されていません。'];
    }

    private static function referrer(array $values): array
    {
        if (!$values) { return ['absent', 'この応答にヘッダーの送信はありません。']; }
        $limited = ['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'origin', 'origin-when-cross-origin'];
        $known = array_merge($limited, ['unsafe-url', 'no-referrer-when-downgrade']);
        foreach (array_reverse(explode(',', implode(',', $values))) as $policy) {
            $policy = trim($policy);
            if (!in_array($policy, $known, true)) { continue; }
            return [in_array($policy, $limited, true) ? 'present' : 'absent', $policy];
        }
        return ['unknown', '参照元の送信方針を判定できませんでした。'];
    }

    private static function permissions(array $values): array
    {
        if (!$values) { return ['absent', 'この応答にヘッダーの送信はありません。']; }
        $limits = [];
        $unrecognized = false;
        $seen = [];
        foreach (explode(',', implode(',', $values)) as $part) {
            if (!preg_match('/^([a-z][a-z0-9-]*)\s*=\s*(\*|\([^()]*\))$/D', trim($part), $match)
                || isset($seen[$match[1]])) {
                return ['unknown', 'ブラウザー機能の制限を判定できませんでした。'];
            }
            $seen[$match[1]] = true;
            if (!in_array($match[1], ['camera', 'microphone', 'geolocation'], true) || $match[2] === '*') { continue; }
            if (preg_match('~^\(\s*(?:(?:self|"https://[a-z0-9.-]+(?::[0-9]+)?")(?:\s+|(?=\))))*\)$~iD', $match[2])) {
                $limits[] = $match[1];
            } else {
                $unrecognized = true;
            }
        }
        if ($limits) { return ['present', '利用範囲を制限している機能 ' . implode('・', $limits)]; }
        return $unrecognized ? ['unknown', '対象機能の許可範囲を判定できませんでした。']
            : ['absent', 'カメラ・マイク・位置情報の利用範囲の制限は確認できませんでした。'];
    }

    private static function cookie(array $values, string $sessionName): array
    {
        $observed = [];
        foreach ($values as $value) {
            $parts = array_map('trim', explode(';', $value));
            $pair = explode('=', array_shift($parts), 2);
            if (count($pair) !== 2 || $pair[0] !== $sessionName) { continue; }
            $attributes = [];
            foreach ($parts as $part) {
                [$name, $attribute] = array_pad(explode('=', $part, 2), 2, '');
                $attributes[strtolower(trim($name))] = strtolower(trim($attribute));
            }
            $sameSite = $attributes['samesite'] ?? '';
            $validSameSite = in_array($sameSite, ['lax', 'strict', 'none'], true);
            $observed[] = [
                array_key_exists('secure', $attributes),
                array_key_exists('httponly', $attributes),
                $validSameSite ? ucfirst($sameSite) : null,
            ];
        }
        if (!$observed) { return ['unknown', 'この応答ではセッションCookieの発行を確認できませんでした。']; }
        if (count(array_unique($observed, SORT_REGULAR)) !== 1) {
            return ['unknown', 'セッションCookieが複数あり、保護属性が一致しません。'];
        }
        [$secure, $httpOnly, $sameSite] = $observed[0];
        return [$secure && $httpOnly && $sameSite !== null ? 'present' : 'absent',
            'Secure ' . ($secure ? 'あり' : 'なし') . ' ／ HttpOnly ' . ($httpOnly ? 'あり' : 'なし') . ' ／ SameSite ' . ($sameSite ?? '未指定')];
    }

    private static function cache(array $values): array
    {
        if (!$values) { return ['absent', 'この応答に Cache-Control はありません。']; }
        $directives = [];
        foreach (explode(',', implode(',', $values)) as $part) {
            $part = strtolower(trim($part));
            if (in_array($part, ['no-store', 'no-cache', 'private', 'public', 'must-revalidate'], true)
                || preg_match('/^(?:s-maxage|max-age)=[0-9]+$/D', $part)) { $directives[] = $part; }
        }
        return $directives ? ['present', implode(', ', array_unique($directives))]
            : ['unknown', 'ヘッダーの送信は確認できましたが、キャッシュ指示を判定できませんでした。'];
    }

    private static function cors(array $headers): array
    {
        $origins = $headers['access-control-allow-origin'] ?? [];
        if (!$origins) { return ['unknown', 'この応答に Access-Control-Allow-Origin はありません。オリジンごとの設定は未確認です。']; }
        if (count($origins) !== 1 || (!in_array($origins[0], ['*', 'null'], true)
            && !preg_match('~^https?://(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]+)?$~iD', $origins[0]))) {
            return ['unknown', '許可オリジンを判定できませんでした。'];
        }
        $origin = $origins[0] === '*' ? 'すべてのオリジン（*）' : ($origins[0] === 'null' ? 'null' : '特定のオリジン');
        $credentials = ($headers['access-control-allow-credentials'] ?? []) === ['true'] ? 'true' : '有効な値なし';
        return ['present', '許可オリジン ' . $origin . ' ／ Access-Control-Allow-Credentials ' . $credentials . '。今回の応答で観測した値です。'];
    }

    private static function server(array $headers): array
    {
        $server = isset($headers['server']);
        $runtime = isset($headers['x-powered-by']);
        return [$server || $runtime ? 'present' : 'absent',
            'Server ' . ($server ? '送信あり' : '送信なし') . ' ／ X-Powered-By ' . ($runtime ? '送信あり' : '送信なし')];
    }
}
