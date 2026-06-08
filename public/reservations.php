<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$allowedTabs = ['today', 'checked_out', 'history', 'checkout_history'];
if ($isAdmin) {
    $allowedTabs[] = 'unmatched';
    $allowedTabs[] = 'kit_audit';
}
$tab         = $_GET['tab'] ?? 'today';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'today';
}

$tabMap = [
    'today'            => __DIR__ . '/staff_checkout.php',
    'checked_out'      => __DIR__ . '/checked_out_assets.php',
    'history'          => __DIR__ . '/staff_reservations.php',
    'checkout_history' => __DIR__ . '/checkout_history.php',
    'unmatched'        => __DIR__ . '/unmatched_checkins_report.php',
    'kit_audit'        => __DIR__ . '/kit_audit_report.php',
];

if (!defined('RESERVATIONS_EMBED')) {
    define('RESERVATIONS_EMBED', true);
}

$tabFile = $tabMap[$tab] ?? null;
if (!$tabFile || !is_file($tabFile)) {
    $tabContent = '<div class="alert alert-danger mb-0">Tab content unavailable.</div>';
} else {
    ob_start();
    include $tabFile;
    $tabContent = ob_get_clean();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservations</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= layout_stylesheet_url() ?>">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4 page-reservations">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Reservations</h1>
            <div class="page-subtitle">
                Manage reservation history, today's checkouts, and checked-out assets from one place.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?php
        $tabSubtitles = [
            'today'            => 'Upcoming Reservations',
            'checked_out'      => 'Checked Out Items',
            'history'          => 'Reservation History',
            'checkout_history' => 'Checkout History',
            'unmatched'        => 'Unmatched Checkins',
            'kit_audit'        => 'Kit Audit',
        ];
        ?>
        <?= layout_render_topbar($active, $tabSubtitles[$tab] ?? '') ?>

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
                <a class="nav-link <?= $tab === 'today' ? 'active' : '' ?>"
                   href="reservations.php?tab=today">Upcoming Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="reservations.php?tab=checked_out">Checked Out Items</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>"
                   href="reservations.php?tab=history">Reservation History</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checkout_history' ? 'active' : '' ?>"
                   href="reservations.php?tab=checkout_history">Checkout History</a>
            </li>
            <?php if ($isAdmin): ?>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'unmatched' ? 'active' : '' ?>"
                   href="reservations.php?tab=unmatched">Unmatched Checkins</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'kit_audit' ? 'active' : '' ?>"
                   href="reservations.php?tab=kit_audit">Kit Audit</a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content">
            <?= $tabContent ?>
        </div>
    </div>
</div>
<?php layout_checkout_loading_overlay(); ?>
<?php layout_model_history_modal(true); ?>
<?php layout_footer(); ?>
</body>
</html>
