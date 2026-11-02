<?php
namespace NxMultiTool;

final class Extension
{
    public static function boot(\app\Core\Router $router): void
    {
        $base = '/admin/extensions/nx_Multi_tool';
        $router->post($base . '/settings', AdminController::class . '@settings');
        $router->post($base . '/action', AdminController::class . '@action');
        $router->post($base . '/api', AdminController::class . '@api');
        $router->get($base . '/export', AdminController::class . '@export');
        $router->get('/nx-multi-tool/assets/{asset}', AssetController::class . '@show');
        $router->get('/nx-multi-tool/comments/{type}/{id}', Comments::class . '@publicPage');
        $router->post('/nx-multi-tool/comments/{type}/{id}', Comments::class . '@publicPage');
        try {
            if (Settings::enabled('access_log') || Settings::enabled('comment_manager')) { Monitor::boot(); }
            \add_action('admin_head', static function (): void {
                echo '<link rel="stylesheet" href="' . \e(Registry::asset('admin.css')) . '">';
            });
            \add_action('admin_footer', static function (): void {
                $path = Monitor::safeUrl((string)($_SERVER['REQUEST_URI'] ?? ''));
                if (str_contains($path, '/admin/extensions/nx_Multi_tool/dashboard')) {
                    echo '<script src="' . \e(Registry::asset('admin.js')) . '" defer></script>';
                }
                if (Settings::enabled('search_replace') && \app\Core\Permission::can('extensions.manage')
                    && preg_match('#/admin/(posts|pages)/(create|edit/[0-9]+)/?$#', $path)) {
                    foreach (['editor-engine.js', 'editor.js'] as $asset) {
                        echo '<script src="' . \e(Registry::asset($asset)) . '" defer></script>';
                    }
                }
            });
            \add_action('nx_head', static function (): void {
                if (!Settings::enabled('comment_manager') || !Settings::all()['comments_open']) { return; }
                $context = Content::publicContext(); if (!$context) { return; }
                echo '<script src="' . \e(Registry::asset('comments.js')) . '" data-nxmt-comments="' . \e(\url('nx-multi-tool/comments/' . $context['type'] . '/' . $context['id'])) . '" defer></script>';
            });
        } catch (\Throwable $e) {
            error_log('[NX Multi Tool] 起動失敗: ' . get_class($e));
            \add_action('admin_head', static function (): void { echo '<p role="alert">NX Multi Toolの設定・保存先を確認してください。</p>'; });
        }
    }
}
