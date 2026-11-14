<?php
namespace NxMultiTool;

final class Monitor
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 100;
    public const MEMO_LIMIT = 500;
    public const SUMMARY_DAYS = 30;

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
        $data = Storage::read('blocks'); $now = time();
        return array_filter($data, static fn($value) => is_array($value) && (int)($value['until'] ?? 0) > $now);
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
            $now = time(); $old = array_filter($old, static fn($row) => is_array($row) && ($row['until'] ?? 0) > $now);
            if ($remove) { unset($old[$ip]); }
            else {
                if (count($old) >= 2000 && !isset($old[$ip])) { throw new \RuntimeException('拒否リストは2000件までです。'); }
                $old[$ip] = ['until' => $now + (int)$settings['block_hours'] * 3600];
            }
            return [$old, null];
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
    public static function logs(array $input, int $limit = self::DEFAULT_PER_PAGE, int $offset = 0): array
    {
        $where = ' WHERE 1=1'; $params = [];
        $q = trim(Access::text($input, 'q', 300));
        if ($q !== '') {
            $escape = ['=' => '==', '%' => '=%', '_' => '=_'];
            $value = '%' . strtr($q, $escape) . '%';
            $ip = self::normalizeIp($q);
            $ipMatch = $ip === '' ? "ip LIKE ? ESCAPE '='" : 'ip = ?';
            $where .= " AND (url LIKE ? ESCAPE '=' OR referrer LIKE ? ESCAPE '=' OR ua LIKE ? ESCAPE '=' OR $ipMatch)";
            array_push($params, $value, $value, $value, $ip === '' ? $value : $ip);
        }
        if (!empty($input['ip'])) { $where .= ' AND ip = ?'; $params[] = self::normalizeIp(Access::text($input, 'ip', 45)); }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $value = Access::text($input, $key, 10);
            if ($value !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('日付が不正です。'); }
                $where .= ' AND created_at ' . $operator . ' ?'; $params[] = ($key === 'to' ? $date->modify('+1 day') : $date)->getTimestamp();
            }
        }
        $total = (int)Database::query('SELECT COUNT(*) FROM ' . Database::table('logs') . $where, $params)->fetchColumn();
        $rows = Database::query('SELECT * FROM ' . Database::table('logs') . $where . ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit)) . ' OFFSET ' . max(0, $offset), $params)->fetchAll();
        return ['rows' => $rows, 'total' => $total];
    }

    public static function ipSummary(int $page): array
    {
        $table = Database::table('logs');
        $now = time();
        $range = [$now - self::SUMMARY_DAYS * 86400, $now];
        $total = (int)Database::query("SELECT COUNT(DISTINCT ip) FROM $table WHERE created_at BETWEEN ? AND ? AND ip <> ''", $range)->fetchColumn();
        $limit = self::DEFAULT_PER_PAGE;
        $page = max(1, min($page, (int)ceil($total / $limit)));
        $offset = ($page - 1) * $limit;
        $dates = [];
        if ($total > 0) {
            $stmt = Database::query(
                "SELECT logs.ip, logs.created_at FROM (
                    SELECT ip, COUNT(*) AS visits, MAX(created_at) AS latest
                    FROM $table WHERE created_at BETWEEN ? AND ? AND ip <> '' GROUP BY ip
                    ORDER BY visits DESC, latest DESC, ip ASC
                    LIMIT $limit OFFSET $offset
                ) AS ranked JOIN $table AS logs ON logs.ip = ranked.ip AND logs.created_at BETWEEN ? AND ?
                ORDER BY ranked.visits DESC, ranked.latest DESC, ranked.ip ASC, logs.created_at DESC, logs.id DESC",
                array_merge($range, $range)
            );
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $dates[$row['ip']][] = (int)$row['created_at'];
            }
        }
        return ['dates' => $dates, 'total' => $total, 'page' => $page];
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
        $query = array_intersect_key($input, array_fill_keys(['q', 'ip', 'from', 'to', 'per_page', 'page', 'ip_page'], true));
        return array_filter($query, static fn($value) => is_string($value) && strlen($value) <= 300);
    }
}
