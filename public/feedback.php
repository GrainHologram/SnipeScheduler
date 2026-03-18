<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';

$active  = 'activity_log.php'; // keep Admin nav highlighted
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config   = load_config();
$timezone = $config['app']['timezone'] ?? 'Europe/Jersey';
$tz       = null;
try {
    $tz = new DateTimeZone($timezone);
} catch (Throwable $e) {
    $tz = null;
}

$statusLabels = [
    'open'        => 'Open',
    'in_progress' => 'In Progress',
    'resolved'    => 'Resolved',
    'closed'      => 'Closed',
];
$statusBadges = [
    'open'        => 'bg-primary',
    'in_progress' => 'bg-warning text-dark',
    'resolved'    => 'bg-success',
    'closed'      => 'bg-secondary',
];
$categoryLabels = [
    'bug'             => 'Bug Report',
    'feature_request' => 'Feature Request',
    'general'         => 'General',
];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    $newStatus  = trim($_POST['status'] ?? '');
    $staffNotes = trim($_POST['staff_notes'] ?? '');

    if ($feedbackId > 0 && array_key_exists($newStatus, $statusLabels)) {
        $resolvedBy = null;
        $resolvedAt = null;
        if (in_array($newStatus, ['resolved', 'closed'], true)) {
            $resolvedBy = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            $resolvedAt = gmdate('Y-m-d H:i:s');
        }

        $stmt = $pdo->prepare("
            UPDATE feedback
               SET status = :status,
                   staff_notes = :staff_notes,
                   resolved_by_name = :resolved_by,
                   resolved_at = :resolved_at
             WHERE id = :id
        ");
        $stmt->execute([
            ':status'      => $newStatus,
            ':staff_notes' => $staffNotes !== '' ? $staffNotes : null,
            ':resolved_by' => $resolvedBy,
            ':resolved_at' => $resolvedAt,
            ':id'          => $feedbackId,
        ]);
    }

    // Redirect to avoid resubmission
    $redirectParams = $_GET;
    header('Location: feedback.php?' . http_build_query($redirectParams));
    exit;
}

