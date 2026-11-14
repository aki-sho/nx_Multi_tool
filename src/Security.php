<?php
namespace NxMultiTool;

final class Security
{
    public const SOURCES = ['cms' => 'NexaPress内', 'server' => 'サーバー側', 'http' => '公開応答'];
    public const STATES = ['present' => '確認あり', 'absent' => '設定なし', 'unknown' => '未確認'];
    private const CATEGORY_POINTS = ['high' => 6, 'medium' => 4, 'low' => 2];

    public static function inspect(?array $sources = null, bool $settingsChanged = false): array
    {
        $initial = $sources === null;
        $sources = array_replace(array_fill_keys(array_keys(self::SOURCES), $initial), $sources ?? []);
        $cms = $sources['cms'];
        $server = $sources['server'];
        $policies = self::attempt(static fn() => PublicHeaders::settings());
        $headers = SecurityHeaders::inspect(SiteInfo::publicUrl(), !$initial && !$settingsChanged && $sources['http'], $policies ?? [], $settingsChanged);

        $version = $cms ? self::attempt(static function () {
            $config = require BASE_PATH . '/config/version.php';
            return is_array($config) && is_string($config['version'] ?? null) ? $config['version'] : null;
        }) : null;
        $extensions = $cms ? self::attempt(static fn() => \app\Core\Extension::all()) : null;
        $guardLoaded = class_exists(\NexaPressAccessGuard\Settings::class, false);
        $guardInactive = !$guardLoaded && $extensions !== null && empty($extensions['access-guard']['is_enabled']);
        $guard = $cms && $guardLoaded ? self::attempt(static fn() => \NexaPressAccessGuard\Settings::all()) : null;
        $debug = $cms ? self::attempt(static fn() => \app\Core\Debug::enabled()) : null;
        $apache = $cms ? array_filter([self::read('.htaccess'), self::read('public/.htaccess')], 'is_string') : [];
        $phpUpload = $server ? self::iniFlag('file_uploads') : null;
        $uploadSize = $server ? ini_get('upload_max_filesize') : false;
        $displayErrors = $server ? ini_get('display_errors') : false;
        $cookie = $server ? session_get_cookie_params() : null;

        $inventory = [];
        if ($version !== null) { $inventory[] = 'NexaPress ' . $version; }
        if ($extensions !== null) {
            foreach ($extensions as $extension) { $inventory[] = $extension['name'] . ' ' . $extension['version']; }
        }
        $facts = [];
        if ($version !== null) { $facts['NexaPress'] = $version; }
        if ($cms) { $facts['NX Multi Tool'] = Registry::VERSION; }
        if ($server) { $facts['PHP'] = PHP_VERSION; }

        $guardItem = static function (string $name, string $field, string $location, string $enabledDetail, bool $scored = true) use ($guard, $guardInactive): array {
            $active = $guard[$field]['enabled'] ?? null;
            $state = is_bool($active) ? $active : ($guardInactive ? false : null);
            $detail = $guardInactive ? 'Access Guardは有効になっていません。'
                : ($state === true ? $enabledDetail : ($state === false ? 'この設定は無効です。' : 'Access Guardの設定を取得できませんでした。'));
            return self::item($name, 'cms', 'Access Guard › ' . $location, $state, $detail, $scored);
        };
        $code = static function (string $name, string $file, array $markers, string $detail, bool $scored = true) use ($cms): array {
            $found = $cms && self::hasCode($file, $markers);
            return self::item($name, 'cms', $file, $found ? true : null,
                $found ? $detail : '対象の処理を確認できませんでした。', $scored);
        };
        $responseItem = static function (array $row, bool $scored = true): array {
            return self::item($row['name'], 'http', '未ログインで取得した公開トップの応答ヘッダー',
                ['present' => true, 'absent' => false][$row['state']] ?? null, $row['detail'], $scored);
        };

        $loginLock = 'ログイン失敗時の制限が有効です。';
        if (is_int($guard['login_lock']['attempts'] ?? null) && is_int($guard['login_lock']['duration'] ?? null)) {
            $loginLock = $guard['login_lock']['attempts'] . '回の失敗で' . $guard['login_lock']['duration'] . '分間ロックします。';
        }
        $uploadsRule = self::apacheEvidence($apache, static fn($text) => preg_match('/^RewriteRule\s+\^.*php.*\[[^\]]*F[^\]]*\]/im', $text) === 1);
        $internalRule = self::apacheEvidence($apache, static fn($text) => preg_match('/^RewriteRule\s+\^.*config.*storage.*\[[^\]]*F[^\]]*\]/im', $text) === 1);
        $sameSite = strtolower((string)($cookie['samesite'] ?? ''));
        $sessionCookie = $cookie === null ? 'PHPの設定値は未確認です。'
            : 'Secure ' . (!empty($cookie['secure']) ? 'あり' : 'なし')
                . ' ／ HttpOnly ' . (!empty($cookie['httponly']) ? 'あり' : 'なし')
                . ' ／ SameSite ' . (in_array($sameSite, ['lax', 'strict', 'none'], true) ? ucfirst($sameSite) : '未指定');
        $cache = self::nitroCache($cms, $extensions);
        $frame = $headers['rows']['frame'];
        $frame['name'] = 'X-Frame-Options / frame-ancestors';

        $groups = [
            ['ブルートフォース', 'both', 'high', [
                $guardItem('ログイン試行回数の制限', 'login_lock', 'ログインロック', $loginLock),
                $guardItem('ログインページのIP許可制', 'login_page_access', 'ログインページへのアクセス制限', '登録したIPだけにログインページへのアクセスを許可する設定です。'),
                self::item('リクエスト回数の制限', 'server', 'Webサーバー › リクエスト制限', null, 'サーバーの制限値を取得できません。'),
            ]],
            ['クレデンシャルスタッフィング', 'cms', 'high', [self::twoFactor($cms && $guardLoaded, $guardInactive)]],
            ['SQLインジェクション', 'cms', 'high', [
                $code('公開記事検索で入力値を分けて渡す処理', 'app/Models/Post.php',
                    ['function publicArchive(', '" AND (p.title LIKE :archive_title', 'self::bindValues($stmt, $params)', '$stmt->execute()'],
                    '本体の公開記事検索で検索語のパラメータ化を確認しました。'),
            ]],
            ['XSS', 'both', 'high', [
                $code('固定ページの本文を無害化する処理', 'app/Core/PageValidator.php', ['HtmlSanitizer::sanitize('],
                    '本体の固定ページ保存処理で本文の無害化を確認しました。'),
                $code('投稿の本文を無害化する処理', 'app/Core/PostValidator.php', ['HtmlSanitizer::sanitize('],
                    '本体の投稿保存処理で本文の無害化を確認しました。'),
                $responseItem($headers['rows']['csp']),
                $responseItem($headers['rows']['nosniff']),
            ]],
            ['DDoS', 'server', 'medium', [
                self::item('接続数・リクエスト数の制限', 'server', 'Webサーバー › 接続・リクエスト制限', null, 'サーバー内部の制限値を取得できません。'),
            ]],
            ['CSRF', 'cms', 'high', [
                $code('セキュリティチェックなどの管理APIでトークンを検証', 'extensions/' . basename(dirname(__DIR__)) . '/src/Access.php',
                    ['\app\Core\Csrf::requireValid('], 'NX Multi Toolの管理APIで使うトークン検証を確認しました。'),
                $code('本体の設定保存でトークンを検証', 'app/Controllers/Admin/SettingController.php',
                    ['Csrf::requireValid('], '本体の一般設定を保存する処理で確認しました。'),
            ]],
            ['リモートコード実行（RCE）', 'both', 'high', [
                $code('メディアを保存する前に形式を検査', 'app/Controllers/Admin/MediaController.php',
                    ['mime_content_type(', 'isset($this->allowedTypes[$mimeType])', 'move_uploaded_file('], '本体のメディア登録で保存前の形式検査を確認しました。', false),
                self::item('アップロード領域のスクリプト実行制限', 'cms', '設置先の .htaccess とWebサーバー設定', null, $uploadsRule),
            ]],
            ['ファイルアップロード', 'both', 'medium', [
                $code('メディアの許可形式に照合', 'app/Controllers/Admin/MediaController.php',
                    ['mime_content_type(', 'isset($this->allowedTypes[$mimeType])'], '登録時に実際のMIME形式を許可一覧と照合します。'),
                self::item('PHP標準のファイル受信を無効化', 'server', 'PHP › file_uploads', $phpUpload === null ? null : !$phpUpload, $phpUpload === null
                    ? 'PHPの設定値を取得できません。' : ($phpUpload ? 'ファイル受信が有効です。' : 'ファイル受信が無効です。'), false),
                self::item('アップロードのサイズ上限', 'server', 'PHP › upload_max_filesize', is_string($uploadSize) && trim($uploadSize) !== '' ? true : null,
                    is_string($uploadSize) && trim($uploadSize) !== '' ? trim($uploadSize) : 'PHPの上限を取得できません。', false),
            ]],
            ['既知脆弱性', 'versions', 'high', [
                self::item('本体・拡張の導入バージョン', 'cms', 'NexaPress本体・インストール済み拡張機能',
                    $version !== null && $extensions !== null ? true : null, $inventory ? implode(' ／ ', $inventory) : 'バージョンを取得できません。', false),
                self::item('既知脆弱性への該当', 'cms', 'NexaPress本体・拡張機能の導入情報', null, 'このチェックでは導入バージョンを参照できます。'),
            ]],
            ['ディレクトリトラバーサル', 'cms', 'medium', [
                $code('拡張機能ZIPの展開先の制限', 'app/Core/ExtensionInstaller.php',
                    ['function extractPackage(', "str_starts_with(\$entryName, '/')", "str_contains(\$entryName, ':')", 'preg_match('],
                    '本体の拡張機能導入処理でZIP内のパスを検証しています。'),
            ]],
            ['権限・アクセス制御', 'both', 'high', [
                self::item('セキュリティチェックの管理権限', 'cms', 'NX Multi Tool › 拡張機能の管理権限', true, 'この画面の利用には extensions.manage 権限が必要です。'),
                $guardItem('ログインURLの変更', 'login_url', 'ログインページの変更', '変更設定が有効です。', false),
                $guardItem('管理画面のIP許可制', 'admin_page_access', '管理画面へのアクセス制限', '管理画面のアクセス制限が有効です。'),
                self::item('管理領域のアクセス制限', 'server', 'Webサーバー › 管理領域へのアクセス設定', null, 'Webサーバーでの制限は未確認です。'),
            ]],
            ['セッション', 'both', 'high', [
                $code('ログイン時のセッションID再生成', 'app/Core/Auth.php', ['function login(', 'session_regenerate_id(true)'],
                    'NexaPress標準のログイン処理でIDの再生成を確認しました。'),
                self::item('PHPセッションCookieの保護属性', 'server', 'PHP › セッションCookie設定', $cookie === null ? null
                    : (!empty($cookie['secure']) && !empty($cookie['httponly']) && in_array($sameSite, ['lax', 'strict', 'none'], true)), $sessionCookie),
                $responseItem($headers['supplement']['cookie'], false),
            ]],
            ['SSRF', 'cms', 'medium', [
                $code('URL収集の通信先・転送先の制限', 'extensions/' . basename(dirname(__DIR__)) . '/src/HttpClient.php',
                    ['self::origin($p) !== self::origin($root)', 'self::publicIp($ip)', 'CURLOPT_RESOLVE', 'CURLOPT_FOLLOWLOCATION => false', 'self::resolve($headers['],
                    'NX Multi ToolのURL収集で、同一サイトと公開IPへの制限を確認しました。'),
            ]],
            ['クリックジャッキング', 'server', 'high', [$responseItem($frame)]],
            ['通信の盗聴・改ざん', 'server', 'high', [
                self::item('公開トップのHTTPS応答', 'http', '未ログインで取得した公開トップの応答',
                    $headers['collected'] ? parse_url($headers['url'], PHP_URL_SCHEME) === 'https' : null,
                    $headers['collected'] ? '応答を取得したURLの方式 ' . strtoupper((string)parse_url($headers['url'], PHP_URL_SCHEME)) : $headers['message']),
                $responseItem($headers['rows']['hsts']),
            ]],
            ['公開ファイルからの情報漏えい', 'both', 'high', [
                self::item('設定・ログの直接アクセス制限', 'cms', '設置先の .htaccess とWebサーバー設定', null, $internalRule),
                self::item('CMSのデバッグ出力を無効化', 'cms', 'NexaPress › config/debug.php', $debug === null ? null : !$debug,
                    $debug === null ? '設定値を取得できません。' : ($debug ? 'デバッグが有効です。' : 'デバッグが無効です。')),
                self::item('PHPのエラー公開を無効化', 'server', 'PHP › display_errors', $displayErrors === false ? null : !self::flag($displayErrors),
                    $displayErrors === false ? '設定値を取得できません。' : (self::flag($displayErrors) ? 'エラーの画面出力が有効です。' : 'エラーの画面出力が無効です。')),
                $responseItem($headers['rows']['referrer']),
            ]],
            ['サプライチェーン', 'cms', 'medium', [
                $code('本体更新ZIPの整合性を確認する処理', 'app/Core/UpdatePackage.php', ["hash_file('sha256',", 'hash_equals($expectedHash, $actualHash)'],
                    '本体の更新処理が配布情報のSHA-256と取得したZIPを照合します。'),
                $code('拡張カタログの配布元設定', 'config/extensions.php', ["'catalog_url'", "'allowed_download_hosts'"],
                    '受け付ける拡張ZIPの配布元を設定する記述を確認しました。'),
            ]],
            ['オープンリダイレクト', 'cms', 'low', [
                $code('NX Multi Toolの設定保存後の転送先', 'extensions/' . basename(dirname(__DIR__)) . '/src/AdminController.php',
                    ["header('Location: ' . Registry::url(), true, 303)"], '確認した設定保存処理の転送先は拡張機能のダッシュボードです。'),
            ]],
            ['Webキャッシュ', 'both', 'medium', [
                $cache,
                $responseItem($headers['supplement']['cache'], false),
                self::item('サーバーの共有キャッシュ設定', 'server', 'Webサーバー › キャッシュ設定', null, '配信環境のキャッシュ方針を取得できません。'),
            ]],
            ['CORS', 'server', 'low', [
                $responseItem($headers['supplement']['cors'], false),
                self::item('連携先だけを許可するCORS設定', 'server', 'Webサーバー・API › CORS設定', null,
                    'この確認では、連携先ごとの許可範囲や認証付き応答の設定を取得できません。'),
            ]],
        ];

        $items = [];
        $categoryScore = ['max' => 0, 'earned' => 0, 'pending' => 0];
        foreach ($groups as $index => [$name, $area, $importance, $rows]) {
            foreach ($rows as &$row) {
                if (!$sources[$row['source']]) {
                    $row['state'] = 'unknown';
                    $row['detail'] = self::SOURCES[$row['source']] . 'が読み取り元で選択されていません。';
                }
            }
            unset($row);
            $score = self::categoryScore($rows, self::CATEGORY_POINTS[$importance]);
            foreach ($score as $key => $points) { $categoryScore[$key] += $points; }
            $items[] = ['number' => $index + 1, 'name' => $name, 'area' => $area, 'importance' => $importance,
                'rows' => $rows, 'score' => $score];
        }

        return ['items' => $items, 'facts' => $facts, 'public' => $headers, 'policies' => $policies,
            'category_score' => $categoryScore, 'category_points' => self::CATEGORY_POINTS, 'checked_at' => time(),
            'observations' => $cms ? self::logs() : null];
    }

