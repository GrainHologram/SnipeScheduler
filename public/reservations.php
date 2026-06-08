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
$tabSubtitles = [
    'today'            => 'Upcoming Reservations',
    'checked_out'      => 'Checked Out Items',
    'history'          => 'Reservation History',
    'checkout_history' => 'Checkout History',
    'unmatched'        => 'Unmatched Checkins',
    'kit_audit'        => 'Kit Audit',
];

layout_page_start([
    'active'             => $active,
    'title'              => 'Reservations',
    'subtitle'           => $tabSubtitles[$tab] ?? '',
    'bodyClass'          => 'p-4 page-reservations',
    'pageHeaderTitle'    => 'Reservations',
    'pageHeaderSubtitle' => "Manage reservation history, today's checkouts, and checked-out assets from one place.",
    'bypassEmbedCheck'   => true,
]);
?>

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
<?php
layout_page_end([
    'withCheckoutOverlay'   => true,
    'withModelHistoryModal' => true,
    'bypassEmbedCheck'      => true,
]);
?>
