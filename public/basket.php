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

// AJAX: user search (staff only)
if ($isStaff && ($_GET['ajax'] ?? '') === 'user_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) { echo json_encode(['results' => []]); exit; }
    try {
        $data = snipeit_request('GET', 'users', ['search' => $q, 'limit' => 10]);
        $rows = $data['rows'] ?? [];
        $results = [];
        foreach ($rows as $row) {
            $email = $row['email'] ?? '';
            $name  = $row['name'] ?? '';
            if ($email === '' && $name === '') continue;
            $results[] = ['email' => $email, 'name' => $name !== '' ? $name : $email];
        }
        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'User search error']);
    }
    exit;
}

// Handle staff user override selection
if ($isStaff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'set_booking_user') {
    $revert   = isset($_POST['booking_user_revert']) && $_POST['booking_user_revert'] === '1';
    $selEmail = trim($_POST['booking_user_email'] ?? '');
    $selName  = trim($_POST['booking_user_name'] ?? '');
    if ($revert || $selEmail === '') {
        unset($_SESSION['booking_user_override']);
    } else {
        $_SESSION['booking_user_override'] = [
            'email'           => $selEmail,
            'first_name'      => $selName,
            'last_name'       => '',
            'id'              => 0,
            'snipeit_user_id' => resolve_snipeit_user_id($selEmail),
        ];
    }
    unset($_SESSION['snipeit_user_groups']);
    header('Location: basket.php');
    exit;
}

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

// Display strings for the window button
$windowDisplayStart = $previewStart ? app_format_datetime($previewStart) : '';
$windowDisplayEnd   = $previewEnd   ? app_format_datetime($previewEnd)   : '';

$models   = [];
$errorMsg = '';

$totalItems     = 0;
$distinctModels = 0;

// Availability per model for preview: model_id => ['total' => X, 'booked' => Y, 'free' => Z]
$availability = [];

