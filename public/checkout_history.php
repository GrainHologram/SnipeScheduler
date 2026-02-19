<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

$active    = basename($_SERVER['PHP_SELF']);
$isAdmin   = !empty($currentUser['is_admin']);
$isStaff   = !empty($currentUser['is_staff']) || $isAdmin;
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'checkout_history.php';
$baseQuery = $embedded ? ['tab' => 'checkout_history'] : [];

if (!function_exists('display_datetime')) {
    function display_datetime(?string $isoDatetime): string
    {
        return app_format_datetime($isoDatetime);
    }
}

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Filters
$qRaw       = trim($_GET['q'] ?? '');
$fromRaw    = trim($_GET['from'] ?? '');
$toRaw      = trim($_GET['to'] ?? '');
$statusRaw  = trim($_GET['status'] ?? '');
$pageRaw    = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$sortRaw    = trim($_GET['sort'] ?? '');

$q          = $qRaw !== '' ? $qRaw : null;
$dateFrom   = $fromRaw !== '' ? $fromRaw : null;
$dateTo     = $toRaw !== '' ? $toRaw : null;
$statusFilter = in_array($statusRaw, ['open', 'partial', 'closed'], true) ? $statusRaw : null;
$page       = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100];
$perPage    = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$sortOptions = [
    'start_desc' => 'c.start_datetime DESC',
    'start_asc'  => 'c.start_datetime ASC',
    'end_desc'   => 'c.end_datetime DESC',
    'end_asc'    => 'c.end_datetime ASC',
    'user_asc'   => 'c.user_name ASC',
    'user_desc'  => 'c.user_name DESC',
    'status'     => 'c.status ASC',
];
$sort = array_key_exists($sortRaw, $sortOptions) ? $sortRaw : 'start_desc';

