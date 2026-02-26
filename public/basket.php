<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/checkout_rules.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Basket: model_id => quantity
$basket = $_SESSION['basket'] ?? [];

// Preview availability dates (from GET) with sensible defaults
$appTz = app_get_timezone();
$now = new DateTime('now', $appTz);
$defaultStart = $now->format('Y-m-d\TH:i');
$defaultEnd   = (new DateTime('tomorrow 9:00', $appTz))->format('Y-m-d\TH:i');

$previewStartRaw = $_GET['start_datetime'] ?? '';
$previewEndRaw   = $_GET['end_datetime'] ?? '';
if ($previewStartRaw === '' && $previewEndRaw === '') {
    $sessionStart = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $sessionEnd   = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($sessionStart !== '' && $sessionEnd !== '') {
        $previewStartRaw = $sessionStart;
        $previewEndRaw   = $sessionEnd;
    }
}

if (trim($previewStartRaw) === '') {
    $previewStartRaw = $defaultStart;
}

if (trim($previewEndRaw) === '') {
    $previewEndRaw = $defaultEnd;
}

$previewStart = null;
$previewEnd   = null;
$previewError = '';

if ($previewStartRaw && $previewEndRaw) {
    $utc = new DateTimeZone('UTC');
    try {
        // Form values are in the app's local timezone
        $startDt = new DateTime($previewStartRaw, $appTz);
        $endDt   = new DateTime($previewEndRaw, $appTz);
    } catch (Throwable $e) {
        $startDt = null;
        $endDt   = null;
    }

    if (!$startDt || !$endDt) {
        $previewError = 'Invalid date/time for availability preview.';
    } elseif ($endDt <= $startDt) {
        $previewError = 'End time must be after start time for availability preview.';
    } else {
        // Convert to UTC for DB queries
        $previewStart = $startDt->setTimezone($utc)->format('Y-m-d H:i:s');
        $previewEnd   = $endDt->setTimezone($utc)->format('Y-m-d H:i:s');
    }
}

$models   = [];
$errorMsg = '';

$totalItems      = 0;
$distinctModels  = 0;

// Availability per model for preview: model_id => ['total' => X, 'booked' => Y, 'free' => Z]
$availability = [];

if (!empty($basket)) {
    try {
        // Load model data and tally basic counts
        foreach ($basket as $modelId => $qty) {
            $modelId = (int)$modelId;
            $qty     = (int)$qty;

            // Count requestable assets for limits/availability
            $requestableCount = null;
            try {
                $requestableCount = count_requestable_assets_by_model($modelId);
            } catch (Throwable $e) {
                $requestableCount = null;
            }

            $models[] = [
                'id'                => $modelId,
                'data'              => get_model($modelId),
                'qty'               => $qty,
                'requestable_count' => $requestableCount,
            ];
            $totalItems     += $qty;
            $distinctModels += 1;
        }

        // If we have valid preview dates, compute availability per model for that window
        if ($previewStart && $previewEnd) {
            foreach ($models as $entry) {
                $mid = (int)$entry['id'];
                $requestableTotal = $entry['requestable_count'] ?? null;

                // How many units already booked in that time range?
                // Pending/confirmed reservations overlapping the window
                $sql = "
                    SELECT COALESCE(SUM(ri.quantity), 0) AS pending_qty
                    FROM reservation_items ri
                    JOIN reservations r ON r.id = ri.reservation_id
                    WHERE ri.model_id = :model_id
                      AND ri.deleted_at IS NULL
                      AND r.status IN ('pending', 'confirmed')
                      AND (r.start_datetime < :end AND r.end_datetime > :start)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':model_id' => $mid,
                    ':start'    => $previewStart,
                    ':end'      => $previewEnd,
                ]);
                $pendingQty = (int)(($stmt->fetch())['pending_qty'] ?? 0);

                // Active checkout items overlapping the window
                $coSql = "
                    SELECT COUNT(*) AS co_qty
                    FROM checkout_items ci
                    JOIN checkouts c ON c.id = ci.checkout_id
                    WHERE ci.model_id = :model_id
                      AND ci.checked_in_at IS NULL
                      AND c.status IN ('open','partial')
                      AND c.start_datetime < :end
                      AND c.end_datetime > :start
                ";
                $coStmt = $pdo->prepare($coSql);
                $coStmt->execute([
                    ':model_id' => $mid,
                    ':start'    => $previewStart,
                    ':end'      => $previewEnd,
                ]);
                $checkedOutQty = (int)(($coStmt->fetch())['co_qty'] ?? 0);

                $booked = $pendingQty + $checkedOutQty;

                // Total requestable units in Snipe-IT
                if ($requestableTotal === null) {
                    try {
                        $requestableTotal = count_requestable_assets_by_model($mid);
                    } catch (Throwable $e) {
                        $requestableTotal = 0;
                    }
                }

                if ($requestableTotal > 0) {
                    $free = max(0, $requestableTotal - $booked);
                } else {
                    $free = null; // unknown
                }

                $availability[$mid] = [
                    'total'  => $requestableTotal,
                    'booked' => $booked,
                    'free'   => $free,
                ];
            }
        }

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

