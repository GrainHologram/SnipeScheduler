<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active    = basename($_SERVER['PHP_SELF']);
$isAdmin   = !empty($currentUser['is_admin']);
$isStaff   = !empty($currentUser['is_staff']) || $isAdmin;
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'unmatched_checkins_report.php';
$baseQuery = $embedded ? ['tab' => 'unmatched'] : [];

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Filters
$search     = trim($_GET['q'] ?? '');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$defaultTo   = date('Y-m-d');
$fromRaw    = trim($_GET['from'] ?? $defaultFrom);
$toRaw      = trim($_GET['to'] ?? $defaultTo);
$typeFilter = trim($_GET['type'] ?? '');
$pageRaw    = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 25);

$dateFrom = $fromRaw !== '' ? $fromRaw : null;
$dateTo   = $toRaw !== '' ? $toRaw : null;
$page     = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100];
$perPage  = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 25;
$typeFilterValid = in_array($typeFilter, ['no_record', 'not_checked_out'], true) ? $typeFilter : '';

$error = '';
$rows  = [];
$totalRows = 0;
$totalPages = 1;

try {
    $where  = [];
    $params = [];

    if ($dateFrom !== null) {
        $where[] = 'uc.created_at >= :from_dt';
        $params[':from_dt'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null) {
        $where[] = 'uc.created_at <= :to_dt';
        $params[':to_dt'] = $dateTo . ' 23:59:59';
    }
    if ($typeFilterValid === 'no_record') {
        $where[] = 'uc.was_checked_out = 1';
    } elseif ($typeFilterValid === 'not_checked_out') {
        $where[] = 'uc.was_checked_out = 0';
    }
    if ($search !== '') {
        $where[] = '(uc.asset_tag LIKE :q1 OR uc.asset_name LIKE :q2 OR uc.model_name LIKE :q3 OR uc.checked_in_from_user_name LIKE :q4 OR uc.checked_in_by LIKE :q5)';
        $params[':q1'] = '%' . $search . '%';
        $params[':q2'] = '%' . $search . '%';
        $params[':q3'] = '%' . $search . '%';
        $params[':q4'] = '%' . $search . '%';
        $params[':q5'] = '%' . $search . '%';
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countSql = "SELECT COUNT(*) FROM unmatched_checkins uc {$whereClause}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // Fetch
    $sql = "SELECT uc.* FROM unmatched_checkins uc {$whereClause} ORDER BY uc.created_at DESC LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function unmatched_build_url(string $base, array $params): string
{
    $query = http_build_query($params);
    return $query === '' ? $base : ($base . '?' . $query);
}
layout_page_start([
    'active'             => $active,
    'title'              => 'Unmatched Checkins Report',
    'pageHeaderTitle'    => 'Unmatched Checkins',
    'pageHeaderSubtitle' => 'Assets returned without a matching local checkout record.',
]);
?>

    <form method="get" action="<?= h($pageBase) ?>" id="unmatched-filter-form">
    <?php foreach ($baseQuery as $k => $v): ?>
        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
    <?php endforeach; ?>
    <div class="border rounded-3 p-3 mb-3">
        <div class="row g-2 align-items-end">
        <div class="col-auto">
            <input type="date" name="from" value="<?= h($fromRaw) ?>" class="form-control form-control-lg" style="min-width: 160px;" placeholder="From date">
        </div>
        <div class="col-auto">
            <input type="date" name="to" value="<?= h($toRaw) ?>" class="form-control form-control-lg" style="min-width: 160px;" placeholder="To date">
        </div>
        <div class="col-auto">
            <select name="type" class="form-select form-select-lg" style="min-width: 200px;">
                <option value="">All types</option>
                <option value="no_record" <?= $typeFilterValid === 'no_record' ? 'selected' : '' ?>>No checkout record</option>
                <option value="not_checked_out" <?= $typeFilterValid === 'not_checked_out' ? 'selected' : '' ?>>Not checked out</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="per_page" class="form-select form-select-lg" style="min-width: 140px;">
                <?php foreach ($perPageOptions as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?> per page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-lg">Filter</button>
        </div>
        <div class="col-auto">
            <a href="<?= h(unmatched_build_url($pageBase, $baseQuery)) ?>" class="btn btn-outline-secondary btn-lg">Clear</a>
        </div>
        </div>
    </div>
    </form>

    <div class="res-history-body">
        <div class="res-history-search-header">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-search text-muted flex-shrink-0"></i>
                <input type="text" name="q" form="unmatched-filter-form"
                       value="<?= h($search) ?>"
                       class="form-control"
                       placeholder="Asset tag, model, user, staff...">
            </div>
        </div>
        <div class="res-history-content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php elseif (empty($rows)): ?>
        <div class="panel-empty-state">
            <i class="bi bi-arrow-return-left panel-empty-icon"></i>
            <p class="panel-empty-text">No unmatched checkins found for the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small"><?= $totalRows ?> record<?= $totalRows !== 1 ? 's' : '' ?> found</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Asset Tag</th>
                        <th>Asset Name</th>
                        <th>Model</th>
                        <th>Type</th>
                        <th>Returned by</th>
                        <th>Processed by</th>
                        <th>Linked Checkout</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= h(app_format_datetime_local($row['created_at'])) ?></td>
                            <td><?= h($row['asset_tag']) ?></td>
                            <td><?= h($row['asset_name']) ?></td>
                            <td><?= h($row['model_name']) ?></td>
                            <td>
                                <?php if ((int)$row['was_checked_out'] === 1): ?>
                                    <span class="badge bg-warning text-dark">No checkout record</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Not checked out</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['checked_in_from_user_name'] ?? '-') ?></td>
                            <td><?= h($row['checked_in_by'] ?? '-') ?></td>
                            <td>
                                <?php if (!empty($row['checkout_id'])): ?>
                                    <?php
                                        $coLinkBase = $embedded ? 'reservations.php' : 'checkout_history.php';
                                        $coLinkParams = $embedded ? ['tab' => 'checkout_history', 'q' => '#' . $row['checkout_id']] : ['q' => '#' . $row['checkout_id']];
                                    ?>
                                    <a href="<?= h(unmatched_build_url($coLinkBase, $coLinkParams)) ?>">#<?= (int)$row['checkout_id'] ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
                $pagerParams = array_merge($baseQuery, [
                    'q'        => $search,
                    'from'     => $fromRaw,
                    'to'       => $toRaw,
                    'type'     => $typeFilterValid,
                    'per_page' => $perPage,
                ]);
            ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php
                        $prevPage = max(1, $page - 1);
                        $nextPage = min($totalPages, $page + 1);
                        $pagerParams['page'] = $prevPage;
                        $prevUrl = unmatched_build_url($pageBase, $pagerParams);
                        $pagerParams['page'] = $nextPage;
                        $nextUrl = unmatched_build_url($pageBase, $pagerParams);
                    ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php $pagerParams['page'] = $p; ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= h(unmatched_build_url($pageBase, $pagerParams)) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= h($nextUrl) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
        </div><!-- /.res-history-content -->
    </div><!-- /.res-history-body -->

<?php layout_page_end(); ?>
