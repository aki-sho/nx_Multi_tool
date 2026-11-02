<?php
namespace NxMultiTool;

final class Discovery
{
    /** Collect references without executing scripts, styles or external entities. */
    public static function html(string $html): array
    {
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $out = Inspector::links($html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@srcset]') as $node) {
            foreach (explode(',', $node->getAttribute('srcset')) as $candidate) {
                $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
                if ($url !== '' && !str_starts_with($url, 'data:')) { $out[] = ['url' => $url, 'type' => 'srcset']; }
            }
        }
        foreach ($xpath->query('//*[@poster]') as $node) { $out[] = ['url' => $node->getAttribute('poster'), 'type' => 'poster']; }
        foreach ($xpath->query('//*[@style]') as $node) {
            foreach (self::css($node->getAttribute('style')) as $url) { $out[] = ['url' => $url, 'type' => 'inline-css']; }
        }
        foreach ($dom->getElementsByTagName('style') as $node) {
            foreach (self::css($node->textContent) as $url) { $out[] = ['url' => $url, 'type' => 'inline-css']; }
        }
        foreach ($dom->getElementsByTagName('link') as $node) {
            if (preg_match('/(?:^|\s)sitemap(?:\s|$)/i', $node->getAttribute('rel'))) { $out[] = ['url' => $node->getAttribute('href'), 'type' => 'sitemap']; }
        }
        return array_slice($out, 0, 10000);
    }
    public static function css(string $css): array
    {
        $css = preg_replace('#/\*[\s\S]*?\*/#', '', $css) ?? '';
        preg_match_all('/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\)]+))\s*\)|@import\s+["\']([^"\']+)["\']/i', $css, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $match) { $out[] = $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''))); }
        return array_slice(array_values(array_unique(array_filter($out))), 0, 10000);
    }
    public static function json(string $json): array
    {
        try { $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable $e) { return []; }
        $out = []; $visit = static function ($value) use (&$visit, &$out): void {
            if (count($out) >= 10000) { return; }
            if (is_array($value)) { foreach ($value as $item) { $visit($item); } }
            elseif (is_string($value) && preg_match('#^(?:https?://|/|\./|\.\./)#', $value)) { $out[] = $value; }
        }; $visit($data); return array_values(array_unique($out));
    }
    public static function kind(string $url, string $mime = ''): string
    {
        $mime = strtolower(explode(';', $mime)[0]); $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        if ($mime === 'text/css' || str_ends_with($path, '.css')) { return 'css'; }
        if (str_contains($mime, 'javascript') || preg_match('/\.(?:js|mjs)$/', $path)) { return 'js'; }
        if (str_starts_with($mime, 'image/') || preg_match('/\.(?:png|jpe?g|gif|webp|svg|avif|ico)$/', $path)) { return 'image'; }
        if (str_starts_with($mime, 'font/') || preg_match('/\.(?:woff2?|ttf|otf)$/', $path)) { return 'font'; }
        if (str_contains($mime, 'xml') || preg_match('/\.xml$/', $path)) { return 'sitemap'; }
        if (str_contains($mime, 'html')) { return 'page'; }
        return preg_match('/\.[a-z0-9]{1,8}$/', $path) ? 'file' : 'page';
    }
}
