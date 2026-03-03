<?php
// ajax_checkout_receipt.php
// Returns structured checkout data as JSON for receipt rendering.
// Staff-only. GET ?checkout_id=N

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

$checkoutId = (int)($_GET['checkout_id'] ?? 0);
if ($checkoutId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid checkout_id.']);
    exit;
}

$config = load_config();

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
    'checkout_id'    => $checkoutId,
    'user_name'      => $checkout['user_name'] ?? '',
    'user_email'     => $checkout['user_email'] ?? '',
    'start_datetime' => app_format_datetime($checkout['start_datetime'] ?? ''),
    'end_datetime'   => app_format_datetime($checkout['end_datetime'] ?? ''),
    'status'         => $checkout['status'] ?? '',
    'items'          => $formattedItems,
    'app_name'       => $config['app']['name'] ?? 'SnipeScheduler',
]);
