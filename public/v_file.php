<?php
// Public file proxy for the per-model documentation page (v.php).
//
// Streams a Snipe-IT model file back to the browser, authenticated against
// the configured Snipe-IT API token (the end user is not authenticated).
//
// Access control: file_id must actually belong to model_id. Same threat
// model as v.php — anyone with a valid QR/tag can already reach this; we
// only block accidental cross-model access via the proxy.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';

$modelId = (int)($_GET['model_id'] ?? 0);
$fileId  = (int)($_GET['file_id'] ?? 0);

if ($modelId <= 0 || $fileId <= 0) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

// Verify the file is actually attached to this model. Avoids using the
// proxy as a general-purpose "any file by id" reader.
try {
    $files = get_model_files($modelId);
} catch (Throwable $e) {
    http_response_code(502);
    echo 'Unable to verify file ownership.';
    exit;
}

$found = null;
foreach ($files as $f) {
    if (is_array($f) && (int)($f['id'] ?? 0) === $fileId) {
        $found = $f;
        break;
    }
}
if ($found === null) {
    http_response_code(404);
    echo 'File not found for this model.';
    exit;
}

try {
    [$contentType, $contentDisposition, $body] = fetch_model_file($modelId, $fileId);
} catch (Throwable $e) {
    http_response_code(502);
    echo 'Upstream fetch failed.';
    exit;
}

header('Content-Type: ' . $contentType);
if ($contentDisposition !== '') {
    header('Content-Disposition: ' . $contentDisposition);
} else {
    $fname = (string)($found['filename'] ?? $found['name'] ?? 'download');
    $safe  = preg_replace('/[^\w.\-]+/', '_', $fname);
    header('Content-Disposition: inline; filename="' . $safe . '"');
}
header('Content-Length: ' . strlen($body));
header('Cache-Control: private, max-age=300');

echo $body;
