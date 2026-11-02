<?php
namespace NxMultiTool;

final class AdminController
{
    public static function dashboard(): void
    {
        $tool = ''; $error = ''; $data = [];
        try {
            $tool = Access::text($_GET, 'tool', 40);
            if ($tool !== '') { Access::requireTool($tool); }
            $settings = Settings::all();
            $page = Access::integer($_GET, 'page', 1, 100000, 1);
            if ($tool === 'post_data') {
                $data = Content::rows(self::contentFilter($_GET), 50, ($page - 1) * 50);
                $data['metrics'] = array_map(static fn($item) => Content::metrics($item, $data['type']), $data['rows']);
            }
            if ($tool === 'access_log') { Monitor::prune(); $data = Monitor::logs($_GET, 50, ($page - 1) * 50); $data['blocks'] = Monitor::blocks(); }
            if ($tool === 'comment_manager') { $data = Comments::list($_GET, ($page - 1) * 50); $data['review'] = $_SESSION['nxmt_block_review'] ?? null; }
            if ($tool === 'security_check') { $data = Security::inspect(); }
        } catch (\Throwable $e) { $error = $e->getMessage(); $settings = null; }
        $notice = $_SESSION['nxmt_notice'] ?? null; unset($_SESSION['nxmt_notice']);
        $registry = Registry::all();
        require dirname(__DIR__) . '/views/admin.php';
    }
    public function settings(): void
    {
        Access::requireTool('', true);
        try { Settings::save($_POST); $_SESSION['nxmt_notice'] = ['success', '設定を保存しました。']; }
        catch (\Throwable $e) { $_SESSION['nxmt_notice'] = ['error', $e->getMessage()]; }
        header('Location: ' . Registry::url(), true, 303);
    }
    public function api(): void
    {
        try {
            $tool = Access::text($_POST, 'tool', 40); Access::requireTool($tool, true);
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { throw new \InvalidArgumentException('入力サイズが上限を超えています。'); }
            $op = Access::text($_POST, 'op', 30);
            if ($tool === 'password') { Access::json(['ok' => true, 'data' => Utility::passwords($_POST)]); return; }
            if ($tool === 'bcrypt') { Access::json(['ok' => true, 'data' => Utility::bcrypt($_POST)]); return; }
            if ($tool === 'link_inspector') { Access::json(['ok' => true, 'data' => Inspector::scan($_POST, Access::integer($_POST, 'offset', 0, 1000000, 0))]); return; }
            if ($tool === 'site_urls') {
                $run = Access::text($_POST, 'run', 32);
                if ($op === 'start') { $result = Crawler::start(); }
                elseif ($op === 'step') { $result = Crawler::step($run); }
                elseif ($op === 'list') { $result = Crawler::read($run); }
                elseif ($op === 'clear') { Crawler::clear($run); $result = []; }
                else { throw new \InvalidArgumentException('収集操作が不正です。'); }
                Access::json(['ok' => true, 'data' => $result]); return;
            }
            throw new \InvalidArgumentException('この操作は利用できません。');
        } catch (\Throwable $e) { Access::json(['ok' => false, 'error' => $e->getMessage()], 400); }
    }
    public function action(): void
    {
        $tool = Access::text($_POST, 'tool', 40); Access::requireTool($tool, true);
        try {
            $op = Access::text($_POST, 'op', 30);
            if (in_array($tool, ['access_log', 'comment_manager'], true) && in_array($op, ['block', 'unblock'], true)) {
                Monitor::block(Access::text($_POST, 'ip', 45), $op === 'unblock');
            } elseif ($tool === 'access_log') {
                if ($op === 'truncate' && ($_POST['confirm'] ?? '') === 'DELETE') { Database::query('DELETE FROM ' . Database::table('logs')); }
                elseif ($op === 'purge') { Database::query('DELETE FROM ' . Database::table('logs') . ' WHERE created_at < ?', [time() - Access::integer($_POST, 'days', 1, 365, 30) * 86400]); }
                elseif ($op === 'delete_selected') {
                    $ids = $_POST['ids'] ?? [];
                    if (!is_array($ids) || !$ids || count($ids) > 50) { throw new \InvalidArgumentException('ログを1〜50件選択してください。'); }
                    foreach ($ids as $id) { if (!is_string($id) || !ctype_digit($id)) { throw new \InvalidArgumentException('ログIDが不正です。'); } }
                    Database::query('DELETE FROM ' . Database::table('logs') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
                } else { throw new \InvalidArgumentException('削除操作を確認してください。'); }
            } elseif ($tool === 'comment_manager') {
                if ($op === 'confirm_block') { Comments::confirmBlock(Access::text($_POST, 'review_token', 32)); }
                elseif ($op === 'cancel_block') { unset($_SESSION['nxmt_block_review']); }
                else {
                    $ids = $_POST['ids'] ?? [];
                    if (!is_array($ids)) { throw new \InvalidArgumentException('対象を選択してください。'); }
                    Comments::act($op, $ids);
                }
            } else { throw new \InvalidArgumentException('操作が不正です。'); }
            $_SESSION['nxmt_notice'] = ['success', '操作を完了しました。'];
        } catch (\Throwable $e) { $_SESSION['nxmt_notice'] = ['error', $e->getMessage()]; }
        header('Location: ' . Registry::url($tool), true, 303);
    }
    public static function contentFilter(array $input): array
    {
        return ['type' => Access::text($input, 'type', 10, 'post'), 'q' => Access::text($input, 'q', 300),
            'status' => Access::text($input, 'status', 20), 'category' => Access::integer($input, 'category', 0, PHP_INT_MAX, 0),
            'sort' => Access::text($input, 'sort', 30, 'id'), 'order' => Access::text($input, 'order', 4, 'DESC')];
    }
    public function export(): void
    {
        $tool = Access::text($_GET, 'tool', 40); Access::requireTool($tool);
        \app\Core\Csrf::requireValid($_GET['_csrf_token'] ?? null);
        if ($tool === 'post_data') {
            $filter = self::contentFilter($_GET);
            $columns = Content::columns();
            $selected = isset($_GET['cols']) && is_array($_GET['cols']) ? array_values(array_unique(array_filter(array_map(static fn($value) => is_scalar($value) && ctype_digit((string)$value) ? (int)$value : -1, $_GET['cols']), static fn($value) => $value >= 0 && $value < count($columns)))) : array_keys($columns);
            if (!$selected) { http_response_code(400); echo '出力する項目を選択してください。'; return; }
            $rows = (static function () use ($filter, $selected) {
                for ($offset = 0; ; $offset += 200) {
                    $page = Content::rows($filter, 200, $offset);
                    foreach ($page['rows'] as $item) { $row = Content::metrics($item, $page['type']); yield array_map(static fn($index) => $row[$index], $selected); }
                    if ($offset + count($page['rows']) >= $page['total']) { break; }
                }
            })();
            Access::csv('nx-multi-tool-post-data.csv', array_map(static fn($index) => $columns[$index], $selected), $rows); return;
        }
        if ($tool === 'access_log') {
            $filter = $_GET;
            $rows = (static function () use ($filter) {
                for ($offset = 0; ; $offset += 1000) {
                    $page = Monitor::logs($filter, 1000, $offset);
                    foreach ($page['rows'] as $row) { yield [$row['id'], date('Y-m-d H:i:s', $row['created_at']), $row['method'], $row['status'], $row['ip'], Monitor::safeUrl($row['url']), Monitor::safeUrl($row['referrer']), $row['ua']]; }
                    if ($offset + count($page['rows']) >= $page['total']) { break; }
                }
            })();
            Access::csv('nx-multi-tool-access-log.csv', ['ID', '日時', 'Method', 'Status', 'IP', 'URL', '参照元', 'UA'], $rows); return;
        }
        http_response_code(400); echo '出力対象が不正です。';
    }
    public static function form(string $tool, string $op): void
    {
        echo '<input type="hidden" name="_csrf_token" value="' . \e(\app\Core\Csrf::token()) . '"><input type="hidden" name="tool" value="' . \e($tool) . '"><input type="hidden" name="op" value="' . \e($op) . '">';
    }
    public static function pagination(string $tool, int $page, int $total): void
    {
        $pages = max(1, (int)ceil($total / 50));
        echo '<nav class="nxmt-pagination" aria-label="ページ送り">';
        foreach (['前へ' => max(1, $page - 1), '次へ' => min($pages, $page + 1)] as $label => $number) {
            $query = $_GET; $query['tool'] = $tool; $query['page'] = $number;
            echo '<a class="button" href="' . \e(Registry::url() . '?' . http_build_query($query)) . '">' . $label . '</a>';
        }
        echo '<span>' . $page . ' / ' . $pages . ' ページ（' . $total . '件）</span></nav>';
    }
}
