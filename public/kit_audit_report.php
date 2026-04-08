<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$active    = basename($_SERVER['PHP_SELF']);
$isAdmin   = !empty($currentUser['is_admin']);
$isStaff   = !empty($currentUser['is_staff']) || $isAdmin;
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'kit_audit_report.php';
$baseQuery = $embedded ? ['tab' => 'kit_audit'] : [];

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Filters
$search     = trim($_GET['q'] ?? '');
$kitTypeFilter = trim($_GET['kit_type'] ?? '');
$kitTypeValid  = in_array($kitTypeFilter, ['functional', 'convenience'], true) ? $kitTypeFilter : '';

$error = '';
$grouped = []; // checkout_id => [ 'checkout' => ..., 'kits' => [ kit_id => [...] ] ]

try {
    // Get all open/partial checkouts with kit-based reservation items that are short
    $sql = "
        SELECT
            c.id AS checkout_id, c.user_name, c.user_email, c.start_datetime,
            ri.kit_id, ri.kit_name_cache, ri.model_id, ri.model_name_cache, ri.quantity AS expected_qty,
            COALESCE(ci_count.actual_qty, 0) AS actual_qty
        FROM checkouts c
        JOIN reservation_items ri ON ri.reservation_id = c.reservation_id
            AND ri.kit_id IS NOT NULL AND ri.deleted_at IS NULL
        LEFT JOIN (
            SELECT checkout_id, model_id, COUNT(*) AS actual_qty
            FROM checkout_items
            WHERE checked_in_at IS NULL
            GROUP BY checkout_id, model_id
        ) ci_count ON ci_count.checkout_id = c.id AND ci_count.model_id = ri.model_id
        WHERE c.status IN ('open', 'partial')
            AND c.reservation_id IS NOT NULL
        HAVING actual_qty < expected_qty
        ORDER BY c.start_datetime
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collect unique kit IDs for type classification
    $kitIds = [];
    foreach ($rawRows as $row) {
        $kitIds[(int)$row['kit_id']] = true;
    }

    // Determine kit types (functional vs convenience)
    $config = load_config();
    $hideFieldName = trim((string)($config['catalogue']['hide_field_name'] ?? ''));
    $kitTypes = []; // kit_id => 'functional' | 'convenience'

    foreach (array_keys($kitIds) as $kitId) {
        try {
            $kitModels = get_kit_models($kitId);
            $kitModelIds = array_map(function ($m) { return (int)($m['id'] ?? 0); }, $kitModels);
            $kitModelIds = array_filter($kitModelIds, function ($id) { return $id > 0; });

            if (empty($kitModelIds) || $hideFieldName === '') {
                $kitTypes[$kitId] = 'convenience';
                continue;
            }

            $stats = prefetch_catalogue_model_stats($kitModelIds);
            $isFunctional = false;
            foreach ($kitModelIds as $mid) {
                if (!empty($stats[$mid]['hidden'])) {
                    $isFunctional = true;
                    break;
                }
            }
            $kitTypes[$kitId] = $isFunctional ? 'functional' : 'convenience';
        } catch (Throwable $e) {
            $kitTypes[$kitId] = 'convenience';
        }
    }

    // Group results by checkout, then by kit
    foreach ($rawRows as $row) {
        $coId  = (int)$row['checkout_id'];
        $kitId = (int)$row['kit_id'];
        $kitType = $kitTypes[$kitId] ?? 'convenience';

        // Apply kit type filter
        if ($kitTypeValid !== '' && $kitType !== $kitTypeValid) {
            continue;
        }

        // Apply search filter
        if ($search !== '') {
            $q = mb_strtolower($search);
            $matchFields = [
                $row['user_name'] ?? '',
                $row['user_email'] ?? '',
                $row['kit_name_cache'] ?? '',
                $row['model_name_cache'] ?? '',
            ];
            $match = false;
            foreach ($matchFields as $f) {
                if (mb_stripos($f, $q) !== false) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
        }

        if (!isset($grouped[$coId])) {
            $grouped[$coId] = [
                'checkout' => [
                    'id'         => $coId,
                    'user_name'  => $row['user_name'],
                    'user_email' => $row['user_email'],
                    'start'      => $row['start_datetime'],
                ],
                'kits' => [],
            ];
        }
        if (!isset($grouped[$coId]['kits'][$kitId])) {
            $grouped[$coId]['kits'][$kitId] = [
                'kit_name' => $row['kit_name_cache'] ?? ('Kit #' . $kitId),
                'kit_type' => $kitType,
                'models'   => [],
            ];
        }
        $grouped[$coId]['kits'][$kitId]['models'][] = [
            'model_name'   => $row['model_name_cache'] ?? '',
            'expected_qty' => (int)$row['expected_qty'],
            'actual_qty'   => (int)$row['actual_qty'],
        ];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function kit_audit_build_url(string $base, array $params): string
{
    $query = http_build_query($params);
    return $query === '' ? $base : ($base . '?' . $query);
}
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kit Audit Report</title>
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
            <h1>Kit Audit</h1>
            <div class="page-subtitle">
                Active checkouts with partially tracked kits.
            </div>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
<?php endif; ?>

    <div class="border rounded-3 p-4 mb-4">
        <form method="get" class="row g-2 mb-0 align-items-end" action="<?= h($pageBase) ?>">
            <?php foreach ($baseQuery as $k => $v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
            <?php endforeach; ?>
            <div class="col-md-4">
                <label class="form-label mb-1">Search</label>
                <input type="text" name="q" value="<?= h($search) ?>"
                       class="form-control" placeholder="User name, kit name, model...">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Kit type</label>
                <select name="kit_type" class="form-select">
                    <option value="">All</option>
                    <option value="functional" <?= $kitTypeValid === 'functional' ? 'selected' : '' ?>>Functional only</option>
                    <option value="convenience" <?= $kitTypeValid === 'convenience' ? 'selected' : '' ?>>Convenience only</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="<?= h(kit_audit_build_url($pageBase, $baseQuery)) ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php elseif (empty($grouped)): ?>
        <div class="alert alert-secondary">No incomplete kit checkouts found.</div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted small"><?= count($grouped) ?> checkout<?= count($grouped) !== 1 ? 's' : '' ?> with incomplete kits</span>
        </div>

        <?php foreach ($grouped as $coId => $entry): ?>
            <?php $co = $entry['checkout']; ?>
            <div class="card mb-3">
                <div class="card-header">
                    <strong>Checkout #<?= (int)$co['id'] ?></strong>
                    &mdash; <?= h($co['user_name']) ?>
                    <span class="text-muted small ms-2">(checked out <?= h(app_format_datetime_local($co['start'])) ?>)</span>
                </div>
                <div class="card-body p-0">
                    <?php foreach ($entry['kits'] as $kitId => $kit): ?>
                        <div class="px-3 py-2 <?= $kitId !== array_key_last($entry['kits']) ? 'border-bottom' : '' ?>">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <strong><?= h($kit['kit_name']) ?></strong>
                                <?php if ($kit['kit_type'] === 'functional'): ?>
                                    <span class="badge bg-danger text-white">Functional</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Convenience</span>
                                <?php endif; ?>
                            </div>
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <?php foreach ($kit['models'] as $model): ?>
                                        <?php $missing = $model['expected_qty'] - $model['actual_qty']; ?>
                                        <tr>
                                            <td class="ps-3"><?= h($model['model_name']) ?></td>
                                            <td>
                                                <?= (int)$model['actual_qty'] ?> of <?= (int)$model['expected_qty'] ?> checked out
                                            </td>
                                            <td>
                                                <span class="badge bg-danger">MISSING <?= $missing ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
