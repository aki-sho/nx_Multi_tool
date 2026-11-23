<?php
namespace NxMultiTool;

final class RouteInventory
{
    private static ?\app\Core\Router $router = null;

    public static function capture(\app\Core\Router $router): void
    {
        // Retain the live instance. Extensions loaded after Multi Tool can still add routes.
        self::$router = $router;
    }

    public static function rows(): array
    {
        if (self::$router === null) {
            throw new \RuntimeException('CMSの登録ルートを取得できません。拡張機能の読み込み状態を確認してください。');
        }
        try {
            $property = new \ReflectionProperty(\app\Core\Router::class, 'routes');
            if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
            $registered = $property->getValue(self::$router);
        } catch (\ReflectionException $exception) {
            throw new \RuntimeException('このCMSの登録ルートを読み取れません。', 0, $exception);
        }
        if (!is_array($registered)) { throw new \RuntimeException('CMSの登録ルートの形式を確認できません。'); }

        $extensions = \app\Core\Extension::enabled();
        $rows = [];
        foreach ($registered as $method => $routes) {
            if (!is_string($method) || !preg_match('/^[A-Z]{3,12}$/D', $method) || !is_array($routes)) { continue; }
            foreach ($routes as $path => $action) {
                if (!is_string($path) || !str_starts_with($path, '/') || !is_string($action)) { continue; }
                $source = self::source($action, $extensions);
                if (!isset($rows[$path])) {
                    $rows[$path] = ['path' => $path, 'methods' => [], 'sources' => []];
                }
                $rows[$path]['methods'][$method] = true;
                $rows[$path]['sources'][$source] = true;
                if ($method === 'GET' && $path === '/admin/extensions/{extensionKey}/dashboard') {
                    foreach ($extensions as $extension) {
                        if (empty($extension['has_dashboard']) || !empty($extension['compatibility_issues'])) { continue; }
                        $dashboard = '/admin/extensions/' . rawurlencode($extension['key']) . '/dashboard';
                        $rows[$dashboard] = ['path' => $dashboard, 'methods' => ['GET' => true],
                            'sources' => [$extension['name'] => true]];
                    }
                }
            }
        }
        foreach ($rows as &$row) {
            $row['methods'] = array_keys($row['methods']);
            sort($row['methods']);
            $row['source'] = implode('・', array_keys($row['sources']));
            unset($row['sources']);
        }
        unset($row);
        return array_values($rows);
    }

    private static function source(string $action, array $extensions): string
    {
        $controller = explode('@', $action, 2)[0];
        if (str_starts_with($controller, 'app\\')) { return 'NexaPress本体'; }
        try {
            $file = (new \ReflectionClass($controller))->getFileName();
            if (is_string($file)) {
                $file = str_replace('\\', '/', $file);
                foreach ($extensions as $extension) {
                    $folder = realpath(BASE_PATH . '/extensions/' . $extension['folder']);
                    if ($folder !== false && str_starts_with($file, str_replace('\\', '/', $folder) . '/')) {
                        return $extension['name'];
                    }
                }
            }
        } catch (\Throwable $exception) {
            // A registered controller can be lazy-loaded or provided by a newer CMS.
        }
        return '拡張機能（登録ルート）';
    }
}
