<?php
// reuse_reservation.php
// Copies items from an existing reservation into the basket, then redirects to basket.php.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_bookings.php');
    exit;
}

$reservationId = (int)($_POST['reservation_id'] ?? 0);
if ($reservationId <= 0) {
    header('Location: my_bookings.php');
    exit;
}

// Verify the reservation belongs to the current user
$currentUserId = (string)($currentUser['snipeit_user_id'] ?? '');
$stmt = $pdo->prepare("SELECT id FROM reservations WHERE id = :id AND snipeit_user_id = :uid");
$stmt->execute([':id' => $reservationId, ':uid' => $currentUserId]);
if (!$stmt->fetch()) {
    header('Location: my_bookings.php');
    exit;
}

// Fetch non-deleted reservation items
$itemStmt = $pdo->prepare("
    SELECT model_id, model_name_cache, quantity, kit_id, kit_name_cache
      FROM reservation_items
     WHERE reservation_id = :rid AND deleted_at IS NULL
     ORDER BY id
");
$itemStmt->execute([':rid' => $reservationId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    header('Location: my_bookings.php');
    exit;
}

// Clear existing basket and reset booking user to self
$_SESSION['basket'] = [];
$_SESSION['basket_kit_groups'] = [];
$_SESSION['basket_kit_names'] = [];
unset($_SESSION['booking_user_override']);

// Populate basket from reservation items
$kitEntries = []; // kit_id => [ [model_id, quantity], ... ]
foreach ($items as $item) {
    $modelId  = (int)$item['model_id'];
    $quantity = (int)$item['quantity'];
    $kitId    = !empty($item['kit_id']) ? (int)$item['kit_id'] : 0;

    if ($modelId <= 0 || $quantity <= 0) {
        continue;
    }

    $currentQty = isset($_SESSION['basket'][$modelId]) ? (int)$_SESSION['basket'][$modelId] : 0;
    $_SESSION['basket'][$modelId] = $currentQty + $quantity;

    if ($kitId > 0) {
        if (!isset($kitEntries[$kitId])) {
            $kitEntries[$kitId] = [];
        }
        $kitEntries[$kitId][] = [
            'model_id' => $modelId,
            'quantity' => $quantity,
        ];
        $_SESSION['basket_kit_names'][$kitId] = $item['kit_name_cache'] ?? ('Kit #' . $kitId);
    }
}

// Store kit groups for basket display
foreach ($kitEntries as $kitId => $entries) {
    $_SESSION['basket_kit_groups'][$kitId] = [$entries];
}

header('Location: basket.php');
exit;
