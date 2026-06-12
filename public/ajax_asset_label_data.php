<?php
// Asset lookup for the bench label printing page (print_label.php).
//
// GET ?tag={asset_tag}  →  JSON { ok: bool, error?, asset_tag, asset_name,
//                                  model_name, svad_name, description }
//
// description is the priority-resolved label text:
//   custom_fields["SVAD Name"] → asset name → model name
//
// Staff-only. Output is small and meant to be polled rapid-fire by the
// scan input on print_label.php.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';

header('Content-Type: application/json');

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admins only.']);
    exit;
}

$tag = normalize_scanned_tag($_GET['tag'] ?? $_POST['tag'] ?? '');
if ($tag === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing asset tag.']);
    exit;
}

try {
    $asset = find_asset_by_tag($tag);
} catch (Throwable $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'asset_tag' => $tag]);
    exit;
}

// Snipe-IT's API returns HTML-encoded text (e.g. apostrophes become
// &#039;) which would print literally on the label. Decode once for
// every text field before handing back to the JS.
$decode = static function ($value): string {
    return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$assetTag  = $decode($asset['asset_tag'] ?? $tag);
$assetName = $decode($asset['name'] ?? '');
$modelName = $decode($asset['model']['name'] ?? '');

// custom_fields is keyed by friendly field name; each value is
// { field, value, field_format, element }.
$cf       = is_array($asset['custom_fields'] ?? null) ? $asset['custom_fields'] : [];
$svadName = '';
if (isset($cf['SVAD Name']['value'])) {
    $svadName = trim($decode($cf['SVAD Name']['value']));
}

// Description priority: SVAD Name → asset name → model name.
$description = $svadName !== '' ? $svadName : ($assetName !== '' ? $assetName : $modelName);

echo json_encode([
    'ok'          => true,
    'asset_tag'   => $assetTag,
    'asset_name'  => $assetName,
    'model_name'  => $modelName,
    'svad_name'   => $svadName,
    'description' => $description,
]);
