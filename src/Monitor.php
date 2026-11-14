<?php
namespace NxMultiTool;

final class Monitor
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 100;
    public const MEMO_LIMIT = 500;
    public const SUMMARY_DAYS = 30;

    private const LOG_ORDER = [
        'date' => 'logs.created_at', 'http' => 'logs.status', 'ip' => 'logs.ip', 'blocked' => '',
        'url' => 'logs.url', 'referrer' => 'logs.referrer', 'ua' => 'logs.ua',
        'comments' => 'COALESCE(comment_totals.comment_count, 0)', 'memo' => "COALESCE(memos.memo, '')",
    ];
    private const SUMMARY_ORDER = [
        'ip' => 'ips.ip', 'count' => 'ips.visits', 'blocked' => '', 'date' => 'ips.latest',
        'memo' => "COALESCE(memos.memo, '')",
    ];

    public static function normalizeIp(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { return ''; }
        $binary = inet_pton($ip);
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") { return inet_ntop(substr($binary, 12)); }
        return strtolower(inet_ntop($binary));
    }
    public static function ip(): string { return self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? '')); }
    public static function safeUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        return substr(preg_replace('/[\x00-\x1f\x7f]/', '', $path) ?? '', 0, 2048);
    }
    public static function blocks(): array
    {
        return self::activeEntries(Storage::read('blocks'));
    }
    private static function activeEntries(array $entries): array
    {
        $now = time();
        return array_filter($entries, static fn($entry) => is_array($entry)
            && (($entry['permanent'] ?? false) === true || (int)($entry['until'] ?? 0) > $now));
    }
    public static function blocked(string $ip): bool
    {
        $ip = self::normalizeIp($ip);
        return $ip !== '' && !in_array($ip, Settings::all()['allow_ips'], true) && isset(self::blocks()[$ip]);
    }
    public static function block(string $ip, bool $remove): void
    {
        $ip = self::normalizeIp($ip); $settings = Settings::all();
        if ($ip === '' || (!$remove && ($ip === self::ip() || in_array($ip, $settings['allow_ips'], true)))) {
            throw new \InvalidArgumentException('このIPは拒否できません。自分のIPと許可リストを確認してください。');
        }
        Storage::transaction('blocks', static function ($old) use ($ip, $remove, $settings) {
            $old = self::activeEntries($old);
            if ($remove) { unset($old[$ip]); }
            elseif (($old[$ip]['permanent'] ?? false) !== true) {
                if (count($old) >= 2000 && !isset($old[$ip])) { throw new \RuntimeException('拒否リストは2000件までです。'); }
                $old[$ip] = ['until' => time() + (int)$settings['block_hours'] * 3600];
            }
            return [$old, null];
        });
    }

    public static function blockSelected(array $ids): int
    {
        $addresses = Database::query('SELECT ip FROM ' . Database::table('logs') . ' WHERE id IN (' .
            implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll(\PDO::FETCH_COLUMN);
        if (count($addresses) !== count($ids)) { throw new \RuntimeException('選択したログが見つかりません。再選択してください。'); }
        $ips = array_unique(array_map([self::class, 'normalizeIp'], $addresses));
        $unavailable = array_merge(['', self::ip()], Settings::all()['allow_ips']);
        if (array_intersect($ips, $unavailable)) {
            throw new \InvalidArgumentException('IPが不明なログ、自分のIP、許可リストのIPは拒否できません。選択を確認してください。');
        }
        return Storage::transaction('blocks', static function (array $old) use ($ips): array {
            $entries = array_replace(self::activeEntries($old), array_fill_keys($ips, ['permanent' => true]));
            if (count($entries) > 2000) { throw new \RuntimeException('拒否リストは2000件までです。'); }
            return [$entries, count($ips)];
        });
    }
    public static function boot(): void
    {
        $path = self::safeUrl((string)($_SERVER['REQUEST_URI'] ?? ''));
        $base = rtrim((string)parse_url(\url(), PHP_URL_PATH), '/');
        $relative = $base !== '' && str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : $path;
        if (preg_match('#^/(admin|install)(/|$)#', $relative)) { return; }
        if (self::blocked(self::ip())) { http_response_code(403); header('Cache-Control: no-store'); exit('アクセスが制限されています。'); }
        if (!Settings::enabled('access_log') || str_starts_with($relative, '/nx-multi-tool/')) { return; }
        register_shutdown_function(static function () use ($path): void {
            try { self::record($path); } catch (\Throwable $exception) { error_log('[NX Multi Tool] ログ保存失敗: ' . get_class($exception)); }
        });
    }
    public static function record(string $path): void
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD'], true) || \app\Core\Auth::check() || preg_match('/\.(?:css|js|png|jpe?g|gif|webp|ico|woff2?|mp3|mp4)$/i', $path)) { return; }
        $settings = Settings::all(); $ip = self::ip(); $now = time();
        // Serialize the count-and-insert operation across PHP workers.
        Storage::transaction('log-rate', static function ($state) use ($path, $method, $settings, $ip, $now) {
            $count = (int)Database::query('SELECT COUNT(*) FROM ' . Database::table('logs') . ' WHERE created_at >= ?', [$now - 60])->fetchColumn();
            $own = (int)Database::query('SELECT COUNT(*) FROM ' . Database::table('logs') . ' WHERE created_at >= ? AND ip = ?', [$now - 60, $ip])->fetchColumn();
            if ($count >= $settings['log_per_minute'] || $own >= $settings['log_per_ip']) { return [null, null]; }
            Database::query('INSERT INTO ' . Database::table('logs') . ' (created_at,user_id,method,status,ip,url,referrer,ua) VALUES (?,?,?,?,?,?,?,?)',
                [$now, 0, $method, http_response_code() ?: 200, $ip, $path,
                    self::safeUrl((string)($_SERVER['HTTP_REFERER'] ?? '')),
                    substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? '', 0, 500)]);
            self::prune($settings);
            return [null, null];
        });
    }
    public static function prune(?array $settings = null): void
    {
        $settings = $settings ?? Settings::all();
        Database::query('DELETE FROM ' . Database::table('logs') . ' WHERE created_at < ?', [time() - $settings['log_days'] * 86400]);
        $cut = Database::query('SELECT id FROM ' . Database::table('logs') . ' ORDER BY id DESC LIMIT 1 OFFSET ' . (int)$settings['log_max'])->fetchColumn();
        if ($cut !== false) { Database::query('DELETE FROM ' . Database::table('logs') . ' WHERE id <= ?', [(int)$cut]); }
    }
    public static function sort(array $input, bool $summary = false): array
    {
        $column = $input[$summary ? 'ip_sort' : 'sort'] ?? '';
        if (!is_string($column) || !isset(($summary ? self::SUMMARY_ORDER : self::LOG_ORDER)[$column])) {
            return ['column' => $summary ? 'count' : 'date', 'direction' => 'desc'];
        }
        return ['column' => $column, 'direction' => ($input[$summary ? 'ip_dir' : 'dir'] ?? '') === 'asc' ? 'asc' : 'desc'];
    }

    private static function blockOrder(string $column, ?array $blocks): array
    {
        $blocks ??= array_diff_key(self::blocks(), array_flip(Settings::all()['allow_ips']));
        if (!$blocks) { return ["($column IS NULL)", []]; }
        $placeholders = implode(',', array_fill(0, count($blocks), '?'));
        return ["($column IN ($placeholders))", array_keys($blocks)];
    }

    private static function searchPattern(string $value): string
    {
        return '%' . strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
    }

    public static function logs(array $input, int $limit = self::DEFAULT_PER_PAGE, int $offset = 0, ?array $blocks = null): array
    {
        $from = ' FROM ' . Database::table('logs') . ' AS logs';
        $where = ' WHERE 1=1'; $params = [];
        $q = trim(Access::text($input, 'q', 300));
        if ($q !== '') {
            $value = self::searchPattern($q);
            $ip = self::normalizeIp($q);
            $ipMatch = $ip === '' ? "logs.ip LIKE ? ESCAPE '='" : 'logs.ip = ?';
            $where .= " AND (logs.url LIKE ? ESCAPE '=' OR logs.referrer LIKE ? ESCAPE '=' OR logs.ua LIKE ? ESCAPE '=' OR $ipMatch)";
            array_push($params, $value, $value, $value, $ip === '' ? $value : $ip);
        }
        if (!empty($input['ip'])) { $where .= ' AND logs.ip = ?'; $params[] = self::normalizeIp(Access::text($input, 'ip', 45)); }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = Access::text($input, $key, 10);
            if ($value !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('日付が不正です。'); }
                $where .= ' AND logs.created_at ' . $operator . ' ?'; $params[] = ($key === 'to' ? $date->modify('+1 day') : $date)->getTimestamp();
            }
        }
        $total = (int)Database::query('SELECT COUNT(*)' . $from . $where, $params)->fetchColumn();
        $sort = self::sort($input);
        $column = $sort['column'];
        $order = self::LOG_ORDER[$column];
        if ($column === 'memo') {
            $from .= ' LEFT JOIN ' . Database::table('ip_memos') . ' AS memos ON memos.ip = logs.ip';
        } elseif ($column === 'comments') {
            $from .= ' LEFT JOIN (' . Comments::ipCountQuery() . ') AS comment_totals ON comment_totals.ip = logs.ip';
        } elseif ($column === 'blocked') {
            [$order, $bindings] = self::blockOrder('logs.ip', $blocks);
            $params = array_merge($params, $bindings);
        }
        $direction = strtoupper($sort['direction']);
        $rows = Database::query('SELECT logs.*' . $from . $where . " ORDER BY $order $direction, logs.id DESC LIMIT " .
            max(1, min(1000, $limit)) . ' OFFSET ' . max(0, $offset), $params)->fetchAll();
        return ['rows' => $rows, 'total' => $total];
    }

    public static function ipSummary(int $page, array $input = [], ?array $blocks = null): array
    {
        $table = Database::table('logs');
        $now = time();
        $range = [$now - self::SUMMARY_DAYS * 86400, $now];
        $search = trim(Access::text($input, 'ip_q', 300));
        $sort = self::sort($input, true);
        $limit = Access::integer($input, 'ip_per_page', 1, self::MAX_PER_PAGE, self::DEFAULT_PER_PAGE);
        $from = " FROM (
            SELECT ip, COUNT(*) AS visits, MAX(created_at) AS latest
            FROM $table WHERE created_at BETWEEN ? AND ? AND ip <> '' GROUP BY ip
        ) AS ips";
        if ($search !== '' || $sort['column'] === 'memo') {
            $from .= ' LEFT JOIN ' . Database::table('ip_memos') . ' AS memos ON memos.ip = ips.ip';
        }
        $params = $range;
        if ($search !== '') {
            $ip = self::normalizeIp($search);
            $match = $ip === '' ? "ips.ip LIKE ? ESCAPE '='" : 'ips.ip = ?';
            $from .= " WHERE ($match OR memos.memo LIKE ? ESCAPE '=')";
            $pattern = self::searchPattern($search);
            array_push($params, $ip === '' ? $pattern : $ip, $pattern);
        }
        $total = (int)Database::query('SELECT COUNT(*)' . $from, $params)->fetchColumn();
        $page = max(1, min($page, (int)ceil($total / $limit)));
        $offset = ($page - 1) * $limit;
        $dates = [];
        if ($total > 0) {
            $order = self::SUMMARY_ORDER[$sort['column']];
            if ($sort['column'] === 'blocked') {
                [$order, $bindings] = self::blockOrder('ips.ip', $blocks);
                $params = array_merge($params, $bindings);
            }
            $direction = strtoupper($sort['direction']);
            $ips = Database::query('SELECT ips.ip' . $from . " ORDER BY $order $direction, ips.latest DESC, ips.ip ASC
                LIMIT $limit OFFSET $offset", $params)->fetchAll(\PDO::FETCH_COLUMN);
            if ($ips) {
                $dates = array_fill_keys($ips, []);
                $placeholders = implode(',', array_fill(0, count($ips), '?'));
                $stmt = Database::query("SELECT ip, created_at FROM $table WHERE created_at BETWEEN ? AND ?
                    AND ip IN ($placeholders) ORDER BY created_at DESC, id DESC", array_merge($range, $ips));
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $dates[$row['ip']][] = (int)$row['created_at'];
                }
            }
        }
        return ['dates' => array_filter($dates), 'total' => $total, 'page' => $page, 'per_page' => $limit];
    }

    public static function memos(array $ips): array
    {
        $ips = array_values(array_unique(array_filter($ips)));
        if (!$ips) { return []; }
        $placeholders = implode(',', array_fill(0, count($ips), '?'));
        return Database::query('SELECT ip, memo FROM ' . Database::table('ip_memos') . " WHERE ip IN ($placeholders)", $ips)
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public static function saveMemo(string $ip, string $memo): string
    {
        $ip = self::normalizeIp($ip);
        if ($ip === '') { throw new \InvalidArgumentException('IPアドレスを確認してください。'); }
        if (!preg_match('/\A[^\x00-\x1f\x7f]{0,' . self::MEMO_LIMIT . '}\z/u', $memo)) {
            throw new \InvalidArgumentException('メモは改行なしで' . self::MEMO_LIMIT . '文字以内にしてください。');
        }
        $memo = trim($memo);
        $table = Database::table('ip_memos');
        if ($memo === '') {
            Database::query("DELETE FROM $table WHERE ip = ?", [$ip]);
            return '';
        }
        $upsert = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE memo = ?' : 'ON CONFLICT (ip) DO UPDATE SET memo = ?';
        Database::query("INSERT INTO $table (ip, memo) VALUES (?, ?) $upsert", [$ip, $memo, $memo]);
        return $memo;
    }

    public static function saveMemoFrom(array $input): string
    {
        return self::saveMemo(Access::text($input, 'ip', 45), Access::text($input, 'memo', 4 * self::MEMO_LIMIT));
    }

    public static function listQuery(array $input): array
    {
        $keys = ['q', 'ip', 'from', 'to', 'per_page', 'page', 'sort', 'dir', 'ip_q', 'ip_per_page', 'ip_page', 'ip_sort', 'ip_dir'];
        $query = array_intersect_key($input, array_fill_keys($keys, true));
        return array_filter($query, static fn($value) => is_string($value) && strlen($value) <= 300);
    }
}
