<?php
namespace NxMultiTool;

final class Inspector
{
    public static function links(string $html, array $types = ['a', 'img', 'video', 'source', 'link', 'script']): array
    {
        $dom = new \DOMDocument(); $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($old); $out = [];
        foreach ($types as $type) {
            if (!in_array($type, ['a', 'img', 'video', 'source', 'link', 'script'], true)) { continue; }
            $attribute = in_array($type, ['a', 'link'], true) ? 'href' : 'src';
            foreach ($dom->getElementsByTagName($type) as $node) {
                if ($node->hasAttribute($attribute)) { $out[] = ['url' => $node->getAttribute($attribute), 'type' => $type]; }
            }
        }
        return $out;
    }
    public static function scan(array $input, int $offset = 0): array
    {
        $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', Access::text($input, 'patterns', 10000))));
        if (count($patterns) > 100) { throw new \InvalidArgumentException('一致パターンは100件までです。'); }
        $host = strtolower(Access::text($input, 'host', 253));
        $distance = Access::integer($input, 'distance', 0, 3, 1);
        $types = is_array($input['types'] ?? null) ? array_values(array_intersect(['a', 'img', 'video', 'source'], $input['types'])) : ['a', 'img'];
        if (!$patterns && $host === '') { throw new \InvalidArgumentException('パターンまたは正しいドメインを入力してください。'); }
        $type = ($input['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $page = Content::rows(['type' => $type], 20, $offset); $results = [];
        foreach ($page['rows'] as $item) {
            foreach (self::links((string)$item['content'], $types) as $link) {
                $url = $link['url']; $reason = '';
                foreach ($patterns as $pattern) { if (stripos($url, $pattern) !== false) { $reason = '一致: ' . $pattern; break; } }
                $foundHost = strtolower((string)parse_url($url, PHP_URL_HOST));
                $target = preg_replace('/^www\./', '', $host); $found = preg_replace('/^www\./', '', $foundHost);
                if ($reason === '' && $distance > 0 && $target !== '' && $found !== '' && $found !== $target && levenshtein($found, $target) <= $distance) { $reason = 'ドメインの誤記候補: ' . $foundHost; }
                if ($reason !== '') { $results[] = ['id' => (int)$item['id'], 'title' => $item['title'], 'url' => $url, 'type' => $link['type'], 'reason' => $reason]; }
                if (count($results) >= 1000) { break 2; }
            }
        }
        $next = $offset + count($page['rows']);
        return ['rows' => $results, 'processed' => $next, 'total' => $page['total'], 'done' => $next >= $page['total'], 'limited' => count($results) >= 1000];
    }
}
