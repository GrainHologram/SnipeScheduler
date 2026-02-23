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
        $links = [
            ['href' => 'index.php',          'label' => 'Dashboard',           'staff' => false],
            ['href' => 'catalogue.php',      'label' => 'Catalogue',           'staff' => false],
            ['href' => 'my_bookings.php',    'label' => 'My Reservations',     'staff' => false],
            ['href' => 'reservations.php',   'label' => 'Reservations',        'staff' => true],
            ['href' => 'quick_checkout.php', 'label' => 'Quick Checkout',      'staff' => true],
            ['href' => 'quick_checkin.php',  'label' => 'Quick Checkin',       'staff' => true],
            ['href' => 'activity_log.php',   'label' => 'Admin',               'staff' => false, 'admin_only' => true],
        ];

        $html = '<nav class="app-nav">';
        foreach ($links as $link) {
            if (!empty($link['admin_only'])) {
                if (!$isAdmin) {
                    continue;
                }
            } elseif ($link['staff'] && !$isStaff) {
                continue;
            }

            $href    = htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8');
            $label   = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
            $classes = 'app-nav-link' . ($active === $link['href'] ? ' active' : '');

            $html .= '<a href="' . $href . '" class="' . $classes . '">' . $label . '</a>';
        }
        $html .= '</nav>';

        return $html;
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

        $commitHash = '';
        $headFile = APP_ROOT . '/.git/HEAD';
        if (is_file($headFile)) {
            $head = trim((string)@file_get_contents($headFile));
            if (str_starts_with($head, 'ref: ')) {
                $refPath = APP_ROOT . '/.git/' . substr($head, 5);
                if (is_file($refPath)) {
                    $commitHash = substr(trim((string)@file_get_contents($refPath)), 0, 7);
                }
            } else {
                $commitHash = substr($head, 0, 7);
            }
        }
        $commitSuffix = $commitHash !== '' ? ' (' . $commitHash . ')' : '';

        echo '<script src="assets/nav.js"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>';
        echo '<script src="assets/datetime-picker.js"></script>';
        echo '<footer class="text-center text-muted mt-4 small">'
            . 'SnipeScheduler Version ' . $versionEsc . $commitSuffix . ' - Created by '
            . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
            . '</footer>';
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

if (!function_exists('layout_model_history_modal')) {
    /**
     * Output the model history modal shell + JS. Call once per page, staff-only.
     */
    function layout_model_history_modal(): void
    {
        ?>
<div id="modelHistoryBackdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1050;" onclick="closeModelHistory()"></div>
<div id="modelHistoryModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeModelHistory()">
    <div style="max-width:800px; margin:0 auto; background:#fff; border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(0,0,0,.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #dee2e6;">
            <h5 id="modelHistoryModalLabel" style="margin:0;">Model History</h5>
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
</style>
<script>
function openModelHistory(modelId, modelName) {
    var backdrop = document.getElementById('modelHistoryBackdrop');
    var modal = document.getElementById('modelHistoryModal');
    var body = document.getElementById('modelHistoryBody');
    var title = document.getElementById('modelHistoryModalLabel');

    title.textContent = modelName || 'Model History';
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';

    backdrop.style.display = 'block';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    fetch('ajax_model_history.php?model_id=' + encodeURIComponent(modelId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = '';

            // Currently checked out
            html += '<h6 class="mb-2">Currently Checked Out</h6>';
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
                data.recent_checkouts.forEach(function(co, idx) {
                    var badge = statusBadge(co.status);
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
                html += '<p class="text-muted">No recent checkout history.</p>';
            }

            body.innerHTML = html;
        })
        .catch(function(err) {
            body.innerHTML = '<div class="alert alert-danger">Failed to load model history.</div>';
        });
}

function closeModelHistory() {
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

function statusBadge(status) {
    var map = {
        'open':    '<span class="badge status-badge-checked-out">Checked Out</span>',
        'partial': '<span class="badge bg-warning text-dark">Partial Return</span>',
        'closed':  '<span class="badge bg-success">Returned</span>'
    };
    return map[status] || '<span class="badge bg-secondary">' + esc(status) + '</span>';
}
</script>
        <?php
    }
}
