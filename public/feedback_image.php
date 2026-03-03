<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$file = basename(trim($_GET['file'] ?? ''));
if ($file === '') {
    http_response_code(400);
    echo 'Missing file parameter.';
    exit;
}

$filePath = CONFIG_PATH . '/uploads/feedback/' . $file;
if (!is_file($filePath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(403);
    echo 'Invalid file type.';
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
