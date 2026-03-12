<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/booking_helpers.php';

$config  = load_config();
$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// ── AJAX: user search (staff only) ──────────────────────────────────
if ($isStaff && ($_GET['ajax'] ?? '') === 'user_search') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $data = snipeit_request('GET', 'users', [
            'search' => $q,
            'limit'  => 10,
        ]);

        $rows = $data['rows'] ?? [];
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'       => $row['id'] ?? null,
                'name'     => $row['name'] ?? '',
                'email'    => $row['email'] ?? '',
                'username' => $row['username'] ?? '',
            ];
        }

        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Staff dashboard data ────────────────────────────────────────────
if ($isStaff) {
    $timezone = $config['app']['timezone'] ?? 'Europe/Jersey';
    $tz       = new DateTimeZone($timezone);
    $utc      = new DateTimeZone('UTC');
    $now      = new DateTime('now', $tz);
    $todayStr = $now->format('Y-m-d');

    $todayLocalStart = new DateTime($todayStr . ' 00:00:00', $tz);
    $todayLocalEnd   = new DateTime($todayStr . ' 23:59:59', $tz);
    $todayUtcStart   = (clone $todayLocalStart)->setTimezone($utc)->format('Y-m-d H:i:s');
    $todayUtcEnd     = (clone $todayLocalEnd)->setTimezone($utc)->format('Y-m-d H:i:s');
    $nowUtc          = (new DateTime('now', $utc))->format('Y-m-d H:i:s');

    // Pending pickups today
    $stmt = $pdo->prepare("
        SELECT * FROM reservations
         WHERE status IN ('pending','confirmed')
           AND start_datetime >= :todayStart AND start_datetime <= :todayEnd
         ORDER BY start_datetime ASC
         LIMIT 10
    ");
    $stmt->execute([':todayStart' => $todayUtcStart, ':todayEnd' => $todayUtcEnd]);
    $pendingPickups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount   = count($pendingPickups);

    // Batch-fetch all reservation items for pending pickups (no API calls)
    $pendingResItems = [];
    if (!empty($pendingPickups)) {
        $pendingResItems = batch_get_reservation_items($pdo, array_column($pendingPickups, 'id'));
    }

    // Active checkouts count
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM checkouts WHERE status IN ('open','partial')")->fetchColumn();

    // Due today — grouped by checkout
    $stmt = $pdo->prepare("
        SELECT c.id AS checkout_id, c.name AS checkout_name, c.user_name, c.user_email,
               c.start_datetime, c.end_datetime, c.snipeit_user_id,
               r.name AS reservation_name, r.asset_name_cache,
               COUNT(ci.id) AS item_count
          FROM checkouts c
          JOIN checkout_items ci ON ci.checkout_id = c.id
          LEFT JOIN reservations r ON r.id = c.reservation_id
         WHERE c.status IN ('open','partial')
           AND ci.checked_in_at IS NULL
           AND c.end_datetime >= :todayStart AND c.end_datetime <= :todayEnd
         GROUP BY c.id
         ORDER BY c.end_datetime ASC
    ");
    $stmt->execute([':todayStart' => $todayUtcStart, ':todayEnd' => $todayUtcEnd]);
    $dueToday  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dueCount  = count($dueToday);

    // Batch-fetch checkout_items for all due-today checkouts (for items modal)
    $dueCheckoutIds = array_column($dueToday, 'checkout_id');
    $dueCheckoutItems = [];
    if (!empty($dueCheckoutIds)) {
        $ph = implode(',', array_fill(0, count($dueCheckoutIds), '?'));
        $ciStmt = $pdo->prepare("SELECT checkout_id, asset_tag, asset_name, model_name, checked_in_at
                                   FROM checkout_items WHERE checkout_id IN ($ph) ORDER BY id");
        $ciStmt->execute(array_values($dueCheckoutIds));
        foreach ($ciStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
            $dueCheckoutItems[(int)$ci['checkout_id']][] = $ci;
        }
    }

    // Group due-today by user_email
    $dueTodayGrouped = [];
    foreach ($dueToday as $row) {
        $email = $row['user_email'];
        if (!isset($dueTodayGrouped[$email])) {
            $dueTodayGrouped[$email] = [
                'user_name' => $row['user_name'],
                'snipeit_user_id' => $row['snipeit_user_id'],
                'checkouts' => [],
            ];
        }
        $dueTodayGrouped[$email]['checkouts'][] = $row;
    }

    // Group pending pickups by user_email
    $pickupsGrouped = [];
    foreach ($pendingPickups as $row) {
        $email = $row['user_email'];
        if (!isset($pickupsGrouped[$email])) {
            $pickupsGrouped[$email] = [
                'user_name' => $row['user_name'],
                'snipeit_user_id' => $row['snipeit_user_id'],
                'reservations' => [],
            ];
        }
        $pickupsGrouped[$email]['reservations'][] = $row;
    }

    // Overdue — grouped by checkout
    $stmt = $pdo->prepare("
        SELECT c.id AS checkout_id, c.name AS checkout_name, c.user_name, c.user_email,
               c.end_datetime, c.snipeit_user_id,
               r.name AS reservation_name, r.asset_name_cache,
               COUNT(ci.id) AS item_count
          FROM checkouts c
          JOIN checkout_items ci ON ci.checkout_id = c.id
          LEFT JOIN reservations r ON r.id = c.reservation_id
         WHERE c.status IN ('open','partial')
           AND ci.checked_in_at IS NULL
           AND c.end_datetime < :nowUtc
         GROUP BY c.id
         ORDER BY c.end_datetime ASC
    ");
    $stmt->execute([':nowUtc' => $nowUtc]);
    $overdueItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $overdueCount = count($overdueItems);

    // Batch-fetch checkout_items for overdue checkouts (for items modal)
    $overdueCheckoutIds = array_column($overdueItems, 'checkout_id');
    $overdueCheckoutItems = [];
    if (!empty($overdueCheckoutIds)) {
        $ph = implode(',', array_fill(0, count($overdueCheckoutIds), '?'));
        $ciStmt = $pdo->prepare("SELECT checkout_id, asset_tag, asset_name, model_name, checked_in_at
                                   FROM checkout_items WHERE checkout_id IN ($ph) ORDER BY id");
        $ciStmt->execute(array_values($overdueCheckoutIds));
        foreach ($ciStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
            $overdueCheckoutItems[(int)$ci['checkout_id']][] = $ci;
        }
    }

    // Group overdue by user_email
    $overdueGrouped = [];
    foreach ($overdueItems as $row) {
        $email = $row['user_email'];
        if (!isset($overdueGrouped[$email])) {
            $overdueGrouped[$email] = [
                'user_name' => $row['user_name'],
                'snipeit_user_id' => $row['snipeit_user_id'],
                'checkouts' => [],
            ];
        }
        $overdueGrouped[$email]['checkouts'][] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipment Booking – Dashboard</title>
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
            <h1>Equipment Booking</h1>
            <div class="page-subtitle">
                <?php if ($isStaff): ?>
                    Staff dashboard — today's pickups, active checkouts, and items due back.
                <?php else: ?>
                    Browse bookable equipment, manage your basket, and review your bookings.
                <?php endif; ?>
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($isStaff): ?>

        <!-- Quick user lookup -->
        <div class="card mb-3" style="overflow:visible; z-index:10;">
            <div class="card-body" style="overflow:visible;">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Quick user lookup</label>
                        <div class="position-relative">
                            <input type="search" id="dash_user_input" name="user_lookup" class="form-control"
                                   placeholder="Start typing name or email" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="dash_user_suggestions">
                            <div class="list-group position-absolute w-100" id="dash_user_suggestions"
                                 style="z-index:9999; max-height:260px; overflow-y:auto; display:none;
                                        box-shadow:0 12px 24px rgba(0,0,0,0.18);"></div>
                        </div>
                    </div>
                    <div class="col-md-7 d-flex gap-2 flex-wrap" id="dash_action_buttons" style="display:none !important">
                        <a href="reservations.php?tab=today" class="btn btn-outline-primary" id="dash_btn_checkout">
                            Start Checkout
                        </a>
                        <a href="quick_checkin.php" class="btn btn-outline-primary" id="dash_btn_checkin">
                            Quick Checkin
                        </a>
                        <form method="post" action="catalogue.php" id="dash_catalogue_form" style="display:inline;">
                            <input type="hidden" name="mode" value="set_booking_user">
                            <input type="hidden" name="booking_user_email" id="dash_catalogue_email">
                            <input type="hidden" name="booking_user_name" id="dash_catalogue_name">
                            <button type="submit" class="btn btn-outline-primary">Browse Catalogue</button>
                        </form>
                    </div>
                </div>
                <div id="dash_user_selected" class="mt-2" style="display:none">
                    <span class="badge bg-primary" id="dash_user_badge"></span>
                    <button type="button" class="btn btn-sm btn-link" id="dash_user_clear">Clear</button>
                </div>
            </div>
        </div>

        <!-- Summary stat cards -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body py-3">
                        <div class="fs-3 fw-bold text-primary"><?= $pendingCount ?></div>
                        <div class="small text-muted">Pending Pickups</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <a href="reservations.php?tab=checkout_history" class="text-decoration-none">
                <div class="card text-center h-100">
                    <div class="card-body py-3">
                        <div class="fs-3 fw-bold text-info"><?= $activeCount ?></div>
                        <div class="small text-muted">Active Checkouts</div>
                    </div>
                </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body py-3">
                        <div class="fs-3 fw-bold text-warning"><?= $dueCount ?></div>
                        <div class="small text-muted">Due Today</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center h-100">
                    <div class="card-body py-3">
                        <div class="fs-3 fw-bold text-danger"><?= $overdueCount ?></div>
                        <div class="small text-muted">Overdue</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Two-column layout -->
        <div class="row g-3">
            <!-- Left column -->
            <div class="col-md-7">
                <!-- Upcoming pickups -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Upcoming Pickups Today</div>
                    <?php if (empty($pendingPickups)): ?>
                        <div class="card-body text-muted">No pending pickups for today.</div>
                    <?php else: ?>
                        <?php $qzConfig = load_config()['qz_tray'] ?? []; ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($pickupsGrouped as $puEmail => $puGroup): ?>
                                <?php
                                    $firstRes = $puGroup['reservations'][0];
                                    $earliestTime = app_format_time($firstRes['start_datetime']);
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-semibold" style="min-width:0; flex:1;"><?= h($puGroup['user_name']) ?></span>
                                        <span class="text-muted small flex-shrink-0">Pickup <?= h($earliestTime) ?></span>
                                        <?= layout_status_badge($firstRes['status']) ?>
                                        <a href="reservations.php?tab=today&res=<?= (int)$firstRes['id'] ?>" class="btn btn-sm btn-outline-primary flex-shrink-0">Process</a>
                                    </div>
                                    <?php foreach ($puGroup['reservations'] as $pickup): ?>
                                        <?php
                                            $resItems = $pendingResItems[(int)$pickup['id']] ?? [];
                                            $resLabel = $pickup['name'] ?: build_items_summary_text($resItems);
                                            $totalQty = 0;
                                            foreach ($resItems as $ri) { $totalQty += (int)($ri['qty'] ?? 0); }
                                        ?>
                                        <div class="d-flex align-items-center gap-2 ps-2" style="min-width:0;">
                                            <span class="text-muted small" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; flex:1;">
                                                <?= h($resLabel ?: '—') ?>
                                            </span>
                                            <?php if ($totalQty > 0): ?>
                                                <a href="javascript:void(0)" onclick="showDashItems('reservation',<?= (int)$pickup['id'] ?>)"
                                                   class="text-muted small flex-shrink-0" style="text-decoration:underline; cursor:pointer;">
                                                    (<?= $totalQty ?> item<?= $totalQty !== 1 ? 's' : '' ?>)
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($qzConfig['enabled'])): ?>
                                                <button type="button" class="btn btn-sm btn-link text-muted p-0 flex-shrink-0"
                                                        data-reservation-id="<?= (int)$pickup['id'] ?>"
                                                        onclick="qzPrintReservationPickList(this)">
                                                    <small>Pick List</small>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Equipment due today -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Equipment Due Today</div>
                    <?php if (empty($dueToday)): ?>
                        <div class="card-body text-muted">No equipment due back today.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($dueTodayGrouped as $dtEmail => $dtGroup): ?>
                                <?php
                                    $earliestDue = $dtGroup['checkouts'][0]['end_datetime'];
                                    $dueTime = app_format_time($earliestDue);
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-semibold" style="min-width:0; flex:1;"><?= h($dtGroup['user_name']) ?></span>
                                        <span class="text-muted small flex-shrink-0">Due <?= h($dueTime) ?></span>
                                        <?php if (!empty($dtGroup['snipeit_user_id'])): ?>
                                            <a href="quick_checkin.php?user=<?= (int)$dtGroup['snipeit_user_id'] ?>" class="btn btn-sm btn-outline-primary flex-shrink-0">Checkin</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php foreach ($dtGroup['checkouts'] as $coRow): ?>
                                        <?php
                                            $coItems = $dueCheckoutItems[(int)$coRow['checkout_id']] ?? [];
                                            $coName = $coRow['checkout_name'] ?: ($coRow['reservation_name'] ?: ($coRow['asset_name_cache'] ?: null));
                                            if (!$coName && !empty($coItems)) {
                                                $names = array_column($coItems, 'asset_name');
                                                $coName = implode(', ', array_filter($names));
                                            }
                                            $coLabel = $coName ?: ($coRow['item_count'] . ' item' . ($coRow['item_count'] != 1 ? 's' : ''));
                                            $itemCount = (int)$coRow['item_count'];
                                        ?>
                                        <div class="d-flex align-items-center gap-2 ps-2" style="min-width:0;">
                                            <span class="text-muted small" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; flex:1;">
                                                <?= h($coLabel) ?>
                                            </span>
                                            <?php if ($itemCount > 0): ?>
                                                <a href="javascript:void(0)" onclick="showDashItems('checkout',<?= (int)$coRow['checkout_id'] ?>)"
                                                   class="text-muted small flex-shrink-0" style="text-decoration:underline; cursor:pointer;">
                                                    (<?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?>)
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right column -->
            <div class="col-md-5">
                <!-- Quick actions -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Quick Actions</div>
                    <div class="list-group list-group-flush">
                        <a href="reservations.php?tab=today" class="list-group-item list-group-item-action">
                            Process Reservations
                        </a>
                        <a href="quick_checkout.php" class="list-group-item list-group-item-action">
                            Quick Checkout
                        </a>
                        <a href="quick_checkin.php" class="list-group-item list-group-item-action">
                            Quick Checkin
                        </a>
                        <a href="catalogue.php" class="list-group-item list-group-item-action">
                            Browse Catalogue
                        </a>
                    </div>
                </div>

                <!-- Overdue -->
                <div class="card mb-3 <?= $overdueCount > 0 ? 'border-danger' : '' ?>">
                    <div class="card-header fw-semibold <?= $overdueCount > 0 ? 'bg-danger text-white' : '' ?>">
                        Overdue Items
                    </div>
                    <?php if (empty($overdueItems)): ?>
                        <div class="card-body text-muted">No overdue items.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($overdueGrouped as $odEmail => $odGroup): ?>
                                <?php
                                    $earliestOverdue = $odGroup['checkouts'][0]['end_datetime'];
                                    $overdueTime = app_format_datetime($earliestOverdue);
                                ?>
                                <div class="list-group-item">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-semibold" style="min-width:0; flex:1;"><?= h($odGroup['user_name']) ?></span>
                                        <span class="text-muted small flex-shrink-0">Due <?= h($overdueTime) ?></span>
                                        <?php if (!empty($odGroup['snipeit_user_id'])): ?>
                                            <a href="quick_checkin.php?user=<?= (int)$odGroup['snipeit_user_id'] ?>" class="btn btn-sm btn-outline-danger flex-shrink-0">Checkin</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php foreach ($odGroup['checkouts'] as $coRow): ?>
                                        <?php
                                            $coItems = $overdueCheckoutItems[(int)$coRow['checkout_id']] ?? [];
                                            $coName = $coRow['checkout_name'] ?: ($coRow['reservation_name'] ?: ($coRow['asset_name_cache'] ?: null));
                                            if (!$coName && !empty($coItems)) {
                                                $names = array_column($coItems, 'asset_name');
                                                $coName = implode(', ', array_filter($names));
                                            }
                                            $coLabel = $coName ?: ($coRow['item_count'] . ' item' . ($coRow['item_count'] != 1 ? 's' : ''));
                                            $itemCount = (int)$coRow['item_count'];
                                        ?>
                                        <div class="d-flex align-items-center gap-2 ps-2" style="min-width:0;">
                                            <span class="text-muted small" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; flex:1;">
                                                <?= h($coLabel) ?>
                                            </span>
                                            <?php if ($itemCount > 0): ?>
                                                <a href="javascript:void(0)" onclick="showDashItems('overdue',<?= (int)$coRow['checkout_id'] ?>)"
                                                   class="text-muted small flex-shrink-0" style="text-decoration:underline; cursor:pointer;">
                                                    (<?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?>)
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($overdueCount > 0): ?>
                        <div class="card-footer text-center">
                            <a href="overdue_report.php" class="btn btn-sm btn-outline-danger">View Full Report</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Items detail modal -->
        <div id="dashItemsBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1050;" onclick="closeDashItemsModal()"></div>
        <div id="dashItemsModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeDashItemsModal()">
            <div style="width:fit-content; max-width:90vw; margin:0 auto; background:#fff; border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15);">
                <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #dee2e6;">
                    <h5 style="margin:0;" id="dashItemsTitle">Items</h5>
                    <button type="button" onclick="closeDashItemsModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
                </div>
                <div id="dashItemsBody" style="padding:1rem; max-height:70vh; overflow-y:auto;"></div>
            </div>
        </div>

        <?php else: ?>

        <!-- Non-staff user view (unchanged) -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">Browse equipment</h5>
                        <p class="card-text">
                            View the catalogue of equipment models available for users to book.
                            Add items to your basket and request them for specific dates.
                        </p>
                        <a href="catalogue.php" class="btn btn-primary mt-auto">
                            Go to catalogue
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">My Reservations</h5>
                        <p class="card-text">
                            See all of your upcoming and past reservations, including which models you
                            requested, and cancel future bookings where allowed.
                        </p>
                        <a href="my_bookings.php" class="btn btn-outline-primary mt-auto">
                            View my reservations
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>

        <div class="mt-4">
            <div class="alert alert-secondary mb-0">
                Need help or something is missing from the catalogue? Please contact staff.
            </div>
        </div>
    </div>
</div>

<?php
$welcomeEnabled = $config['app']['welcome_enabled'] ?? true;
if ($welcomeEnabled):
?>
<div id="welcomeBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1050;" onclick="closeWelcomeModal()"></div>
<div id="welcomeModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeWelcomeModal()">
    <div style="max-width:550px; margin:0 auto; background:#fff; border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #dee2e6;">
            <h5 style="margin:0;">Welcome to <?= h($config['app']['name'] ?? 'SnipeScheduler') ?></h5>
            <button type="button" onclick="closeWelcomeModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div style="padding:1rem;">
            <p class="mb-3">Here's how the equipment booking system works:</p>
            <ol class="mb-3" style="padding-left:1.25rem;">
                <li class="mb-2">
                    <strong>Browse &amp; reserve</strong><br>
                    <span class="text-muted">Visit the catalogue to see available equipment. The catalogue defaults to available kits; to see individual items, click on the "Equipment" tab. Add items to your basket, choose your dates, and submit a reservation request.</span>
                </li>
                <li class="mb-2">
                    <strong>Authorisation</strong><br>
                    <span class="text-muted">Some equipment requires certifications or specific access levels. If an item is restricted, you'll see a badge on it &mdash; contact staff to get authorised.</span>
                </li>
                <li class="mb-2">
                    <strong>Collect &amp; return</strong><br>
                    <span class="text-muted">Pick up your equipment at the start of your reservation. Return it by the scheduled end time and check in with staff.</span>
                </li>
                <li class="mb-2">
                    <strong>Need help?</strong><br>
                    <span class="text-muted">If something is missing from the catalogue or you have questions, please contact staff.</span>
                </li>
            </ol>
            <div class="text-end">
                <button type="button" class="btn btn-primary" onclick="closeWelcomeModal()">Get started</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('snipesched_welcome_dismissed')) return;
    document.getElementById('welcomeBackdrop').style.display = 'block';
    document.getElementById('welcomeModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
});
function closeWelcomeModal() {
    document.getElementById('welcomeBackdrop').style.display = 'none';
    document.getElementById('welcomeModal').style.display = 'none';
    document.body.style.overflow = '';
    localStorage.setItem('snipesched_welcome_dismissed', '1');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('welcomeModal').style.display === 'block') {
        closeWelcomeModal();
    }
});
</script>
<?php endif; ?>

<?php if ($isStaff): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('dash_user_input');
    var list  = document.getElementById('dash_user_suggestions');
    var selected = document.getElementById('dash_user_selected');
    var badge = document.getElementById('dash_user_badge');
    var clearBtn = document.getElementById('dash_user_clear');
    var actionBtns = document.getElementById('dash_action_buttons');
    var timer = null;
    var lastQuery = '';
    var selectedUser = null;

    var activeIndex = -1;

    function hideSuggestions() {
        list.style.display = 'none';
        list.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
    }

    function showActions() {
        actionBtns.style.display = '';
        actionBtns.classList.add('d-flex');
    }

    function hideActions() {
        actionBtns.style.display = 'none !important';
        actionBtns.classList.remove('d-flex');
        actionBtns.setAttribute('style', 'display:none !important');
    }

    var catEmail = document.getElementById('dash_catalogue_email');
    var catName  = document.getElementById('dash_catalogue_name');

    function selectUser(user) {
        selectedUser = user;
        var label = user.name;
        if (user.email && user.email !== user.name) label += ' (' + user.email + ')';
        badge.textContent = label;
        selected.style.display = '';
        input.value = '';
        hideSuggestions();
        showActions();
        if (catEmail) catEmail.value = user.email || '';
        if (catName) catName.value = user.name || user.email || '';
        var checkinBtn = document.getElementById('dash_btn_checkin');
        if (checkinBtn && user.id) checkinBtn.href = 'quick_checkin.php?user=' + encodeURIComponent(user.id);
    }

    function clearUser() {
        selectedUser = null;
        selected.style.display = 'none';
        badge.textContent = '';
        hideActions();
        input.value = '';
        if (catEmail) catEmail.value = '';
        if (catName) catName.value = '';
        var checkinBtn = document.getElementById('dash_btn_checkin');
        if (checkinBtn) checkinBtn.href = 'quick_checkin.php';
    }

    input.addEventListener('input', function() {
        var q = input.value.trim();
        if (q.length < 2) {
            hideSuggestions();
            return;
        }
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() {
            lastQuery = q;
            fetch('index.php?ajax=user_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.ok ? res.json() : null; })
            .then(function(data) {
                if (lastQuery !== q) return;
                if (!data || !data.results || !data.results.length) {
                    hideSuggestions();
                    return;
                }
                list.innerHTML = '';
                data.results.forEach(function(item) {
                    var email = item.email || '';
                    var name = item.name || '';
                    var label = (name && email && name !== email) ? (name + ' (' + email + ')') : (name || email);
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.textContent = label;
                    btn.addEventListener('click', function() {
                        selectUser(item);
                    });
                    list.appendChild(btn);
                });
                list.style.display = 'block';
                input.setAttribute('aria-expanded', 'true');
                activeIndex = -1;
            })
            .catch(function() {
                hideSuggestions();
            });
        }, 250);
    });

    input.addEventListener('keydown', function(e) {
        var items = list.querySelectorAll('.list-group-item');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = (activeIndex + 1) % items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = (activeIndex - 1 + items.length) % items.length;
        } else if (e.key === 'Enter' && activeIndex >= 0 && activeIndex < items.length) {
            e.preventDefault();
            items[activeIndex].click();
            return;
        } else if (e.key === 'Escape') {
            hideSuggestions();
            return;
        } else {
            return;
        }
        items.forEach(function(el, i) {
            el.classList.toggle('active', i === activeIndex);
        });
        items[activeIndex].scrollIntoView({ block: 'nearest' });
    });

    input.addEventListener('blur', function() {
        setTimeout(hideSuggestions, 150);
    });

    clearBtn.addEventListener('click', clearUser);

    // Auto-refresh every 60 seconds (skip if feedback, welcome, or items modal is open)
    setInterval(function() {
        var feedback = document.getElementById('feedbackModal');
        var welcome = document.getElementById('welcomeModal');
        var dashItems = document.getElementById('dashItemsModal');
        if ((!feedback || feedback.style.display !== 'block') &&
            (!welcome || welcome.style.display !== 'block') &&
            (!dashItems || dashItems.style.display !== 'block')) {
            window.location.reload();
        }
    }, 60000);
});

