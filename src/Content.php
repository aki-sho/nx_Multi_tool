<?php
namespace NxMultiTool;

final class Content
{
    public static function find(string $type, int $id): ?array
    {
        if (!in_array($type, ['post', 'page'], true) || $id < 1) { return null; }
        $model = $type === 'post' ? \app\Models\Post::class : \app\Models\Page::class;
        $item = $model::find($id);
        return $item && empty($item['deleted_at']) ? $item : null;
    }
    public static function url(string $type, array $item): string { return $type === 'post' ? \post_url($item) : \page_url($item); }
    public static function rows(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $type = ($filter['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $table = \app\Core\Database::table($type === 'post' ? 'posts' : 'pages');
        $params = []; $where = ' WHERE p.deleted_at IS NULL';
        if (($filter['status'] ?? '') === 'published') { $where .= " AND p.status = 'published'"; }
        if (($filter['status'] ?? '') === 'draft') { $where .= " AND p.status = 'draft'"; }
        if (!empty($filter['q'])) { $where .= " AND p.title LIKE ? ESCAPE '='"; $params[] = '%' . strtr($filter['q'], ['=' => '==', '%' => '=%', '_' => '=_']) . '%'; }
        if ($type === 'post' && !empty($filter['category'])) { $where .= ' AND p.category_id = ?'; $params[] = (int)$filter['category']; }
        $order = in_array($filter['sort'] ?? '', ['id', 'title', 'status', 'slug', 'created_at', 'updated_at'], true) ? $filter['sort'] : 'id';
        $direction = ($filter['order'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
        $extra = $type === 'post' ? ', c.name AS category_name, c.slug AS category_slug, m.file_path AS featured_media_path' : ", '' AS category_name";
        $join = $type === 'post' ? ' LEFT JOIN ' . \app\Core\Database::table('categories') . ' c ON c.id = p.category_id LEFT JOIN ' . \app\Core\Database::table('media') . ' m ON m.id = p.featured_media_id' : '';
        $pdo = \app\Core\Database::connect();
        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' p' . $where); $count->execute($params);
        $stmt = $pdo->prepare('SELECT p.*' . $extra . ' FROM ' . $table . ' p' . $join . $where . ' ORDER BY p.' . $order . ' ' . $direction . ', p.id ' . $direction . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => (int)$count->fetchColumn(), 'type' => $type];
    }
    public static function metrics(array $item, string $type): array
    {
        $html = (string)($item['content'] ?? '');
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', '', $text) ?? '';
        $dom = new \DOMDocument(); $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        $xpath = new \DOMXPath($dom);
        $description = (string)($item['excerpt'] ?? '');
        $seo = BASE_PATH . '/storage/extensions/nx-head-settings/content/' . $type . '-' . (int)$item['id'] . '.json';
        if (is_file($seo) && filesize($seo) <= 16384 && !is_link($seo)) {
            $settings = json_decode((string)file_get_contents($seo), true);
            if (is_array($settings) && is_string($settings['description'] ?? null)) { $description = $settings['description']; }
        }
        $path = Monitor::safeUrl(self::url($type, $item));
        static $viewCounts = null;
        if ($viewCounts === null) {
            $viewCounts = [];
            if (Settings::enabled('access_log')) {
                foreach (Database::query('SELECT url,COUNT(*) AS total FROM ' . Database::table('logs') . " WHERE method = 'GET' AND status = 200 GROUP BY url")->fetchAll() as $row) { $viewCounts[$row['url']] = (int)$row['total']; }
            }
        }
        $views = $viewCounts[$path] ?? 0;
        return [(int)$item['id'], $type, $item['category_name'] ?? '', $item['title'], $item['status'], $item['slug'], $description,
            empty($item['featured_media_id']) ? 'なし' : 'あり',
            $xpath->query('//img')->length, $xpath->query('//a[@href]')->length,
            function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text), $views,
            $item['created_at'] ?? '', $item['updated_at'] ?? '', self::url($type, $item)];
    }
    public static function columns(): array
    {
        return ['ID', '種別', 'カテゴリ', 'タイトル', '公開状態', 'スラッグ', '説明文', 'アイキャッチ', '画像数', 'リンク数', '文字数', '閲覧数（保持ログ内）', '作成日', '更新日', 'URL'];
    }
    public static function publicContext(): ?array
    {
        foreach (debug_backtrace(0, 24) as $frame) {
            if (($frame['class'] ?? '') !== 'app\\Core\\View' || ($frame['function'] ?? '') !== 'render') { continue; }
            $view = $frame['args'][0] ?? ''; $data = $frame['args'][1] ?? [];
            $type = $view === 'post-detail' ? 'post' : ($view === 'page-detail' ? 'page' : '');
            $item = $data[$type] ?? null;
            if ($type === '' || !is_array($item) || !empty($data['isPreview']) || ($item['status'] ?? '') !== 'published' || !empty($item['deleted_at'])) { return null; }
            return ['type' => $type, 'id' => (int)$item['id']];
        }
        return null;
    }
}
