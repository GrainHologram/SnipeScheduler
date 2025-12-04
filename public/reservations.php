<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/footer.php';

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$allowedTabs = ['today', 'checked_out', 'history'];
$tab         = $_GET['tab'] ?? 'today';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'today';
}

/**
 * Render the given reservations tab by embedding the existing page content.
 */
function render_reservations_tab(string $tab): string
{
    $map = [
        'today'       => __DIR__ . '/staff_checkout.php',
        'checked_out' => __DIR__ . '/checked_out_assets.php',
        'history'     => __DIR__ . '/staff_reservations.php',
    ];

    $file = $map[$tab] ?? null;
    if (!$file || !is_file($file)) {
        return '<div class="alert alert-danger mb-0">Tab content unavailable.</div>';
    }

    if (!defined('RESERVATIONS_EMBED')) {
        define('RESERVATIONS_EMBED', true);
    }

    ob_start();
    include $file;
    return ob_get_clean();
}

$tabContent = render_reservations_tab($tab);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reservations</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Reservations</h1>
            <div class="page-subtitle">
                Manage reservation history, today’s checkouts, and checked-out assets from one place.
            </div>
        </div>

        <?= reserveit_render_nav($active, $isStaff) ?>

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

        <ul class="nav nav-tabs">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'today' ? 'active' : '' ?>"
                   href="reservations.php?tab=today">Today’s Reservations (Checkout)</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="reservations.php?tab=checked_out">Checked Out Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>"
                   href="reservations.php?tab=history">Reservation History</a>
            </li>
        </ul>

        <div class="tab-content border border-top-0 p-3 bg-white">
            <?= $tabContent ?>
        </div>
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