var dashCheckoutItems = <?= json_encode($dueCheckoutItems, JSON_HEX_TAG) ?>;
var dashOverdueItems = <?= json_encode($overdueCheckoutItems, JSON_HEX_TAG) ?>;
var dashReservationItems = <?= json_encode($pendingResItems, JSON_HEX_TAG) ?>;

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

function showDashItems(type, id) {
    var title = document.getElementById('dashItemsTitle');
    var body = document.getElementById('dashItemsBody');
    var items, html;

    if (type === 'checkout' || type === 'overdue') {
        items = (type === 'overdue' ? dashOverdueItems[id] : dashCheckoutItems[id]) || [];
        title.textContent = type === 'overdue' ? 'Overdue Items' : 'Checkout Items';
        html = '<table class="table table-sm mb-0"><thead><tr><th>Asset Tag</th><th>Name</th><th>Model</th><th>Status</th></tr></thead><tbody>';
        items.forEach(function(ci) {
            var status = ci.checked_in_at ? '<span class="badge bg-secondary">Returned</span>' : '<span class="badge bg-success">Out</span>';
            html += '<tr><td>' + escHtml(ci.asset_tag || '') + '</td><td>' + escHtml(ci.asset_name || '') + '</td><td>' + escHtml(ci.model_name || '') + '</td><td>' + status + '</td></tr>';
        });
        html += '</tbody></table>';
    } else {
        items = dashReservationItems[id] || [];
        title.textContent = 'Reservation Items';
        html = '<table class="table table-sm mb-0"><thead><tr><th>Model</th><th>Qty</th></tr></thead><tbody>';
        items.forEach(function(ri) {
            html += '<tr><td>' + escHtml(ri.name || '') + '</td><td>' + (ri.qty || 0) + '</td></tr>';
        });
        html += '</tbody></table>';
    }

    if (!items.length) {
        html = '<p class="text-muted mb-0">No items found.</p>';
    }

    body.innerHTML = html;
    document.getElementById('dashItemsBackdrop').style.display = 'block';
    document.getElementById('dashItemsModal').style.display = 'block';
}

function closeDashItemsModal() {
    document.getElementById('dashItemsBackdrop').style.display = 'none';
    document.getElementById('dashItemsModal').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('dashItemsModal').style.display === 'block') {
        closeDashItemsModal();
    }
});
</script>
<?php endif; ?>

<?php layout_footer(); ?>
</body>
</html>
