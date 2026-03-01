<?php
// ajax_model_history.php
// Returns JSON with model metadata, asset inventory, and (for staff) checkout/reservation history.
// GET: returns model detail data. POST: handles staff actions (e.g. add asset note).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/snipeit_client.php';

header('Content-Type: application/json; charset=utf-8');

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// --- POST handler (staff only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isStaff) {
        http_response_code(403);
        echo json_encode(['error' => 'Staff only']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'add_note') {
        $assetId = (int)($input['asset_id'] ?? 0);
        $note = trim($input['note'] ?? '');

        if ($assetId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing asset_id']);
            exit;
        }
        if ($note === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Note text cannot be empty']);
            exit;
        }

        try {
            add_asset_note($assetId, $note);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to add note: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// --- GET handler ---
$modelId = (int)($_GET['model_id'] ?? 0);
if ($modelId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing model_id']);
    exit;
}

// Fetch model metadata from Snipe-IT API
$modelData = [];
$modelName = '';
$modelImage = null;
$manufacturer = '';
$category = '';
$modelNotes = '';

try {
    $modelData = get_model($modelId);
    $modelName = $modelData['name'] ?? '';
    $manufacturer = $modelData['manufacturer']['name'] ?? '';
    $category = $modelData['category']['name'] ?? '';
    $modelNotes = $modelData['notes'] ?? '';

    // Build proxied image URL
    $rawImage = $modelData['image'] ?? '';
    if ($rawImage !== '' && $rawImage !== null) {
        $modelImage = 'image_proxy.php?src=' . urlencode($rawImage);
    }
} catch (Throwable $e) {
    // Fall back to name from local cache
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
}

// Fetch asset inventory from Snipe-IT API
$assets = [];
try {
    $rawAssets = list_assets_by_model($modelId);
    foreach ($rawAssets as $a) {
        // Non-staff: only show requestable assets
        if (!$isStaff && empty($a['requestable'])) {
            continue;
        }

        $statusLabel = $a['status_label'] ?? [];
        $statusName = is_array($statusLabel) ? ($statusLabel['name'] ?? '') : '';
        $statusMeta = is_array($statusLabel) ? ($statusLabel['status_meta'] ?? '') : '';

        $asset = [
            'asset_id'   => (int)($a['id'] ?? 0),
            'asset_tag'  => $a['asset_tag'] ?? '',
            'asset_name' => $a['name'] ?? '',
            'status'     => $statusName,
            'status_meta'=> $statusMeta,
            'deployable' => is_asset_deployable($a),
        ];

        // Staff see who the asset is assigned to
        if ($isStaff) {
            $assignedTo = $a['assigned_to'] ?? null;
            $asset['assigned_to'] = null;
            if (is_array($assignedTo) && !empty($assignedTo['name'])) {
                $asset['assigned_to'] = $assignedTo['name'];
            }
        }

        $assets[] = $asset;
    }
} catch (Throwable $e) {
    // Assets unavailable — continue with empty list
}

$result = [
    'model_name'   => $modelName,
    'model_image'  => $modelImage,
    'manufacturer' => $manufacturer,
    'category'     => $category,
    'notes'        => $modelNotes,
    'assets'       => $assets,
    'is_staff'     => $isStaff,
];

// Staff-only sections
if ($isStaff) {
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
                'checked_out_at' => app_format_datetime($ci['checked_out_at'] ?? ''),
                'checked_in_at'  => $ci['checked_in_at'] ? app_format_datetime($ci['checked_in_at']) : null,
            ];
        }

        $recentCheckouts[] = [
            'checkout_id'    => (int)$co['id'],
            'user_name'      => $co['user_name'] ?? '',
            'user_email'     => $co['user_email'] ?? '',
            'start_datetime' => app_format_datetime($co['start_datetime'] ?? ''),
            'end_datetime'   => app_format_datetime($co['end_datetime'] ?? ''),
            'status'         => $co['status'] ?? '',
            'items'          => $items,
        ];
    }

    // --- Recent reservations ---
    $stmtRes = $pdo->prepare("
        SELECT DISTINCT r.id, r.user_name, r.user_email,
               r.start_datetime, r.end_datetime, r.status, r.name
        FROM reservations r
        JOIN reservation_items ri ON ri.reservation_id = r.id
        WHERE ri.model_id = :mid AND ri.deleted_at IS NULL
        ORDER BY r.start_datetime DESC
        LIMIT 10
    ");
    $stmtRes->execute([':mid' => $modelId]);
    $recentReservations = [];

    foreach ($stmtRes->fetchAll(PDO::FETCH_ASSOC) as $res) {
        $stmtResItems = $pdo->prepare("
            SELECT model_name_cache, quantity
            FROM reservation_items
            WHERE reservation_id = :rid AND model_id = :mid AND deleted_at IS NULL
            ORDER BY id
        ");
        $stmtResItems->execute([':rid' => (int)$res['id'], ':mid' => $modelId]);
        $resItems = [];
        foreach ($stmtResItems->fetchAll(PDO::FETCH_ASSOC) as $ri) {
            $resItems[] = [
                'model_name' => $ri['model_name_cache'] ?? '',
                'quantity'   => (int)$ri['quantity'],
            ];
        }

        $recentReservations[] = [
            'reservation_id' => (int)$res['id'],
            'name'           => $res['name'] ?? '',
            'user_name'      => $res['user_name'] ?? '',
            'user_email'     => $res['user_email'] ?? '',
            'start_datetime' => app_format_datetime($res['start_datetime'] ?? ''),
            'end_datetime'   => app_format_datetime($res['end_datetime'] ?? ''),
            'status'         => $res['status'] ?? '',
            'items'          => $resItems,
        ];
    }

    $result['currently_out']       = $currentlyOut;
    $result['recent_checkouts']    = $recentCheckouts;
    $result['recent_reservations'] = $recentReservations;
}

echo json_encode($result);
