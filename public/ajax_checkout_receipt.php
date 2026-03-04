<?php
// ajax_checkout_receipt.php
// Returns structured checkout or reservation data as JSON for receipt rendering.
// Staff-only. GET ?checkout_id=N or GET ?reservation_id=N

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';

header('Content-Type: application/json');

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$config = load_config();
$appName = $config['app']['name'] ?? 'SnipeScheduler';

$checkoutId    = (int)($_GET['checkout_id'] ?? 0);
$reservationId = (int)($_GET['reservation_id'] ?? 0);

// --- Reservation pick sheet (models + quantities, no specific assets yet) ---
if ($reservationId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $reservationId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        http_response_code(404);
        echo json_encode(['error' => 'Reservation not found.']);
        exit;
    }

    $items = get_reservation_items_with_names($pdo, $reservationId);
    $formattedItems = [];
    foreach ($items as $item) {
        $formattedItems[] = [
            'model_name' => $item['model_name'] ?? '',
            'quantity'   => (int)($item['quantity'] ?? 1),
        ];
    }

    echo json_encode([
        'type'           => 'reservation',
        'reservation_id' => $reservationId,
        'user_name'      => $reservation['user_name'] ?? '',
        'user_email'     => $reservation['user_email'] ?? '',
        'start_datetime' => app_format_datetime($reservation['start_datetime'] ?? ''),
        'end_datetime'   => app_format_datetime($reservation['end_datetime'] ?? ''),
        'status'         => $reservation['status'] ?? '',
        'items'          => $formattedItems,
        'app_name'       => $appName,
    ]);
    exit;
}

// --- Checkout receipt (specific assets with tags) ---
if ($checkoutId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid checkout_id or reservation_id.']);
    exit;
}

// Fetch checkout record
$stmt = $pdo->prepare("SELECT * FROM checkouts WHERE id = :id");
$stmt->execute([':id' => $checkoutId]);
$checkout = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$checkout) {
    http_response_code(404);
    echo json_encode(['error' => 'Checkout not found.']);
    exit;
}

// Fetch checkout items
$items = get_checkout_items($pdo, $checkoutId);

$formattedItems = [];
foreach ($items as $item) {
    $formattedItems[] = [
        'asset_tag'     => $item['asset_tag'] ?? '',
        'model_name'    => $item['model_name'] ?? '',
        'checked_in_at' => $item['checked_in_at'] ?? null,
    ];
}

echo json_encode([
    'type'           => 'checkout',
    'checkout_id'    => $checkoutId,
    'user_name'      => $checkout['user_name'] ?? '',
    'user_email'     => $checkout['user_email'] ?? '',
    'start_datetime' => app_format_datetime($checkout['start_datetime'] ?? ''),
    'end_datetime'   => app_format_datetime($checkout['end_datetime'] ?? ''),
    'status'         => $checkout['status'] ?? '',
    'items'          => $formattedItems,
    'app_name'       => $config['app']['name'] ?? 'SnipeScheduler',
]);
