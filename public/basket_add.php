<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: catalogue.php');
    exit;
}

$modelId      = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$kitId        = isset($_POST['kit_id']) ? (int)$_POST['kit_id'] : 0;
$qtyRequested = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$kitQuantity  = isset($_POST['kit_quantity']) ? (int)$_POST['kit_quantity'] : 1;
$startRaw     = trim($_POST['start_datetime'] ?? '');
$endRaw       = trim($_POST['end_datetime'] ?? '');

if ($startRaw !== '' && $endRaw !== '') {
    $startTs = strtotime($startRaw);
    $endTs   = strtotime($endRaw);
    if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
        $_SESSION['reservation_window_start'] = $startRaw;
        $_SESSION['reservation_window_end']   = $endRaw;
    }
}

// Basket is stored in session as: model_id => quantity
if (!isset($_SESSION['basket']) || !is_array($_SESSION['basket'])) {
    $_SESSION['basket'] = [];
}

// Kit group tracking for display in basket
if (!isset($_SESSION['basket_kit_groups']) || !is_array($_SESSION['basket_kit_groups'])) {
    $_SESSION['basket_kit_groups'] = [];
}
if (!isset($_SESSION['basket_kit_names']) || !is_array($_SESSION['basket_kit_names'])) {
    $_SESSION['basket_kit_names'] = [];
}

if ($kitId > 0) {
    // --- Kit add: expand kit models into individual basket entries ---
    $kitQuantity = max(1, min(20, $kitQuantity));

    try {
        $kitData = get_kit($kitId);
        $kitModels = get_kit_models($kitId);
    } catch (Throwable $e) {
        header('Location: catalogue.php?tab=kits');
        exit;
    }

    if (empty($kitModels)) {
        header('Location: catalogue.php?tab=kits');
        exit;
    }

    $kitName = $kitData['name'] ?? 'Kit';
    $kitGroupEntries = [];

    foreach ($kitModels as $km) {
        $mid = (int)($km['id'] ?? 0);
        if ($mid <= 0) continue;

        $modelQty = max(1, (int)($km['quantity'] ?? 1));
        $addQty = $modelQty * $kitQuantity;

        $currentQty = isset($_SESSION['basket'][$mid]) ? (int)$_SESSION['basket'][$mid] : 0;
        $_SESSION['basket'][$mid] = $currentQty + $addQty;

        $kitGroupEntries[] = [
            'model_id' => $mid,
            'quantity' => $addQty,
        ];
    }

    // Store kit group metadata
    if (!isset($_SESSION['basket_kit_groups'][$kitId])) {
        $_SESSION['basket_kit_groups'][$kitId] = [];
    }
    $_SESSION['basket_kit_groups'][$kitId][] = $kitGroupEntries;
    $_SESSION['basket_kit_names'][$kitId] = $kitName;

} elseif ($modelId > 0 && $qtyRequested > 0) {
    // --- Individual model add (existing behavior) ---
    if ($qtyRequested > 100) {
        $qtyRequested = 100;
    }

    // Enforce hardware limits from Snipe-IT (if available)
    try {
        $requestableTotal = count_requestable_assets_by_model($modelId);
        $activeCheckedOut = count_checked_out_assets_by_model($modelId);
        $maxQty = $requestableTotal > 0 ? max(0, $requestableTotal - $activeCheckedOut) : 0;
    } catch (Throwable $e) {
        $maxQty = 0;
    }

    if ($maxQty > 0 && $qtyRequested > $maxQty) {
        $qtyRequested = $maxQty;
    }

    $currentQty = isset($_SESSION['basket'][$modelId]) ? (int)$_SESSION['basket'][$modelId] : 0;
    $newQty = $currentQty + $qtyRequested;

    if ($maxQty > 0 && $newQty > $maxQty) {
        $newQty = $maxQty;
    }

    $_SESSION['basket'][$modelId] = $newQty;

} else {
    header('Location: catalogue.php');
    exit;
}

// Compute total items in basket for UI feedback
$basketCount = 0;
foreach ($_SESSION['basket'] as $q) {
    $basketCount += (int)$q;
}

// Detect AJAX / fetch request
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    isset($_SERVER['HTTP_ACCEPT']) &&
    strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'           => true,
        'basket_count' => $basketCount,
    ]);
    exit;
}

// Fallback: normal redirect if not AJAX
$redirect = $kitId > 0 ? 'catalogue.php?tab=kits' : 'catalogue.php';
header('Location: ' . $redirect);
exit;
