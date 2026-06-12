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

// Batch-fetch checkout_items per checkout + model, for per-reservation asset display
$checkoutItemsByModel = [];
if (!empty($reservationCheckouts)) {
    $checkoutIds = array_column($reservationCheckouts, 'id');
    $ph = implode(',', array_fill(0, count($checkoutIds), '?'));
    $ciStmt = $pdo->prepare("
        SELECT ci.checkout_id, ci.model_id, ci.asset_tag, ci.asset_name
          FROM checkout_items ci
         WHERE ci.checkout_id IN ($ph)
         ORDER BY ci.checkout_id, ci.model_id, ci.id
    ");
    $ciStmt->execute(array_values($checkoutIds));
    foreach ($ciStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
        $cid = (int)$ci['checkout_id'];
        $mid = (int)$ci['model_id'];
        $checkoutItemsByModel[$cid][$mid][] = [
            'asset_tag'  => $ci['asset_tag']  ?? '',
            'asset_name' => $ci['asset_name'] ?? '',
        ];
    }
}

// Load checked-out items
$checkedOutItems = [];
$checkedOutError = '';
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

// Build per-reservation summary data for reuse modal (staff only)
$reuseSummaries = [];
if ($isStaff) {
    foreach ($reservations as $res) {
        $rid = (int)$res['id'];
        $items = $allResItems[$rid] ?? [];
        if (empty($items)) continue;
        $itemLines = [];
        foreach ($items as $item) {
            $itemLines[] = (int)$item['qty'] . 'x ' . ($item['name'] ?? 'Unknown');
        }
        $reuseSummaries[$rid] = [
            'user' => $res['user_name'] ?? '',
            'items' => $itemLines,
        ];
    }
}

// Build current basket item summary from kit names and model IDs
$currentBasketSummary = [];
$kitGroups = $_SESSION['basket_kit_groups'] ?? [];
$kitNames  = $_SESSION['basket_kit_names'] ?? [];
$kitModelIds = [];
foreach ($kitGroups as $kid => $batches) {
    foreach ($batches as $batch) {
        foreach ($batch as $entry) {
            $kitModelIds[(int)$entry['model_id']] = true;
        }
    }
}
foreach ($currentBasket as $mid => $qty) {
    if (isset($kitModelIds[(int)$mid])) continue;
    $currentBasketSummary[] = (int)$qty . 'x Model #' . (int)$mid;
}
foreach ($kitNames as $kid => $kname) {
    $currentBasketSummary[] = h($kname);
}
layout_page_start([
    'active'             => $active,
    'title'              => 'My Gear',
    'bodyClass'          => 'p-4 page-my-gear',
    'pageHeaderTitle'    => 'My Gear',
    'pageHeaderSubtitle' => 'View all your past, current and future reservations.',
]);
?>

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

        <!-- ===================== Side-by-side panels ===================== -->
        <div class="my-bookings-panels">

            <!-- My Reservations panel -->
            <div class="my-bookings-panel">
                <h2 class="my-bookings-panel-title">My Reservations</h2>
                <div class="my-bookings-panel-box">
                    <?php if (empty($reservations)): ?>
                        <div class="panel-empty-state">
                            <i class="bi bi-calendar-x panel-empty-icon"></i>
                            <p class="panel-empty-text">You don't have any reservations yet.</p>
                        </div>
                    <?php else: ?>
                        <?php if (empty($upcomingReservations)): ?>
                            <div class="panel-empty-state">
                                <i class="bi bi-calendar-check panel-empty-icon"></i>
                                <p class="panel-empty-text">No upcoming reservations.</p>
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
                                        <div class="res-card-header">
                                            <div>
                                                <h5 class="card-title mb-0">Reservation #<?= $resId ?><?= !empty($res['name']) ? ' — ' . h($res['name']) : '' ?></h5>
                                                <div class="res-card-subtitle"><?= h($res['user_name'] ?? $userName) ?></div>
                                            </div>
                                            <?= layout_status_badge($res['status'] ?? '') ?>
                                        </div>
                                        <hr class="res-card-divider" aria-hidden="true">
                                        <?php $linkedCheckout = $reservationCheckouts[$resId] ?? null; ?>
                                        <p class="card-text">
                                            <strong>Start:</strong>
                                            <?= display_datetime($res['start_datetime'] ?? '') ?><br>

                                            <strong>End:</strong>
                                            <?= display_datetime($res['end_datetime'] ?? '') ?><br>
                                        </p>

                                        <?php if (!empty($items)): ?>
                                            <?php
                                                $coId        = $linkedCheckout ? (int)$linkedCheckout['id'] : null;
                                                $hasCheckout = $coId !== null && isset($checkoutItemsByModel[$coId]);
                                            ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Item</th>
                                                            <?php if ($hasCheckout): ?><th>Assets</th><?php endif; ?>
                                                            <th style="width: 80px;">Qty</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($items as $item): ?>
                                                            <?php
                                                                $mid    = (int)($item['model_id'] ?? 0);
                                                                $assets = $hasCheckout ? ($checkoutItemsByModel[$coId][$mid] ?? []) : [];
                                                                $tags   = implode(', ', array_map(fn($a) => h($a['asset_tag']), $assets));
                                                            ?>
                                                            <tr>
                                                                <td><?= h($item['name'] ?? '') ?></td>
                                                                <?php if ($hasCheckout): ?><td><?= $tags ?></td><?php endif; ?>
                                                                <td><?= (int)$item['qty'] ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between align-items-center mt-3">
                                            <div>
                                                <?php if ($linkedCheckout): ?>
                                                    <small class="text-muted">
                                                        Checkout:
                                                        <?php if ($isStaff): ?>
                                                            <a href="checkout_history.php?q=<?= urlencode($res['user_email'] ?? '') ?>">
                                                                #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                                            </a>
                                                        <?php else: ?>
                                                            #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex gap-2">
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
                                                      onsubmit="return confirm('Delete reservation #<?= $resId ?>?\n\nThis will permanently remove the reservation and all its items. This cannot be undone.');">
                                                    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                                    <input type="hidden" name="from" value="my_bookings">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        Delete reservation
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
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
                                            <div class="res-card-header">
                                                <div>
                                                    <h5 class="card-title mb-0">Reservation #<?= $resId ?><?= !empty($res['name']) ? ' — ' . h($res['name']) : '' ?></h5>
                                                    <div class="res-card-subtitle"><?= h($res['user_name'] ?? $userName) ?></div>
                                                </div>
                                                <?= layout_status_badge($res['status'] ?? '') ?>
                                            </div>
                                            <hr class="res-card-divider" aria-hidden="true">
                                            <?php $linkedCheckout = $reservationCheckouts[$resId] ?? null; ?>
                                            <p class="card-text">
                                                <strong>Start:</strong>
                                                <?= display_datetime($res['start_datetime'] ?? '') ?><br>

                                                <strong>End:</strong>
                                                <?= display_datetime($res['end_datetime'] ?? '') ?><br>
                                            </p>

                                            <?php if (!empty($items)): ?>
                                                <?php
                                                    $coId        = $linkedCheckout ? (int)$linkedCheckout['id'] : null;
                                                    $hasCheckout = $coId !== null && isset($checkoutItemsByModel[$coId]);
                                                ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-striped align-middle mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Item</th>
                                                                <?php if ($hasCheckout): ?><th>Assets</th><?php endif; ?>
                                                                <th style="width: 80px;">Qty</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($items as $item): ?>
                                                                <?php
                                                                    $mid    = (int)($item['model_id'] ?? 0);
                                                                    $assets = $hasCheckout ? ($checkoutItemsByModel[$coId][$mid] ?? []) : [];
                                                                    $tags   = implode(', ', array_map(fn($a) => h($a['asset_tag']), $assets));
                                                                ?>
                                                                <tr>
                                                                    <td><?= h($item['name'] ?? '') ?></td>
                                                                    <?php if ($hasCheckout): ?><td><?= $tags ?></td><?php endif; ?>
                                                                    <td><?= (int)$item['qty'] ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>

                                            <div class="d-flex justify-content-between align-items-center mt-3">
                                                <div>
                                                    <?php if ($linkedCheckout): ?>
                                                        <small class="text-muted">
                                                            Checkout:
                                                            <?php if ($isStaff): ?>
                                                                <a href="checkout_history.php?q=<?= urlencode($res['user_email'] ?? '') ?>">
                                                                    #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="my_bookings.php">
                                                                    #<?= (int)$linkedCheckout['id'] ?> (<?= h($linkedCheckout['status']) ?>)
                                                                </a>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <?php
                                                        $deletableStatuses = (load_config())['reservations']['deletable_statuses'] ?? ['pending', 'confirmed', 'cancelled', 'missed'];
                                                        if (in_array($status, $deletableStatuses, true)):
                                                    ?>
                                                    <form method="post"
                                                          action="delete_reservation.php"
                                                          onsubmit="return confirm('Delete reservation #<?= $resId ?>?\n\nThis will permanently remove the reservation and all its items. This cannot be undone.');">
                                                        <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                                        <input type="hidden" name="from" value="my_bookings">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            Delete reservation
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Checked Out Items panel -->
            <div class="my-bookings-panel">
                <h2 class="my-bookings-panel-title">My Checked Out Items</h2>
                <div class="my-bookings-panel-box">
                    <?php if (!empty($checkedOutError)): ?>
                        <div class="alert alert-danger">
                            Error loading checked-out items: <?= htmlspecialchars($checkedOutError) ?>
                        </div>
                    <?php elseif (empty($checkedOutItems)): ?>
                        <div class="panel-empty-state">
                            <i class="bi bi-bag-x panel-empty-icon"></i>
                            <p class="panel-empty-text">You don't have any checked-out items right now.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Assets</th>
                                        <th>Model</th>
                                        <th>Checked Out</th>
                                        <th>Expected Return</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($checkedOutItems as $row): ?>
                                        <tr>
                                            <td><?= h($row['asset_tag'] ?? '') ?></td>
                                            <td><?= h($row['model_name'] ?? '') ?></td>
                                            <td><?= h(display_datetime($row['checked_out_at'] ?? '')) ?></td>
                                            <td><?= h(display_datetime($row['end_datetime'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.my-bookings-panels -->

<?php if ($isStaff && ($currentBasketCount > 0 || $bookingOverride)): ?>
<!-- Reuse confirmation modal -->
<div id="reuseBackdrop" style="display:none; position:fixed; inset:0; background:var(--backdrop-modal); z-index:1050;" onclick="closeReuseModal()"></div>
<div id="reuseModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeReuseModal()">
    <div style="max-width:480px; margin:0 auto; background:var(--panel); border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(var(--black-rgb), 0.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid var(--border);">
            <h5 style="margin:0;">Replace current basket?</h5>
            <button type="button" onclick="closeReuseModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div style="padding:1rem;">
            <p class="mb-3">Your current basket will be <strong>replaced</strong> with the selected reservation's items.</p>
            <div style="display:flex; gap:1rem;">
                <div style="flex:1; min-width:0;">
                    <div class="fw-semibold text-danger small mb-1">Current basket</div>
                    <?php if ($bookingOverride && $basketUserLabel !== ''): ?>
                        <div class="small mb-1"><strong>User:</strong> <?= h($basketUserLabel) ?></div>
                    <?php endif; ?>
                    <?php if ($currentBasketCount > 0): ?>
                        <div class="small text-muted" id="reuseCurrentItems">
                            <?php if (!empty($currentBasketSummary)): ?>
                                <?php foreach ($currentBasketSummary as $line): ?>
                                    <div><?= $line ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?= $currentBasketCount ?> item<?= $currentBasketCount !== 1 ? 's' : '' ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted">Empty</div>
                    <?php endif; ?>
                </div>
                <div style="display:flex; align-items:center; font-size:1.25rem; color:var(--muted);">&rarr;</div>
                <div style="flex:1; min-width:0;">
                    <div class="fw-semibold text-success small mb-1">New items</div>
                    <div class="small mb-1" id="reuseNewUser"></div>
                    <div class="small text-muted" id="reuseNewItems"></div>
                </div>
            </div>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:.5rem; padding:.75rem 1rem; border-top:1px solid var(--border);">
            <button type="button" class="btn btn-outline-secondary" onclick="closeReuseModal()">Cancel</button>
            <form method="post" action="reuse_reservation.php" id="reuseModalForm">
                <input type="hidden" name="reservation_id" id="reuseModalResId" value="">
                <button type="submit" class="btn btn-primary">Replace basket</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
ob_start();
?>
<script>
<?php if ($isStaff && ($currentBasketCount > 0 || $bookingOverride)): ?>
var _reuseSummaries = <?= json_encode($reuseSummaries, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
function showReuseModal(resId) {
    document.getElementById('reuseModalResId').value = resId;
    var data = _reuseSummaries[resId] || {};
    var userEl = document.getElementById('reuseNewUser');
    var itemsEl = document.getElementById('reuseNewItems');
    userEl.innerHTML = data.user ? '<strong>User:</strong> ' + _esc(data.user) : '';
    var lines = data.items || [];
    itemsEl.innerHTML = lines.map(function(l) { return '<div>' + _esc(l) + '</div>'; }).join('');
    document.getElementById('reuseBackdrop').style.display = 'block';
    document.getElementById('reuseModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
function closeReuseModal() {
    document.getElementById('reuseBackdrop').style.display = 'none';
    document.getElementById('reuseModal').style.display = 'none';
    document.body.style.overflow = '';
}
function _esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
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
<?php
layout_page_end(['extraScripts' => ob_get_clean()]);
?>
