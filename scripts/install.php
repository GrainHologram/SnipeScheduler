<?php
// scripts/install.php
// Interactive installer for ReserveIT. Generates config/config.php and seeds the database from schema.sql.

if (php_sapi_name() !== 'cli') {
    echo "Run this installer from the command line.\n";
    exit(1);
}

define('APP_ROOT', dirname(__DIR__));
define('CONFIG_PATH', APP_ROOT . '/config');

require_once APP_ROOT . '/src/config_writer.php';

$examplePath = CONFIG_PATH . '/config.example.php';
$schemaPath  = APP_ROOT . '/schema.sql';
$configPath  = CONFIG_PATH . '/config.php';

$defaults = [];
if (is_file($examplePath)) {
    $defaults = require $examplePath;
    if (!is_array($defaults)) {
        $defaults = [];
    }
}

function prompt(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $label . $suffix . ': ';
    $input = trim((string)fgets(STDIN));
    return $input === '' ? $default : $input;
}

function promptBool(string $label, bool $default = false): bool
{
    $suffix = $default ? ' [Y/n]' : ' [y/N]';
    while (true) {
        $resp = strtolower(prompt($label . $suffix, ''));
        if ($resp === '') {
            return $default;
        }
        if (in_array($resp, ['y', 'yes'], true)) {
            return true;
        }
        if (in_array($resp, ['n', 'no'], true)) {
            return false;
        }
        echo "Please answer y or n.\n";
    }
}

echo "ReserveIT installer\n";
echo "-------------------\n";

$dbDef = $defaults['db_booking'] ?? [];
$dbHost = prompt('DB host', $dbDef['host'] ?? 'localhost');
$dbPort = (int)prompt('DB port', isset($dbDef['port']) ? (string)$dbDef['port'] : '3306');
$dbName = prompt('DB name', $dbDef['dbname'] ?? 'reserveit');
$dbUser = prompt('DB username', $dbDef['username'] ?? '');
$dbPass = prompt('DB password (input hidden not supported here, type carefully)', '');
if ($dbPass === '' && isset($dbDef['password'])) {
    $dbPass = $dbDef['password'];
}
$dbCharset = prompt('DB charset', $dbDef['charset'] ?? 'utf8mb4');

$ldapDef = $defaults['ldap'] ?? [];
$ldapHost = prompt('LDAP host (e.g. ldaps://host)', $ldapDef['host'] ?? 'ldaps://');
$ldapBase = prompt('LDAP base DN', $ldapDef['base_dn'] ?? '');
$ldapBind = prompt('LDAP bind DN', $ldapDef['bind_dn'] ?? '');
$ldapPass = prompt('LDAP bind password (input hidden not supported)', '');
if ($ldapPass === '' && isset($ldapDef['bind_password'])) {
    $ldapPass = $ldapDef['bind_password'];
}
$ldapIgnore = promptBool('Ignore LDAP SSL certificate errors?', (bool)($ldapDef['ignore_cert'] ?? true));

$snipeDef = $defaults['snipeit'] ?? [];
$snipeUrl   = prompt('Snipe-IT base URL', $snipeDef['base_url'] ?? '');
$snipeToken = prompt('Snipe-IT API token (input hidden not supported)', '');
if ($snipeToken === '' && isset($snipeDef['api_token'])) {
    $snipeToken = $snipeDef['api_token'];
}
$snipeVerify = promptBool('Verify Snipe-IT SSL certificate?', (bool)($snipeDef['verify_ssl'] ?? false));

$authDef = $defaults['auth']['staff_group_cn'] ?? [];
$staffGroups = prompt('Staff group CNs (comma separated)', is_array($authDef) ? implode(',', $authDef) : '');
$staffGroupCns = array_values(array_filter(array_map('trim', explode(',', $staffGroups))));

$appDef = $defaults['app'] ?? [];
$timezone  = prompt('App timezone', $appDef['timezone'] ?? 'Europe/Jersey');
$debug     = promptBool('Enable debug mode?', (bool)($appDef['debug'] ?? false));
$logoUrl   = prompt('Logo URL (optional)', $appDef['logo_url'] ?? '');
$primary   = prompt('Primary colour (hex)', $appDef['primary_color'] ?? '#660000');
$missedCutoff = (int)prompt('Missed cutoff minutes', isset($appDef['missed_cutoff_minutes']) ? (string)$appDef['missed_cutoff_minutes'] : '60');

$pageLimit   = (int)prompt('Snipe-IT API page limit', defined('SNIPEIT_API_PAGE_LIMIT') ? (string)SNIPEIT_API_PAGE_LIMIT : '12');
$cataloguePP = (int)prompt('Catalogue items per page', defined('CATALOGUE_ITEMS_PER_PAGE') ? (string)CATALOGUE_ITEMS_PER_PAGE : '12');
$maxModels   = (int)prompt('Snipe-IT max models fetch', defined('SNIPEIT_MAX_MODELS_FETCH') ? (string)SNIPEIT_MAX_MODELS_FETCH : '1000');

$config = $defaults;
$config['db_booking'] = [
    'host'     => $dbHost,
    'port'     => $dbPort,
    'dbname'   => $dbName,
    'username' => $dbUser,
    'password' => $dbPass,
    'charset'  => $dbCharset,
];

$config['ldap'] = [
    'host'          => $ldapHost,
    'base_dn'       => $ldapBase,
    'bind_dn'       => $ldapBind,
    'bind_password' => $ldapPass,
    'ignore_cert'   => $ldapIgnore,
];

$config['snipeit'] = [
    'base_url'   => $snipeUrl,
    'api_token'  => $snipeToken,
    'verify_ssl' => $snipeVerify,
];

$config['auth']['staff_group_cn'] = $staffGroupCns;

$config['app'] = [
    'timezone'              => $timezone,
    'debug'                 => $debug,
    'logo_url'              => $logoUrl,
    'primary_color'         => $primary,
    'missed_cutoff_minutes' => $missedCutoff,
];

$defines = [
    'SNIPEIT_API_PAGE_LIMIT'   => $pageLimit,
    'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
    'SNIPEIT_MAX_MODELS_FETCH' => $maxModels,
];

if (!is_dir(CONFIG_PATH)) {
    mkdir(CONFIG_PATH, 0755, true);
}

if (is_file($configPath)) {
    $overwrite = promptBool("config.php already exists at {$configPath}. Overwrite?", false);
    if (!$overwrite) {
        echo "Aborting without changes.\n";
        exit(0);
    }
}

$configContent = reserveit_build_config_file($config, $defines);
if (file_put_contents($configPath, $configContent, LOCK_EX) === false) {
    echo "Failed to write {$configPath}. Check permissions.\n";
    exit(1);
}
echo "Config written to {$configPath}\n";

// Database setup
echo "Setting up database...\n";
if (!is_file($schemaPath)) {
    echo "schema.sql not found at {$schemaPath}; skipping DB setup.\n";
    exit(0);
}

$dsnBase = sprintf('mysql:host=%s;port=%d;charset=%s', $dbHost, $dbPort, $dbCharset);
try {
    $pdo = new PDO($dsnBase, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $dbNameEsc = str_replace('`', '``', $dbName);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET {$dbCharset} COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbNameEsc}`");

    $schemaSql = file_get_contents($schemaPath);
    $pdo->exec($schemaSql);

    echo "Database '{$dbName}' is ready.\n";
} catch (Throwable $e) {
    echo "Database setup failed: " . $e->getMessage() . "\n";
    echo "You can rerun the installer after fixing the issue.\n";
    exit(1);
}

echo "Installation complete.\n";
