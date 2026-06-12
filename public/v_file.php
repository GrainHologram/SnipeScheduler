<?php
// Public file proxy for the per-model documentation page (v.php).
//
// Streams a Snipe-IT model file back to the browser, authenticated against
// the configured Snipe-IT API token (the end user is not authenticated).
//
// Access control: file_id must actually belong to model_id. Same threat
// model as v.php — anyone with a valid QR/tag can already reach this; we
// only block accidental cross-model access via the proxy.
//
// Self-healing: if the file is missing in the cached file list (cache is
// stale) or Snipe-IT returns 404 (file deleted upstream), we bust the
// model_files cache so the next v.php load fetches fresh data.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';

$modelId = (int)($_GET['model_id'] ?? 0);
$fileId  = (int)($_GET['file_id'] ?? 0);

if ($modelId <= 0 || $fileId <= 0) {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

function v_file_render_gone(int $modelId, string $reason): void
{
    snipeit_cache_delete('model_' . $modelId . '_files');
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>File no longer available</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
         background: #f6f7fb; color: #1f2937; margin: 0; padding: 2rem; }
  .box { max-width: 480px; margin: 4rem auto; padding: 2rem;
         background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
         text-align: center; }
  h1 { font-size: 1.25rem; color: #111827; margin: 0 0 .5rem; }
  p { color: #4b5563; margin: 0 0 1rem; }
  .hint { font-size: .8125rem; color: #6b7280; }
  a.back { display: inline-block; color: #0d6efd; text-decoration: none;
           padding: .5rem 1rem; border: 1px solid #0d6efd; border-radius: 6px;
           margin-top: .5rem; }
  a.back:hover { background: #0d6efd; color: #fff; }
</style>
</head><body>
<div class="box">
  <h1>File no longer available</h1>
  <p>{$safeReason}</p>
  <p class="hint">The documentation list has been refreshed — go back and reload to see the current files.</p>
  <a class="back" href="javascript:history.length>1?history.back():null;">Go back</a>
</div>
</body></html>
HTML;
}

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
    // Cache shows file doesn't belong to this model. Could be a stale
    // cache (file added/removed since the v.php render). Bust and report.
    v_file_render_gone($modelId, 'This file is no longer attached to its model.');
    exit;
}

try {
    [$contentType, $contentDisposition, $body] = fetch_model_file($modelId, $fileId);
} catch (Throwable $e) {
    if ((int)$e->getCode() === 404) {
        // File was deleted on Snipe-IT after we cached the list. Bust the
        // cache so the next v.php render picks up the change.
        v_file_render_gone($modelId, 'This file has been removed from Snipe-IT.');
        exit;
    }
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
