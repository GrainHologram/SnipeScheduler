<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

$active        = basename($_SERVER['PHP_SELF']);
$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;
$currentUserId = (string)($currentUser['snipeit_user_id'] ?? '');

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$tabRaw = $_GET['tab'] ?? 'reservations';
$tab = $tabRaw === 'checked_out' ? 'checked_out' : 'reservations';

// Load this user's reservations
try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE snipeit_user_id = :user_id
        ORDER BY
            CASE WHEN status IN ('pending','confirmed') THEN 0 ELSE 1 END,
            start_datetime DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $currentUserId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
}

// Batch-fetch all reservation items in one query (no API calls)
$allResItems = [];
if (!empty($reservations)) {
    $allResItems = batch_get_reservation_items($pdo, array_column($reservations, 'id'));
}

// Split into upcoming (active) and past (terminal) reservations
$upcomingReservations = [];
$pastReservations = [];
foreach ($reservations as $r) {
    if (in_array($r['status'] ?? '', ['pending', 'confirmed'], true)) {
        $upcomingReservations[] = $r;
    } else {
        $pastReservations[] = $r;
    }
}

// Batch-fetch checkout IDs linked to these reservations
$reservationCheckouts = [];
$resIds = array_column($reservations, 'id');
if (!empty($resIds)) {
    $placeholders = implode(',', array_fill(0, count($resIds), '?'));
    $coStmt = $pdo->prepare("
        SELECT id, reservation_id, status
          FROM checkouts
         WHERE reservation_id IN ($placeholders)
         ORDER BY created_at DESC
    ");
    $coStmt->execute(array_values($resIds));
    foreach ($coStmt->fetchAll(PDO::FETCH_ASSOC) as $co) {
        $rid = (int)$co['reservation_id'];
        if (!isset($reservationCheckouts[$rid])) {
            $reservationCheckouts[$rid] = $co;
        }
    }
}

$checkedOutItems = [];
$checkedOutError = '';
if ($tab === 'checked_out') {
    try {
        $stmt = $pdo->prepare("
            SELECT ci.asset_tag, ci.asset_name, ci.model_name,
                   ci.checked_out_at, c.end_datetime,
                   c.status AS checkout_status
              FROM checkout_items ci
              JOIN checkouts c ON c.id = ci.checkout_id
             WHERE c.snipeit_user_id = :uid
               AND ci.checked_in_at IS NULL
               AND c.status IN ('open','partial')
             ORDER BY ci.checked_out_at DESC
        ");
        $stmt->execute([':uid' => $currentUserId]);
        $checkedOutItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $checkedOutItems = [];
        $checkedOutError = $e->getMessage();
    }
}

$deletedMsg = '';
if (!empty($_GET['deleted'])) {
    $deletedMsg = 'Reservation #' . (int)$_GET['deleted'] . ' has been deleted.';
}

// Basket state for reuse confirmation (staff/admin)
$currentBasket = $_SESSION['basket'] ?? [];
$currentBasketCount = 0;
foreach ($currentBasket as $q) {
    $currentBasketCount += (int)$q;
}
$bookingOverride = $_SESSION['booking_user_override'] ?? null;
$basketUserLabel = '';
if ($bookingOverride) {
    $overrideName = trim(($bookingOverride['first_name'] ?? '') . ' ' . ($bookingOverride['last_name'] ?? ''));
    if ($overrideName === '') {
        $overrideName = $bookingOverride['name'] ?? ($bookingOverride['email'] ?? '');
    }
    $basketUserLabel = $overrideName;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Reservations</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= layout_stylesheet_url() ?>">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>My Reservations</h1>
            <div class="page-subtitle">
                View all your past, current and future reservations.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h($userName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($deletedMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($deletedMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading your reservations: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $reservationsUrl = 'my_bookings.php?tab=reservations';
            $checkedOutUrl = 'my_bookings.php?tab=checked_out';
        ?>
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'reservations' ? 'active' : '' ?>"
                   href="<?= h($reservationsUrl) ?>">My Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="<?= h($checkedOutUrl) ?>">My Checked Out Items</a>
            </li>
        </ul>

        <?php if ($tab === 'checked_out'): ?>
            <?php if (!empty($checkedOutError)): ?>
                <div class="alert alert-danger">
                    Error loading checked-out items: <?= htmlspecialchars($checkedOutError) ?>
                </div>
            <?php elseif (empty($checkedOutItems)): ?>
                <div class="alert alert-info">
                    You don’t have any checked-out items right now.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Asset Tag</th>
                                <th>Name</th>
                                <th>Model</th>
                                <th>Checked Out</th>
                                <th>Expected Return</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkedOutItems as $row): ?>
                                <tr>
                                    <td><?= h($row['asset_tag'] ?? '') ?></td>
                                    <td><?= h($row['asset_name'] ?? '') ?></td>
                                    <td><?= h($row['model_name'] ?? '') ?></td>
                                    <td><?= h(display_datetime($row['checked_out_at'] ?? '')) ?></td>
                                    <td><?= h(display_datetime($row['end_datetime'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($reservations)): ?>
                <div class="alert alert-info">
                    You don't have any reservations yet.
                </div>
            <?php else: ?>
                <?php if (empty($upcomingReservations)): ?>
                    <div class="alert alert-info">
                        No upcoming reservations.
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingReservations as $res): ?>
                        <?php
                            $resId   = (int)$res['id'];
                            $items   = $allResItems[$resId] ?? [];
                            $summary = build_items_summary_text($items);
                            $status  = strtolower((string)($res['status'] ?? ''));
                        ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <h5 class="card-title">
                                    Reservation #<?= $resId ?><?= !empty($res['name']) ? ' — ' . h($res['name']) : '' ?>
                                </h5>
                                <p class="card-text">
                                    <strong>User Name:</strong>
                                    <?= h($res['user_name'] ?? $userName) ?><br>

                                    <strong>Start:</strong>
                                    <?= display_datetime($res['start_datetime'] ?? '') ?><br>

                                    <strong>End:</strong>
                                    <?= display_datetime($res['end_datetime'] ?? '') ?><br>

                                    <strong>Status:</strong>
                                    <?= layout_status_badge($res['status'] ?? '') ?><br>

                                    <?php if ($summary !== ''): ?>
                                        <strong>Items:</strong>
                                        <?= h($summary) ?><br>
                                    <?php endif; ?>

                                    <?php if (!empty($res['asset_name_cache'])): ?>
                                        <strong>Checked-out assets:</strong>
                                        <?= h($res['asset_name_cache']) ?>
                                    <?php endif; ?>

                                    <?php $linkedCheckout = $reservationCheckouts[$resId] ?? null; ?>
                                    <?php if ($linkedCheckout): ?>
                                        <br><strong>Checkout:</strong>
                                        <?php if ($isStaff): ?>
                                            <a href="checkout_history.php?q=<?= urlencode($res['user_email'] ?? '') ?>">
                                                #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                            </a>
                                        <?php else: ?>
                                            <a href="my_bookings.php?tab=checked_out">
                                                #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>

                                <?php if (!empty($items)): ?>
                                    <h6>Items in this reservation</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th style="width: 80px;">Qty</th>
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

                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <?php if (!empty($items)): ?>
                                        <?php if ($isStaff && ($currentBasketCount > 0 || $bookingOverride)): ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    onclick="showReuseModal(<?= $resId ?>)">
                                                Reuse items
                                            </button>
                                        <?php else: ?>
                                            <form method="post" action="reuse_reservation.php">
                                                <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                    Reuse items
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($status === 'pending'): ?>
                                        <a href="reservation_edit.php?id=<?= $resId ?>&from=my_bookings"
                                           class="btn btn-outline-primary btn-sm btn-action">
                                            Edit
                                        </a>
                                    <?php endif; ?>
                                    <?php
                                        $deletableStatuses = (load_config())['reservations']['deletable_statuses'] ?? ['pending', 'confirmed', 'cancelled', 'missed'];
                                        if (in_array($status, $deletableStatuses, true)):
                                    ?>
                                    <form method="post"
                                          action="delete_reservation.php"
                                          onsubmit="return confirm('Delete this reservation and all its items? This cannot be undone.');">
                                        <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            Delete reservation
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($pastReservations)): ?>
                    <button type="button" id="toggle-past-btn" class="btn btn-outline-secondary btn-sm mb-3"
                            onclick="togglePastReservations()">
                        Show past reservations (<?= count($pastReservations) ?>)
                    </button>
                    <div id="past-reservations" style="display:none">
                        <?php foreach ($pastReservations as $res): ?>
                            <?php
                                $resId   = (int)$res['id'];
                                $items   = $allResItems[$resId] ?? [];
                                $summary = build_items_summary_text($items);
                                $status  = strtolower((string)($res['status'] ?? ''));
                            ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        Reservation #<?= $resId ?><?= !empty($res['name']) ? ' — ' . h($res['name']) : '' ?>
                                    </h5>
                                    <p class="card-text">
                                        <strong>User Name:</strong>
                                        <?= h($res['user_name'] ?? $userName) ?><br>

                                        <strong>Start:</strong>
                                        <?= display_datetime($res['start_datetime'] ?? '') ?><br>

                                        <strong>End:</strong>
                                        <?= display_datetime($res['end_datetime'] ?? '') ?><br>

                                        <strong>Status:</strong>
                                        <?= layout_status_badge($res['status'] ?? '') ?><br>

                                        <?php if ($summary !== ''): ?>
                                            <strong>Items:</strong>
                                            <?= h($summary) ?><br>
                                        <?php endif; ?>

                                        <?php if (!empty($res['asset_name_cache'])): ?>
                                            <strong>Checked-out assets:</strong>
                                            <?= h($res['asset_name_cache']) ?>
                                        <?php endif; ?>

                                        <?php $linkedCheckout = $reservationCheckouts[$resId] ?? null; ?>
                                        <?php if ($linkedCheckout): ?>
                                            <br><strong>Checkout:</strong>
                                            <?php if ($isStaff): ?>
                                                <a href="checkout_history.php?q=<?= urlencode($res['user_email'] ?? '') ?>">
                                                    #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                                </a>
                                            <?php else: ?>
                                                <a href="my_bookings.php?tab=checked_out">
                                                    #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php if (!empty($items)): ?>
                                        <h6>Items in this reservation</h6>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Item</th>
                                                        <th style="width: 80px;">Qty</th>
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

                                    <div class="d-flex justify-content-end gap-2 mt-3">
                                        <?php if (!empty($items)): ?>
                                            <?php if ($isStaff && ($currentBasketCount > 0 || $bookingOverride)): ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                        onclick="showReuseModal(<?= $resId ?>)">
                                                    Reuse items
                                                </button>
                                            <?php else: ?>
                                                <form method="post" action="reuse_reservation.php">
                                                    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                                                        Reuse items
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php
                                            $deletableStatuses = (load_config())['reservations']['deletable_statuses'] ?? ['pending', 'confirmed', 'cancelled', 'missed'];
                                            if (in_array($status, $deletableStatuses, true)):
                                        ?>
                                        <form method="post"
                                              action="delete_reservation.php"
                                              onsubmit="return confirm('Delete this reservation and all its items? This cannot be undone.');">
                                            <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                Delete reservation
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<?php if ($isStaff && ($currentBasketCount > 0 || $bookingOverride)): ?>
<!-- Reuse confirmation modal -->
<div id="reuseBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1050;" onclick="closeReuseModal()"></div>
<div id="reuseModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeReuseModal()">
    <div style="max-width:480px; margin:0 auto; background:#fff; border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #dee2e6;">
            <h5 style="margin:0;">Replace current basket?</h5>
            <button type="button" onclick="closeReuseModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div style="padding:1rem;">
            <p>Reusing this reservation will <strong>clear your current basket</strong> and replace it with the selected reservation's items.</p>
            <?php if ($bookingOverride && $basketUserLabel !== ''): ?>
                <div class="mb-2">
                    <strong>Booking for:</strong> <?= h($basketUserLabel) ?>
                    <br><span class="text-muted small">The booking user will be reset to yourself.</span>
                </div>
            <?php endif; ?>
            <?php if ($currentBasketCount > 0): ?>
                <div class="mb-2">
                    <strong>Current basket:</strong> <?= $currentBasketCount ?> item<?= $currentBasketCount !== 1 ? 's' : '' ?>
                    (<?= count($currentBasket) ?> model<?= count($currentBasket) !== 1 ? 's' : '' ?>)
                </div>
            <?php endif; ?>
            <p class="mb-0 text-muted small">This cannot be undone.</p>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:.5rem; padding:.75rem 1rem; border-top:1px solid #dee2e6;">
            <button type="button" class="btn btn-outline-secondary" onclick="closeReuseModal()">Cancel</button>
            <form method="post" action="reuse_reservation.php" id="reuseModalForm">
                <input type="hidden" name="reservation_id" id="reuseModalResId" value="">
                <button type="submit" class="btn btn-primary">Replace basket</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
<script>
<?php if ($isStaff && ($currentBasketCount > 0 || $bookingOverride)): ?>
function showReuseModal(resId) {
    document.getElementById('reuseModalResId').value = resId;
    document.getElementById('reuseBackdrop').style.display = 'block';
    document.getElementById('reuseModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeReuseModal() {
    document.getElementById('reuseBackdrop').style.display = 'none';
    document.getElementById('reuseModal').style.display = 'none';
    document.body.style.overflow = '';
}
<?php endif; ?>

function togglePastReservations() {
    var container = document.getElementById('past-reservations');
    var btn = document.getElementById('toggle-past-btn');
    if (container.style.display === 'none') {
        container.style.display = '';
        btn.textContent = 'Hide past reservations';
    } else {
        container.style.display = 'none';
        btn.textContent = 'Show past reservations (<?= count($pastReservations) ?>)';
    }
}
</script>
</body>
</html>