    private static function item(string $name, string $source, string $location, ?bool $enabled, string $detail, bool $scored = true): array
    {
        $state = $enabled === null ? 'unknown' : ($enabled ? 'present' : 'absent');
        return compact('name', 'source', 'location', 'state', 'detail', 'scored');
    }

    private static function categoryScore(array $rows, int $maxPoints): array
    {
        // Use tenths of a point so displayed category totals add up exactly.
        $max = $maxPoints * 10;
        $states = array_count_values(array_column(array_filter($rows, static fn(array $row): bool => $row['scored']), 'state'));
        $total = array_sum($states);
        if ($total === 0) { return ['max' => $max, 'earned' => 0, 'pending' => $max]; }
        $pending = (int)round($max * ($states['unknown'] ?? 0) / $total);
        $earned = min($max - $pending, (int)round($max * ($states['present'] ?? 0) / $total));
        return compact('max', 'earned', 'pending');
    }

    private static function attempt(callable $read)
    {
        try { return $read(); }
        catch (\Throwable $e) { return null; }
    }

    private static function iniFlag(string $name): ?bool
    {
        $value = ini_get($name);
        return $value === false ? null : self::flag($value);
    }

    private static function flag(string $value): bool
    {
        return !in_array(strtolower(trim($value)), ['', 'off', '0', 'false', 'no'], true);
    }

