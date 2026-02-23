<?php
// ajax_model_history.php
// Returns JSON with currently checked-out assets and recent checkout history for a model.
// Staff-only endpoint.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

header('Content-Type: application/json; charset=utf-8');

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['error' => 'Staff only']);
    exit;
}

$modelId = (int)($_GET['model_id'] ?? 0);
if ($modelId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing model_id']);
    exit;
}

// --- Currently checked out (from local cache) ---
$stmtOut = $pdo->prepare("
    SELECT asset_tag, asset_name, assigned_to_name, assigned_to_email,
           last_checkout, expected_checkin
    FROM checked_out_asset_cache
    WHERE model_id = :mid
    ORDER BY last_checkout DESC
");
$stmtOut->execute([':mid' => $modelId]);
$currentlyOut = [];
foreach ($stmtOut->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $currentlyOut[] = [
        'asset_tag'        => $row['asset_tag'],
        'asset_name'       => $row['asset_name'],
        'assigned_to_name' => $row['assigned_to_name'] ?? '',
        'assigned_to_email'=> $row['assigned_to_email'] ?? '',
        'last_checkout'    => app_format_datetime_local($row['last_checkout'] ?? ''),
        'expected_checkin' => app_format_datetime_local($row['expected_checkin'] ?? ''),
    ];
}

// --- Recent checkouts (from local checkout records) ---
$stmtCheckouts = $pdo->prepare("
    SELECT DISTINCT c.id, c.user_name, c.user_email,
           c.start_datetime, c.end_datetime, c.status
    FROM checkouts c
    JOIN checkout_items ci ON ci.checkout_id = c.id
    WHERE ci.model_id = :mid
    ORDER BY c.start_datetime DESC
    LIMIT 10
");
$stmtCheckouts->execute([':mid' => $modelId]);
$recentCheckouts = [];

foreach ($stmtCheckouts->fetchAll(PDO::FETCH_ASSOC) as $co) {
    $stmtItems = $pdo->prepare("
        SELECT asset_tag, asset_name, checked_out_at, checked_in_at
        FROM checkout_items
        WHERE checkout_id = :cid AND model_id = :mid
        ORDER BY id
    ");
    $stmtItems->execute([':cid' => (int)$co['id'], ':mid' => $modelId]);
    $items = [];
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $ci) {
        $items[] = [
            'asset_tag'      => $ci['asset_tag'],
            'asset_name'     => $ci['asset_name'],
            'checked_out_at' => app_format_datetime_local($ci['checked_out_at'] ?? ''),
            'checked_in_at'  => $ci['checked_in_at'] ? app_format_datetime_local($ci['checked_in_at']) : null,
        ];
    }

    $recentCheckouts[] = [
        'checkout_id'    => (int)$co['id'],
        'user_name'      => $co['user_name'] ?? '',
        'user_email'     => $co['user_email'] ?? '',
        'start_datetime' => app_format_datetime_local($co['start_datetime'] ?? ''),
        'end_datetime'   => app_format_datetime_local($co['end_datetime'] ?? ''),
        'status'         => $co['status'] ?? '',
        'items'          => $items,
    ];
}

// Get model name from cache or first checkout item
$modelName = '';
$stmtName = $pdo->prepare("SELECT model_name FROM checked_out_asset_cache WHERE model_id = :mid LIMIT 1");
$stmtName->execute([':mid' => $modelId]);
$nameRow = $stmtName->fetch(PDO::FETCH_ASSOC);
if ($nameRow) {
    $modelName = $nameRow['model_name'];
} else {
    $stmtName2 = $pdo->prepare("SELECT model_name FROM checkout_items WHERE model_id = :mid LIMIT 1");
    $stmtName2->execute([':mid' => $modelId]);
    $nameRow2 = $stmtName2->fetch(PDO::FETCH_ASSOC);
    if ($nameRow2) {
        $modelName = $nameRow2['model_name'];
    }
}

echo json_encode([
    'model_name'       => $modelName,
    'currently_out'    => $currentlyOut,
    'recent_checkouts' => $recentCheckouts,
]);
