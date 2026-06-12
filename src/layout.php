<?php
// layout.php
// Shared layout helpers (nav, logo, theme, footer) for SnipeScheduler pages.

require_once __DIR__ . '/bootstrap.php';

/**
 * Cache config and expose helper functions for shared UI elements.
 */
if (!function_exists('layout_cached_config')) {
    function layout_cached_config(?array $cfg = null): array
    {
        static $cachedConfig = null;

        if ($cfg !== null) {
            return $cfg;
        }

        if ($cachedConfig === null) {
            try {
                $cachedConfig = load_config();
            } catch (Throwable $e) {
                $cachedConfig = [];
            }
        }

        return $cachedConfig ?? [];
    }
}

/**
 * Normalize a hex color string to #rrggbb.
 */
if (!function_exists('layout_normalize_hex_color')) {
    function layout_normalize_hex_color(?string $color, string $fallback): string
    {
        $fallback = ltrim($fallback, '#');
        $candidate = trim((string)$color);

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
        } elseif (preg_match('/^#?([0-9a-fA-F]{3})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } else {
            $hex = strtolower($fallback);
        }

        return '#' . $hex;
    }
}

/**
 * Convert #rrggbb to [r, g, b].
 */
if (!function_exists('layout_color_to_rgb')) {
    function layout_color_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

/**
 * Adjust lightness: positive to lighten, negative to darken.
 */
if (!function_exists('layout_adjust_lightness')) {
    function layout_adjust_lightness(string $hex, float $ratio): string
    {
        $ratio = max(-1.0, min(1.0, $ratio));
        [$r, $g, $b] = layout_color_to_rgb($hex);

        $adjust = static function (int $channel) use ($ratio): int {
            if ($ratio >= 0) {
                return (int)round($channel + (255 - $channel) * $ratio);
            }
            return (int)round($channel * (1 + $ratio));
        };

        $nr = str_pad(dechex($adjust($r)), 2, '0', STR_PAD_LEFT);
        $ng = str_pad(dechex($adjust($g)), 2, '0', STR_PAD_LEFT);
        $nb = str_pad(dechex($adjust($b)), 2, '0', STR_PAD_LEFT);

        return '#' . $nr . $ng . $nb;
    }
}

if (!function_exists('layout_asset_version')) {
    /**
     * Returns the current git commit hash (7 chars) used as a cache-busting query param.
     * Returns '' when the git HEAD file can't be read.
     */
    function layout_asset_version(): string
    {
        static $hash = null;
        if ($hash !== null) {
            return $hash;
        }
        $hash = '';
        $headFile = APP_ROOT . '/.git/HEAD';
        if (is_file($headFile)) {
            $head = trim((string)@file_get_contents($headFile));
            if (str_starts_with($head, 'ref: ')) {
                $refPath = APP_ROOT . '/.git/' . substr($head, 5);
                if (is_file($refPath)) {
                    $hash = substr(trim((string)@file_get_contents($refPath)), 0, 7);
                }
            } else {
                $hash = substr($head, 0, 7);
            }
        }
        return $hash;
    }
}

if (!function_exists('layout_stylesheet_url')) {
    /**
     * Return the stylesheet URL with a cache-busting version param.
     * Usage in page templates: <link rel="stylesheet" href="<?= layout_stylesheet_url() ?>">
     */
    function layout_stylesheet_url(?array $cfg = null): string
    {
        $v = layout_asset_version();
        return 'assets/style.css' . ($v !== '' ? '?v=' . $v : '');
    }
}

if (!function_exists('layout_primary_color')) {
    function layout_primary_color(?array $cfg = null): string
    {
        $config = layout_cached_config($cfg);
        $raw    = $config['app']['primary_color'] ?? '#660000';

        return layout_normalize_hex_color($raw, '#660000');
    }
}

if (!function_exists('layout_theme_styles')) {
    function layout_theme_styles(?array $cfg = null): string
    {
        $primary      = layout_primary_color($cfg);
        $primarySoft  = layout_adjust_lightness($primary, 0.3);   // subtle gradient partner
        $primaryStrong = layout_adjust_lightness($primary, -0.08); // slightly deeper for contrast

        [$r, $g, $b]          = layout_color_to_rgb($primary);
        [$rs, $gs, $bs]       = layout_color_to_rgb($primaryStrong);
        [$rl, $gl, $bl]       = layout_color_to_rgb($primarySoft);

        $style = <<<CSS
<style>
:root {
    --primary: {$primary};
    --primary-strong: {$primaryStrong};
    --primary-soft: {$primarySoft};
    --primary-rgb: {$r}, {$g}, {$b};
    --primary-strong-rgb: {$rs}, {$gs}, {$bs};
    --primary-soft-rgb: {$rl}, {$gl}, {$bl};
    --accent: var(--primary-strong);
    --accent-2: var(--primary-soft);
}
</style>
CSS;

        $style .= "\n" . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">';

        return $style;
    }
}

if (!function_exists('layout_render_nav')) {
    /**
     * Render the main app navigation. Highlights the active page and hides staff-only items for non-staff users.
     */
    function layout_render_nav(string $active, bool $isStaff, bool $isAdmin = false): string
    {
        $cfg = load_config();
        $quickCheckoutEnabled = $cfg['app']['quick_checkout_enabled'] ?? true;

        $currentTab   = $_GET['tab'] ?? 'kits';

        $links = [
            ['type' => 'header', 'label' => 'Overview',    'staff' => false],
            ['href' => 'index.php',       'label' => 'Dashboard', 'staff' => false, 'icon' => 'bi-grid-fill'],
            ['href' => 'my_bookings.php', 'label' => 'My Gear',   'staff' => false, 'icon' => 'bi-calendar-check'],
            ['href' => 'reservations.php',            'label' => 'Reservations',      'staff' => true,  'icon' => 'bi-calendar3'],

            ['type' => 'header', 'label' => 'Catalogue',   'staff' => false],
            ['href' => 'catalogue.php?tab=equipment&prefetch=1', 'label' => 'Equipment', 'staff' => false, 'tab' => 'equipment', 'icon' => 'bi-camera-video'],
            ['href' => 'catalogue.php?tab=kits&prefetch=1',      'label' => 'Kits',      'staff' => false, 'tab' => 'kits',      'icon' => 'bi-collection'],

            ['type' => 'header', 'label' => 'Processing',  'staff' => true],
            ['href' => 'quick_checkout.php', 'label' => 'Quick Checkout', 'staff' => true, 'enabled' => $quickCheckoutEnabled, 'icon' => 'bi-box-arrow-right'],
            ['href' => 'quick_checkin.php',  'label' => 'Quick Checkin',  'staff' => true,  'icon' => 'bi-box-arrow-in-left'],

            ['type' => 'header', 'label' => 'Admin',       'staff' => false, 'admin_only' => true],
            ['href' => 'print_label.php',    'label' => 'Print Label',    'staff' => false, 'admin_only' => true, 'icon' => 'bi-printer'],
            ['href' => 'activity_log.php',   'label' => 'Admin',          'staff' => false, 'admin_only' => true, 'icon' => 'bi-gear'],
        ];

        $html = '<nav id="app-nav" class="app-nav" aria-label="Main navigation">'
              . '<button class="app-nav-hamburger" type="button" aria-label="Expand navigation" aria-expanded="false" aria-controls="app-nav"><i class="bi bi-list" aria-hidden="true"></i></button>'
              . '<a href="index.php" class="app-nav-brand">Wrap It<span class="app-nav-alpha-tag">Alpha</span></a>';
        foreach ($links as $link) {
            if (isset($link['enabled']) && !$link['enabled']) {
                continue;
            }
            if (!empty($link['admin_only'])) {
                if (!$isAdmin) {
                    continue;
                }
            } elseif (!empty($link['staff']) && !$isStaff) {
                continue;
            }

            $label = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');

            if (isset($link['type']) && $link['type'] === 'header') {
                $html .= '<span class="app-nav-header">' . $label . '</span>';
                continue;
            }

            // Determine active state — for links with a tab param, match against current tab too
            $isActive = $active === $link['href'];
            if (!$isActive && isset($link['tab'])) {
                $isActive = $active === 'catalogue.php' && $currentTab === $link['tab'];
            }

            $href      = htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8');
            $classes   = 'app-nav-link' . ($isActive ? ' active' : '') . (!empty($link['class']) ? ' ' . $link['class'] : '');
            $style     = !empty($link['right']) ? ' style="margin-left:auto"' : '';
            $onclick   = !empty($link['onclick']) ? ' onclick="' . htmlspecialchars($link['onclick'], ENT_QUOTES, 'UTF-8') . '"' : '';
            $icon      = !empty($link['icon']) ? '<i class="bi ' . htmlspecialchars($link['icon'], ENT_QUOTES, 'UTF-8') . ' app-nav-icon" aria-hidden="true"></i>' : '';
            $ariaLabel = ' aria-label="' . $label . '" title="' . $label . '"';
            $html .= '<a href="' . $href . '" class="' . $classes . '"' . $style . $onclick . $ariaLabel . '>' . $icon . '<span class="app-nav-label">' . $label . '</span></a>';
        }
        $html .= '<a href="feedback_submit.php" class="app-nav-feedback-glyph" aria-label="Feedback" title="Submit Feedback"><i class="bi bi-chat-left-dots" aria-hidden="true"></i></a>';

        $user      = $_SESSION['user'] ?? [];
        $firstName = $user['first_name'] ?? '';
        $lastName  = $user['last_name'] ?? '';
        $fullName  = htmlspecialchars(trim($firstName . ' ' . $lastName), ENT_QUOTES, 'UTF-8');
        $email     = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');

        $html .= '<button type="button" class="app-nav-user" id="accountPanelTrigger"'
               . ' aria-label="Account settings for ' . $fullName . '"'
               . ' aria-haspopup="dialog" aria-controls="accountPanel" aria-expanded="false">'
               . '<span class="app-nav-user-name">' . $fullName . '</span>'
               . '<span class="app-nav-user-email">' . $email . '</span>'
               . '</button>';

        $html .= '</nav>';

        $html .= '<div class="account-panel-backdrop" id="accountPanelBackdrop" aria-hidden="true"></div>'
               . '<aside class="account-panel" id="accountPanel" role="dialog" aria-modal="true" aria-label="My Account">'
               . '<div class="account-panel-header">'
               . '<span class="account-panel-title">My Account</span>'
               . '<button type="button" class="account-panel-close" id="accountPanelClose" aria-label="Close account panel">'
               . '<i class="bi bi-x-lg" aria-hidden="true"></i>'
               . '</button>'
               . '</div>'
               . '<div class="account-panel-body" id="accountPanelBody"></div>'
               . '<div class="account-panel-footer">'
               . '<a href="logout.php" class="btn btn-outline-danger btn-sm w-100">Log out</a>'
               . '</div>'
               . '</aside>';

        $html .= layout_discord_link_banner();

        return $html;
    }
}

if (!function_exists('layout_render_topbar')) {
    /**
     * Render the fixed top bar showing the current page title.
     */
    function layout_render_topbar(string $active, string $subtitle = ''): string
    {
        $titles = [
            'index.php'              => 'Dashboard',
            'catalogue.php'          => 'Catalogue',
            'reservations.php'       => 'Reservations',
            'quick_checkout.php'     => 'Quick Checkout',
            'quick_checkin.php'      => 'Quick Checkin',
            'print_label.php'        => 'Print Label',
            'activity_log.php'       => 'Admin',
            'my_bookings.php'        => 'My Gear',
            'basket.php'             => 'Basket',
            'reservation_detail.php' => 'Reservation Detail',
            'reservation_edit.php'   => 'Edit Reservation',
            'settings.php'           => 'Settings',
            'checkout_history.php'   => 'Checkout History',
            'checked_out_assets.php' => 'Checked Out Assets',
            'feedback.php'           => 'Feedback',
            'opening_hours.php'      => 'Opening Hours',
            'overdue_report.php'     => 'Overdue Report',
            'staff_checkout.php'     => 'Staff Checkout',
            'staff_reservations.php'       => 'Staff Reservations',
            'purchase_request_submit.php'  => 'Purchase Requests',
            'my_account.php'               => 'My Account',
            'feedback_submit.php'          => 'Feedback',
        ];

        $title = $titles[$active] ?? '';
        $html  = '<div class="app-topbar">';
        $html .= '<button class="app-topbar-hamburger" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-nav"><i class="bi bi-list" aria-hidden="true"></i></button>';
        $html .= '<span class="app-topbar-crumbs" id="app-topbar-crumbs">';
        $html .= '<span class="app-topbar-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($subtitle !== '') {
            $html .= '<span class="app-topbar-sep" aria-hidden="true">›</span>';
            $html .= '<span class="app-topbar-subtitle" id="app-topbar-crumb-1">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '</span>';

        if ($active === 'catalogue.php') {
            $html .= '<a href="basket.php" class="app-topbar-basket"><i class="bi bi-basket" aria-hidden="true"></i> View Basket</a>';
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('layout_discord_link_banner')) {
    /**
     * Render a dismissible banner prompting unlinked users to connect their Discord account.
     */
    function layout_discord_link_banner(): string
    {
        try {
            $cfg = load_config();
        } catch (\Throwable $e) {
            return '';
        }

        $botCfg = $cfg['discord_bot'] ?? [];
        if (empty($botCfg['dm_enabled']) || empty($botCfg['bot_token'])) {
            return '';
        }
        if (trim($botCfg['oauth_client_id'] ?? '') === '' || trim($botCfg['oauth_client_secret'] ?? '') === '') {
            return '';
        }

        if (!empty($_SESSION['user']['discord_user_id'])) {
            return '';
        }

        $userId = (int)($_SESSION['user']['id'] ?? 0);
        $storageKey = 'discord_banner_dismissed_' . $userId;

        return '<div id="discordLinkBanner" class="alert alert-info alert-dismissible fade show mb-0" role="alert" style="border-radius:0; display:none;">'
            . 'Link your Discord account to receive instant notifications. '
            . '<a href="my_account.php" class="alert-link">Go to My Account</a>'
            . '<button type="button" class="btn-close" id="discordBannerDismiss" aria-label="Close"></button>'
            . '</div>'
            . '<script>'
            . '(function(){'
            . 'var k=' . json_encode($storageKey) . ';'
            . 'var b=document.getElementById("discordLinkBanner");'
            . 'if(!b)return;'
            . 'if(localStorage.getItem(k))return;'
            . 'b.style.display="";'
            . 'document.getElementById("discordBannerDismiss").addEventListener("click",function(){'
            . 'localStorage.setItem(k,"1");b.style.display="none";'
            . '});'
            . '})();'
            . '</script>';
    }
}

if (!function_exists('layout_status_badge')) {
    /**
     * Render a reservation status as a styled Bootstrap badge.
     */
    function layout_status_badge(string $status): string
    {
        $status = strtolower(trim($status));
        $labels = [
            'pending'     => 'Pending',
            'confirmed'   => 'Confirmed',
            'fulfilled'   => 'Fulfilled',
            'cancelled'   => 'Cancelled',
            'missed'      => 'Missed',
        ];
        $classes = [
            'pending'     => 'bg-warning text-dark',
            'confirmed'   => 'bg-info text-dark',
            'fulfilled'   => 'bg-success',
            'cancelled'   => 'bg-secondary',
            'missed'      => 'bg-danger',
        ];
        $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
        $class = $classes[$status] ?? 'bg-secondary';
        return '<span class="badge ' . $class . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

if (!function_exists('layout_checkout_status_badge')) {
    /**
     * Render a checkout status as a styled Bootstrap badge.
     */
    function layout_checkout_status_badge(string $status): string
    {
        $status = strtolower(trim($status));
        $labels = [
            'open'    => 'Checked Out',
            'partial' => 'Partial Return',
            'closed'  => 'Returned',
        ];
        $classes = [
            'open'    => 'status-badge-checked-out',
            'partial' => 'bg-warning text-dark',
            'closed'  => 'bg-success',
        ];
        $label = $labels[$status] ?? ucfirst($status);
        $class = $classes[$status] ?? 'bg-secondary';
        return '<span class="badge ' . $class . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

if (!function_exists('layout_footer')) {
    function layout_footer(): void
    {
        $versionFile = APP_ROOT . '/version.txt';
        $versionRaw  = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : '';
        $version     = $versionRaw !== '' ? $versionRaw : 'dev';
        $versionEsc  = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');

        $commitHash   = layout_asset_version();
        $vSuffix      = $commitHash !== '' ? '?v=' . $commitHash : '';
        $commitSuffix = $commitHash !== '' ? ' (' . $commitHash . ')' : '';

        echo '<script src="assets/nav.js' . $vSuffix . '"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>';
        echo '<script src="assets/datetime-picker.js' . $vSuffix . '"></script>';

        // QZ Tray receipt printing (staff only)
        $qzConfig = load_config()['qz_tray'] ?? [];
        if (!empty($qzConfig['enabled']) && (!empty($_SESSION['user']['is_staff']) || !empty($_SESSION['user']['is_admin']))) {
            echo '<script src="https://cdn.jsdelivr.net/npm/qz-tray@2/qz-tray.js"></script>';
            echo '<script src="assets/qz-print.js' . $vSuffix . '"></script>';
            echo '<script>SnipePrint.init(' . json_encode([
                'connectionType' => $qzConfig['connection_type'] ?? 'usb',
                'printerName'    => $qzConfig['printer_name'] ?? '',
                'usbVendorId'    => $qzConfig['usb_vendor_id'] ?? '',
                'usbProductId'   => $qzConfig['usb_product_id'] ?? '',
                'usbInterface'   => $qzConfig['usb_interface'] ?? '0x00',
                'usbEndpoint'    => $qzConfig['usb_endpoint'] ?? '0x01',
                'certUrl'        => 'ajax_qz_cert.php',
                'paperWidth'     => (int)($qzConfig['paper_width'] ?? 42),
            ]) . ');</script>';
        }

        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        if (in_array($currentPage, ['index.php', 'activity_log.php'], true)) {
            echo '<footer class="text-center text-muted mt-4 small">'
                . 'SnipeScheduler Version ' . $versionEsc . $commitSuffix . ' - Created by '
                . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
                . '</footer>';
        }

        // Render active announcements
        layout_announcements();
    }
}

if (!function_exists('layout_announcements')) {
    function layout_announcements(): void
    {
        global $pdo;
        if (!isset($pdo)) {
            return;
        }

        $isStaff = !empty($_SESSION['user']['is_staff']) || !empty($_SESSION['user']['is_admin']);
        $isAdmin = !empty($_SESSION['user']['is_admin']);

        // Determine which audiences this user can see
        $audiences = ["'all'"];
        if ($isStaff) {
            $audiences[] = "'staff'";
        }
        if ($isAdmin) {
            $audiences[] = "'admin'";
        }
        $audienceIn = implode(',', $audiences);

        try {
            $stmt = $pdo->prepare("
                SELECT id, title, body
                  FROM announcements
                 WHERE start_datetime <= NOW()
                   AND end_datetime > NOW()
                   AND audience IN ({$audienceIn})
                 ORDER BY created_at DESC
            ");
            $stmt->execute();
            $announcements = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return;
        }

        if (empty($announcements)) {
            return;
        }

        // Encode announcements as JSON for JS rendering
        $jsData = [];
        foreach ($announcements as $a) {
            $jsData[] = [
                'id'    => (int)$a['id'],
                'title' => $a['title'],
                'body'  => $a['body'],
            ];
        }
        ?>
<div id="announcementBackdrop" style="display:none; position:fixed; inset:0; background:var(--backdrop-modal); z-index:1050;" onclick="dismissAnnouncement()"></div>
<div id="announcementModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)dismissAnnouncement()">
    <div style="max-width:550px; margin:0 auto; background:var(--panel); border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(var(--black-rgb), 0.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid var(--border);">
            <h5 style="margin:0;" id="announcementTitle"></h5>
            <button type="button" onclick="dismissAnnouncement()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div style="padding:1rem;" id="announcementBody"></div>
        <div style="display:flex; justify-content:space-between; align-items:center; padding:.75rem 1rem; border-top:1px solid var(--border);">
            <span class="text-muted small" id="announcementCounter"></span>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="announcementNextBtn" onclick="nextAnnouncement()" style="display:none;">Next</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="dismissAnnouncement()">Got it</button>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var announcements = <?= json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var currentIdx = -1;
    var undismissed = [];

    // Filter to announcements not yet dismissed
    for (var i = 0; i < announcements.length; i++) {
        var key = 'snipesched_announcement_' + announcements[i].id;
        if (!localStorage.getItem(key)) {
            undismissed.push(announcements[i]);
        }
    }

    if (undismissed.length === 0) return;

    function show(idx) {
        currentIdx = idx;
        var a = undismissed[idx];
        document.getElementById('announcementTitle').textContent = a.title;
        document.getElementById('announcementBody').innerHTML = a.body;
        var counter = document.getElementById('announcementCounter');
        var nextBtn = document.getElementById('announcementNextBtn');
        if (undismissed.length > 1) {
            counter.textContent = (idx + 1) + ' of ' + undismissed.length;
            nextBtn.style.display = idx < undismissed.length - 1 ? '' : 'none';
        } else {
            counter.textContent = '';
            nextBtn.style.display = 'none';
        }
        document.getElementById('announcementBackdrop').style.display = 'block';
        document.getElementById('announcementModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    window.dismissAnnouncement = function() {
        if (currentIdx >= 0 && currentIdx < undismissed.length) {
            localStorage.setItem('snipesched_announcement_' + undismissed[currentIdx].id, '1');
        }
        if (currentIdx < undismissed.length - 1) {
            show(currentIdx + 1);
        } else {
            document.getElementById('announcementBackdrop').style.display = 'none';
            document.getElementById('announcementModal').style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.nextAnnouncement = function() {
        // Skip without dismissing — user can see it again next visit
        if (currentIdx < undismissed.length - 1) {
            show(currentIdx + 1);
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('announcementModal').style.display === 'block') {
            dismissAnnouncement();
        }
    });

    // Show first undismissed announcement on load
    document.addEventListener('DOMContentLoaded', function() {
        // Delay slightly so welcome modal can show first if applicable
        setTimeout(function() { show(0); }, 300);
    });
})();
</script>
        <?php
    }
}

if (!function_exists('layout_logo_tag')) {
    function layout_default_logo_url(): string
    {
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $baseDir    = $scriptDir;

        $leaf = $scriptDir !== '' ? basename($scriptDir) : '';
        if ($leaf === 'install') {
            $baseDir = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
        } elseif ($leaf === 'upgrade' && basename(dirname($scriptDir)) === 'install') {
            $baseDir = rtrim(str_replace('\\', '/', dirname(dirname($scriptDir))), '/');
        }

        if ($baseDir === '') {
            return '/SnipeScheduler-Logo.png';
        }

        return $baseDir . '/SnipeScheduler-Logo.png';
    }

    function layout_logo_tag(?array $cfg = null): string
    {
        $cfg = layout_cached_config($cfg);

        $logoUrl = '';
        if (isset($cfg['app']['logo_url']) && trim($cfg['app']['logo_url']) !== '') {
            $logoUrl = trim($cfg['app']['logo_url']);
        }

        if ($logoUrl === '') {
            $logoUrl = layout_default_logo_url();
        }

        $urlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        return '<div class="app-logo text-center mb-3">'
            . '<a href="index.php" aria-label="Go to dashboard">'
            . '<img src="' . $urlEsc . '" alt="SnipeScheduler logo" style="max-height:80px; width:auto; height:auto; max-width:100%; object-fit:contain;">'
            . '</a>'
            . '</div>';
    }
}

if (!function_exists('layout_checkout_loading_overlay')) {
    /**
     * Emit a loading overlay + JS that activates on forms with a data-loading attribute.
     * The data-loading value is used as the overlay text (e.g. "Processing checkout...").
     */
    function layout_checkout_loading_overlay(): void
    {
        ?>
<div id="checkoutLoadingOverlay" class="loading-overlay is-hidden">
    <div class="loading-card">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="checkoutLoadingText">Processing checkout...</div>
    </div>
</div>
<script>
(function() {
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || !form.hasAttribute('data-loading')) return;

        var overlay = document.getElementById('checkoutLoadingOverlay');
        var textEl = document.getElementById('checkoutLoadingText');
        if (!overlay) return;

        var msg = form.getAttribute('data-loading') || 'Processing...';
        if (textEl) textEl.textContent = msg;

        overlay.classList.remove('is-hidden');

        // Preserve name/value of the clicked submit button before disabling
        var clicked = e.submitter;
        if (clicked && clicked.name) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = clicked.name;
            hidden.value = clicked.value;
            form.appendChild(hidden);
        }

        var buttons = form.querySelectorAll('button[type="submit"]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = true;
        }
    });
})();
</script>
        <?php
    }
}

if (!function_exists('layout_model_history_modal')) {
    /**
     * Output the model detail modal shell + JS. Call once per page.
     * @param bool $isStaff Whether the current user is staff (enables history sections + note form)
     */
    function layout_model_history_modal(bool $isStaff = false): void
    {
        ?>
<div id="modelHistoryBackdrop" style="display:none; position:fixed; inset:0; background:var(--backdrop-modal); z-index:1050;" onclick="closeModelHistory()"></div>
<div id="modelHistoryModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeModelHistory()">
    <div style="max-width:900px; margin:0 auto; background:var(--panel); border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(var(--black-rgb), 0.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid var(--border);">
            <h5 id="modelHistoryModalLabel" style="margin:0;">Model Details</h5>
            <button type="button" onclick="closeModelHistory()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div id="modelHistoryBody" style="padding:1rem; max-height:70vh; overflow-y:auto;">
            <div class="text-center py-4"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>
        </div>
    </div>
</div>
<style>
.model-history-link { text-decoration: none; color: inherit; }
.model-history-link:hover { text-decoration: underline; }
.mh-toggle { cursor:pointer; user-select:none; }
.mh-toggle:hover { background:#f8f9fa; }
.mh-panel { display:none; }
.mh-panel.mh-open { display:block; }
.md-image-wrapper { text-align:center; margin-bottom:1rem; }
.md-image-wrapper img { max-height:300px; max-width:100%; border-radius:.25rem; }
.md-image-placeholder { height:120px; display:flex; align-items:center; justify-content:center; background:#f8f9fa; border-radius:.25rem; color:#6c757d; }
.md-meta { margin-bottom:1rem; }
.md-meta span { margin-right:1rem; }
.md-notes { background:#f8f9fa; border-radius:.25rem; padding:.75rem; margin-bottom:1rem; white-space:pre-wrap; }
.md-note-form { border-top:1px solid var(--border); padding:.75rem; margin-top:.5rem; }
.md-asset-status-deployed { color: var(--bs-primary, #0d6efd); }
.md-asset-status-undeployable { color: #dc3545; }
</style>
<script>
var _modelDetailIsStaff = <?= $isStaff ? 'true' : 'false' ?>;

function openModelHistory(modelId, modelName) {
    var backdrop = document.getElementById('modelHistoryBackdrop');
    var modal = document.getElementById('modelHistoryModal');
    var body = document.getElementById('modelHistoryBody');
    var title = document.getElementById('modelHistoryModalLabel');

    title.textContent = modelName || 'Model Details';
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';

    backdrop.style.display = 'block';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    fetch('ajax_model_history.php?model_id=' + encodeURIComponent(modelId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = '';

            // Model image
            html += '<div class="md-image-wrapper">';
            if (data.model_image) {
                html += '<img src="' + esc(data.model_image) + '" alt="' + esc(data.model_name) + '">';
            } else {
                html += '<div class="md-image-placeholder">No image available</div>';
            }
            html += '</div>';

            // Model metadata
            if (data.manufacturer || data.category) {
                html += '<div class="md-meta">';
                if (data.manufacturer) {
                    html += '<span><strong>Manufacturer:</strong> ' + esc(data.manufacturer) + '</span>';
                }
                if (data.category) {
                    html += '<span><strong>Category:</strong> ' + esc(data.category) + '</span>';
                }
                html += '</div>';
            }

            // Model notes
            if (data.notes) {
                html += '<div class="md-notes">' + esc(data.notes) + '</div>';
            }

            // Asset inventory table
            html += '<h6 class="mb-2">Asset Inventory</h6>';
            if (data.assets && data.assets.length > 0) {
                html += '<div class="table-responsive mb-3"><table class="table table-sm table-striped align-middle mb-0">';
                html += '<thead><tr><th>Asset Tag</th><th>Name</th><th>Status</th>';
                if (_modelDetailIsStaff) {
                    html += '<th>Assigned To</th><th style="width:50px;"></th>';
                }
                html += '</tr></thead><tbody>';
                data.assets.forEach(function(a) {
                    var statusClass = '';
                    if (a.status_meta === 'deployed') statusClass = ' class="md-asset-status-deployed"';
                    else if (a.status_meta === 'undeployable' || a.status_meta === 'archived') statusClass = ' class="md-asset-status-undeployable"';

                    html += '<tr>';
                    html += '<td>' + esc(a.asset_tag) + '</td>';
                    html += '<td>' + esc(a.asset_name) + '</td>';
                    var statusText = a.status_meta === 'deployed' ? 'Checked Out' : a.status;
                    html += '<td' + statusClass + '>' + esc(statusText) + '</td>';
                    if (_modelDetailIsStaff) {
                        html += '<td>' + esc(a.assigned_to || '') + '</td>';
                        html += '<td><button type="button" class="btn btn-sm btn-outline-secondary" title="Add note" onclick="openModelDetailNote(' + a.asset_id + ', \'' + esc(a.asset_tag).replace(/'/g, "\\'") + '\')">&#9998;</button></td>';
                    }
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p class="text-muted mb-3">No assets found.</p>';
            }

            // Staff-only sections
            if (_modelDetailIsStaff) {
                // Currently checked out
                html += '<h6 class="mb-2 mt-3">Currently Checked Out</h6>';
                if (data.currently_out && data.currently_out.length > 0) {
                    html += '<div class="table-responsive mb-3"><table class="table table-sm table-striped align-middle mb-0">';
                    html += '<thead class="table-warning"><tr><th>Asset Tag</th><th>Asset Name</th><th>Checked Out To</th><th>Last Checkout</th><th>Expected Return</th></tr></thead><tbody>';
                    data.currently_out.forEach(function(a) {
                        var user = a.assigned_to_name || a.assigned_to_email || '';
                        if (a.assigned_to_email && a.assigned_to_name && a.assigned_to_name !== a.assigned_to_email) {
                            user = a.assigned_to_name + ' (' + a.assigned_to_email + ')';
                        }
                        html += '<tr><td>' + esc(a.asset_tag) + '</td><td>' + esc(a.asset_name) + '</td><td>' + esc(user) + '</td><td>' + esc(a.last_checkout) + '</td><td>' + esc(a.expected_checkin) + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<p class="text-muted mb-3">None currently checked out.</p>';
                }

                // Recent checkouts
                html += '<h6 class="mb-2">Recent Checkouts</h6>';
                if (data.recent_checkouts && data.recent_checkouts.length > 0) {
                    data.recent_checkouts.forEach(function(co) {
                        var badge = checkoutStatusBadge(co.status);
                        var user = co.user_name || co.user_email || 'Unknown';
                        var header = '#' + co.checkout_id + ' &mdash; ' + esc(user) + ' ' + badge;
                        var dates = esc(co.start_datetime) + ' &rarr; ' + esc(co.end_datetime);

                        html += '<div class="card mb-2">';
                        html += '<div class="card-header py-2 px-3 mh-toggle" onclick="this.nextElementSibling.classList.toggle(\'mh-open\')">';
                        html += '<span>' + header + '</span>';
                        html += '</div>';
                        html += '<div class="mh-panel"><div class="card-body p-2">';
                        html += '<div class="small text-muted mb-2">' + dates + '</div>';

                        if (co.items && co.items.length > 0) {
                            html += '<table class="table table-sm table-striped align-middle mb-0"><thead><tr><th>Asset Tag</th><th>Asset Name</th><th>Checked Out</th><th>Returned</th></tr></thead><tbody>';
                            co.items.forEach(function(ci) {
                                var returned = ci.checked_in_at ? esc(ci.checked_in_at) : '<span class="badge bg-warning text-dark">Out</span>';
                                var rowClass = ci.checked_in_at ? 'table-success' : '';
                                html += '<tr class="' + rowClass + '"><td>' + esc(ci.asset_tag) + '</td><td>' + esc(ci.asset_name) + '</td><td>' + esc(ci.checked_out_at) + '</td><td>' + returned + '</td></tr>';
                            });
                            html += '</tbody></table>';
                        } else {
                            html += '<p class="text-muted mb-0">No item details.</p>';
                        }

                        html += '</div></div></div>';
                    });
                } else {
                    html += '<p class="text-muted mb-3">No recent checkout history.</p>';
                }

                // Recent reservations
                html += '<h6 class="mb-2">Recent Reservations</h6>';
                if (data.recent_reservations && data.recent_reservations.length > 0) {
                    data.recent_reservations.forEach(function(res) {
                        var badge = reservationStatusBadge(res.status);
                        var user = res.user_name || res.user_email || 'Unknown';
                        var header = '#' + res.reservation_id + ' &mdash; ' + esc(user) + ' ' + badge;
                        if (res.name) header += ' <small class="text-muted">(' + esc(res.name) + ')</small>';
                        var dates = esc(res.start_datetime) + ' &rarr; ' + esc(res.end_datetime);

                        html += '<div class="card mb-2">';
                        html += '<div class="card-header py-2 px-3 mh-toggle" onclick="this.nextElementSibling.classList.toggle(\'mh-open\')">';
                        html += '<span>' + header + '</span>';
                        html += '</div>';
                        html += '<div class="mh-panel"><div class="card-body p-2">';
                        html += '<div class="small text-muted mb-2">' + dates + '</div>';

                        if (res.items && res.items.length > 0) {
                            html += '<table class="table table-sm table-striped align-middle mb-0"><thead><tr><th>Model</th><th>Qty</th></tr></thead><tbody>';
                            res.items.forEach(function(ri) {
                                html += '<tr><td>' + esc(ri.model_name) + '</td><td>' + ri.quantity + '</td></tr>';
                            });
                            html += '</tbody></table>';
                        }

                        html += '</div></div></div>';
                    });
                } else {
                    html += '<p class="text-muted">No recent reservations.</p>';
                }
            }

            // Note form placeholder (staff only)
            if (_modelDetailIsStaff) {
                html += '<div id="modelDetailNoteForm" class="md-note-form" style="display:none;"></div>';
            }

            body.innerHTML = html;
        })
        .catch(function(err) {
            body.innerHTML = '<div class="alert alert-danger">Failed to load model details.</div>';
        });
}

function closeModelHistory() {
    closeModelDetailNote();
    document.getElementById('modelHistoryBackdrop').style.display = 'none';
    document.getElementById('modelHistoryModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('modelHistoryModal').style.display === 'block') {
        closeModelHistory();
    }
});

function esc(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

function checkoutStatusBadge(status) {
    var map = {
        'open':    '<span class="badge status-badge-checked-out">Checked Out</span>',
        'partial': '<span class="badge bg-warning text-dark">Partial Return</span>',
        'closed':  '<span class="badge bg-success">Returned</span>'
    };
    return map[status] || '<span class="badge bg-secondary">' + esc(status) + '</span>';
}

function reservationStatusBadge(status) {
    var map = {
        'pending':   '<span class="badge bg-warning text-dark">Pending</span>',
        'confirmed': '<span class="badge bg-info text-dark">Confirmed</span>',
        'fulfilled': '<span class="badge bg-success">Fulfilled</span>',
        'cancelled': '<span class="badge bg-secondary">Cancelled</span>',
        'missed':    '<span class="badge bg-danger">Missed</span>'
    };
    return map[status] || '<span class="badge bg-secondary">' + esc(status) + '</span>';
}

// --- Asset note form (staff only) ---
var _noteAssetId = null;

function openModelDetailNote(assetId, assetTag) {
    _noteAssetId = assetId;
    var form = document.getElementById('modelDetailNoteForm');
    if (!form) return;
    form.style.display = 'block';
    form.innerHTML = '<h6 class="mb-2">Add Note to ' + esc(assetTag) + '</h6>'
        + '<textarea id="modelDetailNoteText" class="form-control mb-2" rows="3" placeholder="e.g. Lens scratched, missing cable..."></textarea>'
        + '<div class="mb-2"><div class="form-check">'
        + '<input class="form-check-input" type="checkbox" id="mdNoteCreateMaint" onchange="mdTogglePullCheckbox()">'
        + '<label class="form-check-label" for="mdNoteCreateMaint">Create maintenance request (Repair)</label>'
        + '</div></div>'
        + '<div class="mb-3"><div class="form-check">'
        + '<input class="form-check-input" type="checkbox" id="mdNotePullRepair" disabled>'
        + '<label class="form-check-label text-muted" for="mdNotePullRepair" id="mdNotePullLabel">Change status to Pulled for Repair/Replace</label>'
        + '</div></div>'
        + '<div><button type="button" class="btn btn-sm btn-primary me-2" onclick="submitModelDetailNote()">Save</button>'
        + '<button type="button" class="btn btn-sm btn-secondary" onclick="closeModelDetailNote()">Cancel</button></div>'
        + '<div id="modelDetailNoteMsg" class="mt-2"></div>';
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    document.getElementById('modelDetailNoteText').focus();
}

function mdTogglePullCheckbox() {
    var maint = document.getElementById('mdNoteCreateMaint');
    var pull = document.getElementById('mdNotePullRepair');
    var label = document.getElementById('mdNotePullLabel');
    if (!maint || !pull) return;
    pull.disabled = !maint.checked;
    if (!maint.checked) pull.checked = false;
    if (label) label.classList.toggle('text-muted', !maint.checked);
}

function submitModelDetailNote() {
    if (!_noteAssetId) return;
    var textarea = document.getElementById('modelDetailNoteText');
    var maintCb = document.getElementById('mdNoteCreateMaint');
    var pullCb = document.getElementById('mdNotePullRepair');
    var msg = document.getElementById('modelDetailNoteMsg');
    var note = (textarea ? textarea.value : '').trim();
    var createMaint = maintCb ? maintCb.checked : false;
    var pullRepair = pullCb ? pullCb.checked : false;

    if (!note && !createMaint) {
        if (msg) msg.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 small">Please enter a note or select an action.</div>';
        return;
    }
    if (msg) msg.innerHTML = '<div class="text-muted small">Saving...</div>';

    fetch('ajax_model_history.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add_note',
            asset_id: _noteAssetId,
            note: note,
            create_maintenance: createMaint,
            pull_for_repair: pullRepair
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            var successMsg = 'Saved successfully.';
            if (data.warnings && data.warnings.length > 0) {
                successMsg += ' Warnings: ' + data.warnings.join('; ');
                if (msg) msg.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 small">' + esc(successMsg) + '</div>';
            } else {
                if (msg) msg.innerHTML = '<div class="alert alert-success py-1 px-2 mb-0 small">' + esc(successMsg) + '</div>';
            }
            if (textarea) textarea.value = '';
            if (maintCb) maintCb.checked = false;
            if (pullCb) { pullCb.checked = false; pullCb.disabled = true; }
            var label = document.getElementById('mdNotePullLabel');
            if (label) label.classList.add('text-muted');
        } else {
            if (msg) msg.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">' + esc(data.error || 'Failed to save.') + '</div>';
        }
    })
    .catch(function() {
        if (msg) msg.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">Network error.</div>';
    });
}

function closeModelDetailNote() {
    _noteAssetId = null;
    var form = document.getElementById('modelDetailNoteForm');
    if (form) {
        form.style.display = 'none';
        form.innerHTML = '';
    }
}
</script>
        <?php
    }
}

/* ==================================================
   Page layout helpers
   ==================================================
   Use layout_page_start([...]) at the top of a page and
   layout_page_end([...]) at the bottom instead of hand-rolling
   the <head>/<body>/nav/topbar/footer boilerplate.
   ================================================== */

if (!function_exists('layout_page_title_map')) {
    /**
     * Page-key → display title map. Single source of truth used by both
     * layout_render_topbar() and layout_page_start() <title> derivation.
     */
    function layout_page_title_map(): array
    {
        return [
            'index.php'                    => 'Dashboard',
            'catalogue.php'                => 'Catalogue',
            'reservations.php'             => 'Reservations',
            'quick_checkout.php'           => 'Quick Checkout',
            'quick_checkin.php'            => 'Quick Checkin',
            'print_label.php'              => 'Print Label',
            'activity_log.php'             => 'Admin',
            'my_bookings.php'              => 'My Gear',
            'basket.php'                   => 'Basket',
            'reservation_detail.php'      => 'Reservation Detail',
            'reservation_edit.php'        => 'Edit Reservation',
            'settings.php'                 => 'Settings',
            'checkout_history.php'        => 'Checkout History',
            'checked_out_assets.php'      => 'Checked Out Assets',
            'feedback.php'                 => 'Feedback',
            'opening_hours.php'            => 'Opening Hours',
            'overdue_report.php'           => 'Overdue Report',
            'staff_checkout.php'           => 'Staff Checkout',
            'staff_reservations.php'       => 'Staff Reservations',
            'purchase_request_submit.php'  => 'Purchase Requests',
            'my_account.php'               => 'My Account',
            'feedback_submit.php'          => 'Feedback',
            'announcements.php'            => 'Announcements',
        ];
    }
}

if (!function_exists('layout_page_start')) {
    /**
     * Emit the page skeleton from <!DOCTYPE> through the opening of the content area.
     * Pair with layout_page_end().
     *
     * Options:
     *   active            (string, required) Page key (e.g. 'my_account.php'), used by nav + topbar.
     *   title             (string) <title> tag. Default: derived from layout_page_title_map().
     *   subtitle          (string) Topbar breadcrumb subtitle.
     *   bodyClass         (string) Body class. 'p-4' is silently prepended if missing
     *                              (it gates sidebar padding via body.p-4 selector).
     *   bodyAttrs         (array<string,string>) Extra body attributes; h()-escaped.
     *   pageHeaderTitle   (string) <h1> inside .page-header. If empty, the block is omitted.
     *   pageHeaderSubtitle(string) Text under the h1.
     *   extraHead         (string) Already-escaped HTML appended after layout_theme_styles().
     *   layout            ('standard'|'catalogue'|'minimal'|'embed') Default: 'standard'.
     *                       - 'standard':  container + page-shell + nav + topbar + top-user-bar (~15 pages)
     *                       - 'catalogue': nav + topbar but caller manages .catalogue-main (1 page)
     *                       - 'minimal':   doctype/head/body only; no nav, no shell (login)
     *                       - 'embed':     emits nothing; also auto-detected via RESERVATIONS_EMBED
     *   staff             (?bool) Override staff detection; default derived from session.
     *   admin             (?bool) Override admin detection; default derived from session.
     *   hideTopUserBar    (bool) Suppress "Logged in as / Log out" strip. Default false.
     */
    function layout_page_start(array $opts): void
    {
        // Embed mode: orchestrator (reservations.php) already rendered the shell.
        // Orchestrators that define RESERVATIONS_EMBED themselves but still need
        // to render their own chrome must pass `bypassEmbedCheck => true`.
        $bypassEmbed = !empty($opts['bypassEmbedCheck']);
        if (!$bypassEmbed && (defined('RESERVATIONS_EMBED') || ($opts['layout'] ?? '') === 'embed')) {
            return;
        }

        $active = (string)($opts['active'] ?? '');
        if ($active === '') {
            throw new \InvalidArgumentException('layout_page_start: "active" key is required');
        }

        $layout      = $opts['layout'] ?? 'standard';
        $titlesMap   = layout_page_title_map();
        $title       = $opts['title'] ?? ($titlesMap[$active] ?? 'SnipeScheduler');
        $subtitle    = (string)($opts['subtitle'] ?? '');
        $bodyClass   = trim((string)($opts['bodyClass'] ?? 'p-4'));
        // Always include p-4 — it gates sidebar padding via body.p-4
        if (!preg_match('/\bp-4\b/', $bodyClass)) {
            $bodyClass = 'p-4 ' . $bodyClass;
        }
        $bodyAttrs   = is_array($opts['bodyAttrs'] ?? null) ? $opts['bodyAttrs'] : [];
        $extraHead   = (string)($opts['extraHead'] ?? '');
        $pageHTitle  = (string)($opts['pageHeaderTitle'] ?? '');
        $pageHSub    = (string)($opts['pageHeaderSubtitle'] ?? '');
        $sessionUser = $_SESSION['user'] ?? [];
        $isStaff     = $opts['staff'] ?? (!empty($sessionUser['is_staff']) || !empty($sessionUser['is_admin']));
        $isAdmin     = $opts['admin'] ?? !empty($sessionUser['is_admin']);
        $hideTopBar  = !empty($opts['hideTopUserBar']);

        $bodyAttrStr = '';
        foreach ($bodyAttrs as $k => $v) {
            $bodyAttrStr .= ' ' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')
                          . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
        }

        $titleEsc     = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $bodyClassEsc = htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8');
        $stylesheet   = htmlspecialchars(layout_stylesheet_url(), ENT_QUOTES, 'UTF-8');

        echo "<!DOCTYPE html>\n<html>\n<head>\n"
           . "    <meta charset=\"UTF-8\">\n"
           . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
           . "    <title>{$titleEsc}</title>\n"
           . "    <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css\">\n"
           . "    <link rel=\"stylesheet\" href=\"{$stylesheet}\">\n"
           . "    " . layout_theme_styles() . "\n";
        if ($extraHead !== '') {
            echo "    {$extraHead}\n";
        }
        echo "</head>\n<body class=\"{$bodyClassEsc}\"{$bodyAttrStr}>\n";

        if ($layout === 'minimal') {
            return;
        }

        if ($layout === 'standard') {
            echo "<div class=\"container\">\n    <div class=\"page-shell\">\n";
            echo "        " . layout_logo_tag() . "\n";

            if ($pageHTitle !== '') {
                $hTitleEsc = htmlspecialchars($pageHTitle, ENT_QUOTES, 'UTF-8');
                echo "        <div class=\"page-header\">\n"
                   . "            <h1>{$hTitleEsc}</h1>\n";
                if ($pageHSub !== '') {
                    $hSubEsc = htmlspecialchars($pageHSub, ENT_QUOTES, 'UTF-8');
                    echo "            <div class=\"page-subtitle\">{$hSubEsc}</div>\n";
                }
                echo "        </div>\n";
            }
        }

        // Nav + topbar are emitted for both 'standard' and 'catalogue'.
        echo layout_render_nav($active, $isStaff, $isAdmin) . "\n";
        echo layout_render_topbar($active, $subtitle) . "\n";

        if ($layout === 'standard' && !$hideTopBar) {
            echo layout_top_user_bar() . "\n";
        }
    }
}

if (!function_exists('layout_top_user_bar')) {
    /**
     * Render the "Logged in as: Name (email) … Log out" strip that appears
     * below the topbar on standard pages.
     */
    function layout_top_user_bar(): string
    {
        $user      = $_SESSION['user'] ?? [];
        $firstName = $user['first_name'] ?? '';
        $lastName  = $user['last_name'] ?? '';
        $fullName  = htmlspecialchars(trim($firstName . ' ' . $lastName), ENT_QUOTES, 'UTF-8');
        $email     = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');

        return '<div class="top-bar mb-3">'
             . '<div class="top-bar-user">Logged in as: <strong>' . $fullName . '</strong> (' . $email . ')</div>'
             . '<div class="top-bar-actions"><a href="logout.php" class="btn btn-link btn-sm">Log out</a></div>'
             . '</div>';
    }
}

if (!function_exists('layout_page_end')) {
    /**
     * Emit the closing of the page skeleton through </html>.
     * Pair with layout_page_start().
     *
     * Options:
     *   withCheckoutOverlay   (bool)        Render layout_checkout_loading_overlay().
     *   withModelHistoryModal (bool)        Render layout_model_history_modal($isStaff).
     *   withWindowModal       (false|array) If array, render layout_window_modal($opts).
     *   extraScripts          (string)      Already-escaped HTML before </body>, after layout_footer().
     *   skipFooter            (bool)        Suppress layout_footer() call.
     *   layout                (string)      Must match the value passed to layout_page_start().
     *                                         Default: 'standard'.
     */
    function layout_page_end(array $opts = []): void
    {
        $bypassEmbed = !empty($opts['bypassEmbedCheck']);
        if (!$bypassEmbed && (defined('RESERVATIONS_EMBED') || ($opts['layout'] ?? '') === 'embed')) {
            return;
        }

        $layout      = $opts['layout'] ?? 'standard';
        $sessionUser = $_SESSION['user'] ?? [];
        $isStaff     = !empty($sessionUser['is_staff']) || !empty($sessionUser['is_admin']);

        if (!empty($opts['withCheckoutOverlay'])) {
            layout_checkout_loading_overlay();
        }
        if (!empty($opts['withModelHistoryModal'])) {
            layout_model_history_modal($isStaff);
        }
        if (!empty($opts['withWindowModal']) && is_array($opts['withWindowModal'])) {
            layout_window_modal($opts['withWindowModal']);
        }

        if ($layout === 'standard') {
            echo "    </div>\n</div>\n"; // close .page-shell + .container
        }

        if (empty($opts['skipFooter'])) {
            layout_footer();
        }

        if (!empty($opts['extraScripts'])) {
            echo $opts['extraScripts'];
        }

        echo "</body>\n</html>\n";
    }
}

if (!function_exists('layout_empty_state')) {
    /**
     * Render the standardized empty-state block used across panels.
     *
     * @param string $icon       Bootstrap icon class without the "bi " prefix (e.g. "bi-basket"). Whitelisted.
     * @param string $text       Empty-state message; passed through h().
     * @param string $extraClass Optional extra class on the wrapper.
     */
    function layout_empty_state(string $icon, string $text, string $extraClass = ''): string
    {
        // Whitelist icon to bi-* tokens to prevent class injection.
        if (!preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
            $icon = 'bi-info-circle';
        }
        $cls = 'panel-empty-state' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') : '');
        return '<div class="' . $cls . '">'
             . '<i class="bi ' . $icon . ' panel-empty-icon" aria-hidden="true"></i>'
             . '<p class="panel-empty-text">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>'
             . '</div>';
    }
}

if (!function_exists('layout_asset_status_badge')) {
    /**
     * Render the per-asset status badge (Out vs Returned) currently inlined
     * in checkout_history.php, reservation_detail.php, and quick_checkin.php.
     */
    function layout_asset_status_badge(bool $isCheckedIn): string
    {
        if ($isCheckedIn) {
            return '<span class="badge bg-success">Returned</span>';
        }
        return '<span class="badge status-badge-checked-out">Out</span>';
    }
}

if (!function_exists('layout_window_modal')) {
    /**
     * Render the booking-window modal markup + open/close JS used by both
     * catalogue.php and basket.php. Keeps the global openWindowModal /
     * closeWindowModal function names the existing JS depends on.
     *
     * Options:
     *   error    (string) Initial error message (renders inside the modal).
     *   isStaff  (bool)   Show "Bypass slot capacity" checkbox.
     *   isAdmin  (bool)   Show "Bypass closed hours" checkbox.
     */
    function layout_window_modal(array $opts = []): void
    {
        $error   = htmlspecialchars((string)($opts['error'] ?? ''), ENT_QUOTES, 'UTF-8');
        $isStaff = !empty($opts['isStaff']);
        $isAdmin = !empty($opts['isAdmin']);
        ?>
<div class="window-modal-backdrop" id="windowModalBackdrop" aria-hidden="true"></div>
<div class="window-modal" id="windowModal" role="dialog" aria-modal="true" aria-labelledby="windowModalTitle" aria-hidden="true">
    <div class="window-modal-inner">
        <div class="window-modal-header">
            <span class="window-modal-title" id="windowModalTitle">Choose your booking window</span>
            <button type="button" class="window-modal-close" id="windowModalClose" aria-label="Close booking window">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="window-modal-body">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mb-2"><?= $error ?></div>
            <?php endif; ?>
            <div id="windowModalSlotPicker" class="slot-picker"></div>
            <?php if ($isStaff || $isAdmin): ?>
                <div class="window-modal-bypass">
                    <?php if ($isStaff): ?>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" id="windowModalBypassCapacity">
                            <span class="form-check-label">Bypass slot capacity</span>
                        </label>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" id="windowModalBypassClosed">
                            <span class="form-check-label">Bypass closed hours</span>
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function() {
    var backdrop = document.getElementById('windowModalBackdrop');
    var modal    = document.getElementById('windowModal');
    var closeBtn = document.getElementById('windowModalClose');
    if (!backdrop || !modal || !closeBtn) return;

    window.openWindowModal = function() {
        backdrop.classList.add('is-active');
        modal.classList.add('is-active');
        modal.setAttribute('aria-hidden', 'false');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('window-modal-open');
    };
    window.closeWindowModal = function() {
        backdrop.classList.remove('is-active');
        modal.classList.remove('is-active');
        modal.setAttribute('aria-hidden', 'true');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('window-modal-open');
    };

    closeBtn.addEventListener('click', window.closeWindowModal);
    backdrop.addEventListener('click', window.closeWindowModal);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('is-active')) {
            window.closeWindowModal();
        }
    });
})();
</script>
        <?php
    }
}
