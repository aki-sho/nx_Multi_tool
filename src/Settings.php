<?php
namespace NxMultiTool;

final class Settings
{
    public static function all(): array
    {
        $defaults = ['tools' => array_fill_keys(array_keys(Registry::all()), true), 'font_size' => 3,
            'site_url' => '', 'comments_open' => false, 'log_days' => 30, 'log_max' => 100000,
            'log_per_minute' => 120, 'log_per_ip' => 10, 'block_hours' => 24, 'allow_ips' => []];
        $saved = Storage::read('settings');
        $settings = array_replace($defaults, $saved);
        if (!is_array($settings['tools']) || !is_array($settings['allow_ips'])) { throw new \RuntimeException('設定の形式が不正です。'); }
        foreach ($defaults['tools'] as $id => $on) {
            $settings['tools'][$id] = array_key_exists($id, $settings['tools']) ? $settings['tools'][$id] === true : $on;
        }
        return $settings;
    }
    public static function enabled(string $id): bool { return (self::all()['tools'][$id] ?? false) === true; }
    public static function save(array $input): void
    {
        $tools = $input['tools'] ?? [];
        if (!is_array($tools)) { throw new \InvalidArgumentException('機能選択が不正です。'); }
        $states = [];
        foreach (Registry::all() as $id => $_) { $states[$id] = in_array($id, $tools, true); }
        $site = trim(Access::text($input, 'site_url', 2048));
        if ($site !== '') { $site = HttpClient::base($site); }
        $allow = preg_split('/[\r\n,]+/', Access::text($input, 'allow_ips', 10000), -1, PREG_SPLIT_NO_EMPTY);
        if (count($allow) > 200) { throw new \InvalidArgumentException('許可IPは200件以内にしてください。'); }
        $ips = [];
        foreach ($allow as $ip) {
            $ip = Monitor::normalizeIp(trim($ip));
            if ($ip === '') { throw new \InvalidArgumentException('許可IPが不正です。'); }
            $ips[] = $ip;
        }
        $next = ['tools' => $states, 'font_size' => Access::integer($input, 'font_size', 1, 5, 3),
            'site_url' => $site, 'comments_open' => ($input['comments_open'] ?? '') === '1',
            'log_days' => Access::integer($input, 'log_days', 1, 365, 30),
            'log_max' => Access::integer($input, 'log_max', 100, 100000, 100000),
            'log_per_minute' => Access::integer($input, 'log_per_minute', 1, 600, 120),
            'log_per_ip' => Access::integer($input, 'log_per_ip', 1, 60, 10),
            'block_hours' => Access::integer($input, 'block_hours', 1, 720, 24), 'allow_ips' => array_values(array_unique($ips))];
        Storage::transaction('settings', static fn($old) => [$next, null]);
    }
}
