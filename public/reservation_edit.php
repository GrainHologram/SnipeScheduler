<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$isStaff = !empty($currentUser['is_admin']);
if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$from      = $_GET['from'] ?? ($_POST['from'] ?? '');
$embedded  = $from === 'reservations';
$pageBase  = $embedded ? 'reservations.php' : 'staff_reservations.php';
$baseQuery = $embedded ? ['tab' => 'history'] : [];

$actionUrl = $pageBase;
if (!empty($baseQuery)) {
    $actionUrl .= '?' . http_build_query($baseQuery);
}

function datetime_local_value(?string $isoDatetime): string
{
    if (!$isoDatetime) {
        return '';
    }
    $ts = strtotime($isoDatetime);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

$errors = [];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error loading reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

if (($reservation['status'] ?? '') !== 'pending') {
    http_response_code(403);
    echo 'Only pending reservations can be edited.';
    exit;
}

try {
    $itemsStmt = $pdo->prepare('
        SELECT model_id, quantity, model_name_cache
        FROM reservation_items
        WHERE reservation_id = :id
        ORDER BY model_id
    ');
    $itemsStmt->execute([':id' => $id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $items = [];
    $errors[] = 'Error loading reservation items: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startRaw = $_POST['start_datetime'] ?? '';
    $endRaw   = $_POST['end_datetime'] ?? '';
    $qtyInput = $_POST['qty'] ?? [];

    $startTs = strtotime($startRaw);
    $endTs   = strtotime($endRaw);

    if ($startTs === false || $endTs === false) {
        $errors[] = 'Start and end date/time must be valid.';
    } else {
        $start = date('Y-m-d H:i:s', $startTs);
        $end   = date('Y-m-d H:i:s', $endTs);
        if ($end <= $start) {
            $errors[] = 'End time must be after start time.';
        }
    }

    if (empty($items)) {
        $errors[] = 'This reservation has no items to edit.';
    }

    $updatedItems = [];
    $totalQty = 0;

    if (empty($errors)) {
        foreach ($items as $item) {
            $mid = (int)($item['model_id'] ?? 0);
            $qty = isset($qtyInput[$mid]) ? (int)$qtyInput[$mid] : 0;

            if ($mid <= 0) {
                continue;
            }

            if ($qty < 0) {
                $errors[] = 'Quantities must be zero or greater.';
                break;
            }

            if ($qty > 0) {
                $modelName = $item['model_name_cache'] ?? ('Model #' . $mid);

                $sql = '
                    SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
                    FROM reservation_items ri
                    JOIN reservations r ON r.id = ri.reservation_id
                    WHERE ri.model_id = :model_id
                      AND r.status IN (\'pending\',\'confirmed\')
                      AND r.id <> :res_id
                      AND (r.start_datetime < :end AND r.end_datetime > :start)
                ';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':model_id' => $mid,
                    ':res_id'   => $id,
                    ':start'    => $start,
                    ':end'      => $end,
                ]);
                $row = $stmt->fetch();
                $existingBooked = $row ? (int)$row['booked_qty'] : 0;

                $totalRequestable = count_requestable_assets_by_model($mid);
                $activeCheckedOut = count_checked_out_assets_by_model($mid);
                $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

                if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
                    $errors[] = 'Not enough units available for "' . $modelName . '" in that time period.';
                }
            }

            $updatedItems[$mid] = $qty;
            $totalQty += $qty;
        }
    }

    if (empty($errors) && $totalQty <= 0) {
        $errors[] = 'Reservation must include at least one item.';
    }

    if (empty($errors)) {
        $pdo->beginTransaction();

        try {
            $updateRes = $pdo->prepare('
                UPDATE reservations
                SET start_datetime = :start,
                    end_datetime = :end
                WHERE id = :id
            ');
            $updateRes->execute([
                ':start' => $start,
                ':end'   => $end,
                ':id'    => $id,
            ]);

            $updateItem = $pdo->prepare('
                UPDATE reservation_items
                SET quantity = :qty
                WHERE reservation_id = :res_id
                  AND model_id = :model_id
            ');
            $deleteItem = $pdo->prepare('
                DELETE FROM reservation_items
                WHERE reservation_id = :res_id
                  AND model_id = :model_id
            ');

            foreach ($updatedItems as $mid => $qty) {
                if ($qty <= 0) {
                    $deleteItem->execute([
                        ':res_id'   => $id,
                        ':model_id' => $mid,
                    ]);
                } else {
                    $updateItem->execute([
                        ':qty'      => $qty,
                        ':res_id'   => $id,
                        ':model_id' => $mid,
                    ]);
                }
            }

            $pdo->commit();

            $redirect = $actionUrl;
            $glue = strpos($redirect, '?') === false ? '?' : '&';
            header('Location: ' . $redirect . $glue . 'updated=' . $id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Unable to update reservation: ' . $e->getMessage();
        }
    }
}

$startValue = datetime_local_value($reservation['start_datetime'] ?? '');
$endValue   = datetime_local_value($reservation['end_datetime'] ?? '');

$active = 'staff_reservations.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Reservation #<?= (int)$id ?></title>

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
            <h1>Edit Reservation #<?= (int)$id ?></h1>
            <div class="page-subtitle">
                Update dates and quantities for a pending reservation.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="<?= h($actionUrl) ?>" class="btn btn-outline-secondary btn-sm">Back to reservations</a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="card">
            <div class="card-body">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php if ($from !== ''): ?>
                    <input type="hidden" name="from" value="<?= h($from) ?>">
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Start date/time</label>
                        <input type="datetime-local"
                               name="start_datetime"
                               class="form-control"
                               value="<?= h($startValue) ?>"
                               required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End date/time</label>
                        <input type="datetime-local"
                               name="end_datetime"
                               class="form-control"
                               value="<?= h($endValue) ?>"
                               required>
                    </div>
                </div>

                <?php if (empty($items)): ?>
                    <div class="alert alert-warning mb-0">
                        No items are attached to this reservation.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th style="width: 120px;">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                        $mid = (int)($item['model_id'] ?? 0);
                                        $qty = (int)($item['quantity'] ?? 0);
                                        $name = $item['model_name_cache'] ?? ('Model #' . $mid);
                                    ?>
                                    <tr>
                                        <td><?= h($name) ?></td>
                                        <td>
                                            <input type="number"
                                                   class="form-control form-control-sm"
                                                   name="qty[<?= $mid ?>]"
                                                   min="0"
                                                   value="<?= $qty ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="<?= h($actionUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
