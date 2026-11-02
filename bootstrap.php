<?php
if (!defined('BASE_PATH') || !isset($router) || !$router instanceof \app\Core\Router) { return; }
require_once __DIR__ . '/autoload.php';
\NxMultiTool\Extension::boot($router);
