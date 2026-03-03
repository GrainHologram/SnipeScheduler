<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/overdue_report.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config      = load_config();
$appName     = $config['app']['name'] ?? 'SnipeScheduler';
$groupByUser = ($_GET['group'] ?? '') === 'user';
$error       = '';
$rows        = [];

try {
    $assets = list_checked_out_assets(true);
    $rows   = build_overdue_report_rows($assets);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalCount = count($rows);
$generated  = app_format_datetime_local(date('Y-m-d H:i:s'));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overdue Asset Report – <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell no-print">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Overdue Asset Report</h1>
            <div class="page-subtitle">
                Assets past their expected return date.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
    </div>

    <!-- Print-only header -->
    <div class="print-only print-report-header">
        <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
        <h2>Overdue Asset Report</h2>
        <p>Generated: <?= htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') ?> | Total: <?= $totalCount ?> overdue item<?= $totalCount !== 1 ? 's' : '' ?></p>
    </div>

    <!-- Toolbar (screen only) -->
    <div class="no-print d-flex flex-wrap gap-2 align-items-center my-3">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
            Print / Save PDF
        </button>
        <?php
            $toggleUrl = 'overdue_report.php' . ($groupByUser ? '' : '?group=user');
            $toggleLabel = $groupByUser ? 'Flat view' : 'Group by user';
        ?>
        <a href="<?= htmlspecialchars($toggleUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
            <?= htmlspecialchars($toggleLabel, ENT_QUOTES, 'UTF-8') ?>
        </a>
        <span class="text-muted small ms-auto">
            <?= $totalCount ?> overdue item<?= $totalCount !== 1 ? 's' : '' ?>
            | Generated <?= htmlspecialchars($generated, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (empty($rows)): ?>
        <div class="alert alert-success">No overdue items. All assets are within their expected return dates.</div>
    <?php else: ?>
        <div class="overdue-report-body">
            <?= render_overdue_report_html($rows, [
                'context'       => 'web',
                'group_by_user' => $groupByUser,
            ]) ?>
        </div>
    <?php endif; ?>
</div>

<?php layout_footer(); ?>
</body>
</html>
