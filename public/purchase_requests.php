<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/activity_log.php';

$active  = 'activity_log.php'; // keep Admin nav highlighted
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config = load_config();
$appTz  = app_get_timezone();

$statusLabels = [
    'open'      => 'Open',
    'approved'  => 'Approved',
    'held'      => 'Held',
    'purchased' => 'Purchased',
    'denied'    => 'Denied',
    'duplicate' => 'Duplicate',
];
$statusBadges = [
    'open'      => 'bg-primary',
    'approved'  => 'bg-success',
    'held'      => 'bg-warning text-dark',
    'purchased' => 'bg-secondary',
    'denied'    => 'bg-danger',
    'duplicate' => 'bg-secondary',
];
$importanceLabels = [
    'low'      => 'Low',
    'medium'   => 'Medium',
    'high'     => 'High',
    'critical' => 'Critical',
];
$importanceBadges = [
    'low'      => 'bg-secondary',
    'medium'   => 'bg-warning text-dark',
    'high'     => 'bg-danger',
    'critical' => 'bg-danger fw-bold',
];
$activeStatuses   = ['open', 'approved', 'held'];
$terminalStatuses = ['purchased', 'denied', 'duplicate'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $requestId     = (int)($_POST['request_id'] ?? 0);
        $newStatus     = trim($_POST['status'] ?? '');
        $importance    = trim($_POST['importance'] ?? '');
        $estimatedCost = trim($_POST['estimated_cost'] ?? '');
        $itemUrl       = trim($_POST['item_url'] ?? '');
        $comments      = trim($_POST['decision_comments'] ?? '');

        if ($requestId > 0 && array_key_exists($newStatus, $statusLabels)) {
            $decidedBy = null;
            $decidedAt = null;
            if ($newStatus !== 'open') {
                $decidedBy = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                $decidedAt = gmdate('Y-m-d H:i:s');
            }

            $costValue = null;
            if ($estimatedCost !== '') {
                $parsed = filter_var($estimatedCost, FILTER_VALIDATE_FLOAT);
                if ($parsed !== false && $parsed >= 0) {
                    $costValue = round($parsed, 2);
                }
            }

            $urlValue = null;
            if ($itemUrl !== '' && filter_var($itemUrl, FILTER_VALIDATE_URL)) {
                $urlValue = $itemUrl;
            }

            $stmt = $pdo->prepare("
                UPDATE purchase_requests
                   SET status            = :status,
                       importance        = :importance,
                       estimated_cost    = :cost,
                       item_url          = :item_url,
                       decision_comments = :comments,
                       decided_by_name   = :decided_by,
                       decided_at        = :decided_at
                 WHERE id = :id
            ");
            $stmt->execute([
                ':status'     => $newStatus,
                ':importance' => ($importance !== '' && array_key_exists($importance, $importanceLabels)) ? $importance : null,
                ':cost'       => $costValue,
                ':item_url'   => $urlValue,
                ':comments'   => $comments !== '' ? $comments : null,
                ':decided_by' => $decidedBy,
                ':decided_at' => $decidedAt,
                ':id'         => $requestId,
            ]);

            activity_log_event('purchase_request.updated', "Updated purchase request #{$requestId} to {$newStatus}", [
                'subject_type' => 'purchase_request',
                'subject_id'   => $requestId,
                'metadata'     => ['status' => $newStatus, 'importance' => $importance],
            ]);

            // Send Discord DM if user has a discord_user_id
            $row = $pdo->prepare("SELECT discord_user_id, submitter_name FROM purchase_requests WHERE id = :id");
            $row->execute([':id' => $requestId]);
            $req = $row->fetch();
            if ($req && $req['discord_user_id'] && function_exists('send_discord_dm')) {
                $statusLabel = $statusLabels[$newStatus] ?? $newStatus;
                send_discord_dm($req['discord_user_id'], "Your purchase request has been updated to **{$statusLabel}**.", [
                    [
                        'title'       => 'Purchase Request Update',
                        'description' => "Request #{$requestId} status changed to **{$statusLabel}**.",
                        'color'       => $newStatus === 'approved' ? 0x22c55e : ($newStatus === 'denied' ? 0xef4444 : 0x3b82f6),
                        'fields'      => $comments !== '' ? [['name' => 'Comments', 'value' => $comments]] : [],
                    ],
                ]);
            }
        }

        header('Location: purchase_requests.php?' . http_build_query($_GET));
        exit;
    }

    if ($action === 'delete') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId > 0) {
            $stmt = $pdo->prepare("DELETE FROM purchase_requests WHERE id = :id");
            $stmt->execute([':id' => $requestId]);

            activity_log_event('purchase_request.deleted', "Deleted purchase request #{$requestId}", [
                'subject_type' => 'purchase_request',
                'subject_id'   => $requestId,
            ]);
        }

        header('Location: purchase_requests.php?' . http_build_query($_GET));
        exit;
    }
}