// Build query
try {
    $where  = [];
    $params = [];
    $joinItems = false;

    if ($q !== null) {
        $joinItems = true;
        $where[] = '(c.user_name LIKE :q OR c.user_email LIKE :q4 OR ci.asset_tag LIKE :q2 OR ci.asset_name LIKE :q3)';
        $params[':q']  = '%' . $q . '%';
        $params[':q2'] = '%' . $q . '%';
        $params[':q3'] = '%' . $q . '%';
        $params[':q4'] = '%' . $q . '%';
    }

    if ($dateFrom !== null) {
        $where[] = 'c.start_datetime >= :from';
        $params[':from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null) {
        $where[] = 'c.start_datetime <= :to';
        $params[':to'] = $dateTo . ' 23:59:59';
    }

    if ($statusFilter !== null) {
        $where[] = 'c.status = :status';
        $params[':status'] = $statusFilter;
    }

    $fromClause = 'checkouts c';
    if ($joinItems) {
        $fromClause .= ' LEFT JOIN checkout_items ci ON ci.checkout_id = c.id';
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = ' WHERE ' . implode(' AND ', $where);
    }

    // Count
    $countSql = "SELECT COUNT(DISTINCT c.id) FROM $fromClause" . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // Fetch
    $sql = "SELECT DISTINCT c.* FROM $fromClause" . $whereSql
         . ' ORDER BY ' . $sortOptions[$sort]
         . ' LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $checkouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $checkouts  = [];
    $loadError  = $e->getMessage();
    $totalRows  = 0;
    $totalPages = 1;
}

// Group checkouts by parent/child chain
$groups = [];       // root_id => ['primary' => row, 'children' => [...]]
$placed = [];       // track IDs already placed in a group

foreach ($checkouts as $co) {
    $id       = (int)$co['id'];
    $parentId = !empty($co['parent_checkout_id']) ? (int)$co['parent_checkout_id'] : null;

    if ($parentId && isset($placed[$parentId])) {
        // Parent is already in a group — add as child
        $rootId = $placed[$parentId];
        $groups[$rootId]['children'][] = $co;
        $placed[$id] = $rootId;
    } elseif ($parentId) {
        // Parent not in result set — this checkout is effectively a root
        $groups[$id] = ['primary' => $co, 'children' => []];
        $placed[$id] = $id;
    } else {
        // No parent — start a new group
        if (!isset($groups[$id])) {
            $groups[$id] = ['primary' => $co, 'children' => []];
        }
        $placed[$id] = $id;
    }
}

// Second pass: attach children whose parent appeared later in results
foreach ($checkouts as $co) {
    $id       = (int)$co['id'];
    $parentId = !empty($co['parent_checkout_id']) ? (int)$co['parent_checkout_id'] : null;
    if ($parentId && isset($placed[$parentId]) && isset($groups[$id]) && $placed[$id] === $id) {
        // This checkout started its own group but its parent is also in results
        $rootId = $placed[$parentId];
        if ($rootId !== $id) {
            $groups[$rootId]['children'][] = $co;
            // Move any children from the orphan group
            foreach ($groups[$id]['children'] as $child) {
                $groups[$rootId]['children'][] = $child;
            }
            unset($groups[$id]);
            $placed[$id] = $rootId;
        }
    }
}
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout History</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
<?php endif; ?>
        <div class="page-header">
            <h1>Checkout History</h1>
            <div class="page-subtitle">
                Browse all checkout records with asset details.
            </div>
        </div>

        <?php if (!$embedded): ?>
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
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading checkouts: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $actionUrl = $pageBase;
            if (!empty($baseQuery)) {
                $actionUrl .= '?' . http_build_query($baseQuery);
            }
        ?>
        <!-- Filters -->
        <div class="border rounded-3 p-4 mb-4">
            <form class="row g-2 mb-0 align-items-end" method="get" action="<?= h($actionUrl) ?>" id="checkout-history-filter-form">
                <?php foreach ($baseQuery as $k => $v): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endforeach; ?>
                <div class="col-12 col-lg">
                    <input type="text"
                           name="q"
                           class="form-control form-control-lg"
                           placeholder="Search by user, asset tag, or asset name..."
                           value="<?= htmlspecialchars($qRaw) ?>">
                </div>
                <div class="col-auto">
                    <input type="date"
                           name="from"
                           class="form-control form-control-lg"
                           style="min-width: 160px;"
                           value="<?= htmlspecialchars($fromRaw) ?>"
                           placeholder="From date">
                </div>
                <div class="col-auto">
                    <input type="date"
                           name="to"
                           class="form-control form-control-lg"
                           style="min-width: 160px;"
                           value="<?= htmlspecialchars($toRaw) ?>"
                           placeholder="To date">
                </div>
                <div class="col-auto">
                    <select name="status" class="form-select form-select-lg" style="min-width: 180px;">
                        <option value="" <?= $statusFilter === null ? 'selected' : '' ?>>All statuses</option>
                        <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Checked Out</option>
                        <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial Return</option>
                        <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Returned</option>
                    </select>
                </div>
                <div class="col-auto">
                    <select name="sort" class="form-select form-select-lg" aria-label="Sort checkouts" style="min-width: 240px;">
                        <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>Start (newest first)</option>
                        <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>Start (oldest first)</option>
                        <option value="end_desc" <?= $sort === 'end_desc' ? 'selected' : '' ?>>End (latest first)</option>
                        <option value="end_asc" <?= $sort === 'end_asc' ? 'selected' : '' ?>>End (soonest first)</option>
                        <option value="user_asc" <?= $sort === 'user_asc' ? 'selected' : '' ?>>User (A-Z)</option>
                        <option value="user_desc" <?= $sort === 'user_desc' ? 'selected' : '' ?>>User (Z-A)</option>
                        <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Status</option>
                    </select>
                </div>
                <div class="col-auto">
                    <select name="per_page" class="form-select form-select-lg" style="min-width: 180px;">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                                <?= $opt ?> per page
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button class="btn btn-primary w-100" type="submit">Filter</button>
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <?php
                        $clearUrl = $pageBase;
                        if (!empty($baseQuery)) {
                            $clearUrl .= '?' . http_build_query($baseQuery);
                        }
                    ?>
                    <a href="<?= h($clearUrl) ?>" class="btn btn-outline-secondary w-100">Clear</a>
                </div>
            </form>
        </div>

        <?php if (empty($groups)): ?>
            <div class="alert alert-info">
                No checkout records found.
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <?php
                    $primary = $group['primary'];
                    $children = $group['children'];
                ?>
                <!-- Primary checkout card -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong>Checkout #<?= (int)$primary['id'] ?></strong>
                            <?= layout_checkout_status_badge($primary['status'] ?? '') ?>
                        </div>
                        <div class="small text-muted mb-1">
                            <?= h(display_datetime($primary['start_datetime'] ?? '')) ?> &rarr; <?= h(display_datetime($primary['end_datetime'] ?? '')) ?>
                        </div>
                        <div class="small mb-2">
                            <strong>User:</strong> <?= h($primary['user_name'] ?? '(Unknown)') ?>
                            <?php if (!empty($primary['reservation_id'])): ?>
                                &middot; <a href="reservation_detail.php?id=<?= (int)$primary['reservation_id'] ?>">Booking #<?= (int)$primary['reservation_id'] ?></a>
                            <?php endif; ?>
                        </div>
                        <?php $coItems = get_checkout_items($pdo, (int)$primary['id']); ?>
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

                <?php if (!empty($children)): ?>
                    <?php foreach ($children as $child): ?>
                        <!-- Related child checkout card -->
                        <div class="card mb-3 border-secondary ms-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>Checkout #<?= (int)$child['id'] ?></strong>
                                    <?= layout_checkout_status_badge($child['status'] ?? '') ?>
                                </div>
                                <div class="small text-muted mb-1">
                                    <?= h(display_datetime($child['start_datetime'] ?? '')) ?> &rarr; <?= h(display_datetime($child['end_datetime'] ?? '')) ?>
                                </div>
                                <div class="small mb-2">
                                    <strong>User:</strong> <?= h($child['user_name'] ?? '(Unknown)') ?>
                                    <?php if (!empty($child['reservation_id'])): ?>
                                        &middot; <a href="reservation_detail.php?id=<?= (int)$child['reservation_id'] ?>">Booking #<?= (int)$child['reservation_id'] ?></a>
                                    <?php endif; ?>
                                </div>
                                <?php $childItems = get_checkout_items($pdo, (int)$child['id']); ?>
                                <?php if (!empty($childItems)): ?>
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
                                                <?php foreach ($childItems as $ci): ?>
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
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
                <?php
                    $pagerBase = $pageBase;
                    $pagerQuery = array_merge($baseQuery, [
                        'q'        => $qRaw,
                        'from'     => $fromRaw,
                        'to'       => $toRaw,
                        'status'   => $statusRaw,
                        'per_page' => $perPage,
                        'sort'     => $sort,
                    ]);
                ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $pagerQuery['page'] = $prevPage;
                            $prevUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            $pagerQuery['page'] = $nextPage;
                            $nextUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php
                                $pagerQuery['page'] = $p;
                                $pageUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= h($pageUrl) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($nextUrl) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
<?php if ($embedded): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('checkout-history-filter-form');
    var sortSelect = form ? form.querySelector('select[name="sort"]') : null;
    if (form && sortSelect) {
        sortSelect.addEventListener('change', function () {
            form.submit();
        });
    }
});
</script>
<?php endif; ?>
