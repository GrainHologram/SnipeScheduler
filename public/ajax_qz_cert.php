<?php
// ajax_qz_cert.php
// Serves the QZ Tray public certificate to the browser.
// Staff-only. Avoids putting the cert file in the web root.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Access denied.';
    exit;
}

$config = load_config();
$qzConfig = $config['qz_tray'] ?? [];

if (empty($qzConfig['enabled'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'QZ Tray is not enabled.';
    exit;
}

$certPath = $qzConfig['cert_path'] ?? '';
if ($certPath === '' || !is_file($certPath)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Certificate not found.';
    exit;
}

header('Content-Type: text/plain');
echo file_get_contents($certPath);
