<?php
// src/bootstrap.php
// Sets up shared paths and config loader for the application.

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!defined('SRC_PATH')) {
    define('SRC_PATH', APP_ROOT . '/src');
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', APP_ROOT . '/config');
}

require_once SRC_PATH . '/config_loader.php';
require_once SRC_PATH . '/datetime_helpers.php';

// Set PHP's default timezone to the app's configured timezone so that
// all date/time functions (new DateTime(), strtotime(), date(), etc.)
// use the correct timezone by default.
$appTz = app_get_timezone();
if ($appTz) {
    date_default_timezone_set($appTz->getName());
}
