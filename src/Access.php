<?php
namespace NxMultiTool;

final class Access
{
    public static function requireTool(string $tool = '', bool $post = false): void
    {
        \app\Core\Permission::require('extensions.manage');
        if ($tool !== '' && (!isset(Registry::all()[$tool]) || !Settings::enabled($tool))) {
            http_response_code(403); exit('このツールは無効です。');
        }
        if ($post) { \app\Core\Csrf::requireValid($_POST['_csrf_token'] ?? null); }
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    public static function text(array $data, string $key, int $max, string $default = ''): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value) || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('入力値を確認してください: ' . $key);
        }
        return $value;
    }
    public static function integer(array $data, string $key, int $min, int $max, int $default): int
    {
        $value = $data[$key] ?? $default;
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) {
            throw new \InvalidArgumentException('数値の範囲を確認してください: ' . $key);
        }
        return (int)$value;
    }
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
    public static function csv(string $filename, array $columns, iterable $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
        foreach ((static function () use ($columns, $rows) { yield $columns; yield from $rows; })() as $row) {
            fputcsv($out, array_map(static function ($value) {
                $value = (string)($value ?? '');
                return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'" . $value : $value;
            }, array_values($row)), ',', '"', '');
        }
        fclose($out);
    }
}
