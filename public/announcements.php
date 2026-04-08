<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active  = 'activity_log.php'; // keep Admin nav highlighted
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$messages = [];
$errors   = [];
$appTz    = app_get_timezone();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title    = trim($_POST['title'] ?? '');
        $body     = trim($_POST['body'] ?? '');
        $audience = $_POST['audience'] ?? 'all';
        $startRaw = trim($_POST['start_datetime'] ?? '');
        $endRaw   = trim($_POST['end_datetime'] ?? '');

        if ($title === '') {
            $errors[] = 'Title is required.';
        }
        if ($body === '') {
            $errors[] = 'Body is required.';
        }
        if (!in_array($audience, ['all', 'staff', 'admin'], true)) {
            $audience = 'all';
        }
        if ($startRaw === '' || $endRaw === '') {
            $errors[] = 'Start and end dates are required.';
        }

        if (empty($errors)) {
            try {
                $startDt = new DateTime($startRaw, $appTz);
                $endDt   = new DateTime($endRaw, $appTz);
                if ($endDt <= $startDt) {
                    $errors[] = 'End date must be after start date.';
                } else {
                    $utc = new DateTimeZone('UTC');
                    $startUtc = $startDt->setTimezone($utc)->format('Y-m-d H:i:s');
                    $endUtc   = $endDt->setTimezone($utc)->format('Y-m-d H:i:s');

                    $staffName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                    if ($staffName === '') {
                        $staffName = $currentUser['email'] ?? '';
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO announcements (title, body, audience, start_datetime, end_datetime, created_by)
                        VALUES (:title, :body, :audience, :start, :end, :created_by)
                    ");
                    $stmt->execute([
                        ':title'      => $title,
                        ':body'       => $body,
                        ':audience'   => $audience,
                        ':start'      => $startUtc,
                        ':end'        => $endUtc,
                        ':created_by' => $staffName,
                    ]);
                    $messages[] = 'Announcement created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Failed to create announcement: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $deleteId = (int)($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $pdo->prepare("DELETE FROM announcements WHERE id = :id")->execute([':id' => $deleteId]);
            $messages[] = 'Announcement deleted.';
        }
    }

    if (empty($errors)) {
        // PRG redirect
        $qs = !empty($messages) ? '?msg=' . urlencode($messages[0]) : '';
        header('Location: announcements.php' . $qs);
        exit;
    }
}

if (!empty($_GET['msg'])) {
    $messages[] = $_GET['msg'];
}

// Load all announcements
$announcements = [];
try {
    $stmt = $pdo->query("SELECT * FROM announcements ORDER BY start_datetime DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to load announcements: ' . $e->getMessage();
}

$nowUtc = gmdate('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Announcements</title>
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
            <h1>Announcements</h1>
            <div class="page-subtitle">
                Create timed announcements that display as pop-ups to users.
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
                <a class="nav-link active" href="announcements.php">Announcements</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="purchase_requests.php">Purchase Requests</a>
            </li>
        </ul>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <?php foreach ($messages as $m): ?>
                    <div><?= h($m) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <div><?= h($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Create form -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">New Announcement</div>
            <div class="card-body">
                <form method="post" action="announcements.php">
                    <input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" required
                                   value="<?= h($_POST['title'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Audience</label>
                            <select name="audience" class="form-select">
                                <option value="all">All users</option>
                                <option value="staff" <?= ($_POST['audience'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff only</option>
                                <option value="admin" <?= ($_POST['audience'] ?? '') === 'admin' ? 'selected' : '' ?>>Admins only</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Body <span class="text-muted small">(HTML allowed)</span></label>
                            <textarea name="body" class="form-control" rows="4" required><?= h($_POST['body'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start</label>
                            <input type="datetime-local" name="start_datetime" class="form-control" required
                                   value="<?= h($_POST['start_datetime'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End</label>
                            <input type="datetime-local" name="end_datetime" class="form-control" required
                                   value="<?= h($_POST['end_datetime'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Create Announcement</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Existing announcements -->
        <?php if (empty($announcements)): ?>
            <div class="alert alert-secondary">No announcements yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Audience</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                            <th>Created by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($announcements as $a): ?>
                            <?php
                                $isActive = $a['start_datetime'] <= $nowUtc && $a['end_datetime'] > $nowUtc;
                                $isPast   = $a['end_datetime'] <= $nowUtc;
                                $isFuture = $a['start_datetime'] > $nowUtc;
                            ?>
                            <tr class="<?= $isPast ? 'text-muted' : '' ?>">
                                <td>
                                    <?= h($a['title']) ?>
                                    <?php if (!empty($a['body'])): ?>
                                        <div class="text-muted small" style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?= h(strip_tags($a['body'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a['audience'] === 'all'): ?>
                                        <span class="badge bg-primary">All</span>
                                    <?php elseif ($a['audience'] === 'staff'): ?>
                                        <span class="badge bg-info text-dark">Staff</span>
                                    <?php else: ?>
                                        <span class="badge bg-dark">Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap"><?= h(app_format_datetime_local($a['start_datetime'])) ?></td>
                                <td class="text-nowrap"><?= h(app_format_datetime_local($a['end_datetime'])) ?></td>
                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php elseif ($isFuture): ?>
                                        <span class="badge bg-warning text-dark">Scheduled</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($a['created_by'] ?? '') ?></td>
                                <td>
                                    <form method="post" action="announcements.php"
                                          onsubmit="return confirm('Delete this announcement?');"
                                          style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?= (int)$a['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
