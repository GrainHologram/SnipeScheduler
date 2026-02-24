<?php
// ajax_checkin_asset_action.php
// AJAX endpoint to save/clear per-asset checkin actions in session.
// Does NOT call any Snipe-IT API — just session storage.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON.']);
        exit;
    }

    $assetId = (int)($input['asset_id'] ?? 0);
    if ($assetId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid asset_id.']);
        exit;
    }

    $note               = trim((string)($input['note'] ?? ''));
    $createMaintenance   = !empty($input['create_maintenance']);
    $pullForRepair       = !empty($input['pull_for_repair']);

    $_SESSION['checkin_asset_actions'][$assetId] = [
        'note'               => $note,
        'create_maintenance' => $createMaintenance,
        'pull_for_repair'    => $pullForRepair,
    ];

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $assetId = (int)($input['asset_id'] ?? 0);
    if ($assetId > 0 && isset($_SESSION['checkin_asset_actions'][$assetId])) {
        unset($_SESSION['checkin_asset_actions'][$assetId]);
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
