<?php
namespace NxMultiTool;

final class AssetController
{
    public function show(string $asset): void
    {
        $files = ['admin.css' => 'css/admin.css', 'admin.js' => 'js/admin.js', 'qr.js' => 'js/qr.js',
            'qrcodegen.js' => 'vendor/qrcodegen.js', 'calc.js' => 'js/calc.js', 'calc.css' => 'css/calc.css',
            'timer.js' => 'js/timer.js', 'timer.css' => 'css/timer.css', 'finish.mp3' => 'sounds/timer-finish.mp3',
            'click.mp3' => 'sounds/ui-click.mp3', 'editor-engine.js' => 'js/editor-engine.js',
            'editor-worker.js' => 'js/editor-worker.js',
            'editor.js' => 'js/editor.js', 'comments.js' => 'js/comments.js'];
        $path = isset($files[$asset]) ? dirname(__DIR__) . '/assets/' . $files[$asset] : '';
        if ($path === '' || !is_file($path)) { http_response_code(404); return; }
        $mime = ['js' => 'application/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'mp3' => 'audio/mpeg'];
        header('Content-Type: ' . $mime[pathinfo($path, PATHINFO_EXTENSION)]);
        header('X-Content-Type-Options: nosniff'); header('Cache-Control: public, max-age=3600');
        readfile($path);
    }
}
