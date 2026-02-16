<?php
require_once __DIR__ . '/../../../src/config_writer.php';

function upgrade_apply_v0_9_0_beta(string $configFile, array $config, array &$messages, array &$errors): array
{
    // Ensure checkout_limits key exists with defaults
    if (!isset($config['checkout_limits'])) {
        $config['checkout_limits'] = [
            'enabled' => false,
            'default' => [
                'max_checkout_hours' => 0,
                'max_renewal_hours'  => 0,
                'max_total_hours'    => 0,
            ],
            'group_overrides' => [],
            'single_active_checkout' => false,
        ];

        if ($configFile !== '' && is_file($configFile)) {
            try {
                $content = layout_build_config_file($config, [
                    'SNIPEIT_API_PAGE_LIMIT' => defined('SNIPEIT_API_PAGE_LIMIT') ? SNIPEIT_API_PAGE_LIMIT : 12,
                    'CATALOGUE_ITEMS_PER_PAGE' => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
                ]);
                file_put_contents($configFile, $content);
                $messages[] = 'Added checkout_limits section to config.php.';
            } catch (Throwable $e) {
                $errors[] = 'Failed to update config.php for checkout_limits: ' . $e->getMessage();
            }
        }
    }

    return $config;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $appRoot = realpath(__DIR__ . '/../../..') ?: (__DIR__ . '/../../..');
    $defaultConfig = $appRoot . '/config/config.php';
    $legacyConfig = $appRoot . '/config.php';

    $configFile = $argv[1] ?? '';
    if ($configFile === '') {
        $configFile = is_file($defaultConfig) ? $defaultConfig : (is_file($legacyConfig) ? $legacyConfig : '');
    }

    $messages = [];
    $errors = [];
    $config = [];

    if ($configFile === '' || !is_file($configFile)) {
        fwrite(STDERR, "config.php not found. Provide a path as the first argument.\n");
        exit(1);
    }

    try {
        $config = require $configFile;
        if (!is_array($config)) {
            $config = [];
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed to load config.php: " . $e->getMessage() . "\n");
        exit(1);
    }

    upgrade_apply_v0_9_0_beta($configFile, $config, $messages, $errors);

    foreach ($messages as $msg) {
        fwrite(STDOUT, $msg . "\n");
    }
    foreach ($errors as $err) {
        fwrite(STDERR, $err . "\n");
    }

    exit(!empty($errors) ? 1 : 0);
}
