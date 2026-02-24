<?php
// quick_checkin.php
// Standalone bulk check-in page (quick scan style).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/booking_helpers.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$ajaxMode = $_GET['ajax'] ?? '';

// AJAX: return detected user's asset list from session
if ($ajaxMode === 'user_assets') {
    header('Content-Type: application/json');
    $detectedUser = $_SESSION['quick_checkin_detected_user'] ?? null;
    $userAssets   = $_SESSION['quick_checkin_user_assets'] ?? [];
    echo json_encode([
        'user'   => $detectedUser,
        'assets' => $userAssets,
    ]);
    exit;
}

if ($ajaxMode === 'asset_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $rows = search_assets($q, 20, true);
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'asset_tag'    => $row['asset_tag'] ?? '',
                'name'         => $row['name'] ?? '',
                'model'        => $row['model']['name'] ?? '',
                'manufacturer' => $row['manufacturer']['name'] ?? '',
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Asset search failed.']);
    }
    exit;
}

// GET ?user=ID — pre-load a user's checked-out assets (e.g. from dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['user'])) {
    $preUserId = (int)$_GET['user'];
    if ($preUserId > 0) {
        try {
            $userData = snipeit_request('GET', 'users/' . $preUserId);
            $_SESSION['quick_checkin_detected_user'] = [
                'id'    => $preUserId,
                'name'  => $userData['name'] ?? '',
                'email' => $userData['email'] ?? '',
            ];
            $_SESSION['quick_checkin_user_assets'] = get_assets_checked_out_to_user($preUserId);
        } catch (Throwable $e) {
            // Silently ignore — page will still work without pre-load
        }
    }
    header('Location: quick_checkin.php');
    exit;
}

if (!isset($_SESSION['quick_checkin_assets'])) {
    $_SESSION['quick_checkin_assets'] = [];
}
$checkinAssets = &$_SESSION['quick_checkin_assets'];

$messages = [];
$errors   = [];
$warnings = [];

// Remove single asset
if (isset($_GET['remove'])) {
    $rid = (int)$_GET['remove'];
    if ($rid > 0 && isset($checkinAssets[$rid])) {
        unset($checkinAssets[$rid]);
        // Clear any deferred actions for this asset
        unset($_SESSION['checkin_asset_actions'][$rid]);
    }
    // Clear detected user if list is now empty
    if (empty($checkinAssets)) {
        unset($_SESSION['quick_checkin_detected_user']);
        unset($_SESSION['quick_checkin_user_assets']);
    }
    header('Location: quick_checkin.php');
    exit;
}

