<?php
define('BASE_PATH', getenv('NXMT_TEST_SITE') ?: __DIR__ . '/.runtime/site');
define('BASE_URL', '');
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/FixtureDatabase.php';
spl_autoload_register(static function ($class): void {
    $path = BASE_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) { require_once $path; }
});
require BASE_PATH . '/app/Core/helpers.php';
require dirname(__DIR__) . '/autoload.php';
