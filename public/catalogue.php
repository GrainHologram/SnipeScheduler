<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/checkout_rules.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$config   = load_config();
$isAdmin  = !empty($currentUser['is_admin']);
$isStaff  = !empty($currentUser['is_staff']) || $isAdmin;

$bookingOverride = $_SESSION['booking_user_override'] ?? null;
$activeUser      = $bookingOverride ?: $currentUser;
$staffNoUserSelected = $isStaff && !$bookingOverride;

$appCfg   = $config['app'] ?? [];
$debugOn  = !empty($appCfg['debug']);
$blockCatalogueOverdue = array_key_exists('block_catalogue_overdue', $appCfg)
    ? !empty($appCfg['block_catalogue_overdue'])
    : true;
$overdueCacheTtl = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['prefetch']) && !isset($_GET['ajax'])) {
    $query = $_GET;
    $query['prefetch'] = 1;
    $fullUrl = 'catalogue.php' . (empty($query) ? '' : '?' . http_build_query($query));
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Catalogue – Book Equipment</title>
        <link rel="stylesheet"
              href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="assets/style.css">
        <?= layout_theme_styles() ?>
    </head>
    <body class="p-4">
        <div class="loading-overlay">
            <div class="loading-card">
                <div class="loading-spinner" aria-hidden="true"></div>
                <div class="loading-text">Fetching assets...</div>
            </div>
        </div>
        <script>
            window.location.replace("<?= h($fullUrl) ?>");
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (($_GET['ajax'] ?? '') === 'overdue_check') {
    header('Content-Type: application/json');
    if (!$blockCatalogueOverdue) {
        echo json_encode(['blocked' => false, 'assets' => []]);
        exit;
    }

    $bookingOverride = $_SESSION['booking_user_override'] ?? null;
    $activeUser      = $bookingOverride ?: $currentUser;

    $activeUserEmail = trim($activeUser['email'] ?? '');
    $activeUserUsername = trim($activeUser['username'] ?? '');
    $activeUserDisplay = trim($activeUser['display_name'] ?? '');
    $activeUserName = trim(trim($activeUser['first_name'] ?? '') . ' ' . trim($activeUser['last_name'] ?? ''));
    $cacheKey = strtolower(trim($activeUserEmail !== '' ? $activeUserEmail : ($activeUserUsername !== '' ? $activeUserUsername : $activeUserDisplay)));
    if ($cacheKey === '') {
        $cacheKey = 'user_' . (int)($activeUser['id'] ?? 0);
    }

    $cacheBucket = $_SESSION['overdue_check_cache'] ?? [];
    $cached = is_array($cacheBucket) && isset($cacheBucket[$cacheKey]) ? $cacheBucket[$cacheKey] : null;
    if (is_array($cached) && isset($cached['ts'], $cached['data']) && $overdueCacheTtl > 0 && (time() - (int)$cached['ts']) <= $overdueCacheTtl) {
        echo json_encode($cached['data']);
        exit;
    }

    try {
        $lookupKeys = build_lookup_keys(
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName
        );
        $lookupSqlValues = build_sql_lookup_values(
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName
        );

        $snipeUserId = (int)($activeUser['snipeit_user_id'] ?? 0);
        if ($snipeUserId <= 0) {
            $lookupQueries = array_values(array_filter(array_unique([
                $activeUserEmail,
                $activeUserUsername,
                $activeUserDisplay,
                $activeUserName,
            ]), 'strlen'));

            foreach ($lookupQueries as $query) {
                try {
                    $matched = find_single_user_by_email_or_name($query);
                    $snipeUserId = (int)($matched['id'] ?? 0);
                    if ($snipeUserId > 0) {
                        break;
                    }
                } catch (Throwable $e) {
                    // Try next identifier.
                }
            }
        }

        $overdueAssets = fetch_overdue_assets_for_user($lookupSqlValues, $snipeUserId);

        $payload = [
            'blocked' => !empty($overdueAssets),
            'assets'  => $overdueAssets,
        ];
        if ($overdueCacheTtl > 0) {
            $_SESSION['overdue_check_cache'][$cacheKey] = [
                'ts'   => time(),
                'data' => $payload,
            ];
        }
        echo json_encode($payload);
    } catch (Throwable $e) {
        $payload = [
            'blocked' => false,
            'assets'  => [],
            'error'   => $debugOn ? $e->getMessage() : 'Unable to check overdue items at the moment.',
        ];
        if ($overdueCacheTtl > 0) {
            $_SESSION['overdue_check_cache'][$cacheKey] = [
                'ts'   => time(),
                'data' => $payload,
            ];
        }
        echo json_encode($payload);
    }
    exit;
}

// Staff-only user autocomplete endpoint (searches Snipe-IT users API)
if ($isStaff && ($_GET['ajax'] ?? '') === 'user_search') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $data = snipeit_request('GET', 'users', [
            'search' => $q,
            'limit'  => 10,
        ]);

        $rows = $data['rows'] ?? [];
        $results = [];
        foreach ($rows as $row) {
            $email = $row['email'] ?? '';
            $name  = $row['name'] ?? '';
            if ($email === '' && $name === '') {
                continue;
            }
            $results[] = [
                'email' => $email,
                'name'  => $name !== '' ? $name : $email,
            ];
        }

        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $debugOn ? $e->getMessage() : 'User search error']);
    }
    exit;
}

// Handle staff override selection
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
    // Bust cached user groups so auth badges and permissions refresh immediately
    unset($_SESSION['snipeit_user_groups']);
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: catalogue.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Active nav + staff flag
$active  = basename($_SERVER['PHP_SELF']);

// ---------------------------------------------------------------------
// Helper: decode Snipe-IT strings safely
// ---------------------------------------------------------------------
function label_safe(?string $str): string
{
    if ($str === null) {
        return '';
    }
    $decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_overdue_date($val): string
{
    return app_format_date_local($val);
}

function normalize_lookup_key(?string $value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return '';
    }

    $lower = strtolower($value);
    if (strpos($lower, '@') !== false) {
        // Preserve emails/usernames with domains.
        return $lower;
    }

    // Normalize names for more reliable matching.
    $lower = preg_replace('/[(),]+/', ' ', $lower);
    $lower = preg_replace('/\s+/', ' ', $lower);
    return trim($lower);
}

