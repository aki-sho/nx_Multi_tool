<?php
namespace NxMultiTool;

final class Crawler
{
    private static function key(string $run): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $run)) { throw new \InvalidArgumentException('収集IDが不正です。'); }
        return 'crawl-' . (int)(\app\Core\Auth::user()['id'] ?? 0) . '-' . $run;
    }
    private static function base(): string
    {
        $siteUrl = SiteInfo::publicUrl();
        if ($siteUrl === null) { throw new \RuntimeException('現在のCMSの公開サイトURLを確認できません。'); }
        return HttpClient::base($siteUrl);
    }
    public static function start(): array
    {
        $base = self::base();
        foreach (glob(Storage::directory() . '/crawl-*.json') ?: [] as $path) {
            if (preg_match('/^crawl-[0-9]+-[a-f0-9]{32}\.json$/D', basename($path)) && filemtime($path) < time() - 3600) { Storage::delete(basename($path, '.json')); }
        }
        $prefix = 'crawl-' . (int)(\app\Core\Auth::user()['id'] ?? 0) . '-';
        $existing = glob(Storage::directory() . '/' . $prefix . '*.json') ?: [];
        usort($existing, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach ($existing as $index => $path) {
            if ($index >= 2 || filemtime($path) < time() - 3600) {
                $name = basename($path, '.json');
                Storage::delete($name);
            }
        }
        $run = bin2hex(random_bytes(16));
        $state = ['run' => $run, 'base' => $base, 'login_path' => SiteInfo::loginPath(), 'created' => time(), 'rows' => [], 'queue' => [], 'visited' => [], 'requests' => 0, 'content_pages' => [], 'unpublished_pages' => [], 'done' => false, 'limited' => false];
        self::add($state, \url($state['login_path']), $base . '/', 'CMS設定（ログイン）');
        foreach (['published', 'draft'] as $contentStatus) {
            foreach (['post', 'page'] as $type) {
                for ($offset = 0; $offset < 10000; $offset += 200) {
                    $page = Content::rows(['type' => $type, 'status' => $contentStatus], 200, $offset);
                    foreach ($page['rows'] as $item) {
                        $url = HttpClient::resolve(Content::url($type, $item), $base . '/', $base);
                        if (!$url || isset($state['content_pages'][$url])) { continue; }
                        if ($contentStatus === 'draft') { $state['unpublished_pages'][$url] = true; }
                        $state['content_pages'][$url] = true; self::add($state, $url, $base . '/', $type === 'post' ? 'CMS設定（投稿）' : 'CMS設定（固定ページ）');
                        if (isset($state['rows'][$url])) {
                            $state['rows'][$url]['title'] = mb_substr(trim((string)$item['title']), 0, 500, 'UTF-8');
                            $state['rows'][$url]['content_status'] = $contentStatus === 'published' ? '公開' : '下書き';
                            $state['rows'][$url]['cms_content'] = true;
                            if ($contentStatus === 'draft') {
                                $state['rows'][$url]['method'] = $type === 'post' ? 'CMS設定（投稿）' : 'CMS設定（固定ページ）';
                                $state['rows'][$url]['source'] = $base . '/';
                                $state['rows'][$url]['status'] = 'CMS保存情報のみ（HTTP未確認）';
                            }
                        }
                        if ($contentStatus === 'draft') { continue; }
                        foreach (Discovery::html((string)$item['content']) as $link) { self::add($state, $link['url'], $url, '保存本文'); }
                        if (!empty($item['featured_media_path'])) {
                            $mediaUrl = \public_url(preg_replace('#^public/#', '', ltrim((string)$item['featured_media_path'], '/')));
                            self::add($state, $mediaUrl, $url, 'アイキャッチ');
                        }
                    }
                    if ($offset + count($page['rows']) >= $page['total'] || $state['limited']) { break; }
                }
            }
        }
        self::add($state, $base . '/', $base . '/', '開始URL');
        self::add($state, $base . '/robots.txt', $base . '/', '探索用の候補', true);
        self::add($state, $base . '/sitemap.xml', $base . '/', '探索用の候補', true);
        $state['warnings'] = [];
        try {
            foreach (RouteInventory::rows() as $route) { self::registeredRoute($state, $route); }
        } catch (\RuntimeException $exception) {
            $state['warnings'][] = $exception->getMessage();
        }
        $state['queue'] = array_values(array_filter($state['queue'], static fn($url) => !self::unpublished($state, $url)));
        Storage::transaction(self::key($run), static fn($old) => [$state, null]);
        return self::response($state);
    }

    private static function unpublished(array $state, string $url): bool
    {
        return isset($state['unpublished_pages'][explode('?', $url, 2)[0]]);
    }

    private static function registeredRoute(array &$state, array $route): void
    {
        $url = HttpClient::resolve(\url($route['path']), $state['base'] . '/', $state['base']);
        if ($url === null) { return; }
        if (isset($state['rows'][$url])) {
            $state['rows'][$url]['registered_methods'] = $route['methods'];
            $state['rows'][$url]['registered_viewer_page'] = !empty($route['viewer_page']);
            return;
        }
        if (count($state['rows']) >= 10000) { $state['limited'] = true; return; }
        $template = preg_match('/\{[a-zA-Z_]+\}/', $route['path']) === 1;
        $state['rows'][$url] = [
            'url' => $url, 'source' => $route['source'],
            'method' => $route['source'] === 'NexaPress本体' ? 'CMS登録ルート' : '拡張機能の登録ルート',
            'candidate' => false, 'http' => 0, 'original_http' => 0, 'final_url' => '', 'mime' => '', 'title' => '', 'content_status' => '',
            'issue' => false, 'tags' => [], 'access' => '未確認',
            'kind' => $template ? 'route' : Discovery::kind($url),
            'status' => $template ? 'URLパターン（{ }内の値を指定して使用・HTTP未確認）' : '登録あり（HTTP未確認）',
            'registered_methods' => $route['methods'], 'registered_only' => true,
            'registered_viewer_page' => !empty($route['viewer_page']),
        ];
    }

    private static function add(array &$state, string $candidate, string $source, string $method, bool $guessed = false): void
    {
        $url = HttpClient::resolve($candidate, $source, $state['base']);
        if ($url === null) { return; }
        $registeredMethods = $state['rows'][$url]['registered_methods'] ?? null;
        $registeredViewerPage = !empty($state['rows'][$url]['registered_viewer_page']);
        if (isset($state['rows'][$url])) {
            if (!$guessed && !empty($state['rows'][$url]['candidate'])) { $state['rows'][$url]['candidate'] = false; $state['rows'][$url]['method'] = $method; $state['rows'][$url]['source'] = $source; }
            $row = $state['rows'][$url];
            if (empty($row['registered_only']) || !in_array('GET', $row['registered_methods'], true)
                || $row['kind'] === 'route' || HttpClient::protectedUrl($url, $state['base'], $state['login_path'] ?? null)) {
                return;
            }
        }
        if (count($state['rows']) >= 10000) { $state['limited'] = true; return; }
        $path = (string)parse_url($url, PHP_URL_PATH);
        $tags = [];
        if (str_contains($path, '/uploads/')) { $tags[] = 'upload'; }
        if (str_contains($url, 'attachment_id=')) { $tags[] = 'attachment'; }
        $protected = HttpClient::protectedUrl($url, $state['base'], $state['login_path'] ?? null);
        $state['rows'][$url] = ['url' => $url, 'source' => $source, 'method' => $method, 'candidate' => $guessed,
            'http' => 0, 'original_http' => 0, 'final_url' => '', 'mime' => '', 'title' => '', 'content_status' => '', 'issue' => false, 'tags' => $tags,
            'access' => '未確認', 'kind' => Discovery::kind($url), 'status' => $protected ? '管理・内部領域のため取得対象外' : (str_contains($url, '?') ? 'クエリ付きのため取得対象外' : '未取得')];
        if ($registeredMethods !== null) {
            $state['rows'][$url]['registered_methods'] = $registeredMethods;
            $state['rows'][$url]['registered_viewer_page'] = $registeredViewerPage;
        }
        if (!str_contains($url, '?') && !$protected && !self::unpublished($state, $url)) { $state['queue'][] = $url; }
    }
    public static function step(string $run): array
    {
        return Storage::transaction(self::key($run), static function ($state) {
            self::valid($state);
            if ($state['done']) { return [null, self::response($state)]; }
            $beforeRows = $state['rows'];
            for ($i = 0; $i < 1 && $state['queue'] && $state['requests'] < 1990; $i++) {
                $url = array_shift($state['queue']);
                if (isset($state['visited'][$url]) || self::unpublished($state, $url)) { continue; }
                $state['visited'][$url] = true;
                $path = (string)parse_url($url, PHP_URL_PATH);
                $head = (bool)preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|pdf|zip|mp[34]|webm|docx?|xlsx?)$/i', $path);
                $requests = 0;
                try {
                    $response = HttpClient::fetch($url, $state['base'], $head, 0, false, $requests);
                    $code = $response['status']; $row = &$state['rows'][$url];
                    $row = array_replace($row, ResponseClassifier::classify($response, !empty($row['candidate'])));
                    if (empty($row['cms_content']) && $row['kind'] === 'page' && $row['access'] === '未ログインで取得可'
                        && str_contains(strtolower($row['mime']), 'html')) {
                        $row['title'] = Discovery::title($response['body'], $row['mime']);
                    }
                    if ($response['redirected'] && str_starts_with($response['headers']['content-type'] ?? '', 'image/')) { $row['tags'][] = 'image_redirect'; }
                    unset($row);
                    $body = $response['body']; $mime = strtolower($response['headers']['content-type'] ?? '');
                    if ($code >= 200 && $code < 300 && !$head) {
                        if (str_contains($mime, 'html')) { foreach (Discovery::html($body) as $link) { self::add($state, $link['url'], $response['url'], $link['type'] === 'sitemap' ? 'サイトマップの宣言' : '公開HTML'); } }
                        elseif (str_contains($mime, 'xml') && stripos($body, '<!DOCTYPE') === false && stripos($body, '<!ENTITY') === false) {
                            $dom = new \DOMDocument(); $old = libxml_use_internal_errors(true);
                            if ($dom->loadXML($body, LIBXML_NONET)) { foreach ($dom->getElementsByTagName('loc') as $loc) { self::add($state, $loc->textContent, $url, 'XML'); } }
                            libxml_clear_errors(); libxml_use_internal_errors($old);
                        } elseif (str_contains($mime, 'css')) {
                            foreach (Discovery::css($body) as $candidate) { self::add($state, $candidate, $url, 'CSS'); }
                        } elseif (str_contains($mime, 'json')) {
                            foreach (Discovery::json($body) as $candidate) { self::add($state, $candidate, $url, 'JSON'); }
                        } elseif (str_ends_with((string)parse_url($url, PHP_URL_PATH), '/robots.txt')) {
                            preg_match_all('/^Sitemap:\s*(\S+)/im', $body, $matches);
                            foreach ($matches[1] as $candidate) { self::add($state, $candidate, $url, 'robots.txtの宣言'); }
                        }
                    }
                } catch (\Throwable $e) { $state['rows'][$url]['status'] = '取得失敗: ' . $e->getMessage(); $state['rows'][$url]['issue'] = true; }
                finally { $state['requests'] += $requests; }
            }
            $state['done'] = !$state['queue'] || $state['requests'] >= 1990;
            if ($state['requests'] >= 1990) { $state['limited'] = true; }
            return [$state, self::response($state, $beforeRows)];
        });
    }
    private static function valid(array $state): void
    {
        if (!isset($state['run'], $state['created']) || time() - $state['created'] > 3600) { throw new \RuntimeException('収集結果が期限切れです。再収集してください。'); }
        if (($state['base'] ?? '') !== self::base()) { throw new \RuntimeException('収集先が変わりました。再収集してください。'); }
    }
    public static function read(string $run): array { $state = Storage::read(self::key($run)); self::valid($state); return self::response($state); }
    public static function clear(string $run): void { Storage::delete(self::key($run)); }
    private static function response(array $state, ?array $before = null): array
    {
        $rows = array_values(array_filter($state['rows'], static fn($row) => $before === null || !isset($before[$row['url']]) || $before[$row['url']] !== $row));
        // Classify the returned rows so inventories started before an extension update work too.
        foreach ($rows as &$row) {
            $row += ['title' => '', 'content_status' => ''];
            if (HttpClient::isAdminUrl($row['url'], $state['base'])) { $row['tags'][] = 'admin'; }
            $pageUrl = explode('?', $row['url'], 2)[0];
            if (!empty($state['rows'][$pageUrl]['cms_content'])) {
                $row['title'] = $state['rows'][$pageUrl]['title'] ?? '';
                $row['content_status'] = $state['rows'][$pageUrl]['content_status'] ?? '';
            }
            $knownPage = isset($state['content_pages'][$pageUrl]) || isset($state['excluded'][$pageUrl])
                || rtrim($pageUrl, '/') === $state['base'] || !empty($row['registered_viewer_page'])
                || !empty($state['rows'][rtrim($pageUrl, '/')]['registered_viewer_page']);
            $htmlPage = empty($row['candidate']) && $row['kind'] === 'page' && str_contains(strtolower($row['mime'] ?? ''), 'html')
                && $row['http'] >= 200 && $row['http'] < 300 && $row['access'] === '未ログインで取得可';
            if (($knownPage || $htmlPage) && !HttpClient::protectedUrl($row['url'], $state['base'], $state['login_path'] ?? null)) {
                $row['tags'][] = 'viewer_page';
            }
            if (!empty($row['registered_methods'])) { $row['status'] .= ' ／ ' . implode('・', $row['registered_methods']); }
        }
        unset($row);
        return ['run' => $state['run'], 'rows' => $rows, 'delta' => $before !== null, 'candidates' => count($state['rows']), 'requests' => $state['requests'],
            'done' => $state['done'], 'limited' => $state['limited'], 'warnings' => $state['warnings'] ?? []];
    }
}