// Clear entire list
if (isset($_GET['clear'])) {
    $checkinAssets = [];
    unset($_SESSION['quick_checkin_detected_user']);
    unset($_SESSION['quick_checkin_user_assets']);
    unset($_SESSION['checkin_asset_actions']);
    header('Location: quick_checkin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'add_asset' || $mode === 'add_asset_by_id') {
        $tag     = trim($_POST['asset_tag'] ?? '');
        $assetIdInput = (int)($_POST['asset_id'] ?? 0);

        if ($mode === 'add_asset_by_id' && $assetIdInput <= 0) {
            $errors[] = 'Invalid asset ID.';
        } elseif ($mode === 'add_asset' && $tag === '') {
            $errors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = ($mode === 'add_asset_by_id')
                    ? snipeit_request('GET', 'hardware/' . $assetIdInput)
                    : find_asset_by_tag($tag);
                $assetId   = (int)($asset['id'] ?? 0);
                $assetTag  = $asset['asset_tag'] ?? '';
                $assetName = $asset['name'] ?? '';
                $modelName = $asset['model']['name'] ?? '';
                $status    = $asset['status_label'] ?? '';
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }

                $assigned = $asset['assigned_to'] ?? null;
                if (empty($assigned) && isset($asset['assigned_to_fullname'])) {
                    $assigned = $asset['assigned_to_fullname'];
                }
                $assignedEmail = '';
                $assignedName  = '';
                $assignedId    = 0;
                if (is_array($assigned)) {
                    $assignedId    = (int)($assigned['id'] ?? 0);
                    $assignedEmail = $assigned['email'] ?? ($assigned['username'] ?? '');
                    $assignedName  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
                } elseif (is_string($assigned)) {
                    $assignedName = $assigned;
                }

                $checkinAssets[$assetId] = [
                    'id'         => $assetId,
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'model_id'   => (int)($asset['model']['id'] ?? 0),
                    'status'     => $status,
                    'assigned_id'    => $assignedId,
                    'assigned_email' => $assignedEmail,
                    'assigned_name'  => $assignedName,
                    'checked_out'    => !empty($assigned),
                ];
                // Detect user from first scanned asset (lock to first user)
                if ($assignedId > 0 && !isset($_SESSION['quick_checkin_detected_user'])) {
                    $_SESSION['quick_checkin_detected_user'] = [
                        'id'    => $assignedId,
                        'name'  => $assignedName,
                        'email' => $assignedEmail,
                    ];
                    try {
                        $_SESSION['quick_checkin_user_assets'] = get_assets_checked_out_to_user($assignedId);
                    } catch (Throwable $e) {
                        $_SESSION['quick_checkin_user_assets'] = [];
                    }
                }

                $label = $modelName !== '' ? $modelName : $assetName;
                $messages[] = "Added asset {$assetTag} ({$label}) to check-in list.";
            } catch (Throwable $e) {
                $errors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkin') {
        $note = trim($_POST['note'] ?? '');

        if (empty($checkinAssets)) {
            $errors[] = 'There are no assets in the check-in list.';
        } else {
            $hadCheckinAssets = !empty($checkinAssets);
            $staffEmail = $currentUser['email'] ?? '';
            $staffName  = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            $staffDisplayName = $staffName !== '' ? $staffName : ($currentUser['email'] ?? 'Staff');
            $assetTags  = [];
            $userBuckets = [];
            $summaryBuckets = [];
            $userLookupCache = [];
            $userIdCache = [];

            foreach ($checkinAssets as $asset) {
                $assetId  = (int)$asset['id'];
                $assetTag = $asset['asset_tag'] ?? '';
                try {
                    $assignedEmail = $asset['assigned_email'] ?? '';
                    $assignedName  = $asset['assigned_name'] ?? '';
                    $assignedId    = (int)($asset['assigned_id'] ?? 0);
                    if (($assignedEmail === '' && $assignedName === '') || $assignedId === 0) {
                        try {
                            $freshAsset = snipeit_request('GET', 'hardware/' . $assetId);
                            $freshAssigned = $freshAsset['assigned_to'] ?? null;
                            if (empty($freshAssigned) && isset($freshAsset['assigned_to_fullname'])) {
                                $freshAssigned = $freshAsset['assigned_to_fullname'];
                            }
                            if (is_array($freshAssigned)) {
                                $assignedId    = (int)($freshAssigned['id'] ?? $assignedId);
                                $assignedEmail = $freshAssigned['email'] ?? ($freshAssigned['username'] ?? $assignedEmail);
                                $assignedName  = $freshAssigned['name'] ?? ($freshAssigned['username'] ?? ($freshAssigned['email'] ?? $assignedName));
                            } elseif (is_string($freshAssigned) && $assignedName === '') {
                                $assignedName = $freshAssigned;
                            }
                        } catch (Throwable $e) {
                            // Skip fresh lookup; proceed with stored assignment data.
                        }
                    }

                    $model = $asset['model'] ?? '';
                    $formatted = $model !== '' ? ($assetTag . ' (' . $model . ')') : $assetTag;
                    $assetTags[] = $formatted;

                    $isCheckedOut = !empty($asset['checked_out']);

                    if (!$isCheckedOut) {
                        // Asset is not currently checked out — add a note to its history instead of failing
                        $noteText = 'Asset returned via quick check-in by ' . $staffDisplayName . '. Asset was not checked out at the time.';
                        if ($note !== '') {
                            $noteText .= ' Staff note: ' . $note;
                        }
                        add_asset_note($assetId, $noteText);
                        $messages[] = "Noted asset {$assetTag} (not checked out).";

                        $summaryLabel = 'Not checked out';
                        if (!isset($summaryBuckets[$summaryLabel])) {
                            $summaryBuckets[$summaryLabel] = [];
                        }
                        $summaryBuckets[$summaryLabel][] = $formatted;
                    } else {
                        checkin_asset($assetId, $note);
                        $messages[] = "Checked in asset {$assetTag}.";

                        // Update checkout_items tracking
                        $ciStmt = $pdo->prepare("
                            SELECT ci.id, ci.checkout_id
                              FROM checkout_items ci
                              JOIN checkouts c ON c.id = ci.checkout_id
                             WHERE ci.asset_id = :aid
                               AND ci.checked_in_at IS NULL
                               AND c.status IN ('open','partial')
                             ORDER BY ci.checked_out_at DESC
                             LIMIT 1
                        ");
                        $ciStmt->execute([':aid' => $assetId]);
                        $ciRow = $ciStmt->fetch(PDO::FETCH_ASSOC);
                        if ($ciRow) {
                            $ciUpd = $pdo->prepare("UPDATE checkout_items SET checked_in_at = NOW() WHERE id = :id");
                            $ciUpd->execute([':id' => (int)$ciRow['id']]);
                            recompute_checkout_status($pdo, (int)$ciRow['checkout_id']);
                        }

                        if ($assignedEmail === '' && $assignedId > 0) {
                            if (isset($userIdCache[$assignedId])) {
                                $cached = $userIdCache[$assignedId];
                                $assignedEmail = $cached['email'] ?? '';
                                $assignedName = $assignedName !== '' ? $assignedName : ($cached['name'] ?? '');
                            } else {
                                try {
                                    $matchedUser = snipeit_request('GET', 'users/' . $assignedId);
                                    $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                    $matchedName  = $matchedUser['name'] ?? ($matchedUser['username'] ?? '');
                                    $userIdCache[$assignedId] = [
                                        'email' => $matchedEmail,
                                        'name'  => $matchedName,
                                    ];
                                    if ($matchedEmail !== '') {
                                        $assignedEmail = $matchedEmail;
                                    }
                                    if ($assignedName === '' && $matchedName !== '') {
                                        $assignedName = $matchedName;
                                    }
                                } catch (Throwable $e) {
                                    // Skip lookup failure; user details may be unavailable.
                                }
                            }
                        }
                        if ($assignedEmail === '' && $assignedName !== '') {
                            $cacheKey = strtolower(trim($assignedName));
                            if (isset($userLookupCache[$cacheKey])) {
                                $assignedEmail = $userLookupCache[$cacheKey];
                            } else {
                                try {
                                    $matchedUser = find_single_user_by_email_or_name($assignedName);
                                    $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                    if ($matchedEmail !== '') {
                                        $assignedEmail = $matchedEmail;
                                        $userLookupCache[$cacheKey] = $matchedEmail;
                                    }
                                } catch (Throwable $e) {
                                    try {
                                        $data = snipeit_request('GET', 'users', [
                                            'search' => $assignedName,
                                            'limit'  => 50,
                                        ]);
                                        $rows = $data['rows'] ?? [];
                                        $exact = [];
                                        $nameLower = strtolower(trim($assignedName));
                                        foreach ($rows as $row) {
                                            $rowName = strtolower(trim((string)($row['name'] ?? '')));
                                            $rowEmail = strtolower(trim((string)($row['email'] ?? ($row['username'] ?? ''))));
                                            if ($rowName !== '' && $rowName === $nameLower) {
                                                $exact[] = $row;
                                            } elseif ($rowEmail !== '' && $rowEmail === $nameLower) {
                                                $exact[] = $row;
                                            }
                                        }
                                        if (!empty($exact)) {
                                            $picked = $exact[0];
                                            $matchedEmail = $picked['email'] ?? ($picked['username'] ?? '');
                                            if ($matchedEmail !== '') {
                                                $assignedEmail = $matchedEmail;
                                                $userLookupCache[$cacheKey] = $matchedEmail;
                                            }
                                            if ($assignedName === '') {
                                                $assignedName = $picked['name'] ?? ($picked['username'] ?? '');
                                            }
                                        }
                                    } catch (Throwable $e2) {
                                        // Skip lookup failure; user email may be unavailable.
                                    }
                                }
                            }
                        }
                        if ($assignedEmail === '' && $assignedName === '' && $assignedId === 0) {
                            try {
                                $history = snipeit_request('GET', 'hardware/' . $assetId . '/history');
                                $rows = $history['rows'] ?? [];
                                foreach ($rows as $row) {
                                    $action = strtolower((string)($row['action_type'] ?? ($row['action'] ?? '')));
                                    if ($action === '' || strpos($action, 'checkout') === false) {
                                        continue;
                                    }
                                    $target = $row['target'] ?? null;
                                    $histId = 0;
                                    $histName = '';
                                    $histEmail = '';
                                    if (is_array($target)) {
                                        $histId = (int)($target['id'] ?? 0);
                                        $histName = $target['name'] ?? ($target['username'] ?? '');
                                        $histEmail = $target['email'] ?? ($target['username'] ?? '');
                                    } else {
                                        $histId = (int)($row['target_id'] ?? 0);
                                        $histName = $row['target_name'] ?? ($row['checkedout_to'] ?? '');
                                        $histEmail = $row['target_email'] ?? '';
                                    }

                                    if ($histEmail === '' && $histId > 0) {
                                        if (isset($userIdCache[$histId])) {
                                            $cached = $userIdCache[$histId];
                                            $histEmail = $cached['email'] ?? '';
                                            $histName = $histName !== '' ? $histName : ($cached['name'] ?? '');
                                        } else {
                                            try {
                                                $matchedUser = snipeit_request('GET', 'users/' . $histId);
                                                $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                                $matchedName  = $matchedUser['name'] ?? ($matchedUser['username'] ?? '');
                                                $userIdCache[$histId] = [
                                                    'email' => $matchedEmail,
                                                    'name'  => $matchedName,
                                                ];
                                                $histEmail = $matchedEmail;
                                                if ($histName === '' && $matchedName !== '') {
                                                    $histName = $matchedName;
                                                }
                                            } catch (Throwable $e) {
                                                // Skip lookup failure; user details may be unavailable.
                                            }
                                        }
                                    }

                                    if ($histEmail !== '' || $histName !== '') {
                                        $assignedEmail = $histEmail !== '' ? $histEmail : $assignedEmail;
                                        $assignedName = $histName !== '' ? $histName : $assignedName;
                                        break;
                                    }
                                }
                            } catch (Throwable $e) {
                                // Skip history lookup failure.
                            }
                        }

                        $summaryLabel = '';
                        if ($assignedEmail !== '') {
                            $summaryLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                                ? ($assignedName . " <{$assignedEmail}>")
                                : $assignedEmail;
                        } elseif ($assignedName !== '') {
                            $summaryLabel = $assignedName;
                        } else {
                            $summaryLabel = 'Unknown user';
                        }
                        if (!isset($summaryBuckets[$summaryLabel])) {
                            $summaryBuckets[$summaryLabel] = [];
                        }
                        $summaryBuckets[$summaryLabel][] = $formatted;

                        if ($assignedEmail !== '') {
                            if (!isset($userBuckets[$assignedEmail])) {
                                $displayName = $assignedName !== '' ? $assignedName : $assignedEmail;
                                $userBuckets[$assignedEmail] = [
                                    'name' => $displayName,
                                    'assets' => [],
                                ];
                            }
                            $userBuckets[$assignedEmail]['assets'][] = $formatted;
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = "Failed to check in {$assetTag}: " . $e->getMessage();
                }
            }
            if (empty($errors)) {
                $assetLineItems = array_map(static function (string $item): string {
                    return '- ' . $item;
                }, array_values(array_filter($assetTags, static function (string $item): bool {
                    return $item !== '';
                })));

                // Notify original users
                foreach ($userBuckets as $email => $info) {
                    $userAssetLines = array_map(static function (string $item): string {
                        return '- ' . $item;
                    }, array_values(array_filter($info['assets'], static function (string $item): bool {
                        return $item !== '';
                    })));
                    $bodyLines = array_merge(
                        ['The following assets have been checked in:'],
                        $userAssetLines,
                        $staffDisplayName !== '' ? ["Checked in by: {$staffDisplayName}"] : [],
                        $note !== '' ? ["Note: {$note}"] : []
                    );
                    layout_send_notification($email, $info['name'], 'Assets checked in', $bodyLines);
                }
                // Notify staff performing check-in
                if ($staffEmail !== '' && !empty($assetTags)) {
                    // Build per-user summary for staff so they can see who had the assets
                    $perUserSummary = [];
                    foreach ($summaryBuckets as $label => $assets) {
                        $perUserSummary[] = '- ' . $label . ': ' . implode(', ', $assets);
                    }

                    $bodyLines = [];
                    $bodyLines[] = 'You checked in the following assets:';
                    if (!empty($perUserSummary)) {
                        $bodyLines = array_merge($bodyLines, $perUserSummary);
                    } else {
                        $bodyLines = array_merge($bodyLines, $assetLineItems);
                    }
                    if ($note !== '') {
                        $bodyLines[] = "Note: {$note}";
                    }
                    layout_send_notification($staffEmail, $staffDisplayName, 'Assets checked in', $bodyLines);
                }

                $checkedInFrom = array_keys($summaryBuckets);
                activity_log_event('quick_checkin', 'Quick checkin completed', [
                    'metadata' => [
                        'assets' => $assetTags,
                        'checked_in_from' => $checkedInFrom,
                        'note'   => $note,
                    ],
                ]);
            }
            // Process deferred per-asset actions (notes, maintenance, status changes)
            if (empty($errors)) {
                $deferredActions = $_SESSION['checkin_asset_actions'] ?? [];
                unset($_SESSION['checkin_asset_actions']);
                $deferWarnings = [];

                foreach ($deferredActions as $dAssetId => $action) {
                    $dAssetId = (int)$dAssetId;
                    if ($dAssetId <= 0) continue;

                    $dNote    = trim((string)($action['note'] ?? ''));
                    $dMaint   = !empty($action['create_maintenance']);
                    $dPull    = !empty($action['pull_for_repair']);
                    $dTag     = '';
                    foreach ($checkinAssets as $ca) {
                        if ((int)$ca['id'] === $dAssetId) {
                            $dTag = $ca['asset_tag'] ?? '';
                            break;
                        }
                    }
                    $dLabel = $dTag !== '' ? $dTag : "#{$dAssetId}";

                    // Note / maintenance
                    if ($dMaint) {
                        try {
                            $maintTitle = 'Repair request — ' . $dLabel;
                            create_asset_maintenance($dAssetId, $maintTitle, $dNote !== '' ? $dNote : 'Flagged during checkin');
                        } catch (Throwable $e) {
                            $deferWarnings[] = "Could not create maintenance for {$dLabel}: " . $e->getMessage();
                        }
                    } elseif ($dNote !== '') {
                        try {
                            add_asset_note($dAssetId, $dNote);
                        } catch (Throwable $e) {
                            $deferWarnings[] = "Could not add note for {$dLabel}: " . $e->getMessage();
                        }
                    }

                    // Pull for repair — change status
                    if ($dPull) {
                        try {
                            $config = load_config();
                            $repairStatusName = $config['snipeit']['repair_status_name'] ?? 'Pulled for Repair/Replace';
                            $statusId = get_status_label_id_by_name($repairStatusName);
                            if ($statusId !== null) {
                                update_asset_status($dAssetId, $statusId);
                            } else {
                                $deferWarnings[] = "Could not find status label \"{$repairStatusName}\" for {$dLabel}.";
                            }
                        } catch (Throwable $e) {
                            $deferWarnings[] = "Could not update status for {$dLabel}: " . $e->getMessage();
                        }
                    }
                }

                if (!empty($deferWarnings)) {
                    $warnings = array_merge($warnings, $deferWarnings);
                }
            }

            if ($hadCheckinAssets) {
                $checkinAssets = [];
                unset($_SESSION['quick_checkin_detected_user']);
                unset($_SESSION['quick_checkin_user_assets']);
                unset($_SESSION['checkin_asset_actions']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quick Checkin – SnipeScheduler</title>
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
            <h1>Quick Checkin</h1>
            <div class="page-subtitle">
                Scan or type asset tags to check items back in via Snipe-IT.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($messages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($warnings as $w): ?>
                        <li><?= h($w) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="sticky-scan-sentinel"></div>
        <div class="card mb-3 sticky-scan-bar">
            <div class="card-body py-2">
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="mode" value="add_asset">
                    <div class="col">
                        <label class="form-label mb-1 fw-semibold">Find or scan asset</label>
                        <div class="position-relative asset-autocomplete-wrapper">
                            <input type="text"
                                   name="asset_tag"
                                   class="form-control asset-autocomplete"
                                   autocomplete="off"
                                   placeholder="Scan barcode or search by name, model..."
                                   autofocus>
                            <div class="list-group position-absolute w-100"
                                 data-asset-suggestions
                                 style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-outline-primary">
                            Add to check-in list
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Bulk check-in</h5>
                <p class="card-text">
                    Scan or type asset tags to add them to the check-in list. When ready, click check in.
                </p>

                <?php
                // User assets panel — shows all assets checked out to the detected user
                $detectedUser = $_SESSION['quick_checkin_detected_user'] ?? null;
                $userAssets   = $_SESSION['quick_checkin_user_assets'] ?? [];
                if ($detectedUser && !empty($userAssets)):
                    $allUserAssetsAdded = true;
                    foreach ($userAssets as $ua) {
                        $uaId = (int)($ua['id'] ?? 0);
                        if ($uaId > 0 && !isset($checkinAssets[$uaId])) {
                            $allUserAssetsAdded = false;
                            break;
                        }
                    }
                    $detectedDisplayName = $detectedUser['name'] ?? $detectedUser['email'] ?? 'Unknown';
                    $detectedEmail = $detectedUser['email'] ?? '';
                    $showEmail = $detectedEmail !== '' && $detectedEmail !== $detectedDisplayName;
                ?>
                <div class="card bg-light mt-3">
                    <div class="card-body">
                        <h6 class="card-title d-flex align-items-center mb-2">
                            <span>Assets checked out to <?= h($detectedDisplayName) ?></span>
                            <?php if ($showEmail): ?>
                                <small class="text-muted ms-1">(<?= h($detectedEmail) ?>)</small>
                            <?php endif; ?>
                            <?php if ($allUserAssetsAdded): ?>
                                <span class="badge bg-success ms-2">All assets added</span>
                            <?php endif; ?>
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Asset Tag</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th style="width: 100px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userAssets as $ua):
                                        $uaId  = (int)($ua['id'] ?? 0);
                                        $inCheckinList = $uaId > 0 && isset($checkinAssets[$uaId]);
                                    ?>
                                        <tr class="<?= $inCheckinList ? 'table-success' : '' ?>">
                                            <td><?= h($ua['asset_tag'] ?? '') ?></td>
                                            <td><?= h($ua['name'] ?? '') ?></td>
                                            <td><a href="#" class="model-history-link" onclick="openModelHistory(<?= (int)($ua['model']['id'] ?? 0) ?>, <?= htmlspecialchars(json_encode($ua['model']['name'] ?? ''), ENT_QUOTES) ?>); return false;"><?= h($ua['model']['name'] ?? '') ?></a></td>
                                            <td>
                                                <?php if ($inCheckinList): ?>
                                                    <span class="badge bg-success">&#10003; Added</span>
                                                <?php else: ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="mode" value="add_asset_by_id">
                                                        <input type="hidden" name="asset_id" value="<?= $uaId ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Add</button>
                                                    </form>
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

                <?php if (empty($checkinAssets)): ?>
                    <div class="alert alert-secondary">
                        No assets in the check-in list yet. Scan or enter an asset tag above.
                    </div>
                <?php else: ?>
                    <?php $savedActions = $_SESSION['checkin_asset_actions'] ?? []; ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Asset Tag</th>
                                    <th>Name</th>
                                    <th>Model</th>
                                    <th>Checked out to</th>
                                    <th style="width: 150px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checkinAssets as $asset):
                                    $aId = (int)$asset['id'];
                                    $hasAction = isset($savedActions[$aId]);
                                    $actionData = $hasAction ? $savedActions[$aId] : null;
                                ?>
                                    <tr>
                                        <td><?= h($asset['asset_tag']) ?></td>
                                        <td><?= h($asset['name']) ?></td>
                                        <td><a href="#" class="model-history-link" onclick="openModelHistory(<?= (int)($asset['model_id'] ?? 0) ?>, <?= htmlspecialchars(json_encode($asset['model'] ?? ''), ENT_QUOTES) ?>); return false;"><?= h($asset['model']) ?></a></td>
                                        <?php
                                            $assignedName = $asset['assigned_name'] ?? '';
                                            $assignedEmail = $asset['assigned_email'] ?? '';
                                            if ($assignedEmail !== '') {
                                                $assignedLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                                                    ? $assignedName . " <{$assignedEmail}>"
                                                    : $assignedEmail;
                                            } elseif ($assignedName !== '') {
                                                $assignedLabel = $assignedName;
                                            } else {
                                                $assignedLabel = '';
                                            }
                                        ?>
                                        <td><?php if ($assignedLabel !== ''): ?><?= h($assignedLabel) ?><?php else: ?><span class="badge bg-warning text-dark">Not checked out</span><?php endif; ?></td>
                                        <td class="text-nowrap">
                                            <button type="button"
                                                    id="noteBtn_<?= $aId ?>"
                                                    class="btn btn-sm <?= $hasAction ? 'btn-warning' : 'btn-outline-secondary' ?>"
                                                    onclick="openAssetNoteModal(<?= $aId ?>, <?= htmlspecialchars(json_encode($asset['asset_tag']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($asset['name'] ?: $asset['model']), ENT_QUOTES) ?>)">
                                                Note
                                            </button>
                                            <a href="quick_checkin.php?remove=<?= $aId ?>"
                                               class="btn btn-sm btn-outline-danger">
                                                Remove
                                            </a>
                                        </td>
                                    </tr>
                                    <tr id="noteRow_<?= $aId ?>" style="<?= $hasAction ? '' : 'display:none;' ?>"
                                        data-note="<?= $hasAction ? h($actionData['note'] ?? '') : '' ?>"
                                        data-maint="<?= $hasAction && !empty($actionData['create_maintenance']) ? '1' : '0' ?>"
                                        data-pull="<?= $hasAction && !empty($actionData['pull_for_repair']) ? '1' : '0' ?>">
                                        <td colspan="5" class="py-1 px-3 bg-light border-0">
                                            <small class="text-muted">
                                                <span id="notePreview_<?= $aId ?>"><?= $hasAction && ($actionData['note'] ?? '') !== '' ? h(mb_strimwidth($actionData['note'], 0, 80, '...')) : '' ?></span>
                                                <?php if ($hasAction && !empty($actionData['create_maintenance'])): ?>
                                                    <span class="badge bg-danger ms-1" id="maintBadge_<?= $aId ?>">Repair</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger ms-1" id="maintBadge_<?= $aId ?>" style="display:none;">Repair</span>
                                                <?php endif; ?>
                                                <?php if ($hasAction && !empty($actionData['pull_for_repair'])): ?>
                                                    <span class="badge bg-secondary ms-1" id="pullBadge_<?= $aId ?>">Pulled</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary ms-1" id="pullBadge_<?= $aId ?>" style="display:none;">Pulled</span>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="post" class="border-top pt-3">
                        <input type="hidden" name="mode" value="checkin">

                        <div class="row g-3 mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Note (optional)</label>
                                <input type="text"
                                       name="note"
                                       class="form-control"
                                       placeholder="Optional note to store with check-in">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Check in all listed assets
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
<script>
(function () {
    const assetWrappers = document.querySelectorAll('.asset-autocomplete-wrapper');
    assetWrappers.forEach((wrapper) => {
        const input = wrapper.querySelector('.asset-autocomplete');
        const list  = wrapper.querySelector('[data-asset-suggestions]');
        if (!input || !list) return;

        let timer = null;
        let lastQuery = '';

        input.addEventListener('input', () => {
            const q = input.value.trim();
            if (q.length < 2) {
                hideSuggestions();
                return;
            }
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => fetchSuggestions(q), 200);
        });

        input.addEventListener('blur', () => {
            setTimeout(hideSuggestions, 150);
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('quick_checkin.php?ajax=asset_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return;
                    renderSuggestions(data.results || []);
                })
                .catch(() => {
                    renderSuggestions([]);
                });
        }

        function renderSuggestions(items) {
            list.innerHTML = '';
            if (!items || !items.length) {
                hideSuggestions();
                return;
            }

            items.forEach((item) => {
                const tag = item.asset_tag || '';
                const name = item.name || '';
                const model = item.model || '';
                const manufacturer = item.manufacturer || '';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action py-1 px-3';
                btn.dataset.value = tag;

                const primary = document.createElement('div');
                const tagSpan = document.createElement('strong');
                tagSpan.textContent = tag;
                primary.appendChild(tagSpan);
                if (name) {
                    const nameSpan = document.createElement('span');
                    nameSpan.textContent = ' ' + name;
                    primary.appendChild(nameSpan);
                }
                btn.appendChild(primary);

                if (model || manufacturer) {
                    const secondary = document.createElement('div');
                    secondary.className = 'text-muted small';
                    const parts = [model, manufacturer].filter(Boolean);
                    secondary.textContent = parts.join(' \u2013 ');
                    btn.appendChild(secondary);
                }

                btn.addEventListener('click', () => {
                    input.value = btn.dataset.value;
                    hideSuggestions();
                    input.focus();
                });

                list.appendChild(btn);
            });

            list.style.display = 'block';
        }

        function hideSuggestions() {
            list.style.display = 'none';
            list.innerHTML = '';
        }
    });
})();
</script>
<?php layout_model_history_modal(); ?>

<!-- Asset Note Modal -->
<div id="assetNoteBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1060;" onclick="closeAssetNoteModal()"></div>
<div id="assetNoteModal" style="display:none; position:fixed; inset:0; z-index:1065; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeAssetNoteModal()">
    <div style="max-width:500px; margin:0 auto; background:#fff; border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #dee2e6;">
            <h5 id="assetNoteModalLabel" style="margin:0;">Asset Note</h5>
            <button type="button" onclick="closeAssetNoteModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div style="padding:1rem;">
            <input type="hidden" id="assetNoteAssetId" value="">
            <div class="mb-3">
                <label for="assetNoteText" class="form-label">Note</label>
                <textarea id="assetNoteText" class="form-control" rows="3" placeholder="e.g. Lens scratched, missing cable..."></textarea>
            </div>
            <div class="mb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="assetNoteCreateMaint" onchange="togglePullCheckbox()">
                    <label class="form-check-label" for="assetNoteCreateMaint">
                        Create maintenance request (Repair)
                    </label>
                </div>
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="assetNotePullRepair" disabled>
                    <label class="form-check-label text-muted" for="assetNotePullRepair" id="assetNotePullLabel">
                        Change status to Pulled for Repair/Replace
                    </label>
                </div>
            </div>
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-danger" id="assetNoteClearBtn" style="display:none;" onclick="clearAssetNote()">Clear</button>
                <div class="ms-auto">
                    <button type="button" class="btn btn-secondary me-1" onclick="closeAssetNoteModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveAssetNote()">Save</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Per-asset note modal — saves to session via AJAX, processed after checkin
var _noteModalAssetId = null;

function openAssetNoteModal(assetId, assetTag, assetLabel) {
    _noteModalAssetId = assetId;
    document.getElementById('assetNoteAssetId').value = assetId;
    document.getElementById('assetNoteModalLabel').textContent = assetTag + (assetLabel ? ' — ' + assetLabel : '');

    // Pre-fill from DOM state (reflects session data rendered on page load)
    var noteRow = document.getElementById('noteRow_' + assetId);
    var notePreview = document.getElementById('notePreview_' + assetId);
    var maintBadge = document.getElementById('maintBadge_' + assetId);
    var pullBadge = document.getElementById('pullBadge_' + assetId);

    var hasSaved = noteRow && noteRow.style.display !== 'none';
    document.getElementById('assetNoteClearBtn').style.display = hasSaved ? '' : 'none';

    // Read current saved state from data attributes if available
    var savedNote = '';
    var savedMaint = false;
    var savedPull = false;
    if (noteRow && noteRow.dataset.note !== undefined) {
        savedNote = noteRow.dataset.note || '';
        savedMaint = noteRow.dataset.maint === '1';
        savedPull = noteRow.dataset.pull === '1';
    } else if (hasSaved) {
        // Fallback: read from visible DOM
        savedNote = notePreview ? notePreview.textContent.replace(/\.\.\.$/, '') : '';
        savedMaint = maintBadge && maintBadge.style.display !== 'none';
        savedPull = pullBadge && pullBadge.style.display !== 'none';
    }

    document.getElementById('assetNoteText').value = savedNote;
    document.getElementById('assetNoteCreateMaint').checked = savedMaint;
    document.getElementById('assetNotePullRepair').checked = savedPull;
    document.getElementById('assetNotePullRepair').disabled = !savedMaint;
    document.getElementById('assetNotePullLabel').classList.toggle('text-muted', !savedMaint);

    document.getElementById('assetNoteBackdrop').style.display = 'block';
    document.getElementById('assetNoteModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.getElementById('assetNoteText').focus();
}

function closeAssetNoteModal() {
    document.getElementById('assetNoteBackdrop').style.display = 'none';
    document.getElementById('assetNoteModal').style.display = 'none';
    document.body.style.overflow = '';
    _noteModalAssetId = null;
}

function togglePullCheckbox() {
    var maint = document.getElementById('assetNoteCreateMaint').checked;
    var pull = document.getElementById('assetNotePullRepair');
    pull.disabled = !maint;
    if (!maint) pull.checked = false;
    document.getElementById('assetNotePullLabel').classList.toggle('text-muted', !maint);
}

function saveAssetNote() {
    var assetId = _noteModalAssetId;
    if (!assetId) return;

    var note = document.getElementById('assetNoteText').value.trim();
    var createMaint = document.getElementById('assetNoteCreateMaint').checked;
    var pullRepair = document.getElementById('assetNotePullRepair').checked;

    if (note === '' && !createMaint && !pullRepair) {
        // Nothing to save — clear instead
        clearAssetNote();
        return;
    }

    fetch('ajax_checkin_asset_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({
            asset_id: assetId,
            note: note,
            create_maintenance: createMaint,
            pull_for_repair: pullRepair
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            updateNoteRow(assetId, note, createMaint, pullRepair);
            closeAssetNoteModal();
        }
    })
    .catch(function() {
        // Silently fail — data persists in session on next page load anyway
        closeAssetNoteModal();
    });
}

function clearAssetNote() {
    var assetId = _noteModalAssetId;
    if (!assetId) return;

    fetch('ajax_checkin_asset_action.php', {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({asset_id: assetId})
    })
    .then(function(r) { return r.json(); })
    .then(function() {
        updateNoteRow(assetId, '', false, false);
        closeAssetNoteModal();
    })
    .catch(function() {
        closeAssetNoteModal();
    });
}

function updateNoteRow(assetId, note, createMaint, pullRepair) {
    var noteRow = document.getElementById('noteRow_' + assetId);
    var noteBtn = document.getElementById('noteBtn_' + assetId);
    var notePreview = document.getElementById('notePreview_' + assetId);
    var maintBadge = document.getElementById('maintBadge_' + assetId);
    var pullBadge = document.getElementById('pullBadge_' + assetId);

    var hasData = note !== '' || createMaint || pullRepair;

    if (noteBtn) {
        noteBtn.className = 'btn btn-sm ' + (hasData ? 'btn-warning' : 'btn-outline-secondary');
    }
    if (noteRow) {
        noteRow.style.display = hasData ? '' : 'none';
        noteRow.dataset.note = note;
        noteRow.dataset.maint = createMaint ? '1' : '0';
        noteRow.dataset.pull = pullRepair ? '1' : '0';
    }
    if (notePreview) {
        notePreview.textContent = note.length > 80 ? note.substring(0, 77) + '...' : note;
    }
    if (maintBadge) {
        maintBadge.style.display = createMaint ? '' : 'none';
    }
    if (pullBadge) {
        pullBadge.style.display = pullRepair ? '' : 'none';
    }
}
</script>
<?php layout_footer(); ?>
</body>
</html>
