<?php
// ajax_qz_sign.php
// Server-side RSA-SHA512 signing endpoint for QZ Tray certificate authentication.
// Staff-only. Receives JSON { "request": "<string to sign>" }, returns { "signature": "<base64>" }.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

header('Content-Type: application/json');

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$config = load_config();
$qzConfig = $config['qz_tray'] ?? [];

if (empty($qzConfig['enabled'])) {
    http_response_code(400);
    echo json_encode(['error' => 'QZ Tray is not enabled.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$toSign = $input['request'] ?? '';

if ($toSign === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing request data to sign.']);
    exit;
}

$keyPath = $qzConfig['private_key_path'] ?? '';
if ($keyPath === '' || !is_file($keyPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Private key not found.']);
    exit;
}

$keyContents = file_get_contents($keyPath);
$key = openssl_pkey_get_private($keyContents);
if ($key === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid private key.']);
    exit;
}

$signature = '';
$ok = openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA512);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Signing failed.']);
    exit;
}

echo json_encode(['signature' => base64_encode($signature)]);