// Filters
$statusFilter = trim($_GET['status'] ?? '');
$sourceFilter = trim($_GET['source'] ?? '');
$deptFilter   = trim($_GET['dept'] ?? '');
$showAll      = !empty($_GET['show_all']);
$pageRaw      = (int)($_GET['page'] ?? 1);
$page         = $pageRaw > 0 ? $pageRaw : 1;
$perPage      = 25;

$rows       = [];
$loadError  = '';
$totalRows  = 0;
$totalPages = 1;

try {
    $where  = [];
    $params = [];

    // Default: show only active statuses unless show_all or specific status filter
    if ($statusFilter !== '' && array_key_exists($statusFilter, $statusLabels)) {
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    } elseif (!$showAll) {
        $where[] = "status IN ('open','approved','held')";
    }

    if ($sourceFilter !== '' && in_array($sourceFilter, ['discord', 'web'], true)) {
        $where[] = 'source = :source';
        $params[':source'] = $sourceFilter;
    }

    if ($deptFilter !== '') {
        $where[] = 'department LIKE :dept';
        $params[':dept'] = '%' . $deptFilter . '%';
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = ' WHERE ' . implode(' AND ', $where);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_requests' . $whereSql);
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT * FROM purchase_requests' . $whereSql . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Requests – SnipeScheduler</title>

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
            <h1>Purchase Requests</h1>
            <div class="page-subtitle">
                Review and manage equipment purchase requests.
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

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="opening_hours.php">Opening Hours</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="feedback.php">Feedback</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="announcements.php">Announcements</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="purchase_requests.php">Purchase Requests</a>
            </li>
        </ul>

        <div class="card">
            <div class="card-body">
                <!-- Filters -->
                <div class="border rounded-3 p-4 mb-4">
                    <form class="row g-3 mb-0 align-items-end" method="get" action="purchase_requests.php">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="">Active only</option>
                                <?php foreach ($statusLabels as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Source</label>
                            <select name="source" class="form-select" onchange="this.form.submit()">
                                <option value="">All sources</option>
                                <option value="discord" <?= $sourceFilter === 'discord' ? 'selected' : '' ?>>Discord</option>
                                <option value="web" <?= $sourceFilter === 'web' ? 'selected' : '' ?>>Web</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Department</label>
                            <input type="text" name="dept" class="form-control" placeholder="Search department..." value="<?= h($deptFilter) ?>">
                        </div>
                        <div class="col-12 col-md-2">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="show_all" value="1" class="form-check-input" id="showAll"
                                       <?= $showAll ? 'checked' : '' ?> onchange="this.form.submit()">
                                <label class="form-check-label" for="showAll">Show closed</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Filter</button>
                            <a href="purchase_requests.php" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </form>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small"><?= $totalRows ?> request<?= $totalRows !== 1 ? 's' : '' ?></span>
                </div>

                <?php if ($loadError): ?>
                    <div class="alert alert-warning small mb-3">
                        Could not load requests: <?= h($loadError) ?>
                    </div>
                <?php elseif (empty($rows)): ?>
                    <div class="text-muted small">No purchase requests found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Submitter</th>
                                    <th>Source</th>
                                    <th>Item</th>
                                    <th>Dept</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>Importance</th>
                                    <th>Est. Cost</th>
                                    <th style="min-width:100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $displayTime = (string)($row['created_at'] ?? '');
                                    if ($displayTime !== '' && $appTz) {
                                        try {
                                            $displayTime = app_format_datetime($displayTime, null, $appTz);
                                        } catch (Throwable $e) {
                                            // keep raw
                                        }
                                    }
                                    $statusVal   = $row['status'];
                                    $badgeClass  = $statusBadges[$statusVal] ?? 'bg-secondary';
                                    $statusLabel = $statusLabels[$statusVal] ?? ucfirst($statusVal);
                                    $impVal      = $row['importance'];
                                    $impBadge    = $importanceBadges[$impVal] ?? '';
                                    $impLabel    = $importanceLabels[$impVal] ?? '—';
                                    $isFaculty   = (int)$row['is_faculty'];
                                    ?>
                                    <tr>
                                        <td class="text-muted small"><?= (int)$row['id'] ?></td>
                                        <td class="text-nowrap small"><?= h($displayTime) ?></td>
                                        <td>
                                            <div>
                                                <?= h($row['submitter_name']) ?>
                                                <?php if ($isFaculty): ?>
                                                    <span class="badge bg-info text-dark ms-1">Faculty</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($row['submitter_email']): ?>
                                                <div class="text-muted small"><?= h($row['submitter_email']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['source'] === 'discord'): ?>
                                                <span class="badge bg-dark">Discord</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">Web</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="max-width:250px;">
                                                <strong><?= h($row['item_name']) ?></strong>
                                                <?php if ($row['item_url']): ?>
                                                    <a href="<?= h($row['item_url']) ?>" target="_blank" rel="noopener noreferrer" class="ms-1 small">🔗</a>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                            $desc = $row['description'];
                                            $descTruncated = mb_strlen($desc) > 80;
                                            $descPreview = $descTruncated ? mb_substr($desc, 0, 80) . '...' : $desc;
                                            ?>
                                            <div class="text-muted small" style="max-width:250px;">
                                                <?php if ($descTruncated): ?>
                                                    <span class="pr-desc-preview"><?= h($descPreview) ?> <a href="javascript:void(0)" class="small" onclick="this.parentElement.style.display='none';this.parentElement.nextElementSibling.style.display='';">more</a></span>
                                                    <span class="pr-desc-full" style="display:none;"><?= h($desc) ?></span>
                                                <?php else: ?>
                                                    <?= h($desc) ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="small"><?= h($row['department']) ?></td>
                                        <td><?= (int)$row['quantity'] ?></td>
                                        <td><span class="badge <?= $badgeClass ?>"><?= h($statusLabel) ?></span></td>
                                        <td>
                                            <?php if ($impVal): ?>
                                                <span class="badge <?= $impBadge ?>"><?= h($impLabel) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <?php if ($row['estimated_cost'] !== null): ?>
                                                $<?= h(number_format((float)$row['estimated_cost'], 2)) ?>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    onclick="openManageModal(<?= (int)$row['id'] ?>, <?= h(json_encode($row['status'])) ?>, <?= h(json_encode($row['importance'] ?? '')) ?>, <?= h(json_encode($row['estimated_cost'] ?? '')) ?>, <?= h(json_encode($row['item_url'] ?? '')) ?>, <?= h(json_encode($row['decision_comments'] ?? '')) ?>)">
                                                Manage
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <?php
                            $pagerQuery = [
                                'status'   => $statusFilter,
                                'source'   => $sourceFilter,
                                'dept'     => $deptFilter,
                                'show_all' => $showAll ? '1' : '',
                            ];
                        ?>
                        <nav class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php
                                    $prevPage = max(1, $page - 1);
                                    $nextPage = min($totalPages, $page + 1);
                                    $pagerQuery['page'] = $prevPage;
                                    $prevUrl = 'purchase_requests.php?' . http_build_query($pagerQuery);
                                    $pagerQuery['page'] = $nextPage;
                                    $nextUrl = 'purchase_requests.php?' . http_build_query($pagerQuery);
                                ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                                </li>
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <?php
                                        $pagerQuery['page'] = $p;
                                        $pageUrl = 'purchase_requests.php?' . http_build_query($pagerQuery);
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
            </div>
        </div>
    </div>
</div>

<!-- Manage Modal -->
<div id="manageBackdrop" style="display:none; position:fixed; inset:0; background:var(--backdrop-modal); z-index:1050;"
     onclick="closeManageModal()"></div>
<div id="manageModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;"
     onclick="if(event.target===this)closeManageModal()">
    <div style="max-width:550px; margin:0 auto; background:var(--panel); border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(var(--black-rgb), 0.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid var(--border);">
            <h5 style="margin:0;">Manage Request</h5>
            <button type="button" onclick="closeManageModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:1rem;">
            <form method="post" action="purchase_requests.php?<?= h(http_build_query($_GET)) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="request_id" id="manageRequestId" value="">

                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="manageStatus" class="form-select">
                        <?php foreach ($statusLabels as $val => $label): ?>
                            <option value="<?= h($val) ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Importance</label>
                    <select name="importance" id="manageImportance" class="form-select">
                        <option value="">Not set</option>
                        <?php foreach ($importanceLabels as $val => $label): ?>
                            <option value="<?= h($val) ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Estimated Cost ($)</label>
                    <input type="number" name="estimated_cost" id="manageCost" class="form-control"
                           step="0.01" min="0" placeholder="0.00">
                </div>

                <div class="mb-3">
                    <label class="form-label">Item URL</label>
                    <input type="url" name="item_url" id="manageUrl" class="form-control"
                           maxlength="2048" placeholder="https://...">
                </div>

                <div class="mb-3">
                    <label class="form-label">Decision Comments</label>
                    <textarea name="decision_comments" id="manageComments" class="form-control" rows="3" placeholder="Internal notes about this decision..."></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeManageModal()">Cancel</button>
                </div>
            </form>

            <hr>
            <form method="post" action="purchase_requests.php?<?= h(http_build_query($_GET)) ?>"
                  onsubmit="return confirm('Delete this request permanently?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="request_id" id="manageDeleteId" value="">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete Request</button>
            </form>
        </div>
    </div>
</div>

<script>
function openManageModal(id, status, importance, cost, url, comments) {
    document.getElementById('manageRequestId').value = id;
    document.getElementById('manageDeleteId').value = id;
    document.getElementById('manageStatus').value = status;
    document.getElementById('manageImportance').value = importance || '';
    document.getElementById('manageCost').value = cost || '';
    document.getElementById('manageUrl').value = url || '';
    document.getElementById('manageComments').value = comments || '';
    document.getElementById('manageBackdrop').style.display = 'block';
    document.getElementById('manageModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeManageModal() {
    document.getElementById('manageBackdrop').style.display = 'none';
    document.getElementById('manageModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('manageModal').style.display === 'block') {
        closeManageModal();
    }
});
</script>
<?php layout_footer(); ?>
</body>
</html>
