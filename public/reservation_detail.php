<?php
// reservation_detail.php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

// Load reservation
try {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error loading reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

// Load items via shared helper
$items = get_reservation_items_with_names($pdo, $id);

$active  = 'staff_reservations.php'; // Treat detail view as part of booking history.
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking #<?= (int)$id ?> – Details</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Booking #<?= (int)$id ?> – Details</h1>
            <div class="page-subtitle">
                Full details for this booking.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="staff_reservations.php" class="btn btn-outline-secondary btn-sm">Back to all bookings</a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Booking information</h5>
                <p class="card-text">
                    <strong>User Name:</strong>
                    <?= h($reservation['user_name'] ?? '(Unknown)') ?><br>

                    <strong>Start:</strong>
                    <?= display_datetime($reservation['start_datetime'] ?? '') ?><br>

                    <strong>End:</strong>
                    <?= display_datetime($reservation['end_datetime'] ?? '') ?><br>

                    <strong>Status:</strong>
                    <?= layout_status_badge($reservation['status'] ?? '') ?><br>

                    <?php if (!empty($reservation['asset_name_cache'])): ?>
                        <strong>Checked-out assets:</strong>
                        <?= h($reservation['asset_name_cache']) ?><br>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <h5>Items reserved</h5>

        <?php if (empty($items)): ?>
            <div class="alert alert-info">
                No item records found for this booking.
            </div>
        <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="width: 80px;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['name'] ?? '') ?></td>
                                <td><?= (int)$item['qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php
            // Show linked checkouts if reservation is fulfilled
            $linkedCheckouts = get_checkouts_for_reservation($pdo, $id);
            if (!empty($linkedCheckouts)):
        ?>
        <h5>Checkouts</h5>
        <?php foreach ($linkedCheckouts as $co): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Checkout #<?= (int)$co['id'] ?></strong>
                        <?= layout_checkout_status_badge($co['status'] ?? '') ?>
                    </div>
                    <div class="small text-muted mb-2">
                        <?= h(display_datetime($co['start_datetime'] ?? '')) ?> &rarr; <?= h(display_datetime($co['end_datetime'] ?? '')) ?>
                    </div>
                    <?php $coItems = get_checkout_items($pdo, (int)$co['id']); ?>
                    <?php if (!empty($coItems)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Asset Tag</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th>Checked Out</th>
                                        <th>Returned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($coItems as $ci): ?>
                                        <tr class="<?= $ci['checked_in_at'] ? 'table-success' : '' ?>">
                                            <td><?= h($ci['asset_tag'] ?? '') ?></td>
                                            <td><?= h($ci['asset_name'] ?? '') ?></td>
                                            <td><?= h($ci['model_name'] ?? '') ?></td>
                                            <td><?= h(display_datetime($ci['checked_out_at'] ?? '')) ?></td>
                                            <td><?= $ci['checked_in_at'] ? h(display_datetime($ci['checked_in_at'])) : '<span class="badge bg-warning text-dark">Out</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php
            $deletableStatuses = (load_config())['reservations']['deletable_statuses'] ?? ['pending', 'confirmed', 'cancelled', 'missed'];
            if (in_array($reservation['status'] ?? '', $deletableStatuses, true)):
        ?>
        <form method="post"
              action="delete_reservation.php"
              onsubmit="return confirm('Delete this booking and all its items? This cannot be undone.');">
            <input type="hidden" name="reservation_id" value="<?= (int)$id ?>">
            <button class="btn btn-outline-danger" type="submit">
                Delete this booking
            </button>
        </form>
        <?php endif; ?>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
