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

    // Active checkouts count
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM checkouts WHERE status IN ('open','partial')")->fetchColumn();

    // Due today — grouped by checkout
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
           AND c.end_datetime >= :todayStart AND c.end_datetime <= :todayEnd
         GROUP BY c.id
         ORDER BY c.end_datetime ASC
    ");
    $stmt->execute([':todayStart' => $todayUtcStart, ':todayEnd' => $todayUtcEnd]);
    $dueToday  = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dueCount  = count($dueToday);

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
                <div class="card text-center h-100">
                    <div class="card-body py-3">
                        <div class="fs-3 fw-bold text-info"><?= $activeCount ?></div>
                        <div class="small text-muted">Active Checkouts</div>
                    </div>
                </div>
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
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Time</th>
                                        <th>Items</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingPickups as $pickup): ?>
                                        <?php
                                            $items = get_reservation_items_with_names($pdo, (int)$pickup['id']);
                                            $summary = build_items_summary_text($items);
                                        ?>
                                        <tr>
                                            <td><?= h($pickup['user_name']) ?></td>
                                            <td><?= h(app_format_datetime($pickup['start_datetime'])) ?></td>
                                            <td><?= h($summary ?: '—') ?></td>
                                            <td><?= layout_status_badge($pickup['status']) ?></td>
                                            <td>
                                                <a href="reservations.php?tab=today&res=<?= (int)$pickup['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    Process
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Equipment due today -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Equipment Due Today</div>
                    <?php if (empty($dueToday)): ?>
                        <div class="card-body text-muted">No equipment due back today.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Checkout</th>
                                        <th>User</th>
                                        <th>Due</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dueToday as $item):
                                        $coName = $item['checkout_name'] ?: ($item['reservation_name'] ?: ($item['asset_name_cache'] ?: null));
                                        $coLabel = $coName ?: ($item['item_count'] . ' item' . ($item['item_count'] != 1 ? 's' : ''));
                                    ?>
                                        <tr>
                                            <td>
                                                <?= h($coLabel) ?>
                                                <?php if ($coName && $item['item_count'] > 0): ?>
                                                    <span class="text-muted small">(<?= (int)$item['item_count'] ?> item<?= $item['item_count'] != 1 ? 's' : '' ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($item['user_name']) ?></td>
                                            <td><?= h(app_format_datetime($item['end_datetime'])) ?></td>
                                            <td>
                                                <?php if (!empty($item['snipeit_user_id'])): ?>
                                                    <a href="quick_checkin.php?user=<?= (int)$item['snipeit_user_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        Checkin
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
                            <?php foreach ($overdueItems as $item):
                                $coName = $item['checkout_name'] ?: ($item['reservation_name'] ?: ($item['asset_name_cache'] ?: null));
                                $coLabel = $coName ?: ($item['item_count'] . ' item' . ($item['item_count'] != 1 ? 's' : ''));
                            ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <?= h($coLabel) ?>
                                            <?php if ($coName && $item['item_count'] > 0): ?>
                                                <span class="text-muted small">(<?= (int)$item['item_count'] ?> item<?= $item['item_count'] != 1 ? 's' : '' ?>)</span>
                                            <?php endif; ?>
                                            <div class="small text-muted">
                                                <?= h($item['user_name']) ?> &mdash;
                                                due <?= h(app_format_datetime($item['end_datetime'])) ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($item['snipeit_user_id'])): ?>
                                            <a href="quick_checkin.php?user=<?= (int)$item['snipeit_user_id'] ?>" class="btn btn-sm btn-outline-danger">
                                                Checkin
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
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
    }

    function clearUser() {
        selectedUser = null;
        selected.style.display = 'none';
        badge.textContent = '';
        hideActions();
        input.value = '';
        if (catEmail) catEmail.value = '';
        if (catName) catName.value = '';
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

    // Auto-refresh every 60 seconds
    setTimeout(function() { window.location.reload(); }, 60000);
});
</script>
<?php endif; ?>

<?php layout_footer(); ?>
</body>
</html>