    private static function read(string $path): ?string
    {
        $base = realpath(BASE_PATH);
        $file = $base === false ? false : realpath($base . '/' . $path);
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) { return null; }
        $text = @file_get_contents($file, false, null, 0, 262145);
        return is_string($text) && strlen($text) <= 262144 ? $text : null;
    }

    private static function codeTokens(string $source): string
    {
        $text = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) { $text .= $token; }
            elseif (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) { $text .= $token[1]; }
        }
        return $text;
    }

    private static function hasCode(string $file, array $markers): bool
    {
        $text = self::read($file);
        if ($text === null || !function_exists('token_get_all')) { return false; }
        $code = self::codeTokens($text);
        foreach ($markers as $marker) {
            if (!str_contains($code, self::codeTokens('<?php ' . $marker))) { return false; }
        }
        return true;
    }

    private static function apacheEvidence(array $files, callable $matches): string
    {
        foreach ($files as $file) {
            if ($matches($file)) { return '関連する制限の記述あり。Webサーバーでの適用は未確認です。'; }
        }
        return $files ? '読み取れたファイルから対象のルールを特定できませんでした。'
            : '.htaccess の内容を取得できませんでした。';
    }

    private static function twoFactor(bool $available, bool $inactive): array
    {
        $name = '管理者全員の二要素認証';
        $location = 'Access Guard › 二要素認証';
        if (!$available || !is_callable([\NexaPressAccessGuard\TwoFactor::class, 'all'])) {
            return self::item($name, 'cms', $location, $inactive ? false : null,
                $inactive ? 'Access Guardは有効になっていません。' : '二要素認証の設定を取得できません。');
        }
        $counts = self::attempt(static function (): array {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT id FROM ' . \app\Core\Database::table('users') . ' WHERE role = ?');
            $stmt->execute(['administrator']);
            $administrators = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $registered = \NexaPressAccessGuard\TwoFactor::all();
            return [count($administrators), count(array_intersect_key($registered, array_fill_keys($administrators, true)))];
        });
        if (!$counts || $counts[0] === 0) {
            return self::item($name, 'cms', $location, null, '管理者の登録状況を確認できません。');
        }
        return self::item($name, 'cms', $location, $counts[1] === $counts[0],
            '管理者ロール ' . $counts[0] . '名のうち ' . $counts[1] . '名が設定済みです。');
    }

    private static function nitroCache(bool $cms, ?array $extensions): array
    {
        $name = 'NX Nitro Cacheのセッション付きリクエストの除外';
        $location = 'NX Nitro Cache › キャッシュ対象';
        if (!$cms || $extensions === null) { return self::item($name, 'cms', $location, null, '導入状況を取得できません。'); }
        $extension = $extensions['nx_nitro_cache'] ?? null;
        if (empty($extension['is_enabled'])) { return self::item($name, 'cms', $location, false, 'NX Nitro Cacheは有効になっていません。', false); }
        $active = is_callable([\NxNitroCache\Settings::class, 'get'])
            ? self::attempt(static fn() => \NxNitroCache\Settings::get('enabled')) : null;
        if ($active === false) { return self::item($name, 'cms', $location, false, 'NX Nitro Cacheのページキャッシュが無効です。', false); }
        $detected = $active === true && self::hasCode('extensions/' . $extension['folder'] . '/includes/PageCache.php', ["if (!empty(\$_SESSION)) { return 'session'; }"]);
        return self::item($name, 'cms', $location, $detected ? true : null,
            $detected ? 'NX Nitro Cacheでセッション付きの応答が保存対象から外れる処理を確認しました。' : '現在のキャッシュ除外処理を確認できません。');
    }

    private static function logs(): ?string
    {
        if (!Settings::enabled('access_log')) { return null; }
        $rows = self::attempt(static function (): array {
            $stmt = Database::connection()->prepare(
                'SELECT status FROM ' . Database::table('logs') . ' WHERE created_at >= ? ORDER BY id DESC LIMIT 2000'
            );
            $stmt->execute([time() - 86400]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        });
        if ($rows === null) { return '集計できるアクセスログはありません。'; }
        $errors = count(array_filter($rows, static fn($status) => (int)$status >= 500 && (int)$status < 600));
        return '直近24時間の保存ログ（直近最大2,000件） ' . count($rows) . '件 ／ HTTP 5xx ' . $errors . '件';
    }
}
