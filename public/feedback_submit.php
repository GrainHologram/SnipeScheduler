<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/activity_log.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config = load_config();
$appTz  = app_get_timezone();

$tab = $_GET['tab'] ?? 'feedback';
if (!in_array($tab, ['feedback', 'purchase_requests'], true)) {
    $tab = 'feedback';
}

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

// Purchase request POST handling
$errors  = [];
$success = $_GET['msg'] ?? '';
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = :id AND user_id = :user_id AND status = 'open'");
    $stmt->execute([':id' => $editId, ':user_id' => $currentUser['id']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $itemName    = trim($_POST['item_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department  = trim($_POST['department'] ?? '');
        $itemUrl     = trim($_POST['item_url'] ?? '');
        $quantity    = (int)($_POST['quantity'] ?? 1);
        $postEditId  = (int)($_POST['edit_id'] ?? 0);

        if ($itemName === '')    $errors[] = 'Item name is required.';
        if ($description === '') $errors[] = 'Description of need is required.';
        if ($department === '')  $errors[] = 'Department / class is required.';
        if ($quantity < 1)       $quantity = 1;
        if ($itemUrl !== '' && !filter_var($itemUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid URL.';
        }

        if (empty($errors)) {
            $userId   = $currentUser['id'] ?? '';
            $userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            if ($userName === '') {
                $userName = $currentUser['display_name'] ?? $currentUser['email'] ?? 'Unknown';
            }
            $userEmail = $currentUser['email'] ?? '';

            if ($action === 'edit' && $postEditId > 0) {
                $check = $pdo->prepare("SELECT id FROM purchase_requests WHERE id = :id AND user_id = :user_id AND status = 'open'");
                $check->execute([':id' => $postEditId, ':user_id' => $userId]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("
                        UPDATE purchase_requests
                           SET item_name = :item_name, description = :description,
                               department = :department, item_url = :item_url, quantity = :quantity
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':item_name'   => $itemName,
                        ':description' => $description,
                        ':department'  => $department,
                        ':item_url'    => $itemUrl !== '' ? $itemUrl : null,
                        ':quantity'    => $quantity,
                        ':id'          => $postEditId,
                    ]);
                    activity_log_event('purchase_request.edited', "Edited purchase request #{$postEditId}", [
                        'subject_type' => 'purchase_request', 'subject_id' => $postEditId,
                    ]);
                    header('Location: feedback_submit.php?tab=purchase_requests&msg=updated');
                    exit;
                } else {
                    $errors[] = 'This request can no longer be edited.';
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO purchase_requests
                        (submitter_name, submitter_email, user_id, source, item_name, description, department, item_url, quantity)
                    VALUES
                        (:name, :email, :user_id, 'web', :item_name, :description, :department, :item_url, :quantity)
                ");
                $stmt->execute([
                    ':name'        => $userName,
                    ':email'       => $userEmail,
                    ':user_id'     => $userId,
                    ':item_name'   => $itemName,
                    ':description' => $description,
                    ':department'  => $department,
                    ':item_url'    => $itemUrl !== '' ? $itemUrl : null,
                    ':quantity'    => $quantity,
                ]);
                $newId = $pdo->lastInsertId();
                activity_log_event('purchase_request.submitted', "Submitted purchase request #{$newId}", [
                    'subject_type' => 'purchase_request', 'subject_id' => $newId,
                ]);
                header('Location: feedback_submit.php?tab=purchase_requests&msg=submitted');
                exit;
            }
        }
        $tab = 'purchase_requests';
    }

    if ($action === 'delete') {
        $deleteId = (int)($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $pdo->prepare("DELETE FROM purchase_requests WHERE id = :id AND user_id = :user_id AND status = 'open'");
            $stmt->execute([':id' => $deleteId, ':user_id' => $currentUser['id']]);
            activity_log_event('purchase_request.deleted', "Deleted own purchase request #{$deleteId}", [
                'subject_type' => 'purchase_request', 'subject_id' => $deleteId,
            ]);
        }
        header('Location: feedback_submit.php?tab=purchase_requests&msg=deleted');
        exit;
    }
}

// Load user's own purchase requests
$myRequests = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([':user_id' => $currentUser['id']]);
    $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // silently fail
}

$formValues = [
    'item_name'   => $_POST['item_name']   ?? ($editRow['item_name']   ?? ''),
    'description' => $_POST['description'] ?? ($editRow['description'] ?? ''),
    'department'  => $_POST['department']  ?? ($editRow['department']  ?? ''),
    'item_url'    => $_POST['item_url']    ?? ($editRow['item_url']    ?? ''),
    'quantity'    => $_POST['quantity']    ?? ($editRow['quantity']    ?? '1'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Feedback – SnipeScheduler</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= layout_stylesheet_url() ?>">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">

<?= layout_render_nav($active, $isStaff, $isAdmin) ?>
<?= layout_render_topbar($active) ?>

<div class="page-shell">
    <div class="page-header">
        <h1>Feedback</h1>
    </div>

    <ul class="nav nav-tabs reservations-subtabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'feedback' ? 'active' : '' ?>" href="feedback_submit.php?tab=feedback">Submit Feedback</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'purchase_requests' ? 'active' : '' ?>" href="feedback_submit.php?tab=purchase_requests">Purchase Requests</a>
        </li>
    </ul>

    <?php if ($tab === 'feedback'): ?>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <form id="feedbackForm" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="feedbackCategory" class="form-label">Category</label>
                                <select id="feedbackCategory" name="category" class="form-select" required>
                                    <option value="general">General</option>
                                    <option value="bug">Bug Report</option>
                                    <option value="feature_request">Feature Request</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="feedbackMessage" class="form-label">Message</label>
                                <textarea id="feedbackMessage" name="message" class="form-control" rows="5" required minlength="10" placeholder="Describe your feedback (min 10 characters)..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="feedbackScreenshot" class="form-label">Screenshot <span class="text-muted small">(optional, max 5MB)</span></label>
                                <input type="file" id="feedbackScreenshot" name="screenshot" class="form-control" accept="image/*">
                                <div id="feedbackPreview" style="display:none; margin-top:.5rem;">
                                    <img id="feedbackPreviewImg" src="" alt="Preview" style="max-width:100%; max-height:200px; border-radius:4px;">
                                </div>
                            </div>
                            <div id="feedbackMsg" style="display:none;" class="mb-3" role="status" aria-live="polite" aria-atomic="true"></div>
                            <button type="submit" class="btn btn-primary" id="feedbackSubmitBtn">Submit Feedback</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.getElementById('feedbackScreenshot').addEventListener('change', function() {
            var preview = document.getElementById('feedbackPreview');
            var img     = document.getElementById('feedbackPreviewImg');
            if (this.files && this.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) { img.src = e.target.result; preview.style.display = 'block'; };
                reader.readAsDataURL(this.files[0]);
            } else {
                preview.style.display = 'none';
            }
        });

        document.getElementById('feedbackForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var form    = this;
            var msg     = document.getElementById('feedbackMsg');
            var btn     = document.getElementById('feedbackSubmitBtn');
            var message = document.getElementById('feedbackMessage').value.trim();

            if (message.length < 10) {
                msg.innerHTML = '<div class="alert alert-warning">Message must be at least 10 characters.</div>';
                msg.style.display = 'block';
                return;
            }

            btn.disabled    = true;
            btn.textContent = 'Submitting...';
            msg.style.display = 'none';

            fetch('ajax_feedback.php', { method: 'POST', body: new FormData(form) })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        msg.innerHTML = '<div class="alert alert-success">Feedback submitted successfully. Thank you!</div>';
                        msg.style.display = 'block';
                        form.reset();
                        document.getElementById('feedbackPreview').style.display = 'none';
                    } else {
                        msg.innerHTML = '<div class="alert alert-danger">' + (data.error || 'Failed to submit.') + '</div>';
                        msg.style.display = 'block';
                    }
                    btn.disabled    = false;
                    btn.textContent = 'Submit Feedback';
                })
                .catch(function() {
                    msg.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
                    msg.style.display = 'block';
                    btn.disabled    = false;
                    btn.textContent = 'Submit Feedback';
                });
        });
        </script>

    <?php elseif ($tab === 'purchase_requests'): ?>

        <?php if ($success === 'submitted'): ?>
            <div class="alert alert-success">Your purchase request has been submitted.</div>
        <?php elseif ($success === 'updated'): ?>
            <div class="alert alert-success">Your purchase request has been updated.</div>
        <?php elseif ($success === 'deleted'): ?>
            <div class="alert alert-info">Purchase request deleted.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <div><?= h($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <strong><?= $editRow ? 'Edit Request #' . (int)$editRow['id'] : 'New Purchase Request' ?></strong>
            </div>
            <div class="card-body">
                <form method="post" action="feedback_submit.php?tab=purchase_requests">
                    <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'create' ?>">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="edit_id" value="<?= (int)$editRow['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="item_name" class="form-label">Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="item_name" id="item_name" class="form-control"
                               required maxlength="255" value="<?= h($formValues['item_name']) ?>"
                               placeholder="e.g., Rode NTG5 Shotgun Microphone">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description of Need <span class="text-danger">*</span></label>
                        <textarea name="description" id="description" class="form-control" rows="3"
                                  required placeholder="Why is this equipment needed?"><?= h($formValues['description']) ?></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="department" class="form-label">Department / Class <span class="text-danger">*</span></label>
                            <input type="text" name="department" id="department" class="form-control"
                                   required maxlength="255" value="<?= h($formValues['department']) ?>"
                                   placeholder="e.g., Film Production, Studio Arts">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="item_url" class="form-label">URL to Item</label>
                            <input type="url" name="item_url" id="item_url" class="form-control"
                                   maxlength="2048" value="<?= h($formValues['item_url']) ?>"
                                   placeholder="https://...">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="quantity" class="form-label">Quantity</label>
                            <input type="number" name="quantity" id="quantity" class="form-control"
                                   min="1" value="<?= h($formValues['quantity']) ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <?= $editRow ? 'Update Request' : 'Submit Request' ?>
                        </button>
                        <?php if ($editRow): ?>
                            <a href="feedback_submit.php?tab=purchase_requests" class="btn btn-outline-secondary">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($myRequests)): ?>
            <div class="card">
                <div class="card-header">
                    <strong>My Requests</strong>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Item</th>
                                    <th>Dept</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myRequests as $req): ?>
                                    <?php
                                    $displayTime = (string)($req['created_at'] ?? '');
                                    if ($displayTime !== '' && $appTz) {
                                        try {
                                            $displayTime = app_format_datetime($displayTime, null, $appTz);
                                        } catch (Throwable $e) { /* keep raw */ }
                                    }
                                    $sVal   = $req['status'];
                                    $sBadge = $statusBadges[$sVal] ?? 'bg-secondary';
                                    $sLabel = $statusLabels[$sVal] ?? ucfirst($sVal);
                                    ?>
                                    <tr>
                                        <td class="text-nowrap small"><?= h($displayTime) ?></td>
                                        <td>
                                            <?= h($req['item_name']) ?>
                                            <?php if ($req['item_url']): ?>
                                                <a href="<?= h($req['item_url']) ?>" target="_blank" rel="noopener noreferrer" class="small">🔗</a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?= h($req['department']) ?></td>
                                        <td><?= (int)$req['quantity'] ?></td>
                                        <td><span class="badge <?= $sBadge ?>"><?= h($sLabel) ?></span></td>
                                        <td>
                                            <?php if ($sVal === 'open'): ?>
                                                <a href="feedback_submit.php?tab=purchase_requests&edit=<?= (int)$req['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                                <form method="post" action="feedback_submit.php?tab=purchase_requests" class="d-inline"
                                                      onsubmit="return confirm('Delete this request?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$req['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php layout_footer(); ?>
</body>
</html>
