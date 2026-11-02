<?php
if (!defined('BASE_PATH')) { return; }
require_once dirname(__DIR__) . '/autoload.php';
\NxMultiTool\Access::requireTool();
\NxMultiTool\AdminController::dashboard();
