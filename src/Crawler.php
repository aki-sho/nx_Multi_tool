<?php
namespace NxMultiTool;

final class Crawler
{
    private static function key(string $run): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $run)) { throw new \InvalidArgumentException('収集IDが不正です。'); }
        return 'crawl-' . (int)(\app\Core\Auth::user()['id'] ?? 0) . '-' . $run;
    }
    public static function start(): array
    {
        $base = Settings::all()['site_url'];
        if ($base === '') { throw new \RuntimeException('ダッシュボードで公開サイトURLを設定してください。'); }
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
        $state = ['run' => $run, 'base' => $base, 'created' => time(), 'rows' => [], 'queue' => [], 'visited' => [], 'requests' => 0, 'excluded' => [], 'done' => false, 'limited' => false];
        foreach (['post', 'page'] as $type) {
            for ($offset = 0; $offset < 10000; $offset += 200) {
                $page = Content::rows(['type' => $type, 'status' => 'published'], 200, $offset);
                foreach ($page['rows'] as $item) {
                    $url = HttpClient::resolve(Content::url($type, $item), $base . '/', $base);
                    if (!$url) { continue; }
                    $state['excluded'][$url] = true; self::add($state, $url, $base . '/', '記事URL');
                    foreach (Discovery::html((string)$item['content']) as $link) { self::add($state, $link['url'], $url, '保存本文'); }
                    if (!empty($item['featured_media_path'])) {
                        $mediaUrl = \public_url(preg_replace('#^public/#', '', ltrim((string)$item['featured_media_path'], '/')));
                        self::add($state, $mediaUrl, $url, 'アイキャッチ');
                    }
                }
                if ($offset + count($page['rows']) >= $page['total'] || $state['limited']) { break; }
            }
        }
        self::add($state, $base . '/', $base . '/', '開始URL');
        self::add($state, $base . '/robots.txt', $base . '/', '探索用の候補', true);
        self::add($state, $base . '/sitemap.xml', $base . '/', '探索用の候補', true);
        Storage::transaction(self::key($run), static fn($old) => [$state, null]);
        return self::response($state);
    }
    private static function add(array &$state, string $candidate, string $source, string $method, bool $guessed = false): void
    {
        $url = HttpClient::resolve($candidate, $source, $state['base']);
        if ($url === null) { return; }
        if (isset($state['rows'][$url])) {
            if (!$guessed && !empty($state['rows'][$url]['candidate'])) { $state['rows'][$url]['candidate'] = false; $state['rows'][$url]['method'] = $method; $state['rows'][$url]['source'] = $source; }
            return;
        }
        if (count($state['rows']) >= 10000) { $state['limited'] = true; return; }
        $path = (string)parse_url($url, PHP_URL_PATH);
        $tags = [];
        if (str_contains($path, '/uploads/')) { $tags[] = 'upload'; }
        if (str_contains($url, 'attachment_id=')) { $tags[] = 'attachment'; }
        $protected = HttpClient::protectedUrl($url, $state['base']);
        $state['rows'][$url] = ['url' => $url, 'source' => $source, 'method' => $method, 'candidate' => $guessed,
            'http' => 0, 'original_http' => 0, 'final_url' => '', 'mime' => '', 'issue' => false, 'tags' => $tags,
            'access' => '未確認', 'kind' => Discovery::kind($url), 'status' => $protected ? '管理・内部領域のため取得対象外' : (str_contains($url, '?') ? 'クエリ付きのため取得対象外' : '未取得')];
        if (!str_contains($url, '?') && !$protected) { $state['queue'][] = $url; }
    }
    public static function step(string $run): array
    {
        return Storage::transaction(self::key($run), static function ($state) {
            self::valid($state);
            if ($state['done']) { return [null, self::response($state)]; }
            $beforeRows = $state['rows'];
            for ($i = 0; $i < 1 && $state['queue'] && $state['requests'] < 1990; $i++) {
                $url = array_shift($state['queue']);
                if (isset($state['visited'][$url])) { continue; }
                $state['visited'][$url] = true;
                $path = (string)parse_url($url, PHP_URL_PATH);
                $head = (bool)preg_match('/\.(?:jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|pdf|zip|mp[34]|webm|docx?|xlsx?)$/i', $path);
                $requests = 0;
                try {
                    $response = HttpClient::fetch($url, $state['base'], $head, 0, false, $requests);
                    $code = $response['status']; $row = &$state['rows'][$url];
                    $row = array_replace($row, ResponseClassifier::classify($response, !empty($row['candidate'])));
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
    }
    public static function read(string $run): array { $state = Storage::read(self::key($run)); self::valid($state); return self::response($state); }
    public static function clear(string $run): void { Storage::delete(self::key($run)); }
    private static function response(array $state, ?array $before = null): array
    {
        $rows = array_values(array_filter($state['rows'], static fn($row) => !isset($state['excluded'][$row['url']])
            && ($before === null || !isset($before[$row['url']]) || $before[$row['url']] !== $row)));
        return ['run' => $state['run'], 'rows' => $rows, 'delta' => $before !== null, 'candidates' => count($state['rows']), 'requests' => $state['requests'], 'done' => $state['done'], 'limited' => $state['limited']];
    }
}
