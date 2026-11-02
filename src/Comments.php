<?php
namespace NxMultiTool;

final class Comments
{
    public static function publicPage(string $type, string $id): void
    {
        header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'");
        header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=UTF-8');
        if (!Settings::enabled('comment_manager') || !Settings::all()['comments_open'] || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
            http_response_code(404); echo 'コメントの受付は停止しています。'; return;
        }
        $item = Content::find($type, (int)$id);
        if (!$item || ($item['status'] ?? '') !== 'published') { http_response_code(404); echo 'ページが見つかりません。'; return; }
        if (Monitor::blocked(Monitor::ip())) { http_response_code(403); echo '投稿が制限されています。'; return; }
        $scope = $type . '-' . $id; $error = ''; $notice = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            \app\Core\Csrf::requireValid($_POST['_csrf_token'] ?? null);
            try {
                if (!empty($_POST['website']) || time() - (int)($_SESSION['nxmt_comment_time'][$scope] ?? PHP_INT_MAX) < 2) { throw new \RuntimeException('少し待ってから投稿してください。'); }
                self::submit($type, (int)$id, $_POST, Monitor::ip());
                $_SESSION['nxmt_comment_notice'][$scope] = 'コメントを受け付けました。承認後に表示されます。';
                header('Location: ' . \url('nx-multi-tool/comments/' . $type . '/' . $id), true, 303); return;
            } catch (\Throwable $e) { $error = $e->getMessage(); }
        }
        $_SESSION['nxmt_comment_time'][$scope] = time();
        $_SESSION['nxmt_comment_time'] = array_slice($_SESSION['nxmt_comment_time'], -30, null, true);
        $notice = $_SESSION['nxmt_comment_notice'][$scope] ?? ''; unset($_SESSION['nxmt_comment_notice'][$scope]);
        $page = max(1, min(10000, (int)($_GET['p'] ?? 1)));
        $rows = Database::query('SELECT name,body,created_at FROM ' . Database::table('comments') . " WHERE content_type = ? AND content_id = ? AND status = 'approved' ORDER BY id ASC LIMIT 50 OFFSET " . (($page - 1) * 50), [$type, (int)$id])->fetchAll();
        require dirname(__DIR__) . '/views/comments.php';
    }
    public static function submit(string $type, int $id, array $input, string $ip): int
    {
        $item = Content::find($type, $id);
        if (!$item || ($item['status'] ?? '') !== 'published' || !Settings::all()['comments_open'] || !Settings::enabled('comment_manager')) { throw new \RuntimeException('コメントを投稿できません。'); }
        $name = trim(Access::text($input, 'name', 120)); $email = trim(Access::text($input, 'email', 254)); $body = trim(Access::text($input, 'body', 8000));
        if ($name === '' || $body === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) { throw new \InvalidArgumentException('名前・本文・メールアドレスを確認してください。'); }
        $ip = Monitor::normalizeIp($ip);
        if (Monitor::blocked($ip)) { throw new \RuntimeException('投稿が制限されています。'); }
        return Storage::transaction('comment-rate', static function ($old) use ($type, $id, $name, $email, $body, $ip) {
            $count = (int)Database::query('SELECT COUNT(*) FROM ' . Database::table('comments') . ' WHERE ip = ? AND created_at >= ?', [$ip, time() - 600])->fetchColumn();
            $all = (int)Database::query('SELECT COUNT(*) FROM ' . Database::table('comments'))->fetchColumn();
            if ($count >= 5 || $all >= 100000) { throw new \RuntimeException('投稿上限に達しました。時間をおいて再度お試しください。'); }
            Database::query('INSERT INTO ' . Database::table('comments') . ' (content_type,content_id,name,email,body,ip,status,created_at) VALUES (?,?,?,?,?,?,?,?)', [$type, $id, $name, $email, $body, $ip, 'pending', time()]);
            return [null, (int)Database::connection()->lastInsertId()];
        });
    }
    public static function list(array $filter, int $offset = 0): array
    {
        $where = ' WHERE 1=1'; $params = [];
        $status = Access::text($filter, 'status', 20);
        if (in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) { $where .= ' AND status = ?'; $params[] = $status; }
        $q = Access::text($filter, 'q', 300);
        if ($q !== '') { $where .= " AND (body LIKE ? ESCAPE '=' OR name LIKE ? ESCAPE '=' OR ip = ?)"; $term = '%' . strtr($q, ['=' => '==', '%' => '=%', '_' => '=_']) . '%'; array_push($params, $term, $term, $q); }
        if (!empty($filter['content_id'])) { $where .= ' AND content_id = ?'; $params[] = Access::integer($filter, 'content_id', 1, PHP_INT_MAX, 1); }
        return ['rows' => Database::query('SELECT * FROM ' . Database::table('comments') . $where . ' ORDER BY id DESC LIMIT 50 OFFSET ' . max(0, $offset), $params)->fetchAll(),
            'total' => (int)Database::query('SELECT COUNT(*) FROM ' . Database::table('comments') . $where, $params)->fetchColumn()];
    }
    public static function act(string $action, array $ids): void
    {
        if (!$ids || count($ids) > 50) { throw new \InvalidArgumentException('コメントを1〜50件選択してください。'); }
        $ids = array_map(static function ($id) {
            if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,12}$/D', (string)$id)) { throw new \InvalidArgumentException('コメントIDが不正です。'); }
            return (int)$id;
        }, $ids);
        if ($action === 'prepare_block') {
            $rows = Database::query('SELECT id,ip,status FROM ' . Database::table('comments') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll();
            if (!$rows) { throw new \InvalidArgumentException('対象が見つかりません。'); }
            $_SESSION['nxmt_block_review'] = ['rows' => $rows, 'expires' => time() + 600, 'token' => bin2hex(random_bytes(16))]; return;
        }
        if ($action === 'delete') {
            Database::query('DELETE FROM ' . Database::table('comments') . " WHERE status = 'trash' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids); return;
        }
        if (!in_array($action, ['approved', 'pending', 'spam', 'trash'], true)) { throw new \InvalidArgumentException('操作が不正です。'); }
        Database::query('UPDATE ' . Database::table('comments') . ' SET status = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$action], $ids));
    }
    public static function confirmBlock(string $token): void
    {
        $review = $_SESSION['nxmt_block_review'] ?? []; unset($_SESSION['nxmt_block_review']);
        if (empty($review['token']) || !hash_equals($review['token'], $token) || ($review['expires'] ?? 0) < time()) { throw new \RuntimeException('確認が期限切れです。'); }
        foreach ($review['rows'] as $row) {
            $current = Database::query('SELECT id,ip,status FROM ' . Database::table('comments') . ' WHERE id = ?', [$row['id']])->fetch();
            if (!$current || $current['ip'] !== $row['ip'] || $current['status'] !== $row['status']) { throw new \RuntimeException('対象の状態が変わりました。再選択してください。'); }
            if ($row['ip'] === Monitor::ip() || in_array($row['ip'], Settings::all()['allow_ips'], true)) { throw new \RuntimeException('自分のIP・許可IPは拒否できません。'); }
        }
        foreach ($review['rows'] as $row) { Monitor::block($row['ip'], false); self::act('spam', [$row['id']]); }
    }
}