// Checkout rules validation (run when basket is non-empty)
$checkoutErrors = [];
$userOverride = $_SESSION['booking_user_override'] ?? null;
$bookingUser  = $userOverride ?: $currentUser;
$staffNoUserSelected = $isStaff && !$userOverride;
if ($staffNoUserSelected) {
    $checkoutErrors[] = 'Please select a user in the catalogue before submitting a reservation.';
}
$snipeUserId = (int)($bookingUser['snipeit_user_id'] ?? 0);
if ($snipeUserId <= 0) {
    $snipeUserId = resolve_snipeit_user_id($bookingUser['email'] ?? '');
}

// Compute max checkout hours for the slot picker JS
$maxCheckoutHours = 0; // 0 = unlimited
if (!empty($basket) && $snipeUserId > 0) {
    $limits = get_effective_checkout_limits($snipeUserId);
    $maxCheckoutHours = $limits['max_checkout_hours'];
}

if (!empty($basket) && $snipeUserId > 0) {
    // Access group gate
    if (!check_user_has_access_group($snipeUserId)) {
        $checkoutErrors[] = 'You do not have access to reserve equipment. Please contact an administrator to be assigned an Access group.';
    }

    $clCfg = checkout_limits_config();

    // Duration limit (requires valid preview dates)
    if ($clCfg['enabled'] && $previewStart && $previewEnd) {
        try {
            $valStartDt = new DateTime($previewStart, new DateTimeZone('UTC'));
            $valEndDt   = new DateTime($previewEnd, new DateTimeZone('UTC'));
            $durationErr = validate_checkout_duration($snipeUserId, $valStartDt, $valEndDt);
            if ($durationErr !== null) {
                $checkoutErrors[] = $durationErr;
            }
            $advanceErr = validate_advance_reservation($snipeUserId, $valStartDt);
            if ($advanceErr !== null) {
                $checkoutErrors[] = $advanceErr;
            }
        } catch (Throwable $e) {
            // Skip duration check if dates can't be parsed
        }
    }

    // Authorization enforcement per model in basket
    foreach ($basket as $modelId => $qty) {
        $modelId = (int)$modelId;
        if ($modelId <= 0) {
            continue;
        }
        $authReqs = get_model_auth_requirements($modelId);
        if (!empty($authReqs['certs']) || !empty($authReqs['access_levels'])) {
            $authMissing = check_model_authorization($snipeUserId, $authReqs);
            if (!empty($authMissing)) {
                $modelData = get_model($modelId);
                $modelName = $modelData['name'] ?? ('Model #' . $modelId);
                if (!empty($authMissing['certs'])) {
                    $checkoutErrors[] = 'You lack required certification(s) for "' . $modelName . '": ' . implode(', ', $authMissing['certs']);
                } else {
                    $checkoutErrors[] = 'You lack the required access level for "' . $modelName . '": ' . implode(', ', $authMissing['access_levels']);
                }
            }
        }
    }
}

