<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/checkout_rules.php';
require_once SRC_PATH . '/layout.php';

// Helper: redirect back to basket with an error message
function basket_error(string $msg): void
{
    $_SESSION['basket_error'] = $msg;
    header('Location: basket.php');
    exit;
}

$userOverride = $_SESSION['booking_user_override'] ?? null;
$user   = $userOverride ?: $currentUser;
$basket = $_SESSION['basket'] ?? [];

if (empty($basket)) {
    basket_error('Your basket is empty.');
}

$startRaw = $_POST['start_datetime'] ?? '';
$endRaw   = $_POST['end_datetime'] ?? '';

if (!$startRaw || !$endRaw) {
    basket_error('Start and end date/time are required.');
}

// Form values are in the app's local timezone; convert to UTC for DB storage
$appTz = app_get_timezone();
$utc   = new DateTimeZone('UTC');
try {
    $startDt = new DateTime($startRaw, $appTz);
    $endDt   = new DateTime($endRaw, $appTz);
} catch (Throwable $e) {
    basket_error('Invalid date/time.');
}

$start = $startDt->setTimezone($utc)->format('Y-m-d H:i:s');
$end   = $endDt->setTimezone($utc)->format('Y-m-d H:i:s');

if ($end <= $start) {
    basket_error('End time must be after start time.');
}

// Build user info from Snipe-IT user record
$userName  = trim($user['first_name'] . ' ' . $user['last_name']);
$userEmail = $user['email'];
$userId    = $user['id']; // Snipe-IT user id

// Checkout rules enforcement
$clCfg = checkout_limits_config();
$snipeUserId = (int)$userId;

// Single active checkout
if ($clCfg['enabled'] && $clCfg['single_active_checkout'] && $snipeUserId > 0 && check_user_has_active_checkout($snipeUserId)) {
    basket_error('You already have assets checked out. Please return them before making a new reservation. (Single active checkout is enforced.)');
}

// Duration limit
if ($clCfg['enabled'] && $snipeUserId > 0) {
    $durationErr = validate_checkout_duration($snipeUserId, $startDt, $endDt);
    if ($durationErr !== null) {
        basket_error($durationErr);
    }
}

// Certification enforcement per model in basket
if ($snipeUserId > 0) {
    foreach ($basket as $modelId => $qty) {
        $modelId = (int)$modelId;
        if ($modelId <= 0) {
            continue;
        }
        $certReqs = get_model_certification_requirements($modelId);
        if (!empty($certReqs)) {
            $missing = check_user_certifications($snipeUserId, $certReqs);
            if (!empty($missing)) {
                $modelData = get_model($modelId);
                $modelName = $modelData['name'] ?? ('Model #' . $modelId);
                basket_error('You lack required certification(s) for "' . $modelName . '": ' . implode(', ', $missing));
            }
        }
    }
}

$pdo->beginTransaction();

try {
    $models = [];
    $totalRequestedItems = 0;

    foreach ($basket as $modelId => $qty) {
        $modelId = (int)$modelId;
        $qty     = (int)$qty;

        if ($modelId <= 0 || $qty < 1) {
            throw new Exception('Invalid model/quantity in basket.');
        }

        $model = get_model($modelId);
        if (empty($model['id'])) {
            throw new Exception('Model not found in Snipe-IT: ID ' . $modelId);
        }

        // How many units of this model are already booked for this time range?
        $sql = "
            SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
            FROM reservation_items ri
            JOIN reservations r ON r.id = ri.reservation_id
            WHERE ri.model_id = :model_id
              AND r.status IN ('pending','confirmed')
              AND (r.start_datetime < :end AND r.end_datetime > :start)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':model_id' => $modelId,
            ':start'    => $start,
            ':end'      => $end,
        ]);
        $row = $stmt->fetch();
        $existingBooked = $row ? (int)$row['booked_qty'] : 0;

        // Total requestable units in Snipe-IT
        $totalRequestable = count_requestable_assets_by_model($modelId);
        $activeCheckedOut = count_checked_out_assets_by_model($modelId);
        $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

        if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
            throw new Exception(
                'Not enough units available for "' . ($model['name'] ?? ('ID '.$modelId)) . '" '
                . 'in that time period. Requested ' . $qty . ', already booked ' . $existingBooked
                . ', total available ' . $availableNow . '.'
            );
        }

        $models[] = [
            'model' => $model,
            'qty'   => $qty,
        ];
        $totalRequestedItems += $qty;
    }

    // Reservation header summary text
    if (!empty($models)) {
        $firstName = $models[0]['model']['name'] ?? 'Multiple models';
    } else {
        $firstName = 'Multiple models';
    }

    $label = $firstName;
    if ($totalRequestedItems > 1) {
        $label .= ' +' . ($totalRequestedItems - 1) . ' more item(s)';
    }

    $insertRes = $pdo->prepare("
        INSERT INTO reservations (
            user_name, user_email, user_id, snipeit_user_id,
            asset_id, asset_name_cache,
            start_datetime, end_datetime, status
        ) VALUES (
            :user_name, :user_email, :user_id, :snipeit_user_id,
            0, :asset_name_cache,
            :start_datetime, :end_datetime, 'pending'
        )
    ");
    $insertRes->execute([
        ':user_name'        => $userName,
        ':user_email'       => $userEmail,
        ':user_id'          => $userId,
        ':snipeit_user_id'  => $user['id'],
        ':asset_name_cache' => 'Pending checkout',
        ':start_datetime'   => $start,
        ':end_datetime'     => $end,
    ]);

    $reservationId = (int)$pdo->lastInsertId();

    // Insert reservation_items as model-level rows with quantity
    $insertItem = $pdo->prepare("
        INSERT INTO reservation_items (
            reservation_id, model_id, model_name_cache, quantity
        ) VALUES (
            :reservation_id, :model_id, :model_name_cache, :quantity
        )
    ");

    foreach ($models as $entry) {
        $model = $entry['model'];
        $qty   = (int)$entry['qty'];

        $insertItem->execute([
            ':reservation_id'   => $reservationId,
            ':model_id'         => (int)$model['id'],
            ':model_name_cache' => $model['name'] ?? ('Model #' . $model['id']),
            ':quantity'         => $qty,
        ]);
    }

    $pdo->commit();
    $_SESSION['basket'] = []; // clear basket

    activity_log_event('reservation_submitted', 'Reservation submitted', [
        'subject_type' => 'reservation',
        'subject_id'   => $reservationId,
        'metadata'     => [
            'items'     => $totalRequestedItems,
            'start'     => $start,
            'end'       => $end,
            'booked_for'=> $userEmail,
        ],
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    die('Could not create booking: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking submitted</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <?= layout_logo_tag() ?>
    <h1>Thank you</h1>
    <p>Your booking has been submitted for <?= (int)$totalRequestedItems ?> item(s).</p>
    <p>
        <a href="catalogue.php" class="btn btn-primary">Book more equipment</a>
        <a href="my_bookings.php" class="btn btn-secondary">View my bookings</a>
    </p>
</div>
<?php layout_footer(); ?>
</body>
</html>