function build_lookup_keys(string $email, string $username, string $display, string $name): array
{
    $raw = [$email, $username, $display, $name];
    $keys = array_map('normalize_lookup_key', $raw);

    $nameCandidates = array_filter([$name, $display], 'strlen');
    foreach ($nameCandidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if (strpos($candidate, ',') !== false) {
            $parts = array_map('trim', explode(',', $candidate, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $keys[] = normalize_lookup_key($parts[1] . ' ' . $parts[0]);
            }
        } else {
            $parts = preg_split('/\s+/', $candidate);
            if (count($parts) >= 2) {
                $first = array_shift($parts);
                $last = array_pop($parts);
                if ($first !== '' && $last !== '') {
                    $keys[] = normalize_lookup_key($last . ' ' . $first);
                }
            }
        }
    }

    $keys = array_values(array_filter(array_unique($keys), 'strlen'));
    return $keys;
}

function build_name_variants(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $variants = [$value];
    if (strpos($value, ',') !== false) {
        $parts = array_map('trim', explode(',', $value, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $variants[] = $parts[1] . ' ' . $parts[0];
        }
    } else {
        $parts = preg_split('/\\s+/', $value);
        if (count($parts) >= 2) {
            $first = array_shift($parts);
            $last = array_pop($parts);
            if ($first !== '' && $last !== '') {
                $variants[] = $last . ' ' . $first;
            }
        }
    }

    $variants = array_values(array_filter(array_unique($variants), 'strlen'));
    return $variants;
}

function build_sql_lookup_values(string $email, string $username, string $display, string $name): array
{
    $email = strtolower(trim($email));
    $username = strtolower(trim($username));
    $nameVariants = array_merge(
        build_name_variants($name),
        build_name_variants($display)
    );
    $nameVariants = array_values(array_filter(array_unique(array_map('strtolower', $nameVariants)), 'strlen'));

    return [
        'emails' => $email !== '' ? [$email] : [],
        'usernames' => $username !== '' ? [$username] : [],
        'names' => $nameVariants,
    ];
}

function expected_to_timestamp($value): ?int
{
    if (is_array($value)) {
        $value = $value['datetime'] ?? ($value['date'] ?? '');
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        $value .= ' 23:59:59';
    }
    // Snipe-IT dates are in the Snipe-IT server's timezone.
    $snipeTz = snipe_get_timezone();
    try {
        $dt = new DateTime($value, $snipeTz);
        return $dt->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

function fetch_overdue_assets_for_user(array $lookup, int $snipeUserId): array
{
    global $pdo;

    // When we have a definitive Snipe-IT user ID, match only on that to avoid
    // false positives from name collisions between different accounts.
    $where = [];
    $params = [];
    if ($snipeUserId > 0) {
        $where[] = 'assigned_to_id = ?';
        $params[] = $snipeUserId;
    } else {
        if (!empty($lookup['emails'])) {
            $placeholders = implode(',', array_fill(0, count($lookup['emails']), '?'));
            $where[] = "(assigned_to_email IS NOT NULL AND LOWER(assigned_to_email) IN ({$placeholders}))";
            $params = array_merge($params, $lookup['emails']);
        }
        if (!empty($lookup['usernames'])) {
            $placeholders = implode(',', array_fill(0, count($lookup['usernames']), '?'));
            $where[] = "(assigned_to_username IS NOT NULL AND LOWER(assigned_to_username) IN ({$placeholders}))";
            $params = array_merge($params, $lookup['usernames']);
        }
        if (!empty($lookup['names'])) {
            $placeholders = implode(',', array_fill(0, count($lookup['names']), '?'));
            $where[] = "(assigned_to_name IS NOT NULL AND LOWER(assigned_to_name) IN ({$placeholders}))";
            $params = array_merge($params, $lookup['names']);
        }
    }

    if (empty($where)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT asset_tag, model_name, expected_checkin
          FROM checked_out_asset_cache
         WHERE " . implode(' OR ', $where)
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $now = time();
    $overdueAssets = [];
    foreach ($rows as $row) {
        $ts = expected_to_timestamp($row['expected_checkin'] ?? '');
        if ($ts === null || $ts > $now) {
            continue;
        }
        $tag = $row['asset_tag'] ?? 'Unknown tag';
        $modelName = $row['model_name'] ?? '';
        $due = format_overdue_date($row['expected_checkin'] ?? '');
        $overdueAssets[] = [
            'tag' => $tag,
            'model' => $modelName,
            'due' => $due,
        ];
    }

    return $overdueAssets;
}

function row_assigned_to_matches_user(array $row, array $keys, int $userId): bool
{
    $assigned = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
    $assignedId = 0;
    $assignedKeys = [];

    if (is_array($assigned)) {
        $assignedId = (int)($assigned['id'] ?? 0);
        $assignedKeys[] = $assigned['email'] ?? '';
        $assignedKeys[] = $assigned['username'] ?? '';
        $assignedKeys[] = $assigned['name'] ?? '';
    } elseif (is_string($assigned)) {
        $assignedKeys[] = $assigned;
    }

    if ($userId > 0 && $assignedId === $userId) {
        return true;
    }

    foreach ($assignedKeys as $key) {
        $norm = normalize_lookup_key($key);
        if ($norm !== '' && in_array($norm, $keys, true)) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------
// Current basket count (for "View basket (X)")
// ---------------------------------------------------------------------
$basket       = $_SESSION['basket'] ?? [];
$basketCount  = 0;
foreach ($basket as $qty) {
    $basketCount += (int)$qty;
}

// ---------------------------------------------------------------------
// Cached overdue state (session cache)
// ---------------------------------------------------------------------
$overdueAssets = [];
$overdueErr = '';
$catalogueBlocked = false;
$skipOverdueCheck = !$blockCatalogueOverdue;
$catalogueSnipeUserId = 0;
$activeUserEmail = trim($activeUser['email'] ?? '');
$activeUserUsername = trim($activeUser['username'] ?? '');
$activeUserDisplay = trim($activeUser['display_name'] ?? '');
$activeUserName = trim(trim($activeUser['first_name'] ?? '') . ' ' . trim($activeUser['last_name'] ?? ''));
$cacheKey = strtolower(trim($activeUserEmail !== '' ? $activeUserEmail : ($activeUserUsername !== '' ? $activeUserUsername : $activeUserDisplay)));
if ($cacheKey === '') {
    $cacheKey = 'user_' . (int)($activeUser['id'] ?? 0);
}
$lookupKeys = build_lookup_keys(
    $activeUserEmail,
    $activeUserUsername,
    $activeUserDisplay,
    $activeUserName
);
$lookupSqlValues = build_sql_lookup_values(
    $activeUserEmail,
    $activeUserUsername,
    $activeUserDisplay,
    $activeUserName
);
$cacheBucket = $_SESSION['overdue_check_cache'] ?? [];
$cached = is_array($cacheBucket) && isset($cacheBucket[$cacheKey]) ? $cacheBucket[$cacheKey] : null;
if (!$skipOverdueCheck && is_array($cached) && isset($cached['ts'], $cached['data']) && $overdueCacheTtl > 0 && (time() - (int)$cached['ts']) <= $overdueCacheTtl) {
    $cachedData = $cached['data'];
    $catalogueBlocked = !empty($cachedData['blocked']);
    $overdueAssets = $cachedData['assets'] ?? [];
    $overdueErr = $cachedData['error'] ?? '';
}
if (!$skipOverdueCheck && !$catalogueBlocked && empty($overdueAssets)) {
    try {
        $snipeUserId = (int)($activeUser['snipeit_user_id'] ?? 0);
        if ($snipeUserId <= 0) {
            $lookupQueries = array_values(array_filter(array_unique([
                $activeUserEmail,
                $activeUserUsername,
                $activeUserDisplay,
                $activeUserName,
            ]), 'strlen'));

            foreach ($lookupQueries as $query) {
                try {
                    $matched = find_single_user_by_email_or_name($query);
                    $snipeUserId = (int)($matched['id'] ?? 0);
                    if ($snipeUserId > 0) {
                        break;
                    }
                } catch (Throwable $e) {
                    // Try next identifier.
                }
            }
        }

        $overdueAssets = fetch_overdue_assets_for_user($lookupSqlValues, $snipeUserId);
        $catalogueBlocked = !empty($overdueAssets);
        $catalogueSnipeUserId = $snipeUserId;
    } catch (Throwable $e) {
        $overdueErr = $debugOn ? $e->getMessage() : 'Unable to check overdue items at the moment.';
    }
}

// If we didn't resolve the Snipe-IT user ID above (e.g. overdue check was skipped),
// try to resolve it now for certification/checkout-rules checks.
if ($catalogueSnipeUserId <= 0) {
    $catalogueSnipeUserId = (int)($activeUser['snipeit_user_id'] ?? 0);
    if ($catalogueSnipeUserId <= 0) {
        $lookupQueries = array_values(array_filter(array_unique([
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName,
        ]), 'strlen'));
        foreach ($lookupQueries as $query) {
            try {
                $matched = find_single_user_by_email_or_name($query);
                $catalogueSnipeUserId = (int)($matched['id'] ?? 0);
                if ($catalogueSnipeUserId > 0) {
                    break;
                }
            } catch (Throwable $e) {
                // Try next identifier.
            }
        }
    }
}

// ---------------------------------------------------------------------
// Access group gate: user must belong to at least one "Access - *" group
// ---------------------------------------------------------------------
$accessBlocked = false;
if ($catalogueSnipeUserId > 0 && !$staffNoUserSelected) {
    $accessBlocked = !check_user_has_access_group($catalogueSnipeUserId);
}

$catLimits = get_effective_checkout_limits($catalogueSnipeUserId > 0 ? $catalogueSnipeUserId : 0);
$maxCheckoutHours = $catLimits['max_checkout_hours'];

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$tab          = ($_GET['tab'] ?? 'kits') === 'equipment' ? 'equipment' : 'kits';
$searchRaw    = trim($_GET['q'] ?? '');
$categoryRaw  = trim($_GET['category'] ?? '');
$sortRaw      = trim($_GET['sort'] ?? '');
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$windowStartRaw = trim($_GET['start_datetime'] ?? '');
$windowEndRaw   = trim($_GET['end_datetime'] ?? '');

// Normalise filters
$search   = $searchRaw !== '' ? $searchRaw : null;
$category = ctype_digit($categoryRaw) ? (int)$categoryRaw : null;
$sort     = $sortRaw !== '' ? $sortRaw : null;

if ($windowStartRaw === '' && $windowEndRaw === '') {
    $sessionStart = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $sessionEnd   = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($sessionStart !== '' && $sessionEnd !== '') {
        $windowStartRaw = $sessionStart;
        $windowEndRaw   = $sessionEnd;
    }
}

// User-entered window dates are in app_tz; convert to UTC for DB queries
$appTz = app_get_timezone($config);
$windowActive  = false;
$windowError   = '';
$windowStartDt = null;
$windowEndDt   = null;
if ($windowStartRaw !== '' || $windowEndRaw !== '') {
    try {
        $windowStartDt = $windowStartRaw !== '' ? new DateTime($windowStartRaw, $appTz) : null;
        $windowEndDt   = $windowEndRaw !== ''   ? new DateTime($windowEndRaw, $appTz)   : null;
    } catch (Throwable $e) {
        // fall through — null values trigger error below
    }
    if (!$windowStartDt || !$windowEndDt) {
        $windowError = 'Please enter a valid start and end date/time.';
    } elseif ($windowEndDt <= $windowStartDt) {
        $windowError = 'End date/time must be after start date/time.';
    } else {
        $windowActive = true;
        $_SESSION['reservation_window_start'] = $windowStartRaw;
        $_SESSION['reservation_window_end']   = $windowEndRaw;
    }
}

// Pagination limit (from config constants)
$perPage = defined('CATALOGUE_ITEMS_PER_PAGE')
    ? (int)CATALOGUE_ITEMS_PER_PAGE
    : 12;

// Deferred loading of categories/models happens after initial render flush.
$categories   = [];
$categoryErr  = '';
$allowedCategoryMap = [];
$allowedCategoryIds = [];
$models      = [];
$modelErr    = '';
$totalModels = 0;
$totalPages  = 1;
$nowIso      = date('Y-m-d H:i:s');
if ($windowActive) {
    $windowStartDt->setTimezone(new DateTimeZone('UTC'));
    $windowEndDt->setTimezone(new DateTimeZone('UTC'));
}
$windowStartIso = $windowActive ? $windowStartDt->format('Y-m-d H:i:s') : '';
$windowEndIso   = $windowActive ? $windowEndDt->format('Y-m-d H:i:s') : '';
$checkedOutCounts = [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catalogue – Book Equipment</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/slot-picker.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4"
      data-catalogue-overdue="<?= $blockCatalogueOverdue ? '1' : '0' ?>"
      data-date-format="<?= h(app_get_date_format()) ?>"
      data-time-format="<?= h(app_get_time_format()) ?>">
<div id="catalogue-loading" class="loading-overlay" aria-live="polite" aria-busy="true">
    <div class="loading-card">
        <div class="loading-spinner" aria-hidden="true"></div>
        <div class="loading-text">Fetching assets...</div>
    </div>
</div>
<?php
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
if (function_exists('ob_flush')) {
    @ob_flush();
}
@flush();

// ---------------------------------------------------------------------
// Load categories from Snipe-IT (deferred so loader shows immediately)
// ---------------------------------------------------------------------
try {
    $categories = get_model_categories();
} catch (Throwable $e) {
    $categories  = [];
    $categoryErr = $e->getMessage();
}

// Optional admin-controlled allowlist for categories shown in the filter
$allowedCfg = $config['catalogue']['allowed_categories'] ?? [];
if (is_array($allowedCfg)) {
    foreach ($allowedCfg as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $cid = (int)$cid;
            $allowedCategoryMap[$cid] = true;
            $allowedCategoryIds[]     = $cid;
        }
    }
}

// ---------------------------------------------------------------------
// Load models from Snipe-IT (deferred so loader shows immediately)
// ---------------------------------------------------------------------
try {
    $data = get_bookable_models($page, $search ?? '', $category, $sort, $perPage, $allowedCategoryIds);

    if (isset($data['rows']) && is_array($data['rows'])) {
        $models = $data['rows'];
    }

    if (isset($data['total'])) {
        $totalModels = (int)$data['total'];
    } else {
        $totalModels = count($models);
    }

    if ($perPage > 0) {
        $totalPages = max(1, (int)ceil($totalModels / $perPage));
    } else {
        $totalPages = 1;
    }
} catch (Throwable $e) {
    $models   = [];
    $modelErr = $e->getMessage();
}

if (!empty($models)) {
    try {
        $stmt = $pdo->query("
            SELECT model_id, COUNT(*) AS cnt
              FROM checked_out_asset_cache
             GROUP BY model_id
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $mid = (int)($row['model_id'] ?? 0);
            if ($mid > 0) {
                $checkedOutCounts[$mid] = (int)($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $checkedOutCounts = [];
    }
}

// Apply allowlist if configured; otherwise show all categories returned by Snipe-IT
if (!empty($allowedCategoryMap) && !empty($categories)) {
    $categories = array_values(array_filter($categories, function ($cat) use ($allowedCategoryMap) {
        $id = isset($cat['id']) ? (int)$cat['id'] : 0;
        return $id > 0 && isset($allowedCategoryMap[$id]);
    }));
}
?>
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Equipment catalogue</h1>
            <div class="page-subtitle">
                Browse bookable equipment models and add them to your basket.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?php if ($blockCatalogueOverdue): ?>
            <div id="overdue-warning" class="alert alert-warning<?= $overdueErr ? '' : ' d-none' ?>">
                <?= h($overdueErr) ?>
            </div>
        <?php endif; ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= htmlspecialchars(trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])) ?></strong>
                (<?= htmlspecialchars($currentUser['email']) ?>)
            </div>
            <div class="top-bar-actions d-flex gap-2">
                <a href="basket.php"
                   class="btn btn-lg btn-primary fw-semibold shadow-sm px-4"
                   style="font-size:16px;"
                   id="view-basket-btn">
                    View basket<?= $basketCount > 0 ? ' (' . $basketCount . ')' : '' ?>
                </a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($isStaff): ?>
            <?php
                // Fetch user groups for auth badge display
                $userAuthGroups = $catalogueSnipeUserId > 0 ? get_user_groups($catalogueSnipeUserId) : [];
                $userAccessLevels = [];
                $userCerts = [];
                foreach ($userAuthGroups as $g) {
                    $gName = trim($g['name'] ?? '');
                    if (preg_match('/^Access\s*-/i', $gName)) {
                        $userAccessLevels[] = $gName;
                    } elseif (preg_match('/^Cert\s*-/i', $gName)) {
                        $userCerts[] = $gName;
                    }
                }
            ?>
            <div class="alert alert-info d-flex flex-column flex-md-row align-items-md-center justify-content-md-between booking-for-alert">
                <div class="mb-2 mb-md-0">
                    <?php if ($staffNoUserSelected): ?>
                        <strong>No user selected</strong> — search to begin booking
                    <?php else: ?>
                        <strong>Booking for:</strong>
                        <?= h($activeUser['email'] ?? '') ?>
                        <?php if (!empty($activeUser['first_name'])): ?>
                            (<?= h(trim(($activeUser['first_name'] ?? '') . ' ' . ($activeUser['last_name'] ?? ''))) ?>)
                        <?php endif; ?>
                        <?php if (!empty($userAccessLevels) || !empty($userCerts)): ?>
                            <span class="ms-2">
                                <?php foreach ($userAccessLevels as $level): ?>
                                    <span class="badge bg-info text-dark"><?= h($level) ?></span>
                                <?php endforeach; ?>
                                <?php foreach ($userCerts as $cert): ?>
                                    <span class="badge bg-warning text-dark"><?= h($cert) ?></span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <form method="post" id="booking_user_form" class="d-flex gap-2 mb-0 flex-wrap position-relative" style="z-index: 9998;">
                    <input type="hidden" name="mode" value="set_booking_user">
                    <input type="hidden" name="booking_user_email" id="booking_user_email">
                    <input type="hidden" name="booking_user_name" id="booking_user_name">
                    <div class="position-relative">
                        <input type="search"
                               id="booking_user_input"
                               name="user_lookup"
                               class="form-control form-control-sm"
                               placeholder="Start typing email or name"
                               autocomplete="off"
                               role="combobox"
                               aria-expanded="false"
                               aria-controls="booking_user_suggestions">
                        <div class="list-group position-absolute w-100"
                             id="booking_user_suggestions"
                             style="z-index: 9999; max-height: 260px; overflow-y: auto; display: none; box-shadow: 0 12px 24px rgba(0,0,0,0.18);"></div>
                    </div>
                    <?php if ($bookingOverride): ?>
                        <button class="btn btn-sm btn-outline-secondary" type="submit" name="booking_user_revert" value="1">Clear selected user</button>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($blockCatalogueOverdue): ?>
            <div id="overdue-alert" class="alert alert-danger<?= $catalogueBlocked ? '' : ' d-none' ?>">
                <div class="fw-semibold mb-2">Catalogue unavailable</div>
                <div class="mb-2">
                    You have overdue items. Please return them before booking more equipment.
                </div>
                <ul class="mb-0" id="overdue-list">
                    <?php foreach ($overdueAssets as $asset): ?>
                        <?php
                            $tag = $asset['tag'] ?? 'Unknown tag';
                            $modelName = $asset['model'] ?? '';
                            $due = $asset['due'] ?? '';
                        ?>
                        <li>
                            <?= h($tag) ?>
                            <?= $modelName !== '' ? ' (' . h($modelName) . ')' : '' ?>
                            <?= $due !== '' ? ' — due ' . h($due) : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div id="catalogue-content" class="<?= $catalogueBlocked ? 'd-none' : '' ?>">
            <?php
                // Build query string that preserves filters across tabs
                $tabQueryParams = array_filter([
                    'q'              => $searchRaw,
                    'category'       => $categoryRaw,
                    'sort'           => $sortRaw,
                    'start_datetime' => $windowStartRaw,
                    'end_datetime'   => $windowEndRaw,
                    'prefetch'       => '1',
                ], 'strlen');
            ?>
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'equipment' ? 'active' : '' ?>"
                       href="catalogue.php?<?= http_build_query(array_merge($tabQueryParams, ['tab' => 'equipment'])) ?>">Equipment</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'kits' ? 'active' : '' ?>"
                       href="catalogue.php?<?= http_build_query(array_merge($tabQueryParams, ['tab' => 'kits'])) ?>">Kits</a>
                </li>
            </ul>

            <?php if ($categoryErr): ?>
                <div class="alert alert-warning">
                    Could not load categories from Snipe-IT: <?= htmlspecialchars($categoryErr) ?>
                </div>
            <?php endif; ?>

            <?php if ($modelErr && $tab === 'equipment'): ?>
                <div class="alert alert-danger">
                    Error talking to Snipe-IT (models): <?= htmlspecialchars($modelErr) ?>
                </div>
            <?php endif; ?>

        <?php if ($tab === 'equipment'): ?>
        <!-- Filters -->

        <form class="filter-panel mb-4" method="get" action="catalogue.php" id="catalogue-filter-form">
            <div class="filter-panel__header d-flex align-items-center gap-3">
                <span class="filter-panel__dot"></span>
                <div class="filter-panel__title">SEARCH</div>
            </div>

            <input type="hidden" name="tab" value="equipment">
            <input type="hidden" name="start_datetime" value="<?= h($windowStartRaw) ?>">
            <input type="hidden" name="end_datetime" value="<?= h($windowEndRaw) ?>">
            <input type="hidden" name="prefetch" value="1">

            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-5">
                    <label class="form-label mb-1 fw-semibold">Search by name</label>
                    <div class="input-group filter-search">
                        <span class="input-group-text filter-search__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <input type="text"
                               name="q"
                               class="form-control form-control-lg filter-search__input"
                               placeholder="Search by model name or manufacturer"
                               value="<?= htmlspecialchars($searchRaw) ?>">
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <label class="form-label mb-1 fw-semibold">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <?php
                            $cid   = (int)($cat['id'] ?? 0);
                            $cname = $cat['name'] ?? '';
                            ?>
                            <option value="<?= $cid ?>"
                                <?= ($category === $cid) ? 'selected' : '' ?>>
                                <?= label_safe($cname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1 fw-semibold">Sort</label>
                    <select name="sort" class="form-select">
                        <option value="">Model name (A–Z)</option>
                        <option value="name_asc"   <?= $sort === 'name_asc'   ? 'selected' : '' ?>>Model Name (Ascending)</option>
                        <option value="name_desc"  <?= $sort === 'name_desc'  ? 'selected' : '' ?>>Model Name (Descending)</option>
                        <option value="manu_asc"   <?= $sort === 'manu_asc'   ? 'selected' : '' ?>>Manufacturer (Ascending)</option>
                        <option value="manu_desc"  <?= $sort === 'manu_desc'  ? 'selected' : '' ?>>Manufacturer (Descending)</option>
                        <option value="units_asc"  <?= $sort === 'units_asc'  ? 'selected' : '' ?>>Units in Total (Ascending)</option>
                        <option value="units_desc" <?= $sort === 'units_desc' ? 'selected' : '' ?>>Units in Total (Descending)</option>
                    </select>
                </div>

                <div class="col-12 col-lg-2 d-grid">
                    <button class="btn btn-primary btn-lg" type="submit">Filter results</button>
                </div>
            </div>
        </form>

        <form class="filter-panel filter-panel--compact mb-4" method="get" action="catalogue.php" id="catalogue-window-form">
            <div class="filter-panel__header d-flex align-items-center gap-3">
                <span class="filter-panel__dot"></span>
                <div class="filter-panel__title">RESERVATION WINDOW</div>
            </div>
            <input type="hidden" name="tab" value="equipment">
            <input type="hidden" name="q" value="<?= h($searchRaw) ?>">
            <input type="hidden" name="category" value="<?= h($categoryRaw) ?>">
            <input type="hidden" name="sort" value="<?= h($sortRaw) ?>">
            <input type="hidden" name="prefetch" value="1">
            <input type="hidden" name="start_datetime" id="catalogue_start_datetime" value="<?= h($windowStartRaw) ?>">
            <input type="hidden" name="end_datetime" id="catalogue_end_datetime" value="<?= h($windowEndRaw) ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Pick-up date &amp; time</label>
                    <div id="equip-start-picker"></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Return date &amp; time</label>
                    <div id="equip-end-picker"></div>
                </div>
                <div class="col-md-4 d-grid d-md-flex gap-2">
                    <button class="btn btn-primary btn-lg w-100 flex-md-fill mt-3 mt-md-0 reservation-window-btn" type="button" id="catalogue-today-btn">
                        Today
                    </button>
                    <button class="btn btn-primary btn-lg w-100 flex-md-fill mt-3 mt-md-0 reservation-window-btn" type="submit">
                        Update availability
                    </button>
                </div>
            </div>
            <?php if ($isStaff): ?>
            <div class="mt-2">
                <div class="form-check form-check-inline">
                    <input class="form-check-input equip-bypass-cap" type="checkbox" id="equip-bypass-capacity">
                    <label class="form-check-label" for="equip-bypass-capacity">Bypass slot capacity</label>
                </div>
                <?php if ($isAdmin): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input equip-bypass-closed" type="checkbox" id="equip-bypass-closed">
                    <label class="form-check-label" for="equip-bypass-closed">Bypass closed hours</label>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($windowError !== ''): ?>
                <div class="text-danger small mt-2"><?= h($windowError) ?></div>
            <?php endif; ?>
        </form>

        <?php if (empty($models) && !$modelErr): ?>
            <div class="alert alert-info">
                No models found. Try adjusting your filters.
            </div>
        <?php endif; ?>

        <?php if (!empty($models)): ?>
            <?php
                $displayedModelIds = array_map(fn($m) => (int)($m['id'] ?? 0), $models);
                $modelStats = prefetch_catalogue_model_stats($displayedModelIds);
            ?>
            <div class="row g-3">
                <?php foreach ($models as $model): ?>
                    <?php
                    $modelId    = (int)($model['id'] ?? 0);
                    $name       = $model['name'] ?? 'Model';
                    $manuName   = $model['manufacturer']['name'] ?? '';
                    $catName    = $model['category']['name'] ?? '';
                    $imagePath  = $model['image'] ?? '';
                    $assetCount = null;
                    $freeNow     = 0;
                    $maxQty      = 0;
                    $isRequestable = false;
                    try {
                        $bulkStats = $modelStats[$modelId] ?? null;
                        $assetCount = $bulkStats ? $bulkStats['requestable_count'] : 0;

                        if ($windowActive) {
                            // Pending/confirmed reservations overlapping the window
                            $stmt = $pdo->prepare("
                                SELECT COALESCE(SUM(ri.quantity), 0) AS pending_qty
                                FROM reservation_items ri
                                JOIN reservations r ON r.id = ri.reservation_id
                                WHERE ri.model_id = :mid
                                  AND ri.deleted_at IS NULL
                                  AND r.status IN ('pending','confirmed')
                                  AND r.start_datetime < :end
                                  AND r.end_datetime > :start
                            ");
                            $stmt->execute([
                                ':mid' => $modelId,
                                ':start' => $windowStartIso,
                                ':end' => $windowEndIso,
                            ]);
                            $pendingQty = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['pending_qty'] ?? 0);

                            // Active checkout items overlapping the window
                            $coStmt = $pdo->prepare("
                                SELECT COUNT(*) AS co_qty
                                FROM checkout_items ci
                                JOIN checkouts c ON c.id = ci.checkout_id
                                WHERE ci.model_id = :mid
                                  AND ci.checked_in_at IS NULL
                                  AND c.status IN ('open','partial')
                                  AND c.start_datetime < :end
                                  AND c.end_datetime > :start
                            ");
                            $coStmt->execute([
                                ':mid' => $modelId,
                                ':start' => $windowStartIso,
                                ':end' => $windowEndIso,
                            ]);
                            $checkedOutQty = (int)(($coStmt->fetch(PDO::FETCH_ASSOC))['co_qty'] ?? 0);

                            $booked = $pendingQty + $checkedOutQty;
                        } else {
                            // "Now" mode: pending/confirmed reservations
                            $stmt = $pdo->prepare("
                                SELECT COALESCE(SUM(ri.quantity), 0) AS pending_qty
                                FROM reservation_items ri
                                JOIN reservations r ON r.id = ri.reservation_id
                                WHERE ri.model_id = :mid
                                  AND ri.deleted_at IS NULL
                                  AND r.status IN ('pending','confirmed')
                                  AND r.start_datetime <= :now
                                  AND r.end_datetime   > :now
                            ");
                            $stmt->execute([
                                ':mid' => $modelId,
                                ':now' => $nowIso,
                            ]);
                            $pendingQty = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['pending_qty'] ?? 0);

                            // "Now" mode: use live cache count for currently checked-out assets.
                            if (array_key_exists($modelId, $checkedOutCounts)) {
                                $activeCheckedOut = $checkedOutCounts[$modelId];
                            } else {
                                $activeCheckedOut = count_checked_out_assets_by_model($modelId);
                            }
                            $booked = $pendingQty + $activeCheckedOut;
                        }
                        $freeNow = max(0, $assetCount - $booked);
                        $maxQty = $freeNow;
                        $isRequestable = $assetCount > 0;
                    } catch (Throwable $e) {
                        $assetCount = $assetCount ?? 0;
                        $freeNow    = 0;
                        $maxQty     = 0;
                        $isRequestable = $assetCount > 0;
                    }
                    // Fetch schedule info for this model (conflicting, prior, next reservations)
                    $scheduleConflicts = [];
                    $schedulePrior = null;
                    $scheduleNext = null;
                    try {
                        if ($windowActive) {
                            // Conflicting reservations in the selected window
                            $schedStmt = $pdo->prepare("
                                SELECT r.id, r.user_name, r.status, r.start_datetime, r.end_datetime,
                                       COALESCE(SUM(ri.quantity), 0) AS qty
                                FROM reservations r
                                JOIN reservation_items ri ON ri.reservation_id = r.id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed')
                                  AND r.start_datetime < :end
                                  AND r.end_datetime > :start
                                GROUP BY r.id
                                ORDER BY r.start_datetime ASC
                                LIMIT 10
                            ");
                            $schedStmt->execute([':mid' => $modelId, ':start' => $windowStartIso, ':end' => $windowEndIso]);

                            // Prior reservation (ends before window start)
                            $priorStmt = $pdo->prepare("
                                SELECT r.start_datetime, r.end_datetime, r.status,
                                       COALESCE(SUM(ri.quantity), 0) AS qty
                                FROM reservations r
                                JOIN reservation_items ri ON ri.reservation_id = r.id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed')
                                  AND r.end_datetime <= :start
                                GROUP BY r.id
                                ORDER BY r.end_datetime DESC
                                LIMIT 1
                            ");
                            $priorStmt->execute([':mid' => $modelId, ':start' => $windowStartIso]);

                            // Next reservation (starts after window end)
                            $nextStmt = $pdo->prepare("
                                SELECT r.start_datetime, r.end_datetime, r.status,
                                       COALESCE(SUM(ri.quantity), 0) AS qty
                                FROM reservations r
                                JOIN reservation_items ri ON ri.reservation_id = r.id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed')
                                  AND r.start_datetime >= :end
                                GROUP BY r.id
                                ORDER BY r.start_datetime ASC
                                LIMIT 1
                            ");
                            $nextStmt->execute([':mid' => $modelId, ':end' => $windowEndIso]);
                        } else {
                            // "Now" mode — overlapping reservations
                            $schedStmt = $pdo->prepare("
                                SELECT r.id, r.user_name, r.status, r.start_datetime, r.end_datetime,
                                       COALESCE(SUM(ri.quantity), 0) AS qty
                                FROM reservations r
                                JOIN reservation_items ri ON ri.reservation_id = r.id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed')
                                  AND r.start_datetime <= :now
                                  AND r.end_datetime > :now
                                GROUP BY r.id
                                ORDER BY r.start_datetime ASC
                                LIMIT 10
                            ");
                            $schedStmt->execute([':mid' => $modelId, ':now' => $nowIso]);

                            // Prior reservation (ended before now)
                            $priorStmt = $pdo->prepare("
                                SELECT r.start_datetime, r.end_datetime, r.status,
                                       COALESCE(SUM(ri.quantity), 0) AS qty
                                FROM reservations r
                                JOIN reservation_items ri ON ri.reservation_id = r.id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed')
                                  AND r.end_datetime <= :now
                                GROUP BY r.id
                                ORDER BY r.end_datetime DESC
                                LIMIT 1
                            ");
                            $priorStmt->execute([':mid' => $modelId, ':now' => $nowIso]);

                            // Next reservation (starts after now)
                            $nextStmt = $pdo->prepare("
                                SELECT r.start_datetime, r.end_datetime, r.status,
                                       COALESCE(SUM(ri.quantity), 0) AS qty
                                FROM reservations r
                                JOIN reservation_items ri ON ri.reservation_id = r.id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed')
                                  AND r.start_datetime > :now
                                GROUP BY r.id
                                ORDER BY r.start_datetime ASC
                                LIMIT 1
                            ");
                            $nextStmt->execute([':mid' => $modelId, ':now' => $nowIso]);
                        }

                        $scheduleConflicts = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
                        $schedulePrior = $priorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                        $scheduleNext = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    } catch (Throwable $e) {
                        // Non-fatal — schedule info is supplementary
                    }
                    $hasScheduleInfo = !empty($scheduleConflicts) || $schedulePrior || $scheduleNext;

                    $notes      = $model['notes'] ?? '';
                    if (is_array($notes)) {
                        $notes = $notes['text'] ?? '';
                    }

                    // Authorization requirements (certs + access levels)
                    $authReqs = [
                        'certs' => $bulkStats ? ($bulkStats['certs'] ?? []) : [],
                        'access_levels' => $bulkStats ? ($bulkStats['access_levels'] ?? []) : [],
                    ];
                    $authMissing = [];
                    try {
                        if ((!empty($authReqs['certs']) || !empty($authReqs['access_levels'])) && $catalogueSnipeUserId > 0 && !$staffNoUserSelected) {
                            $authMissing = check_model_authorization($catalogueSnipeUserId, $authReqs);
                        }
                    } catch (Throwable $e) {
                        // Silently fail — don't block catalogue
                    }
                    $authBlocked = !empty($authMissing['certs']) || !empty($authMissing['access_levels']);

                    $proxiedImage = '';
                    if ($imagePath !== '') {
                        $proxiedImage = 'image_proxy.php?src=' . urlencode($imagePath);
                    }
                    ?>
                    <div class="col-md-4">
                        <div class="card h-100 model-card">
                            <?php if ($proxiedImage !== ''): ?>
                                <div class="model-image-wrapper">
                                    <img src="<?= htmlspecialchars($proxiedImage) ?>"
                                         alt=""
                                         class="model-image img-fluid">
                                </div>
                            <?php else: ?>
                                <div class="model-image-wrapper model-image-wrapper--placeholder">
                                    <div class="model-image-placeholder">
                                        No image
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">
                                    <?php if ($isStaff): ?>
                                        <a href="#" class="model-history-link" onclick="openModelHistory(<?= (int)$modelId ?>, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>); return false;">
                                            <?= label_safe($name) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= label_safe($name) ?>
                                    <?php endif; ?>
                                </h5>
                                <p class="card-text small text-muted mb-2">
                                    <?php if ($manuName): ?>
                                        <span><strong>Manufacturer:</strong> <?= label_safe($manuName) ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($catName): ?>
                                        <span><strong>Category:</strong> <?= label_safe($catName) ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($assetCount !== null): ?>
                                        <span><strong>Requestable units:</strong> <?= $assetCount ?></span><br>
                                    <?php endif; ?>
                                    <span><strong><?= $windowActive ? 'Available for selected dates:' : 'Available now:' ?></strong> <?= $freeNow ?></span>
                                    <?php if (!empty($notes)): ?>
                                        <div class="mt-2 text-muted clamp-3">
                                            <?= label_safe($notes) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                        $undeployInfo = $bulkStats ? $bulkStats['undeployable'] : ['undeployable_count' => 0, 'status_names' => []];
                                        if ($undeployInfo['undeployable_count'] > 0):
                                            $uCount = $undeployInfo['undeployable_count'];
                                            $uStatuses = implode(', ', $undeployInfo['status_names']);
                                    ?>
                                        <div class="mt-2">
                                            <span class="badge bg-danger" title="<?= h($uStatuses) ?>"><?= $uCount ?> unit<?= $uCount !== 1 ? 's' : '' ?> unavailable (<?= h($uStatuses) ?>)</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($authReqs['certs'])): ?>
                                        <div class="mt-1">
                                            <?php foreach ($authReqs['certs'] as $certName): ?>
                                                <span class="badge bg-warning text-dark"><?= h($certName) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($authReqs['access_levels'])): ?>
                                        <div class="mt-1">
                                            <?php foreach ($authReqs['access_levels'] as $level): ?>
                                                <span class="badge bg-info text-dark"><?= h($level) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </p>

                                <?php if ($hasScheduleInfo): $utcTz = new DateTimeZone('UTC'); ?>
                                    <div class="schedule-info mt-2 mb-2">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary w-100 schedule-toggle"
                                                onclick="this.parentElement.classList.toggle('schedule-open')">
                                            View schedule
                                        </button>
                                        <div class="schedule-details">
                                            <?php if ($schedulePrior): ?>
                                                <div class="schedule-entry schedule-prior">
                                                    <span class="schedule-label">Previous:</span>
                                                    <?= layout_status_badge($schedulePrior['status']) ?>
                                                    <span class="schedule-qty"><?= (int)$schedulePrior['qty'] ?> unit<?= (int)$schedulePrior['qty'] !== 1 ? 's' : '' ?></span>
                                                    <span class="schedule-dates">
                                                        <?= h(app_format_datetime_local($schedulePrior['start_datetime'], null, $utcTz)) ?>
                                                        – <?= h(app_format_datetime_local($schedulePrior['end_datetime'], null, $utcTz)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($scheduleConflicts)): ?>
                                                <div class="schedule-section-label">Conflicts:</div>
                                                <?php foreach ($scheduleConflicts as $conflict): ?>
                                                    <div class="schedule-entry schedule-conflict">
                                                        <?= layout_status_badge($conflict['status']) ?>
                                                        <span class="schedule-qty"><?= (int)$conflict['qty'] ?> unit<?= (int)$conflict['qty'] !== 1 ? 's' : '' ?></span>
                                                        <span class="schedule-dates">
                                                            <?= h(app_format_datetime_local($conflict['start_datetime'], null, $utcTz)) ?>
                                                            – <?= h(app_format_datetime_local($conflict['end_datetime'], null, $utcTz)) ?>
                                                        </span>
                                                        <?php if ($isStaff && !empty($conflict['user_name'])): ?>
                                                            <span class="schedule-user text-muted">(<?= h($conflict['user_name']) ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>

                                            <?php if ($scheduleNext): ?>
                                                <div class="schedule-entry schedule-next">
                                                    <span class="schedule-label">Next:</span>
                                                    <?= layout_status_badge($scheduleNext['status']) ?>
                                                    <span class="schedule-qty"><?= (int)$scheduleNext['qty'] ?> unit<?= (int)$scheduleNext['qty'] !== 1 ? 's' : '' ?></span>
                                                    <span class="schedule-dates">
                                                        <?= h(app_format_datetime_local($scheduleNext['start_datetime'], null, $utcTz)) ?>
                                                        – <?= h(app_format_datetime_local($scheduleNext['end_datetime'], null, $utcTz)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <form method="post"
                                      action="basket_add.php"
                                      class="mt-auto add-to-basket-form">
                                    <input type="hidden" name="model_id" value="<?= $modelId ?>">
                                    <?php if ($windowActive): ?>
                                        <input type="hidden" name="start_datetime" value="<?= h($windowStartRaw) ?>">
                                        <input type="hidden" name="end_datetime" value="<?= h($windowEndRaw) ?>">
                                    <?php endif; ?>

                                    <?php if ($staffNoUserSelected): ?>
                                        <div class="alert alert-info small mb-0">Select a user above before adding to basket.</div>
                                        <button type="button" class="btn btn-sm btn-secondary w-100 mt-2" disabled>Add to basket</button>
                                    <?php elseif ($accessBlocked): ?>
                                        <div class="alert alert-warning small mb-0">
                                            You do not have access to reserve equipment. Please contact an administrator to be assigned an Access group.
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-secondary w-100 mt-2"
                                                disabled>
                                            Add to basket
                                        </button>
                                    <?php elseif ($authBlocked): ?>
                                        <div class="alert alert-warning small mb-0">
                                            <?php if (!empty($authMissing['certs'])): ?>
                                                Requires certification: <?= h(implode(', ', $authMissing['certs'])) ?>
                                            <?php else: ?>
                                                Requires access level: <?= h(implode(', ', $authMissing['access_levels'] ?? [])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-secondary w-100 mt-2"
                                                disabled>
                                            Add to basket
                                        </button>
                                    <?php elseif ($isRequestable && $freeNow > 0): ?>
                                        <div class="row g-2 align-items-center mb-2">
                                            <div class="col-6">
                                                <label class="form-label mb-0 small">Quantity</label>
                                                <input type="number"
                                                       name="quantity"
                                                       class="form-control form-control-sm"
                                                       value="1"
                                                       min="1"
                                                       max="<?= $maxQty ?>">
                                            </div>
                                        </div>

                                        <button type="submit"
                                                class="btn btn-sm btn-success w-100">
                                            Add to basket
                                        </button>
                                    <?php else: ?>
                                        <div class="alert alert-secondary small mb-0">
                                            <?php if (!$isRequestable): ?>
                                                No requestable units available.
                                            <?php else: ?>
                                                <?= $windowActive ? 'No units available for selected dates.' : 'No units available right now.' ?>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-secondary w-100 mt-2"
                                                disabled>
                                            Add to basket
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination">
                        <?php
                        $baseQuery = [
                            'tab'      => $tab,
                            'q'        => $searchRaw,
                            'category' => $categoryRaw,
                            'sort'     => $sortRaw,
                            'start_datetime' => $windowStartRaw,
                            'end_datetime' => $windowEndRaw,
                            'prefetch' => 1,
                        ];
                        ?>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php $q = http_build_query(array_merge($baseQuery, ['page' => $p])); ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="catalogue.php?<?= $q ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

        <?php endif; // end equipment tab ?>

        <?php if ($tab === 'kits'): ?>
        <!-- Kits tab -->
        <?php
            $kitsError = '';
            $kits = [];
            $kitCards = [];
            try {
                $kits = get_kits();
            } catch (Throwable $e) {
                $kitsError = $e->getMessage();
            }

            if (!empty($kits)) {
                // For each kit, fetch its models and compute availability
                $allKitModelIds = [];
                $kitModelsMap = []; // kitId => array of model entries

                foreach ($kits as $kit) {
                    $kitId = (int)($kit['id'] ?? 0);
                    if ($kitId <= 0) continue;
                    try {
                        $kitModels = get_kit_models($kitId);
                        $kitModelsMap[$kitId] = $kitModels;
                        foreach ($kitModels as $km) {
                            // Kit models API returns flat objects: id, name, quantity (no nested 'model' key)
                            $mid = (int)($km['id'] ?? 0);
                            if ($mid > 0) {
                                $allKitModelIds[] = $mid;
                            }
                        }
                    } catch (Throwable $e) {
                        $kitModelsMap[$kitId] = [];
                    }
                }

                // Bulk-fetch stats for all models referenced by kits
                $allKitModelIds = array_unique($allKitModelIds);
                $kitModelStats = [];
                if (!empty($allKitModelIds)) {
                    try {
                        $kitModelStats = prefetch_catalogue_model_stats($allKitModelIds);
                    } catch (Throwable $e) {
                        // Non-fatal
                    }

                    // Load checked-out counts for "now" mode
                    if (!$windowActive) {
                        try {
                            $stmt = $pdo->query("
                                SELECT model_id, COUNT(*) AS cnt
                                  FROM checked_out_asset_cache
                                 GROUP BY model_id
                            ");
                            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($rows as $row) {
                                $mid = (int)($row['model_id'] ?? 0);
                                if ($mid > 0 && !isset($checkedOutCounts[$mid])) {
                                    $checkedOutCounts[$mid] = (int)($row['cnt'] ?? 0);
                                }
                            }
                        } catch (Throwable $e) {
                            // Non-fatal
                        }
                    }
                }

                // Build kit card data
                foreach ($kits as $kit) {
                    $kitId = (int)($kit['id'] ?? 0);
                    if ($kitId <= 0) continue;
                    $kitName = $kit['name'] ?? 'Kit';
                    $kitModels = $kitModelsMap[$kitId] ?? [];

                    if (empty($kitModels)) continue;

                    $modelLines = [];
                    $modelDetails = []; // per-model data for partial kit UI
                    $kitCerts = [];
                    $kitAccessLevels = [];
                    $kitAvailability = PHP_INT_MAX; // min across all models
                    $bottleneckModel = '';
                    $hasAvailability = true;
                    $anyModelAvailable = false;

                    foreach ($kitModels as $km) {
                        $mid = (int)($km['id'] ?? 0);
                        $modelName = $km['name'] ?? 'Unknown model';
                        $kitQty = max(1, (int)($km['quantity'] ?? 1));
                        $modelLines[] = $kitQty . 'x ' . $modelName;

                        // Stats
                        $stats = $kitModelStats[$mid] ?? null;
                        $requestableCount = $stats ? $stats['requestable_count'] : 0;

                        // Authorization requirements (union across all models)
                        if ($stats && !empty($stats['certs'])) {
                            foreach ($stats['certs'] as $cert) {
                                $kitCerts[$cert] = true;
                            }
                        }
                        if ($stats && !empty($stats['access_levels'])) {
                            foreach ($stats['access_levels'] as $level) {
                                $kitAccessLevels[$level] = true;
                            }
                        }

                        $freeUnits = 0;

                        // Compute free units for this model
                        if ($requestableCount <= 0) {
                            $kitAvailability = 0;
                            $hasAvailability = false;
                        } else {
                            try {
                                if ($windowActive) {
                                    $stmt = $pdo->prepare("
                                        SELECT COALESCE(SUM(ri.quantity), 0) AS pending_qty
                                        FROM reservation_items ri
                                        JOIN reservations r ON r.id = ri.reservation_id
                                        WHERE ri.model_id = :mid
                                          AND ri.deleted_at IS NULL
                                          AND r.status IN ('pending','confirmed')
                                          AND r.start_datetime < :end
                                          AND r.end_datetime > :start
                                    ");
                                    $stmt->execute([':mid' => $mid, ':start' => $windowStartIso, ':end' => $windowEndIso]);
                                    $pendingQty = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['pending_qty'] ?? 0);

                                    $coStmt = $pdo->prepare("
                                        SELECT COUNT(*) AS co_qty
                                        FROM checkout_items ci
                                        JOIN checkouts c ON c.id = ci.checkout_id
                                        WHERE ci.model_id = :mid
                                          AND ci.checked_in_at IS NULL
                                          AND c.status IN ('open','partial')
                                          AND c.start_datetime < :end
                                          AND c.end_datetime > :start
                                    ");
                                    $coStmt->execute([':mid' => $mid, ':start' => $windowStartIso, ':end' => $windowEndIso]);
                                    $checkedOutQty = (int)(($coStmt->fetch(PDO::FETCH_ASSOC))['co_qty'] ?? 0);

                                    $booked = $pendingQty + $checkedOutQty;
                                } else {
                                    $stmt = $pdo->prepare("
                                        SELECT COALESCE(SUM(ri.quantity), 0) AS pending_qty
                                        FROM reservation_items ri
                                        JOIN reservations r ON r.id = ri.reservation_id
                                        WHERE ri.model_id = :mid
                                          AND ri.deleted_at IS NULL
                                          AND r.status IN ('pending','confirmed')
                                          AND r.start_datetime <= :now
                                          AND r.end_datetime   > :now
                                    ");
                                    $stmt->execute([':mid' => $mid, ':now' => $nowIso]);
                                    $pendingQty = (int)(($stmt->fetch(PDO::FETCH_ASSOC))['pending_qty'] ?? 0);

                                    $activeCheckedOut = $checkedOutCounts[$mid] ?? count_checked_out_assets_by_model($mid);
                                    $booked = $pendingQty + $activeCheckedOut;
                                }

                                $freeUnits = max(0, $requestableCount - $booked);
                                $kitsFromModel = (int)floor($freeUnits / $kitQty);

                                if ($kitsFromModel < $kitAvailability) {
                                    $kitAvailability = $kitsFromModel;
                                    $bottleneckModel = $modelName;
                                }
                            } catch (Throwable $e) {
                                $kitAvailability = 0;
                                $hasAvailability = false;
                            }
                        }

                        if ($freeUnits > 0) {
                            $anyModelAvailable = true;
                        }

                        $modelDetails[] = [
                            'id'       => $mid,
                            'name'     => $modelName,
                            'kit_qty'  => $kitQty,
                            'free'     => $freeUnits,
                        ];
                    }

                    if ($kitAvailability === PHP_INT_MAX) {
                        $kitAvailability = 0;
                    }

                    // Check authorization requirements for current user
                    $kitAuthReqs = [
                        'certs' => array_keys($kitCerts),
                        'access_levels' => array_keys($kitAccessLevels),
                    ];
                    $kitAuthMissing = [];
                    if ((!empty($kitAuthReqs['certs']) || !empty($kitAuthReqs['access_levels'])) && $catalogueSnipeUserId > 0 && !$staffNoUserSelected) {
                        try {
                            $kitAuthMissing = check_model_authorization($catalogueSnipeUserId, $kitAuthReqs);
                        } catch (Throwable $e) {
                            // Non-fatal
                        }
                    }
                    $kitAuthBlocked = !empty($kitAuthMissing['certs']) || !empty($kitAuthMissing['access_levels']);

                    $kitCards[] = [
                        'id'              => $kitId,
                        'name'            => $kitName,
                        'model_lines'     => $modelLines,
                        'model_details'   => $modelDetails,
                        'availability'    => $kitAvailability,
                        'any_available'   => $anyModelAvailable,
                        'bottleneck'      => $bottleneckModel,
                        'certs'           => $kitAuthReqs['certs'],
                        'access_levels'   => $kitAuthReqs['access_levels'],
                        'auth_blocked'    => $kitAuthBlocked,
                        'auth_missing'    => $kitAuthMissing,
                    ];
                }
            }
        ?>
        <?php if ($kitsError): ?>
            <div class="alert alert-danger">
                Error loading kits from Snipe-IT: <?= h($kitsError) ?>
            </div>
        <?php elseif (empty($kitCards)): ?>
            <div class="alert alert-info">
                No kits available. Equipment kits are configured in Snipe-IT.
            </div>
        <?php else: ?>
            <?php if ($windowActive): ?>
                <div class="alert alert-info">
                    Showing kit availability for:
                    <strong>
                        <?= h(app_format_datetime_local($windowStartIso, null, new DateTimeZone('UTC'))) ?>
                        &ndash;
                        <?= h(app_format_datetime_local($windowEndIso, null, new DateTimeZone('UTC'))) ?>
                    </strong>
                </div>
            <?php endif; ?>

            <form class="filter-panel filter-panel--compact mb-4" method="get" action="catalogue.php" id="kits-window-form">
                <div class="filter-panel__header d-flex align-items-center gap-3">
                    <span class="filter-panel__dot"></span>
                    <div class="filter-panel__title">RESERVATION WINDOW</div>
                </div>
                <input type="hidden" name="tab" value="kits">
                <input type="hidden" name="q" value="<?= h($searchRaw) ?>">
                <input type="hidden" name="category" value="<?= h($categoryRaw) ?>">
                <input type="hidden" name="sort" value="<?= h($sortRaw) ?>">
                <input type="hidden" name="prefetch" value="1">
                <input type="hidden" name="start_datetime" id="kits_start_datetime" value="<?= h($windowStartRaw) ?>">
                <input type="hidden" name="end_datetime" id="kits_end_datetime" value="<?= h($windowEndRaw) ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Pick-up date &amp; time</label>
                        <div id="kits-start-picker"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Return date &amp; time</label>
                        <div id="kits-end-picker"></div>
                    </div>
                    <div class="col-md-4 d-grid d-md-flex gap-2">
                        <button class="btn btn-primary btn-lg w-100 flex-md-fill mt-3 mt-md-0 reservation-window-btn" type="button" id="kits-today-btn">
                            Today
                        </button>
                        <button class="btn btn-primary btn-lg w-100 flex-md-fill mt-3 mt-md-0 reservation-window-btn" type="submit">
                            Update availability
                        </button>
                    </div>
                </div>
                <?php if ($isStaff): ?>
                <div class="mt-2">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input kits-bypass-cap" type="checkbox" id="kits-bypass-capacity">
                        <label class="form-check-label" for="kits-bypass-capacity">Bypass slot capacity</label>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input kits-bypass-closed" type="checkbox" id="kits-bypass-closed">
                        <label class="form-check-label" for="kits-bypass-closed">Bypass closed hours</label>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </form>

            <div class="row g-3">
                <?php foreach ($kitCards as $kitCard): ?>
                    <div class="col-md-4">
                        <div class="card h-100 model-card">
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><?= h($kitCard['name']) ?></h5>
                                <div class="small text-muted mb-2">
                                    <?php foreach ($kitCard['model_lines'] as $line): ?>
                                        <div><?= h($line) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="card-text small mb-2">
                                    <strong><?= $windowActive ? 'Kits available for selected dates:' : 'Kits available now:' ?></strong>
                                    <?= (int)$kitCard['availability'] ?>
                                    <?php if ($kitCard['availability'] <= 0 && $kitCard['bottleneck']): ?>
                                        <span class="text-danger"><?php if ($kitCard['any_available']): ?>(limited by <?= h($kitCard['bottleneck']) ?>)<?php else: ?>(no items available)<?php endif; ?></span>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($kitCard['certs'])): ?>
                                    <div class="mb-2">
                                        <?php foreach ($kitCard['certs'] as $certName): ?>
                                            <span class="badge bg-warning text-dark"><?= h($certName) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($kitCard['access_levels'])): ?>
                                    <div class="mb-2">
                                        <?php foreach ($kitCard['access_levels'] as $level): ?>
                                            <span class="badge bg-info text-dark"><?= h($level) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post"
                                      action="basket_add.php"
                                      class="mt-auto add-to-basket-form">
                                    <input type="hidden" name="kit_id" value="<?= (int)$kitCard['id'] ?>">
                                    <?php if ($windowActive): ?>
                                        <input type="hidden" name="start_datetime" value="<?= h($windowStartRaw) ?>">
                                        <input type="hidden" name="end_datetime" value="<?= h($windowEndRaw) ?>">
                                    <?php endif; ?>

                                    <?php if ($staffNoUserSelected): ?>
                                        <div class="alert alert-info small mb-0">Select a user above before adding to basket.</div>
                                        <button type="button" class="btn btn-sm btn-secondary w-100 mt-2" disabled>Add kit to basket</button>
                                    <?php elseif ($accessBlocked): ?>
                                        <div class="alert alert-warning small mb-0">
                                            You do not have access to reserve equipment. Please contact an administrator to be assigned an Access group.
                                        </div>
                                        <button type="button" class="btn btn-sm btn-secondary w-100 mt-2" disabled>Add kit to basket</button>
                                    <?php elseif ($kitCard['auth_blocked']): ?>
                                        <div class="alert alert-warning small mb-0">
                                            <?php if (!empty($kitCard['auth_missing']['certs'])): ?>
                                                Requires certification: <?= h(implode(', ', $kitCard['auth_missing']['certs'])) ?>
                                            <?php else: ?>
                                                Requires access level: <?= h(implode(', ', $kitCard['auth_missing']['access_levels'] ?? [])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-secondary w-100 mt-2" disabled>Add kit to basket</button>
                                    <?php elseif ($kitCard['availability'] > 0): ?>
                                        <div class="row g-2 align-items-center mb-2">
                                            <div class="col-6">
                                                <label class="form-label mb-0 small">Kit quantity</label>
                                                <input type="number"
                                                       name="kit_quantity"
                                                       class="form-control form-control-sm"
                                                       value="1"
                                                       min="1"
                                                       max="<?= (int)$kitCard['availability'] ?>">
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-success w-100">Add kit to basket</button>
                                    <?php elseif ($kitCard['any_available']): ?>
                                        <div class="alert alert-secondary small mb-2">
                                            <?= $windowActive ? 'Not enough stock for a full kit in selected dates.' : 'Not enough stock for a full kit right now.' ?>
                                            Add individual items below.
                                        </div>
                                        <input type="hidden" name="partial" value="1">
                                        <div class="kit-model-rows mb-2">
                                            <?php foreach ($kitCard['model_details'] as $md): ?>
                                                <div class="d-flex align-items-center gap-2 mb-1 small">
                                                    <span class="flex-grow-1 text-truncate" title="<?= h($md['name']) ?>"><?= h($md['name']) ?></span>
                                                    <span class="text-muted text-nowrap"><?= (int)$md['free'] ?> avail</span>
                                                    <input type="number"
                                                           name="quantities[<?= (int)$md['id'] ?>]"
                                                           class="form-control form-control-sm"
                                                           style="width: 64px;"
                                                           value="<?= min((int)$md['kit_qty'], (int)$md['free']) ?>"
                                                           min="0"
                                                           max="<?= (int)$md['free'] ?>"
                                                           <?= (int)$md['free'] <= 0 ? 'disabled' : '' ?>>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-outline-success w-100">Add selected to basket</button>
                                    <?php else: ?>
                                        <div class="alert alert-secondary small mb-0">
                                            <?= $windowActive ? 'No kits available for selected dates.' : 'No kits available right now.' ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-secondary w-100 mt-2" disabled>Add kit to basket</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; // end kits tab ?>
        </div>
    </div>
</div>

<div id="basket-toast"
     class="basket-toast"
     role="status"
     aria-live="polite"
     aria-hidden="true"></div>

<!-- AJAX add-to-basket + update basket count text -->
<script src="assets/slot-picker.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const loadingOverlay = document.getElementById('catalogue-loading');
    if (loadingOverlay) {
        loadingOverlay.classList.add('is-hidden');
        loadingOverlay.setAttribute('aria-busy', 'false');
    }
    const overdueAlert = document.getElementById('overdue-alert');
    const overdueList = document.getElementById('overdue-list');
    const overdueWarning = document.getElementById('overdue-warning');
    const catalogueContent = document.getElementById('catalogue-content');
    const viewBasketBtn = document.getElementById('view-basket-btn');
    const forms = document.querySelectorAll('.add-to-basket-form');
    const bookingInput = document.getElementById('booking_user_input');
    const bookingList  = document.getElementById('booking_user_suggestions');
    const bookingEmail = document.getElementById('booking_user_email');
    const bookingName  = document.getElementById('booking_user_name');
    const basketToast  = document.getElementById('basket-toast');
    const filterForm = document.getElementById('catalogue-filter-form');
    const categorySelect = filterForm ? filterForm.querySelector('select[name="category"]') : null;
    const sortSelect = filterForm ? filterForm.querySelector('select[name="sort"]') : null;
    let bookingTimer   = null;
    let bookingQuery   = '';
    let bookingActiveIndex = -1;
    let basketToastTimer = null;

    function showLoadingOverlay() {
        if (!loadingOverlay) return;
        loadingOverlay.classList.remove('is-hidden');
        loadingOverlay.setAttribute('aria-busy', 'true');
    }

    function pad(n) { return String(n).padStart(2, '0'); }
    function toDatetimeStr(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    var maxCheckoutHours = <?= json_encode($maxCheckoutHours) ?>;
    var intervalMinutes = <?= (int)(load_config()['app']['slot_interval_minutes'] ?? 15) ?>;
    var spOpts = {
        isStaff: <?= $isStaff ? 'true' : 'false' ?>,
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
        timeFormat: <?= json_encode(app_get_time_format()) ?>,
        dateFormat: <?= json_encode(app_get_date_format()) ?>
    };

    var equipEndManuallySet = false;
    var kitsEndManuallySet = false;

    function autoSetEnd(endPicker, datetime) {
        if (maxCheckoutHours > 0) {
            var ms = Date.parse(datetime);
            if (!isNaN(ms)) {
                endPicker.setValue(toDatetimeStr(new Date(ms + maxCheckoutHours * 3600000)));
            }
        } else {
            var parts = datetime.split('T')[0].split('-');
            var nextDay = new Date(parseInt(parts[0],10), parseInt(parts[1],10)-1, parseInt(parts[2],10)+1, 9, 0, 0);
            endPicker.setValue(toDatetimeStr(nextDay));
        }
    }

    function submitWindowForm(form) {
        showLoadingOverlay();
        form.submit();
    }

    // ---- Equipment tab slot pickers ----
    var equipForm = document.getElementById('catalogue-window-form');
    var equipStartHidden = document.getElementById('catalogue_start_datetime');
    var equipEndHidden = document.getElementById('catalogue_end_datetime');
    var equipStartPicker = null, equipEndPicker = null;

    if (document.getElementById('equip-start-picker')) {
        equipEndPicker = new SlotPicker(Object.assign({}, spOpts, {
            container: document.getElementById('equip-end-picker'),
            hiddenInput: equipEndHidden,
            type: 'end',
            intervalMinutes: intervalMinutes,
            onSelect: function () { equipEndManuallySet = true; if (equipStartHidden.value) submitWindowForm(equipForm); }
        }));
        equipStartPicker = new SlotPicker(Object.assign({}, spOpts, {
            container: document.getElementById('equip-start-picker'),
            hiddenInput: equipStartHidden,
            type: 'start',
            intervalMinutes: intervalMinutes,
            onSelect: function (dt) { if (!equipEndManuallySet) autoSetEnd(equipEndPicker, dt); }
        }));
        if (equipStartHidden.value) equipStartPicker.setValue(equipStartHidden.value);
        if (equipEndHidden.value) { equipEndPicker.setValue(equipEndHidden.value); equipEndManuallySet = true; }
    }

    // ---- Kits tab slot pickers ----
    var kitsForm = document.getElementById('kits-window-form');
    var kitsStartHidden = document.getElementById('kits_start_datetime');
    var kitsEndHidden = document.getElementById('kits_end_datetime');
    var kitsStartPicker = null, kitsEndPicker = null;

    if (document.getElementById('kits-start-picker')) {
        kitsEndPicker = new SlotPicker(Object.assign({}, spOpts, {
            container: document.getElementById('kits-end-picker'),
            hiddenInput: kitsEndHidden,
            type: 'end',
            intervalMinutes: intervalMinutes,
            onSelect: function () { kitsEndManuallySet = true; if (kitsStartHidden.value) submitWindowForm(kitsForm); }
        }));
        kitsStartPicker = new SlotPicker(Object.assign({}, spOpts, {
            container: document.getElementById('kits-start-picker'),
            hiddenInput: kitsStartHidden,
            type: 'start',
            intervalMinutes: intervalMinutes,
            onSelect: function (dt) { if (!kitsEndManuallySet) autoSetEnd(kitsEndPicker, dt); }
        }));
        if (kitsStartHidden.value) kitsStartPicker.setValue(kitsStartHidden.value);
        if (kitsEndHidden.value) { kitsEndPicker.setValue(kitsEndHidden.value); kitsEndManuallySet = true; }
    }

    // ---- Today buttons ----
    var todayBtn = document.getElementById('catalogue-today-btn');
    var kitsTodayBtn = document.getElementById('kits-today-btn');

    function handleToday(startPicker, endPicker, form, btn) {
        if (btn) btn.disabled = true;
        var params = 'next_open=1';
        if (startPicker.bypassClosed) params += '&bypass_closed=1';

        fetch('ajax_slot_data.php?' + params, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (btn) btn.disabled = false;
                if (data.error || !data.start) {
                    // Fallback: use current time
                    var now = new Date();
                    startPicker.setValue(toDatetimeStr(now));
                    autoSetEnd(endPicker, toDatetimeStr(now));
                } else {
                    startPicker.setValue(data.start);
                    if (data.end) {
                        endPicker.setValue(data.end);
                    } else {
                        autoSetEnd(endPicker, data.start);
                    }
                }
                submitWindowForm(form);
            })
            .catch(function () {
                if (btn) btn.disabled = false;
                // Fallback: use current time
                var now = new Date();
                startPicker.setValue(toDatetimeStr(now));
                autoSetEnd(endPicker, toDatetimeStr(now));
                submitWindowForm(form);
            });
    }

    if (todayBtn && equipStartPicker) {
        todayBtn.addEventListener('click', function () { equipEndManuallySet = false; handleToday(equipStartPicker, equipEndPicker, equipForm, todayBtn); });
    }
    if (kitsTodayBtn && kitsStartPicker) {
        kitsTodayBtn.addEventListener('click', function () { kitsEndManuallySet = false; handleToday(kitsStartPicker, kitsEndPicker, kitsForm, kitsTodayBtn); });
    }

    // ---- Bypass toggles ----
    function wireBypass(capId, closedId, startP, endP) {
        var cap = document.getElementById(capId);
        var closed = document.getElementById(closedId);
        if (cap && startP) {
            cap.addEventListener('change', function () {
                startP.setBypass('capacity', this.checked);
                endP.setBypass('capacity', this.checked);
            });
        }
        if (closed && startP) {
            closed.addEventListener('change', function () {
                startP.setBypass('closed', this.checked);
                endP.setBypass('closed', this.checked);
            });
        }
    }
    wireBypass('equip-bypass-capacity', 'equip-bypass-closed', equipStartPicker, equipEndPicker);
    wireBypass('kits-bypass-capacity', 'kits-bypass-closed', kitsStartPicker, kitsEndPicker);

    function applyOverdueBlock(items) {
        if (catalogueContent) {
            catalogueContent.classList.add('d-none');
        }
        if (overdueList) {
            overdueList.innerHTML = '';
            items.forEach(function (item) {
                const tag = item.tag || 'Unknown tag';
                const model = item.model || '';
                const due = item.due || '';
                let label = tag;
                if (model) {
                    label += ' (' + model + ')';
                }
                if (due) {
                    label += ' — due ' + due;
                }
                const li = document.createElement('li');
                li.textContent = label;
                overdueList.appendChild(li);
            });
        }
        if (overdueAlert) {
            overdueAlert.classList.remove('d-none');
        }
    }

    function showBasketToast(message) {
        if (!basketToast) return;
        basketToast.textContent = message;
        basketToast.setAttribute('aria-hidden', 'false');
        basketToast.classList.add('show');
        if (basketToastTimer) {
            clearTimeout(basketToastTimer);
        }
        basketToastTimer = setTimeout(function () {
            basketToast.classList.remove('show');
            basketToast.setAttribute('aria-hidden', 'true');
        }, 2200);
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function () {
            showLoadingOverlay();
        });
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            showLoadingOverlay();
            filterForm.submit();
        });
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            showLoadingOverlay();
            filterForm.submit();
        });
    }

    const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
    if (searchInput) {
        searchInput.addEventListener('blur', function () {
            if (!filterForm) return;
            const value = searchInput.value.trim();
            if (value === '' && searchInput.defaultValue.trim() === '') {
                return;
            }
            showLoadingOverlay();
            filterForm.submit();
        });
    }

    const overdueEnabled = document.body.dataset.catalogueOverdue === '1';
    if (overdueEnabled) {
        fetch('catalogue.php?ajax=overdue_check', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data) return;
                if (data.error && overdueWarning) {
                    overdueWarning.textContent = data.error;
                    overdueWarning.classList.remove('d-none');
                }
                if (data.blocked && Array.isArray(data.assets)) {
                    applyOverdueBlock(data.assets);
                }
            })
            .catch(function () {
                // Ignore overdue check failures; catalogue remains accessible.
            });
    }

    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    const ct = response.headers.get('Content-Type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        return response.json();
                    }
                    return null;
                })
                .then(function (data) {
                    if (!viewBasketBtn) return;

                    if (data && typeof data.basket_count !== 'undefined') {
                        const count = parseInt(data.basket_count, 10) || 0;
                        if (count > 0) {
                            viewBasketBtn.textContent = 'View basket (' + count + ')';
                        } else {
                            viewBasketBtn.textContent = 'View basket';
                        }
                        showBasketToast('Added to basket');
                    }
                })
                .catch(function () {
                // Fallback: if AJAX fails for any reason, do normal form submit
                form.submit();
            });
    });

    function hideBookingSuggestions() {
        if (!bookingList) return;
        bookingList.style.display = 'none';
        bookingList.innerHTML = '';
        if (bookingInput) bookingInput.setAttribute('aria-expanded', 'false');
        bookingActiveIndex = -1;
    }

    function renderBookingSuggestions(items) {
        if (!bookingList) return;
        bookingList.innerHTML = '';
        if (!items || !items.length) {
            hideBookingSuggestions();
            return;
        }
        items.forEach(function (item) {
            const email = item.email || '';
            const name = item.name || '';
            const label = (name && email && name !== email) ? (name + ' (' + email + ')') : (name || email);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action';
            btn.textContent = label;
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                bookingEmail.value = email;
                bookingName.value  = name || email;
                document.getElementById('booking_user_form').submit();
            });
            bookingList.appendChild(btn);
        });
        bookingList.style.display = 'block';
        if (bookingInput) bookingInput.setAttribute('aria-expanded', 'true');
        bookingActiveIndex = -1;
    }

    if (bookingInput && bookingList) {
        bookingInput.addEventListener('input', function () {
            const q = bookingInput.value.trim();
            if (q.length < 2) {
                hideBookingSuggestions();
                return;
            }
            if (bookingTimer) clearTimeout(bookingTimer);
            bookingTimer = setTimeout(function () {
                bookingQuery = q;
                fetch('catalogue.php?ajax=user_search&q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (bookingQuery !== q) return;
                        renderBookingSuggestions(data && data.results ? data.results : []);
                    })
                    .catch(function () {
                        hideBookingSuggestions();
                    });
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
            } else if (e.key === 'Enter' && bookingActiveIndex >= 0 && bookingActiveIndex < items.length) {
                e.preventDefault();
                items[bookingActiveIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                return;
            } else if (e.key === 'Escape') {
                hideBookingSuggestions();
                return;
            } else {
                return;
            }
            items.forEach(function (el, i) {
                el.classList.toggle('active', i === bookingActiveIndex);
            });
            items[bookingActiveIndex].scrollIntoView({ block: 'nearest' });
        });

        bookingInput.addEventListener('blur', function () {
            setTimeout(hideBookingSuggestions, 150);
        });
    }

    if (filterForm && categorySelect) {
        categorySelect.addEventListener('change', function () {
            filterForm.submit();
        });
    }

    if (filterForm && sortSelect) {
        sortSelect.addEventListener('change', function () {
            filterForm.submit();
        });
    }
});

function clearBookingUser() {
    const email = document.getElementById('booking_user_email');
    const name  = document.getElementById('booking_user_name');
    const input = document.getElementById('booking_user_input');
    if (email) email.value = '';
    if (name) name.value = '';
    if (input) input.value = '';
}

function revertToLoggedIn(e) {
    if (e) e.preventDefault();
    const email = document.getElementById('booking_user_email');
    const name  = document.getElementById('booking_user_name');
    const input = document.getElementById('booking_user_input');
    const form  = document.getElementById('booking_user_form');
    if (email) email.value = '';
    if (name) name.value = '';
    if (input) input.value = '';
    // Submit form via hidden revert button to mirror normal submit
    const revertBtn = document.querySelector('button[name="booking_user_revert"]');
    if (revertBtn) {
        revertBtn.click();
    } else if (form) {
        form.submit();
    }
}
});
</script>
<?php if ($isStaff) { layout_model_history_modal(); } ?>
<?php layout_footer(); ?>
</body>
</html>