// Filters
$statusFilter   = trim($_GET['status'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$pageRaw        = (int)($_GET['page'] ?? 1);
$page           = $pageRaw > 0 ? $pageRaw : 1;
$perPage        = 25;

$feedbackRows  = [];
$feedbackError = '';
$totalRows     = 0;
$totalPages    = 1;

try {
    $where  = [];
    $params = [];

    if ($statusFilter !== '' && array_key_exists($statusFilter, $statusLabels)) {
        $where[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($categoryFilter !== '' && array_key_exists($categoryFilter, $categoryLabels)) {
        $where[] = 'category = :category';
        $params[':category'] = $categoryFilter;
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = ' WHERE ' . implode(' AND ', $where);
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM feedback' . $whereSql);
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT * FROM feedback' . $whereSql . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $feedbackRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $feedbackError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feedback – SnipeScheduler</title>

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
            <h1>Feedback</h1>
            <div class="page-subtitle">
                Review and manage staff feedback submissions.
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
                <a class="nav-link active" href="feedback.php">Feedback</a>
            </li>
        </ul>

        <div class="card">
            <div class="card-body">
                <div class="border rounded-3 p-4 mb-4">
                    <form class="row g-3 mb-0 align-items-end" method="get" action="feedback.php">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="">All statuses</option>
                                <?php foreach ($statusLabels as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" onchange="this.form.submit()">
                                <option value="">All categories</option>
                                <?php foreach ($categoryLabels as $val => $label): ?>
                                    <option value="<?= h($val) ?>" <?= $categoryFilter === $val ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Filter</button>
                            <a href="feedback.php" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </form>
                </div>

                <?php if ($feedbackError): ?>
                    <div class="alert alert-warning small mb-3">
                        Could not load feedback: <?= h($feedbackError) ?>
                    </div>
                <?php elseif (empty($feedbackRows)): ?>
                    <div class="text-muted small">No feedback submissions yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>User</th>
                                    <th>Category</th>
                                    <th>Message</th>
                                    <th>Screenshot</th>
                                    <th>Status</th>
                                    <th style="min-width:260px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($feedbackRows as $row): ?>
                                    <?php
                                    $displayTime = (string)($row['created_at'] ?? '');
                                    if ($displayTime !== '' && $tz) {
                                        try {
                                            $displayTime = app_format_datetime($displayTime, null, $tz);
                                        } catch (Throwable $e) {
                                            // keep raw
                                        }
                                    }
                                    $catLabel = $categoryLabels[$row['category']] ?? ucfirst($row['category']);
                                    $msgFull = $row['message'];
                                    $msgTruncated = mb_strlen($msgFull) > 120;
                                    $msgPreview = $msgTruncated
                                        ? mb_substr($msgFull, 0, 120) . '...'
                                        : $msgFull;
                                    $statusVal = $row['status'];
                                    $badgeClass = $statusBadges[$statusVal] ?? 'bg-secondary';
                                    $statusLabel = $statusLabels[$statusVal] ?? ucfirst($statusVal);
                                    ?>
                                    <tr>
                                        <td class="text-nowrap"><?= h($displayTime) ?></td>
                                        <td>
                                            <div><?= h($row['user_name']) ?></div>
                                            <div class="text-muted small"><?= h($row['user_email']) ?></div>
                                        </td>
                                        <td><?= h($catLabel) ?></td>
                                        <td>
                                            <div style="max-width:300px;">
                                                <?php if ($msgTruncated): ?>
                                                    <span class="feedback-msg-preview"><?= h($msgPreview) ?> <a href="javascript:void(0)" class="small" onclick="this.parentElement.style.display='none';this.parentElement.nextElementSibling.style.display='';">more</a></span>
                                                    <span class="feedback-msg-full" style="display:none;"><?= h($msgFull) ?></span>
                                                <?php else: ?>
                                                    <?= h($msgFull) ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($row['staff_notes']): ?>
                                                <div class="text-muted small mt-1"><strong>Staff notes:</strong> <?= h($row['staff_notes']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($row['resolved_by_name']): ?>
                                                <div class="text-muted small">
                                                    Resolved by <?= h($row['resolved_by_name']) ?>
                                                    <?php if ($row['resolved_at'] && $tz): ?>
                                                        on <?php try { echo h(app_format_datetime($row['resolved_at'], null, $tz)); } catch (Throwable $e) { echo h($row['resolved_at']); } ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['screenshot_path']): ?>
                                                <a href="feedback_image.php?file=<?= urlencode($row['screenshot_path']) ?>" target="_blank" rel="noopener noreferrer">
                                                    <img src="feedback_image.php?file=<?= urlencode($row['screenshot_path']) ?>"
                                                         alt="Screenshot"
                                                         style="max-width:80px; max-height:60px; border-radius:4px; border:1px solid #dee2e6;">
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= $badgeClass ?>"><?= h($statusLabel) ?></span></td>
                                        <td>
                                            <form method="post" action="feedback.php?<?= h(http_build_query($_GET)) ?>" class="d-flex gap-1 align-items-start flex-wrap">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="feedback_id" value="<?= (int)$row['id'] ?>">
                                                <select name="status" class="form-select form-select-sm" style="width:auto;">
                                                    <?php foreach ($statusLabels as $val => $label): ?>
                                                        <option value="<?= h($val) ?>" <?= $statusVal === $val ? 'selected' : '' ?>>
                                                            <?= h($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="staff_notes" class="form-control form-control-sm" placeholder="Staff notes..." value="<?= h($row['staff_notes'] ?? '') ?>" style="width:120px;">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                                            </form>
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
                                'category' => $categoryFilter,
                            ];
                        ?>
                        <nav class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php
                                    $prevPage = max(1, $page - 1);
                                    $nextPage = min($totalPages, $page + 1);
                                    $pagerQuery['page'] = $prevPage;
                                    $prevUrl = 'feedback.php?' . http_build_query($pagerQuery);
                                    $pagerQuery['page'] = $nextPage;
                                    $nextUrl = 'feedback.php?' . http_build_query($pagerQuery);
                                ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                                </li>
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <?php
                                        $pagerQuery['page'] = $p;
                                        $pageUrl = 'feedback.php?' . http_build_query($pagerQuery);
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
<?php layout_footer(); ?>
</body>
</html>
