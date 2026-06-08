<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/activity_log.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$config  = load_config();
$appTz   = app_get_timezone();

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

$errors   = [];
$success  = $_GET['msg'] ?? '';
$editId   = (int)($_GET['edit'] ?? 0);
$editRow  = null;

// Load existing request for editing
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = :id AND user_id = :user_id AND status = 'open'");
    $stmt->execute([':id' => $editId, ':user_id' => $currentUser['id']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $editId = 0; // not found or not editable
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create' || $action === 'edit') {
        $itemName    = trim($_POST['item_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department  = trim($_POST['department'] ?? '');
        $itemUrl     = trim($_POST['item_url'] ?? '');
        $quantity    = (int)($_POST['quantity'] ?? 1);
        $postEditId  = (int)($_POST['edit_id'] ?? 0);

        if ($itemName === '') {
            $errors[] = 'Item name is required.';
        }
        if ($description === '') {
            $errors[] = 'Description of need is required.';
        }
        if ($department === '') {
            $errors[] = 'Department / class is required.';
        }
        if ($quantity < 1) {
            $quantity = 1;
        }
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
                // Verify ownership and open status
                $check = $pdo->prepare("SELECT id FROM purchase_requests WHERE id = :id AND user_id = :user_id AND status = 'open'");
                $check->execute([':id' => $postEditId, ':user_id' => $userId]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("
                        UPDATE purchase_requests
                           SET item_name   = :item_name,
                               description = :description,
                               department  = :department,
                               item_url    = :item_url,
                               quantity    = :quantity
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
                        'subject_type' => 'purchase_request',
                        'subject_id'   => $postEditId,
                    ]);

                    header('Location: purchase_request_submit.php?msg=updated');
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
                    'subject_type' => 'purchase_request',
                    'subject_id'   => $newId,
                ]);

                header('Location: purchase_request_submit.php?msg=submitted');
                exit;
            }
        }
    }

    if ($action === 'delete') {
        $deleteId = (int)($_POST['delete_id'] ?? 0);
        if ($deleteId > 0) {
            $stmt = $pdo->prepare("DELETE FROM purchase_requests WHERE id = :id AND user_id = :user_id AND status = 'open'");
            $stmt->execute([':id' => $deleteId, ':user_id' => $currentUser['id']]);

            activity_log_event('purchase_request.deleted', "Deleted own purchase request #{$deleteId}", [
                'subject_type' => 'purchase_request',
                'subject_id'   => $deleteId,
            ]);
        }

        header('Location: purchase_request_submit.php?msg=deleted');
        exit;
    }
}

// Load user's own requests
$myRequests = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM purchase_requests
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 50
    ");
    $stmt->execute([':user_id' => $currentUser['id']]);
    $myRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // silently fail
}

// Prefill form values (from POST on error, or from editRow)
$formValues = [
    'item_name'   => $_POST['item_name']   ?? ($editRow['item_name'] ?? ''),
    'description' => $_POST['description'] ?? ($editRow['description'] ?? ''),
    'department'  => $_POST['department']  ?? ($editRow['department'] ?? ''),
    'item_url'    => $_POST['item_url']    ?? ($editRow['item_url'] ?? ''),
    'quantity'    => $_POST['quantity']    ?? ($editRow['quantity'] ?? '1'),
];
layout_page_start([
    'active'             => $active,
    'title'              => 'Submit Purchase Request – SnipeScheduler',
    'pageHeaderTitle'    => 'Purchase Requests',
    'pageHeaderSubtitle' => 'Submit a request for new equipment or supplies.',
]);
?>

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

        <!-- Submit / Edit Form -->
        <div class="card mb-4">
            <div class="card-header">
                <strong><?= $editRow ? 'Edit Request #' . (int)$editRow['id'] : 'New Purchase Request' ?></strong>
            </div>
            <div class="card-body">
                <form method="post" action="purchase_request_submit.php">
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
                                  required placeholder="Why is this equipment needed? What problem does it solve?"><?= h($formValues['description']) ?></textarea>
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
                            <a href="purchase_request_submit.php" class="btn btn-outline-secondary">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- My Requests -->
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
                                                <a href="purchase_request_submit.php?edit=<?= (int)$req['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                                <form method="post" action="purchase_request_submit.php" class="d-inline"
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
<?php layout_page_end(); ?>
