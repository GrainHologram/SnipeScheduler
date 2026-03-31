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
$stmt = $pdo->prepare("SELECT id, user_name, user_email, snipeit_user_id FROM reservations WHERE id = :id AND snipeit_user_id = :uid");
$stmt->execute([':id' => $reservationId, ':uid' => $currentUserId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reservation) {
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

// Clear existing basket
$_SESSION['basket'] = [];
$_SESSION['basket_kit_groups'] = [];
$_SESSION['basket_kit_names'] = [];

// Set booking user from the reservation
$isStaff = !empty($currentUser['is_staff']) || !empty($currentUser['is_admin']);
if ($isStaff) {
    $resEmail = trim((string)($reservation['user_email'] ?? ''));
    $resName  = trim((string)($reservation['user_name'] ?? ''));
    $resSnipeId = (int)($reservation['snipeit_user_id'] ?? 0);
    if ($resEmail !== '') {
        $_SESSION['booking_user_override'] = [
            'email'           => $resEmail,
            'first_name'      => $resName,
            'last_name'       => '',
            'id'              => 0,
            'snipeit_user_id' => $resSnipeId,
        ];
    } else {
        unset($_SESSION['booking_user_override']);
    }
} else {
    unset($_SESSION['booking_user_override']);
}

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
