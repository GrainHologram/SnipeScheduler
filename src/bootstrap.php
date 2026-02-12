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

// Keep PHP in UTC so all internal dates (DB, comparisons) are UTC.
// Convert to/from the app's configured timezone only at the UI boundary.
date_default_timezone_set('UTC');
