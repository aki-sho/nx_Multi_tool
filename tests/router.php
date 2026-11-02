<?php
// Test server only. This file and its synthetic login routes are never packaged.
$testSessions = getenv('NXMT_TEST_SESSIONS') ?: __DIR__ . '/.runtime/sessions';
if (!is_dir($testSessions)) { mkdir($testSessions, 0700, true); }
session_save_path($testSessions);
session_start(); require __DIR__ . '/fixture.php';
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/fixture/login') {
    $id = ($_GET['role'] ?? '') === 'editor' ? 2 : 1;
    \app\Core\Auth::login(\app\Models\User::find($id)); header('Location: /admin/extensions/nx_Multi_tool/dashboard'); return;
}
if ($path === '/fixture/logout') { \app\Core\Auth::logout(); echo 'logged out'; return; }
if (str_starts_with($path, '/public/')) {
    $target = realpath(BASE_PATH . $path); $allowed = realpath(BASE_PATH . '/public');
    if ($target && $allowed && str_starts_with($target, $allowed . DIRECTORY_SEPARATOR) && is_file($target)) {
        $mime = ['css'=>'text/css','js'=>'application/javascript','png'=>'image/png'];
        header('Content-Type: ' . ($mime[pathinfo($target, PATHINFO_EXTENSION)] ?? 'application/octet-stream')); readfile($target); return;
    }
}
$router = new \app\Core\Router();
$router->get('/admin/extensions/{extensionKey}/dashboard', \app\Controllers\Admin\ExtensionController::class . '@dashboard');
$router->get('/admin/posts/edit/{id}', \app\Controllers\Admin\PostController::class . '@edit');
$router->get('/admin/posts/create', \app\Controllers\Admin\PostController::class . '@create');
$router->get('/admin/pages/edit/{id}', \app\Controllers\Admin\PageController::class . '@edit');
$router->get('/post/{slug}', \app\Controllers\PostController::class . '@show');
$router->get('/page/{slug}', \app\Controllers\PageController::class . '@show');
\app\Core\Extension::bootEnabled($router);
$router->dispatch();