// Opening hours enforcement (admins can bypass)
if (!empty($basket) && $previewStart && $previewEnd && !$isAdmin) {
    require_once SRC_PATH . '/opening_hours.php';
    $ohErrors = oh_validate_reservation_window(
        new DateTime($previewStart, new DateTimeZone('UTC')),
        new DateTime($previewEnd, new DateTimeZone('UTC'))
    );
    foreach ($ohErrors as $ohe) {
        $checkoutErrors[] = $ohe;
    }
}

$hasCheckoutErrors = !empty($checkoutErrors);

// Non-blocking warnings
$checkoutWarnings = [];

// Active checkout warning — query by email since session user ID is the local
// auto-increment, not the Snipe-IT user ID stored in checkouts.snipeit_user_id.
$bookingEmail = trim($bookingUser['email'] ?? '');
if (!empty($basket) && $bookingEmail !== '') {
    $acStmt = $pdo->prepare("
        SELECT * FROM checkouts
         WHERE user_email = :email
           AND parent_checkout_id IS NULL
           AND status IN ('open','partial')
         ORDER BY created_at DESC
         LIMIT 1
    ");
    $acStmt->execute([':email' => $bookingEmail]);
    $activeCheckout = $acStmt->fetch(PDO::FETCH_ASSOC);
    $activeCheckoutReturnDate = $activeCheckout ? app_format_datetime($activeCheckout['end_datetime']) : '';
}

if (!empty($basket)) {
    foreach ($basket as $wModelId => $wQty) {
        $wModelId = (int)$wModelId;
        if ($wModelId <= 0) continue;
        try {
            $uInfo = count_undeployable_assets_by_model($wModelId);
            if ($uInfo['undeployable_count'] > 0) {
                $wModelData = get_model($wModelId);
                $wModelName = $wModelData['name'] ?? ('Model #' . $wModelId);
                $statuses = implode('/', $uInfo['status_names']);
                $checkoutWarnings[] = 'Some units of "' . $wModelName . '" are currently flagged as ' . $statuses . '. Your reservation may be affected.';
            }
        } catch (Throwable $e) {
            // skip on error
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Basket – Book Equipment</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/slot-picker.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4"
      data-date-format="<?= h(app_get_date_format()) ?>"
      data-time-format="<?= h(app_get_time_format()) ?>">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Your basket</h1>
            <div class="page-subtitle">
                Review models and quantities, check date-specific availability, and confirm your booking.
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
                <a href="catalogue.php" class="btn btn-outline-primary">
                    Back to catalogue
                </a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php
            $basketError = $_SESSION['basket_error'] ?? '';
            unset($_SESSION['basket_error']);
        ?>
        <?php if ($basketError): ?>
            <div class="alert alert-danger">
                <?= h($basketError) ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
            <div class="alert alert-danger">
                Error talking to Snipe-IT: <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($basket)): ?>
            <div class="alert alert-info">
                Your basket is empty. Add models from the <a href="catalogue.php">catalogue</a>.
            </div>
        <?php else: ?>
            <div class="mb-3">
                <span class="badge-summary">
                    <?= $distinctModels ?> model(s), <?= $totalItems ?> item(s) total
                </span>
            </div>

            <?php if ($previewError): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($previewError) ?>
                </div>
            <?php elseif ($previewStart && $previewEnd): ?>
                <div class="alert alert-info">
                    Showing availability for:
                    <strong>
                        <?= h(app_format_datetime($previewStart)) ?>
                        &ndash;
                        <?= h(app_format_datetime($previewEnd)) ?>
                    </strong>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary">
                    Choose a start and end date below and click
                    <strong>Check availability</strong> to see how many units are free for your dates.
                </div>
            <?php endif; ?>

            <?php
                // Build kit membership lookup: model_id => [kit_id, ...]
                $kitGroups = $_SESSION['basket_kit_groups'] ?? [];
                $kitNames  = $_SESSION['basket_kit_names'] ?? [];
                $modelKitMap = []; // model_id => kit_id (first kit that contains it)
                $kitModelIds = []; // kit_id => [model_id, ...]
                foreach ($kitGroups as $kid => $batches) {
                    foreach ($batches as $batch) {
                        foreach ($batch as $entry) {
                            $mid = (int)($entry['model_id'] ?? 0);
                            if ($mid > 0) {
                                $modelKitMap[$mid] = (int)$kid;
                                $kitModelIds[(int)$kid][] = $mid;
                            }
                        }
                    }
                }
                // Deduplicate model lists per kit
                foreach ($kitModelIds as $kid => $mids) {
                    $kitModelIds[$kid] = array_unique($mids);
                }

                // Group models: first render kit-grouped items, then standalone
                $renderedModelIds = [];
            ?>
            <div class="table-responsive mb-4">
                <table class="table table-striped table-bookings align-middle">
                    <thead>
                        <tr>
                            <th style="width:60px"></th>
                            <th>Model</th>
                            <th>Manufacturer</th>
                            <th>Category</th>
                            <th>Requested qty</th>
                            <th>Availability (for chosen dates)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        // Render kit groups first
                        foreach ($kitModelIds as $kid => $mids):
                            $kName = $kitNames[$kid] ?? ('Kit #' . $kid);
                    ?>
                        <tr class="table-info">
                            <td colspan="6">
                                <strong><?= h($kName) ?></strong>
                                <span class="text-muted small">(kit)</span>
                            </td>
                            <td>
                                <a href="basket_remove.php?kit_id=<?= $kid ?>"
                                   class="btn btn-sm btn-outline-danger">
                                    Remove kit
                                </a>
                            </td>
                        </tr>
                        <?php foreach ($models as $entry):
                            $mid = (int)$entry['id'];
                            if (!in_array($mid, $mids)) continue;
                            $renderedModelIds[$mid] = true;
                            $model = $entry['data'];
                            $qty   = (int)$entry['qty'];
                            $availText = 'Not calculated yet';
                            $warnClass = '';
                            if ($previewStart && $previewEnd && isset($availability[$mid])) {
                                $a = $availability[$mid];
                                if ($a['total'] > 0 && $a['free'] !== null) {
                                    $availText = $a['free'] . ' of ' . $a['total'] . ' units free';
                                    if ($qty > $a['free']) {
                                        $warnClass = 'text-danger fw-semibold';
                                        $availText .= ' – not enough for requested quantity';
                                    }
                                } elseif ($a['total'] > 0) {
                                    $availText = $a['total'] . ' units total (unable to compute free units)';
                                } else {
                                    $availText = 'Availability unknown (no total count from Snipe-IT)';
                                }
                            }
                        ?>
                        <?php
                            $imgPath = $model['image'] ?? '';
                            $imgSrc = $imgPath !== '' ? 'image_proxy.php?src=' . urlencode($imgPath) : '';
                        ?>
                        <tr>
                            <td>
                                <?php if ($imgSrc !== ''): ?>
                                    <img src="<?= h($imgSrc) ?>" alt="" class="basket-thumb">
                                <?php endif; ?>
                            </td>
                            <td class="ps-4"><?= h($model['name'] ?? 'Model') ?></td>
                            <td><?= h($model['manufacturer']['name'] ?? '') ?></td>
                            <td><?= h($model['category']['name'] ?? '') ?></td>
                            <td><?= $qty ?></td>
                            <td class="<?= $warnClass ?>"><?= htmlspecialchars($availText) ?></td>
                            <td>
                                <a href="basket_remove.php?model_id=<?= (int)$model['id'] ?>"
                                   class="btn btn-sm btn-outline-danger btn-sm">
                                    Remove
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php
                        // Render standalone (non-kit) items
                        foreach ($models as $entry):
                            $mid = (int)$entry['id'];
                            if (isset($renderedModelIds[$mid])) continue;
                            $model = $entry['data'];
                            $qty   = (int)$entry['qty'];
                            $availText = 'Not calculated yet';
                            $warnClass = '';
                            if ($previewStart && $previewEnd && isset($availability[$mid])) {
                                $a = $availability[$mid];
                                if ($a['total'] > 0 && $a['free'] !== null) {
                                    $availText = $a['free'] . ' of ' . $a['total'] . ' units free';
                                    if ($qty > $a['free']) {
                                        $warnClass = 'text-danger fw-semibold';
                                        $availText .= ' – not enough for requested quantity';
                                    }
                                } elseif ($a['total'] > 0) {
                                    $availText = $a['total'] . ' units total (unable to compute free units)';
                                } else {
                                    $availText = 'Availability unknown (no total count from Snipe-IT)';
                                }
                            }
                    ?>
                        <?php
                            $imgPath = $model['image'] ?? '';
                            $imgSrc = $imgPath !== '' ? 'image_proxy.php?src=' . urlencode($imgPath) : '';
                        ?>
                        <tr>
                            <td>
                                <?php if ($imgSrc !== ''): ?>
                                    <img src="<?= h($imgSrc) ?>" alt="" class="basket-thumb">
                                <?php endif; ?>
                            </td>
                            <td><?= h($model['name'] ?? 'Model') ?></td>
                            <td><?= h($model['manufacturer']['name'] ?? '') ?></td>
                            <td><?= h($model['category']['name'] ?? '') ?></td>
                            <td><?= $qty ?></td>
                            <td class="<?= $warnClass ?>"><?= htmlspecialchars($availText) ?></td>
                            <td>
                                <a href="basket_remove.php?model_id=<?= (int)$model['id'] ?>"
                                   class="btn btn-sm btn-outline-danger">
                                    Remove
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Form to preview availability for chosen dates -->
            <div class="availability-box mb-4">
                <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                    <div class="availability-pill">Select reservation window</div>
                    <div class="text-muted small">Start defaults to now, end to tomorrow at 09:00</div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Pick-up date &amp; time</label>
                        <div id="start-slot-picker"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Return date &amp; time</label>
                        <div id="end-slot-picker"></div>
                    </div>
                </div>
                <?php if ($isStaff): ?>
                <div class="mt-2">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="bypass-capacity">
                        <label class="form-check-label" for="bypass-capacity">Bypass slot capacity</label>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="bypass-closed">
                        <label class="form-check-label" for="bypass-closed">Bypass closed hours</label>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($hasCheckoutErrors): ?>
                <div class="alert alert-danger">
                    <strong>Cannot confirm booking:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($checkoutErrors as $err): ?>
                            <li><?= h($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($activeCheckout)): ?>
                <div class="alert alert-warning">
                    <strong>You have an active checkout</strong> (return expected <?= h($activeCheckoutReturnDate) ?>).
                    <p class="mb-2 mt-2">New items will be added to your existing checkout and will use the existing return date. Your selected dates will be ignored.</p>
                    <ul class="mb-0">
                        <li><strong>To continue:</strong> click <em>Confirm booking</em> below. Items will be appended to your current checkout.</li>
                        <li><strong>To create a separate reservation:</strong> go back to the <a href="catalogue.php">catalogue</a> and choose dates that don't overlap with your current checkout.</li>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($checkoutWarnings)): ?>
                <div class="alert alert-warning">
                    <ul class="mb-0">
                        <?php foreach ($checkoutWarnings as $warn): ?>
                            <li><?= h($warn) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Final checkout form (uses the same dates, if provided) -->
            <form method="post" action="basket_checkout.php">
                <input type="hidden" name="start_datetime" id="post-start-datetime"
                       value="<?= htmlspecialchars($previewStartRaw) ?>">
                <input type="hidden" name="end_datetime" id="post-end-datetime"
                       value="<?= htmlspecialchars($previewEndRaw) ?>">

                <p class="mb-2 text-muted">
                    When you click <strong>Confirm booking</strong>, the system will re-check availability
                    and reject the booking if another user has taken items in the meantime.
                </p>

                <button class="btn btn-primary btn-lg px-4"
                        type="submit"
                        <?= (!$previewStart || !$previewEnd || $hasCheckoutErrors) ? 'disabled' : '' ?>>
                    Confirm booking for all items
                </button>
                <?php if (!$previewStart || !$previewEnd): ?>
                    <span class="ms-2 text-danger small">
                        Please check availability first.
                    </span>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="assets/slot-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var maxCheckoutHours = <?= json_encode($maxCheckoutHours) ?>;
    var intervalMinutes = <?= (int)(load_config()['app']['slot_interval_minutes'] ?? 15) ?>;

    function pad(n) { return String(n).padStart(2, '0'); }
    function toDatetimeStr(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    var endManuallySet = false;

    var startPicker = new SlotPicker({
        container: document.getElementById('start-slot-picker'),
        hiddenInput: document.getElementById('post-start-datetime'),
        type: 'start',
        intervalMinutes: intervalMinutes,
        isStaff: <?= $isStaff ? 'true' : 'false' ?>,
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
        timeFormat: <?= json_encode(app_get_time_format()) ?>,
        dateFormat: <?= json_encode(app_get_date_format()) ?>,
        onSelect: function (datetime) {
            if (endManuallySet) return;
            // Auto-set end picker default based on max checkout hours
            if (maxCheckoutHours > 0) {
                var startMs = Date.parse(datetime);
                if (!isNaN(startMs)) {
                    var endDate = new Date(startMs + maxCheckoutHours * 3600000);
                    endPicker.setValue(toDatetimeStr(endDate));
                }
            } else {
                // Unlimited: default to next day 09:00
                var parts = datetime.split('T');
                var dateParts = parts[0].split('-');
                var nextDay = new Date(
                    parseInt(dateParts[0], 10),
                    parseInt(dateParts[1], 10) - 1,
                    parseInt(dateParts[2], 10) + 1,
                    9, 0, 0
                );
                endPicker.setValue(toDatetimeStr(nextDay));
            }
        }
    });

    var endPicker = new SlotPicker({
        container: document.getElementById('end-slot-picker'),
        hiddenInput: document.getElementById('post-end-datetime'),
        type: 'end',
        intervalMinutes: intervalMinutes,
        isStaff: <?= $isStaff ? 'true' : 'false' ?>,
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
        timeFormat: <?= json_encode(app_get_time_format()) ?>,
        dateFormat: <?= json_encode(app_get_date_format()) ?>,
        onSelect: function (datetime) {
            endManuallySet = true;
            // When both have values, redirect to check availability
            var startVal = document.getElementById('post-start-datetime').value;
            if (startVal) {
                window.location.href = 'basket.php?start_datetime='
                    + encodeURIComponent(startVal)
                    + '&end_datetime=' + encodeURIComponent(datetime);
            }
        }
    });

    // Restore previous selection after page reload (availability check redirect)
    var existingStart = document.getElementById('post-start-datetime').value;
    var existingEnd = document.getElementById('post-end-datetime').value;
    if (existingStart) {
        startPicker.setValue(existingStart);
    }
    if (existingEnd) {
        endPicker.setValue(existingEnd);
        endManuallySet = true;
    }

    // Bypass toggles
    var capToggle = document.getElementById('bypass-capacity');
    var closedToggle = document.getElementById('bypass-closed');
    if (capToggle) {
        capToggle.addEventListener('change', function () {
            startPicker.setBypass('capacity', this.checked);
            endPicker.setBypass('capacity', this.checked);
        });
    }
    if (closedToggle) {
        closedToggle.addEventListener('change', function () {
            startPicker.setBypass('closed', this.checked);
            endPicker.setBypass('closed', this.checked);
        });
    }
});
</script>
<?php layout_footer(); ?>
</body>
</html>