if (!empty($basket)) {
    try {
        // Load model data and tally basic counts
        foreach ($basket as $modelId => $qty) {
            $modelId = (int)$modelId;
            $qty     = (int)$qty;

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
$overrideName  = $userOverride ? (trim(($userOverride['first_name'] ?? '') . ' ' . ($userOverride['last_name'] ?? '')) ?: ($userOverride['email'] ?? '')) : '';
$overrideEmail = $userOverride ? ($userOverride['email'] ?? '') : '';

if ($staffNoUserSelected) {
    $checkoutErrors[] = 'Please select a user above before confirming a reservation.';
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

// Active checkout warning
$bookingEmail = trim($bookingUser['email'] ?? '');
$activeCheckout = null;
$activeCheckoutReturnDate = '';
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

layout_page_start([
    'active'     => $active,
    'title'      => 'Basket – Book Equipment',
    'bodyClass'  => 'p-4 page-basket',
    'bodyAttrs'  => [
        'data-date-format' => app_get_date_format(),
        'data-time-format' => app_get_time_format(),
    ],
    'hideTopUserBar' => true,
]);
?>

        <div class="basket-layout">

            <!-- ====== SIDEBAR ====== -->
            <aside class="basket-sidebar" aria-label="Booking details">

                <!-- Selected User -->
                <?php if ($isStaff): ?>
                <div class="cat-sidebar-user-card">
                    <div class="cat-sidebar-user-header">
                        <div class="cat-sidebar-section-label">Selected User</div>
                    </div>
                    <hr class="cat-sidebar-user-divider" aria-hidden="true">
                    <form method="post" id="booking_user_form" class="cat-sidebar-user-body position-relative">
                        <input type="hidden" name="mode" value="set_booking_user">
                        <input type="hidden" name="booking_user_email" id="booking_user_email" value="<?= h($overrideEmail) ?>">
                        <input type="hidden" name="booking_user_name" id="booking_user_name" value="<?= h($overrideName) ?>">
                        <input type="search" id="booking_user_input" name="user_lookup"
                               class="form-control form-control-sm cat-sidebar-user-search"
                               placeholder="Search name or email"
                               autocomplete="off"
                               role="combobox"
                               aria-autocomplete="list"
                               aria-expanded="false"
                               aria-controls="booking_user_suggestions"
                               value="<?= h($overrideName) ?>">
                        <div class="list-group position-fixed"
                             id="booking_user_suggestions"
                             role="listbox"
                             aria-label="User suggestions"
                             style="z-index:9999; max-height:260px; overflow-y:auto; display:none; box-shadow: 0 12px 24px rgba(var(--black-rgb), 0.18);"></div>
                    </form>
                </div>
                <?php else: ?>
                <div class="cat-sidebar-user-card">
                    <div class="cat-sidebar-user-header">
                        <div class="cat-sidebar-section-label">Selected User</div>
                    </div>
                    <hr class="cat-sidebar-user-divider" aria-hidden="true">
                    <div class="cat-sidebar-user-body px-3 py-2">
                        <div class="cat-sidebar-user-name">
                            <?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?>
                        </div>
                        <div class="text-muted" style="font-size:0.78rem;"><?= h($currentUser['email'] ?? '') ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Check-Out / Check-In window button -->
                <?php if (!empty($basket)): ?>
                <div class="cat-sidebar-section-label mb-1" style="margin-top:0.85rem;">Check-Out / Check-In</div>
                <button type="button"
                        class="cat-sidebar-window-display"
                        id="window-display-btn"
                        aria-haspopup="dialog"
                        aria-controls="windowModal"
                        <?= $previewError !== '' ? 'data-open-on-load="1"' : '' ?>>
                    <?php if ($windowDisplayStart !== '' && $windowDisplayEnd !== ''): ?>
                        <div class="cat-sidebar-window-row">
                            <span class="cat-sidebar-window-label">Pick-up</span>
                            <span class="cat-sidebar-window-value"><?= h($windowDisplayStart) ?></span>
                        </div>
                        <div class="cat-sidebar-window-row">
                            <span class="cat-sidebar-window-label">Return</span>
                            <span class="cat-sidebar-window-value"><?= h($windowDisplayEnd) ?></span>
                        </div>
                    <?php else: ?>
                        <span class="cat-sidebar-window-placeholder">Set booking window</span>
                    <?php endif; ?>
                </button>

                <!-- Checkout form -->
                <form method="post" action="basket_checkout.php" class="basket-checkout-form" data-loading="Confirming reservation...">
                    <input type="hidden" name="start_datetime" id="post-start-datetime"
                           value="<?= h($previewStartRaw) ?>">
                    <input type="hidden" name="end_datetime" id="post-end-datetime"
                           value="<?= h($previewEndRaw) ?>">

                    <!-- Reservation name -->
                    <div class="basket-sidebar-section">
                        <label for="reservation-name" class="cat-sidebar-section-label mb-1 d-block">
                            Reservation name<?= $isStaff ? ' <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(optional)</span>' : '' ?>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="reservation-name" name="reservation_name"
                               placeholder="e.g. Studio A shoot" maxlength="255"
                               <?= $isStaff ? '' : 'required' ?>>
                    </div>

                    <!-- Notes -->
                    <div class="basket-sidebar-section">
                        <label for="reservation-notes" class="cat-sidebar-section-label mb-1 d-block">
                            Notes <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(optional)</span>
                        </label>
                        <textarea class="form-control form-control-sm" id="reservation-notes" name="reservation_notes"
                                  rows="3" placeholder="Any additional details about this reservation"></textarea>
                    </div>

                    <!-- Confirm section -->
                    <div class="basket-confirm-section">
                        <?php if ($hasCheckoutErrors): ?>
                        <div class="alert alert-danger py-2 px-3 mb-2 small">
                            <strong>Cannot confirm booking:</strong>
                            <ul class="mb-0 mt-1 ps-3">
                                <?php foreach ($checkoutErrors as $err): ?>
                                    <li><?= h($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <button class="btn btn-primary w-100" type="submit"
                                <?= $hasCheckoutErrors ? 'disabled' : '' ?>>
                            Confirm booking
                        </button>
                        <div class="text-muted mt-2" style="font-size:0.75rem; line-height:1.4;">
                            Availability is re-checked on submit. The booking may be rejected if items were taken by another user in the meantime.
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </aside>

            <!-- ====== MAIN CONTENT ====== -->
            <div class="basket-main">
                <div class="basket-scroll-area">

                    <?php
                        $basketError = $_SESSION['basket_error'] ?? '';
                        unset($_SESSION['basket_error']);
                    ?>
                    <?php if ($basketError): ?>
                        <div class="alert alert-danger mb-3"><?= h($basketError) ?></div>
                    <?php endif; ?>

                    <?php if ($errorMsg): ?>
                        <div class="alert alert-danger mb-3">
                            Error talking to Snipe-IT: <?= h($errorMsg) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($basket)): ?>
                        <div class="panel-empty-state">
                            <i class="bi bi-basket panel-empty-icon" aria-hidden="true"></i>
                            <p class="panel-empty-text">Your basket is empty. Add models from the <a href="catalogue.php">catalogue</a>.</p>
                        </div>
                    <?php else: ?>

                        <?php if (!empty($activeCheckout)): ?>
                        <div class="alert alert-warning small mb-3">
                            <strong>You have an active checkout</strong> (return expected <?= h($activeCheckoutReturnDate) ?>).
                            <p class="mb-0 mt-1">New items will be added to your existing checkout and will use the existing return date. Your selected dates will be ignored.</p>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($checkoutWarnings)): ?>
                        <div class="alert alert-warning small mb-3">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($checkoutWarnings as $warn): ?>
                                    <li><?= h($warn) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php
                            // Build kit membership lookup
                            $kitGroups = $_SESSION['basket_kit_groups'] ?? [];
                            $kitNames  = $_SESSION['basket_kit_names'] ?? [];
                            $kitModelIds = [];
                            foreach ($kitGroups as $kid => $batches) {
                                foreach ($batches as $batch) {
                                    foreach ($batch as $entry) {
                                        $mid = (int)($entry['model_id'] ?? 0);
                                        if ($mid > 0) {
                                            $kitModelIds[(int)$kid][] = $mid;
                                        }
                                    }
                                }
                            }
                            foreach ($kitModelIds as $kid => $mids) {
                                $kitModelIds[$kid] = array_unique($mids);
                            }
                            $renderedModelIds = [];

                            function renderBasketItemRow(array $entry, array $availability, ?string $previewStart, ?string $previewEnd): void {
                                $mid   = (int)$entry['id'];
                                $model = $entry['data'];
                                $qty   = (int)$entry['qty'];

                                $availText = $previewStart && $previewEnd ? 'No availability data' : 'Not calculated yet';
                                $warnClass = '';
                                if ($previewStart && $previewEnd && isset($availability[$mid])) {
                                    $a = $availability[$mid];
                                    if ($a['total'] > 0 && $a['free'] !== null) {
                                        $availText = $a['free'] . ' of ' . $a['total'] . ' units free';
                                        if ($qty > $a['free']) {
                                            $warnClass = 'text-danger';
                                            $availText .= ' – not enough for requested qty';
                                        }
                                    } elseif ($a['total'] > 0) {
                                        $availText = $a['total'] . ' units total (unable to compute free)';
                                    } else {
                                        $availText = 'Availability unknown';
                                    }
                                }

                                $imgPath = $model['image'] ?? '';
                                $imgSrc  = $imgPath !== '' ? 'image_proxy.php?src=' . urlencode($imgPath) : '';
                                $catName = $model['category']['name'] ?? '';
                                $mfrName = $model['manufacturer']['name'] ?? '';
                                $modelId = (int)($model['id'] ?? $mid);

                                echo '<div class="basket-item-row">';

                                echo '<div class="basket-item-image">';
                                if ($imgSrc !== '') {
                                    echo '<img src="' . htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') . '" alt="">';
                                }
                                echo '</div>';

                                echo '<div class="basket-item-info">';
                                echo '<div class="basket-item-name">' . htmlspecialchars($model['name'] ?? 'Model', ENT_QUOTES, 'UTF-8') . '</div>';
                                if ($mfrName !== '') {
                                    echo '<div class="basket-item-manufacturer">' . htmlspecialchars($mfrName, ENT_QUOTES, 'UTF-8') . '</div>';
                                }
                                if ($catName !== '') {
                                    echo '<div class="model-card-tags"><span class="model-meta-category">' . htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') . '</span></div>';
                                }
                                echo '</div>';

                                echo '<div class="basket-item-actions">';
                                echo '<form method="post" action="basket_update.php" class="d-flex align-items-center gap-1">';
                                echo '<input type="hidden" name="model_id" value="' . $mid . '">';
                                $minusVal = max(1, $qty - 1);
                                $plusVal  = $qty + 1;
                                echo '<button type="submit" name="quantity" value="' . $minusVal . '" class="btn btn-sm btn-outline-secondary"' . ($qty <= 1 ? ' disabled' : '') . '>−</button>';
                                echo '<input type="number" name="quantity" value="' . $qty . '" min="1" max="100" class="form-control form-control-sm text-center" style="width:3.5rem;" onchange="this.form.submit()">';
                                echo '<button type="submit" name="quantity" value="' . $plusVal . '" class="btn btn-sm btn-outline-secondary">+</button>';
                                echo '</form>';
                                echo '<div class="basket-item-availability ' . $warnClass . '">' . htmlspecialchars($availText, ENT_QUOTES, 'UTF-8') . '</div>';
                                echo '<a href="basket_remove.php?model_id=' . $modelId . '" class="btn btn-sm btn-outline-danger align-self-start">Remove</a>';
                                echo '</div>';

                                echo '</div>';
                            }
                        ?>

                        <?php
                        // Kits — each in its own labelled box
                        foreach ($kitModelIds as $kid => $mids) {
                            $kName = $kitNames[$kid] ?? ('Kit #' . $kid);
                            echo '<div class="basket-kit-box">';
                            echo '<div class="basket-kit-box-header">';
                            echo '<div class="basket-kit-box-title"><strong>' . h($kName) . '</strong><span class="text-muted ms-1 small">(kit)</span></div>';
                            echo '<a href="basket_remove.php?kit_id=' . (int)$kid . '" class="btn btn-sm btn-outline-danger">Remove kit</a>';
                            echo '</div>';
                            echo '<div class="basket-kit-box-items">';
                            foreach ($models as $entry) {
                                $mid = (int)$entry['id'];
                                if (!in_array($mid, $mids, true)) continue;
                                $renderedModelIds[$mid] = true;
                                renderBasketItemRow($entry, $availability, $previewStart, $previewEnd);
                            }
                            echo '</div>';
                            echo '</div>';
                        }

                        // Non-kit items grouped by category
                        $categoryGroups = [];
                        foreach ($models as $entry) {
                            $mid = (int)$entry['id'];
                            if (isset($renderedModelIds[$mid])) continue;
                            $catKey = $entry['data']['category']['name'] ?? '';
                            $categoryGroups[$catKey][] = $entry;
                        }
                        foreach ($categoryGroups as $catLabel => $catEntries) {
                            $catQty = array_sum(array_column($catEntries, 'qty'));
                            $catDisplay = h($catLabel !== '' ? $catLabel : 'Other') . ' – ' . $catQty;
                            echo '<div class="basket-category-group">';
                            echo '<div class="basket-category-header">' . $catDisplay . '</div>';
                            foreach ($catEntries as $entry) {
                                renderBasketItemRow($entry, $availability, $previewStart, $previewEnd);
                            }
                            echo '</div>';
                        }
                        ?>

                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.basket-layout -->

<!-- Basket window form (GET submission to reload with new dates) -->
<?php if (!empty($basket)): ?>
<form id="basket-window-form" method="get" action="basket.php" style="display:none;">
    <input type="hidden" id="window_start_datetime" name="start_datetime" value="<?= h($previewStartRaw) ?>">
    <input type="hidden" id="window_end_datetime"   name="end_datetime"   value="<?= h($previewEndRaw) ?>">
</form>

<div id="windowModalBackdrop"
     style="display:none; position:fixed; inset:0; background:var(--backdrop-modal-strong); z-index:1070;"
     onclick="closeWindowModal()"></div>
<div id="windowModal" role="dialog" aria-modal="true" aria-labelledby="windowModalTitle"
     style="display:none; position:fixed; inset:0; z-index:1075; overflow-y:auto; padding:1.75rem;"
     onclick="if(event.target===this)closeWindowModal()">
    <div class="window-modal-inner">
        <div class="window-modal-header">
            <h5 id="windowModalTitle" class="mb-0">Booking Window</h5>
            <button type="button" class="window-modal-close" onclick="closeWindowModal()"
                    aria-label="Close">&times;</button>
        </div>
        <div class="window-modal-body">
            <div id="window-modal-error" class="text-danger small mb-3"
                 <?= ($previewError === '') ? 'style="display:none;"' : '' ?>>
                <?= h($previewError) ?>
            </div>
            <div class="wm-picker-section">
                <div class="wm-section-label">Pick-up date &amp; time</div>
                <div id="window-start-picker"></div>
            </div>
            <hr class="wm-picker-divider">
            <div class="wm-picker-section">
                <div class="wm-section-label">Return date &amp; time</div>
                <div id="window-end-picker"></div>
            </div>
            <div class="wm-picker-footer">
                <button class="btn btn-sm btn-outline-secondary" type="button" id="window-today-btn">Today</button>
                <button class="btn btn-sm btn-outline-danger" type="button" id="window-clear-btn">Clear</button>
                <?php if ($isStaff): ?>
                <div class="form-check mb-0">
                    <input class="form-check-input window-bypass-cap" type="checkbox" id="window-bypass-capacity">
                    <label class="form-check-label" for="window-bypass-capacity">Bypass slot capacity</label>
                </div>
                <?php if ($isAdmin): ?>
                <div class="form-check mb-0">
                    <input class="form-check-input window-bypass-closed" type="checkbox" id="window-bypass-closed">
                    <label class="form-check-label" for="window-bypass-closed">Bypass closed hours</label>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm ms-auto" type="button" id="window-confirm-btn"
                        disabled>Confirm</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/slot-picker.js"></script>
<script>
var _windowModalTrapFn = null;
function openWindowModal() {
    document.getElementById('windowModalBackdrop').style.display = 'block';
    document.getElementById('windowModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    var modal = document.getElementById('windowModal');
    var focusables = Array.from(modal.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
    ));
    var first = focusables[0];
    var last  = focusables[focusables.length - 1];
    if (first) first.focus();
    _windowModalTrapFn = function (e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
            if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
        }
    };
    document.addEventListener('keydown', _windowModalTrapFn);
}
function closeWindowModal() {
    document.getElementById('windowModalBackdrop').style.display = 'none';
    document.getElementById('windowModal').style.display = 'none';
    document.body.style.overflow = '';
    if (_windowModalTrapFn) {
        document.removeEventListener('keydown', _windowModalTrapFn);
        _windowModalTrapFn = null;
    }
    var trigger = document.getElementById('window-display-btn');
    if (trigger) trigger.focus();
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var maxCheckoutHours = <?= json_encode($maxCheckoutHours) ?>;
    var intervalMinutes  = <?= (int)(load_config()['app']['slot_interval_minutes'] ?? 15) ?>;
    var spOpts = {
        isStaff:      <?= $isStaff ? 'true' : 'false' ?>,
        isAdmin:      <?= $isAdmin ? 'true' : 'false' ?>,
        timeFormat:   <?= json_encode(app_get_time_format()) ?>,
        dateFormat:   <?= json_encode(app_get_date_format()) ?>,
        intervalMinutes: intervalMinutes,
        noCollapse:   true
    };

    function pad(n) { return String(n).padStart(2, '0'); }
    function toDatetimeStr(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function autoSetEnd(picker, datetime) {
        if (maxCheckoutHours > 0) {
            var ms = Date.parse(datetime);
            if (!isNaN(ms)) { picker.setValue(toDatetimeStr(new Date(ms + maxCheckoutHours * 3600000))); }
        } else {
            var p = datetime.split('T')[0].split('-');
            picker.setValue(toDatetimeStr(new Date(parseInt(p[0],10), parseInt(p[1],10)-1, parseInt(p[2],10)+1, 9, 0, 0)));
        }
    }

    // ---- Window slot pickers ----
    var windowForm        = document.getElementById('basket-window-form');
    var windowStartHidden = document.getElementById('window_start_datetime');
    var windowEndHidden   = document.getElementById('window_end_datetime');
    var windowStartPicker = null;
    var windowEndPicker   = null;
    var windowEndManuallySet = false;

    function updateWindowConfirmBtn() {
        var btn = document.getElementById('window-confirm-btn');
        if (!btn) return;
        btn.disabled = !(windowStartHidden && windowStartHidden.value &&
                         windowEndHidden   && windowEndHidden.value);
    }

    function submitWindowForm() {
        if (windowForm) windowForm.submit();
    }

    if (document.getElementById('window-start-picker')) {
        windowEndPicker = new SlotPicker(Object.assign({}, spOpts, {
            container:   document.getElementById('window-end-picker'),
            hiddenInput: windowEndHidden,
            type:        'end',
            onSelect:    function () { windowEndManuallySet = true; updateWindowConfirmBtn(); }
        }));
        windowStartPicker = new SlotPicker(Object.assign({}, spOpts, {
            container:   document.getElementById('window-start-picker'),
            hiddenInput: windowStartHidden,
            type:        'start',
            onSelect:    function (dt) { if (!windowEndManuallySet) autoSetEnd(windowEndPicker, dt); updateWindowConfirmBtn(); }
        }));
        if (windowStartHidden.value) windowStartPicker.setValue(windowStartHidden.value);
        if (windowEndHidden.value) { windowEndPicker.setValue(windowEndHidden.value); windowEndManuallySet = true; }
        updateWindowConfirmBtn();

        var windowConfirmBtn = document.getElementById('window-confirm-btn');
        if (windowConfirmBtn) {
            windowConfirmBtn.addEventListener('click', function () { submitWindowForm(); });
        }

        var windowClearBtn = document.getElementById('window-clear-btn');
        if (windowClearBtn) {
            windowClearBtn.addEventListener('click', function () {
                windowStartPicker.reset();
                windowEndPicker.reset();
                windowEndManuallySet = false;
                submitWindowForm();
            });
        }
    }

    // ---- Today button ----
    var todayBtn = document.getElementById('window-today-btn');
    if (todayBtn && windowStartPicker) {
        todayBtn.addEventListener('click', function () {
            todayBtn.disabled = true;
            windowEndManuallySet = false;
            var params = 'next_open=1';
            if (windowStartPicker.bypassClosed) params += '&bypass_closed=1';
            fetch('ajax_slot_data.php?' + params, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    todayBtn.disabled = false;
                    if (data.error || !data.start) {
                        var now = new Date();
                        windowStartPicker.setValue(toDatetimeStr(now));
                        autoSetEnd(windowEndPicker, toDatetimeStr(now));
                    } else {
                        windowStartPicker.setValue(data.start);
                        if (data.end) { windowEndPicker.setValue(data.end); }
                        else          { autoSetEnd(windowEndPicker, data.start); }
                    }
                    submitWindowForm();
                })
                .catch(function () {
                    todayBtn.disabled = false;
                    var now = new Date();
                    windowStartPicker.setValue(toDatetimeStr(now));
                    autoSetEnd(windowEndPicker, toDatetimeStr(now));
                    submitWindowForm();
                });
        });
    }

    // ---- Bypass toggles ----
    (function () {
        var cap    = document.getElementById('window-bypass-capacity');
        var closed = document.getElementById('window-bypass-closed');
        if (cap && windowStartPicker) {
            cap.addEventListener('change', function () {
                windowStartPicker.setBypass('capacity', this.checked);
                windowEndPicker.setBypass('capacity', this.checked);
            });
        }
        if (closed && windowStartPicker) {
            closed.addEventListener('change', function () {
                windowStartPicker.setBypass('closed', this.checked);
                windowEndPicker.setBypass('closed', this.checked);
            });
        }
    })();

    // ---- Window display button ----
    var windowDisplayBtn = document.getElementById('window-display-btn');
    if (windowDisplayBtn) {
        windowDisplayBtn.addEventListener('click', openWindowModal);
        if (windowDisplayBtn.dataset.openOnLoad === '1') openWindowModal();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var wm = document.getElementById('windowModal');
            if (wm && wm.style.display !== 'none') closeWindowModal();
        }
    });

    // ---- User search (staff only) ----
    var bookingInput   = document.getElementById('booking_user_input');
    var bookingList    = document.getElementById('booking_user_suggestions');
    var bookingEmailEl = document.getElementById('booking_user_email');
    var bookingNameEl  = document.getElementById('booking_user_name');
    var bookingTimer   = null;
    var bookingQuery   = '';
    var bookingActiveIndex   = -1;
    var originalBookingName  = bookingInput   ? bookingInput.value   : '';
    var originalBookingEmail = bookingEmailEl ? bookingEmailEl.value : '';

    function hideBookingSuggestions() {
        if (!bookingList) return;
        bookingList.style.display = 'none';
        bookingList.innerHTML = '';
        if (bookingInput) bookingInput.setAttribute('aria-expanded', 'false');
        bookingActiveIndex = -1;
        var sidebar = document.querySelector('.basket-sidebar');
        if (sidebar) sidebar.style.overflowY = '';
    }

    function renderBookingSuggestions(items) {
        if (!bookingList) return;
        bookingList.innerHTML = '';
        if (!items || !items.length) { hideBookingSuggestions(); return; }
        items.forEach(function (item) {
            var email = item.email || '';
            var name  = item.name  || '';
            var label = (name && email && name !== email) ? (name + ' (' + email + ')') : (name || email);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action';
            btn.setAttribute('role', 'option');
            btn.setAttribute('aria-selected', 'false');
            btn.setAttribute('tabindex', '-1');
            btn.textContent = label;
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                bookingEmailEl.value = email;
                bookingNameEl.value  = name || email;
                document.getElementById('booking_user_form').submit();
            });
            bookingList.appendChild(btn);
        });
        bookingList.style.display = 'block';
        if (bookingInput) bookingInput.setAttribute('aria-expanded', 'true');
        if (bookingInput) {
            var rect = bookingInput.getBoundingClientRect();
            bookingList.style.top  = rect.bottom + 'px';
            bookingList.style.left = rect.left + 'px';
            var sidebar = document.querySelector('.basket-sidebar');
            if (sidebar) sidebar.style.overflowY = 'hidden';
        }
        bookingActiveIndex = -1;
    }

    if (bookingInput && bookingList) {
        bookingInput.addEventListener('input', function () {
            var q = bookingInput.value.trim();
            if (q.length < 2) { hideBookingSuggestions(); return; }
            if (bookingTimer) clearTimeout(bookingTimer);
            bookingTimer = setTimeout(function () {
                bookingQuery = q;
                fetch('basket.php?ajax=user_search&q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (bookingQuery !== q) return;
                        renderBookingSuggestions(data && data.results ? data.results : []);
                    })
                    .catch(function () { hideBookingSuggestions(); });
            }, 250);
        });

        bookingInput.addEventListener('keydown', function (e) {
            var items = bookingList.querySelectorAll('.list-group-item');
            if (!items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                bookingActiveIndex = (bookingActiveIndex + 1) % items.length;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                bookingActiveIndex = (bookingActiveIndex - 1 + items.length) % items.length;
            } else if (e.key === 'Enter' && bookingActiveIndex >= 0) {
                e.preventDefault();
                items[bookingActiveIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                return;
            } else if (e.key === 'Escape') {
                hideBookingSuggestions(); return;
            } else { return; }
            items.forEach(function (el, i) {
                var active = i === bookingActiveIndex;
                el.classList.toggle('active', active);
                el.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            items[bookingActiveIndex].scrollIntoView({ block: 'nearest' });
        });

        bookingInput.addEventListener('focus', function () {
            if (originalBookingEmail && bookingInput.value === originalBookingName) {
                bookingInput.value = '';
            }
        });

        bookingInput.addEventListener('blur', function () {
            setTimeout(function () {
                hideBookingSuggestions();
                if (originalBookingEmail && bookingEmailEl.value === originalBookingEmail) {
                    bookingInput.value = originalBookingName;
                }
            }, 150);
        });
    }
});
</script>
<?php layout_page_end(['withCheckoutOverlay' => true]); ?>
