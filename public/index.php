<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/opening_hours.php';

function gantt_pct(int $mins, int $start, int $span): string {
    return round(($mins - $start) / $span * 100, 3) . '%';
}

$config        = load_config();
$active        = basename($_SERVER['PHP_SELF']);
$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;

// ── AJAX: user search (staff only) ──────────────────────────────────
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
            $results[] = [
                'id'       => $row['id'] ?? null,
                'name'     => $row['name'] ?? '',
                'email'    => $row['email'] ?? '',
                'username' => $row['username'] ?? '',
            ];
        }

        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Staff dashboard data ────────────────────────────────────────────
if ($isStaff) {
    $timezone = $config['app']['timezone'] ?? 'Europe/Jersey';
    $tz       = new DateTimeZone($timezone);
    $utc      = new DateTimeZone('UTC');
    $now      = new DateTime('now', $tz);
    $todayStr    = $now->format('Y-m-d');
    $tomorrowStr = (clone $now)->modify('+1 day')->format('Y-m-d');

    $todayLocalStart = new DateTime($todayStr . ' 00:00:00', $tz);
    $todayLocalEnd   = new DateTime($todayStr . ' 23:59:59', $tz);
    $todayUtcStart   = (clone $todayLocalStart)->setTimezone($utc)->format('Y-m-d H:i:s');
    $todayUtcEnd     = (clone $todayLocalEnd)->setTimezone($utc)->format('Y-m-d H:i:s');
    $nowUtc          = (new DateTime('now', $utc))->format('Y-m-d H:i:s');

    // Week range for upcoming pickups/returns and the "Week at a Glance" header
    $weekEndDay    = (clone $now)->modify('+6 days');
    $weekEndLocal  = new DateTime($weekEndDay->format('Y-m-d') . ' 23:59:59', $tz);
    $weekEndUtc    = (clone $weekEndLocal)->setTimezone($utc)->format('Y-m-d H:i:s');
    $weekLabel     = $now->format('M j') . ' – ' . $weekEndDay->format('M j');
    $weekDays      = [];
    for ($i = 0; $i < 7; $i++) {
        $d = (clone $now)->modify("+$i days");
        $weekDays[] = [
            'label'   => $d->format('D j'),
            'isToday' => $i === 0,
        ];
    }

    // Upcoming pickups — next 7 days
    $stmt = $pdo->prepare("
        SELECT * FROM reservations
         WHERE status IN ('pending','confirmed')
           AND start_datetime >= :todayStart AND start_datetime <= :weekEnd
         ORDER BY start_datetime ASC
         LIMIT 30
    ");
    $stmt->execute([':todayStart' => $todayUtcStart, ':weekEnd' => $weekEndUtc]);
    $upcomingPickups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Today-only pickup count for stat card
    $pendingCount = 0;
    foreach ($upcomingPickups as $p) {
        $dtP = new DateTime($p['start_datetime'], new DateTimeZone('UTC'));
        $dtP->setTimezone($tz);
        if ($dtP->format('Y-m-d') === $todayStr) $pendingCount++;
    }

    // First pickup today for stat sub-text (first scheduled, even if already past)
    $nextPickupTime = null;
    foreach ($upcomingPickups as $p) {
        $dtP = new DateTime($p['start_datetime'], new DateTimeZone('UTC'));
        $dtP->setTimezone($tz);
        if ($dtP->format('Y-m-d') === $todayStr) {
            $nextPickupTime = $dtP->format('H:i');
            break;
        }
    }

    // Fetch reservation items for upcoming pickups (for items modal)
    $upcomingResItems = [];
    if (!empty($upcomingPickups)) {
        $upcomingResItems = batch_get_reservation_items($pdo, array_column($upcomingPickups, 'id'));
    }

    // Active checkouts count
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM checkouts WHERE status IN ('open','partial')")->fetchColumn();

    // Due this week — excludes already-overdue (end_datetime > now)
    $stmt = $pdo->prepare("
        SELECT c.id AS checkout_id, c.name AS checkout_name, c.user_name, c.user_email,
               c.start_datetime, c.end_datetime, c.snipeit_user_id,
               r.name AS reservation_name, r.asset_name_cache,
               COUNT(ci.id) AS item_count
          FROM checkouts c
          JOIN checkout_items ci ON ci.checkout_id = c.id
          LEFT JOIN reservations r ON r.id = c.reservation_id
         WHERE c.status IN ('open','partial')
           AND ci.checked_in_at IS NULL
           AND c.end_datetime > :nowUtc AND c.end_datetime <= :weekEnd
         GROUP BY c.id
         ORDER BY c.end_datetime ASC
    ");
    $stmt->execute([':nowUtc' => $nowUtc, ':weekEnd' => $weekEndUtc]);
    $dueSoon = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Today-only return count for stat card
    $dueCount = 0;
    $nextReturnTime = null;
    foreach ($dueSoon as $row) {
        $dtD = new DateTime($row['end_datetime'], new DateTimeZone('UTC'));
        $dtD->setTimezone($tz);
        if ($dtD->format('Y-m-d') === $todayStr) {
            $dueCount++;
            if ($nextReturnTime === null) {
                $nextReturnTime = $dtD->format('H:i');
            }
        }
    }

    // Batch-fetch checkout_items for due-soon checkouts (for items modal)
    $dueSoonIds = array_column($dueSoon, 'checkout_id');
    $dueCheckoutItems = [];
    if (!empty($dueSoonIds)) {
        $ph = implode(',', array_fill(0, count($dueSoonIds), '?'));
        $ciStmt = $pdo->prepare("SELECT checkout_id, asset_tag, asset_name, model_name, checked_in_at
                                   FROM checkout_items WHERE checkout_id IN ($ph) ORDER BY id");
        $ciStmt->execute(array_values($dueSoonIds));
        foreach ($ciStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
            $ci['asset_name'] = html_entity_decode($ci['asset_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $ci['model_name'] = html_entity_decode($ci['model_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $dueCheckoutItems[(int)$ci['checkout_id']][] = $ci;
        }
    }

    // Overdue — grouped by checkout
    $stmt = $pdo->prepare("
        SELECT c.id AS checkout_id, c.name AS checkout_name, c.user_name, c.user_email,
               c.start_datetime, c.end_datetime, c.snipeit_user_id,
               r.name AS reservation_name, r.asset_name_cache,
               COUNT(ci.id) AS item_count
          FROM checkouts c
          JOIN checkout_items ci ON ci.checkout_id = c.id
          LEFT JOIN reservations r ON r.id = c.reservation_id
         WHERE c.status IN ('open','partial')
           AND ci.checked_in_at IS NULL
           AND c.end_datetime < :nowUtc
         GROUP BY c.id
         ORDER BY c.end_datetime ASC
    ");
    $stmt->execute([':nowUtc' => $nowUtc]);
    $overdueItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $overdueCount = count($overdueItems);

    // Fallback: if no future returns today, check overdue items due today
    if ($nextReturnTime === null) {
        foreach ($overdueItems as $row) {
            $dtD = new DateTime($row['end_datetime'], new DateTimeZone('UTC'));
            $dtD->setTimezone($tz);
            if ($dtD->format('Y-m-d') === $todayStr) {
                $nextReturnTime = $dtD->format('H:i');
                break;
            }
        }
    }

    // Batch-fetch checkout_items for overdue checkouts (for items modal)
    $overdueCheckoutIds = array_column($overdueItems, 'checkout_id');
    $overdueCheckoutItems = [];
    if (!empty($overdueCheckoutIds)) {
        $ph = implode(',', array_fill(0, count($overdueCheckoutIds), '?'));
        $ciStmt = $pdo->prepare("SELECT checkout_id, asset_tag, asset_name, model_name, checked_in_at
                                   FROM checkout_items WHERE checkout_id IN ($ph) ORDER BY id");
        $ciStmt->execute(array_values($overdueCheckoutIds));
        foreach ($ciStmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
            $ci['asset_name'] = html_entity_decode($ci['asset_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $ci['model_name'] = html_entity_decode($ci['model_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $overdueCheckoutItems[(int)$ci['checkout_id']][] = $ci;
        }
    }

    // (no static gantt_start/end_hour — open hours come from the opening_hours tables per day)

    // ── Calendar-week bounds (Sun–Sat) ──────────────────────────────────
    $ganttDayOfWeek    = (int)$now->format('w');   // 0 = Sun, 6 = Sat
    $ganttSunday       = (clone $now)->modify("-{$ganttDayOfWeek} days");
    $ganttSaturday     = (clone $ganttSunday)->modify('+6 days');
    $ganttWeekStartUtc = (new DateTime($ganttSunday->format('Y-m-d')   . ' 00:00:00', $tz))
                            ->setTimezone($utc)->format('Y-m-d H:i:s');
    $ganttWeekEndUtc   = (new DateTime($ganttSaturday->format('Y-m-d') . ' 23:59:59', $tz))
                            ->setTimezone($utc)->format('Y-m-d H:i:s');

    $ganttWeekDays = [];
    for ($i = 0; $i < 7; $i++) {
        $d = (clone $ganttSunday)->modify("+{$i} days");
        $ganttWeekDays[$i] = [
            'date'    => $d->format('Y-m-d'),
            'label'   => $d->format('D'),
            'dayNum'  => $d->format('j'),
            'isToday' => ($d->format('Y-m-d') === $todayStr),
        ];
    }

    // ── Fetch gantt data for the full calendar week ─────────────────────
    $stmt = $pdo->prepare("
        SELECT id, user_name, start_datetime, status
          FROM reservations
         WHERE status IN ('pending','confirmed','fulfilled')
           AND start_datetime BETWEEN :ws AND :we
         ORDER BY start_datetime ASC
         LIMIT 200
    ");
    $stmt->execute([':ws' => $ganttWeekStartUtc, ':we' => $ganttWeekEndUtc]);
    $ganttAllPickups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT id, user_name, end_datetime, status, snipeit_user_id
          FROM checkouts
         WHERE end_datetime BETWEEN :ws AND :we
         ORDER BY end_datetime ASC
         LIMIT 200
    ");
    $stmt->execute([':ws' => $ganttWeekStartUtc, ':we' => $ganttWeekEndUtc]);
    $ganttAllReturns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Build per-day event arrays with dynamic range ───────────────────
    $ganttNowDt   = new DateTime('now', $utc);
    $ganttNowMins = (int)$now->format('H') * 60 + (int)$now->format('i');
    $ganttDayData = [];

    foreach ($ganttWeekDays as $i => $wd) {
        $isToday = $wd['isToday'];
        $pickups = [];
        $returns = [];
        $allMins = [];

        foreach ($ganttAllPickups as $p) {
            $dt = (new DateTime($p['start_datetime'], $utc))->setTimezone($tz);
            if ($dt->format('Y-m-d') !== $wd['date']) continue;
            $mins      = (int)$dt->format('H') * 60 + (int)$dt->format('i');
            $allMins[] = $mins;
            $pickups[] = [
                'user'   => $p['user_name'],
                'time'   => $dt->format('H:i'),
                'mins'   => $mins,
                'res_id' => (int)$p['id'],
                'past'   => $isToday && $mins < $ganttNowMins,
                'done'   => $p['status'] === 'fulfilled',
            ];
        }

        foreach ($ganttAllReturns as $r) {
            $dt        = (new DateTime($r['end_datetime'], $utc))->setTimezone($tz);
            if ($dt->format('Y-m-d') !== $wd['date']) continue;
            $endDt     = new DateTime($r['end_datetime'], $utc);
            $isOverdue = ($r['status'] !== 'closed') && ($endDt < $ganttNowDt);
            $mins      = (int)$dt->format('H') * 60 + (int)$dt->format('i');
            $allMins[] = $mins;
            $returns[] = [
                'user'        => $r['user_name'],
                'time'        => $dt->format('H:i'),
                'mins'        => $mins,
                'overdue'     => $isOverdue,
                'co_id'       => (int)$r['id'],
                'snipeit_uid' => $r['snipeit_user_id'],
                'past'        => $isToday && !$isOverdue && $mins < $ganttNowMins,
                'done'        => $r['status'] === 'closed',
            ];
        }

        // Range: always at least the facility's open hours for this day; expand if events fall outside
        $dayHours  = oh_get_hours_for_date($wd['date']);
        $startHour = $dayHours['is_closed'] || $dayHours['open_time']  === null ? 8  : (int)substr($dayHours['open_time'],  0, 2);
        $endHour   = $dayHours['is_closed'] || $dayHours['close_time'] === null ? 18 : (int)substr($dayHours['close_time'], 0, 2);
        if (!empty($allMins)) {
            $startHour = min($startHour, max(0,  (int)floor((min($allMins) - 30) / 60)));
            $endHour   = max($endHour,   min(23, (int)ceil( (max($allMins) + 30) / 60)));
        }
        $startMins = $startHour * 60;
        $endMins   = $endHour   * 60;
        $span      = max(60, $endMins - $startMins);

        // Gridlines every 2 hrs when span > 4 hrs, otherwise every 1 hr
        $step  = ($span > 240) ? 2 : 1;
        $hours = [];
        for ($h = $startHour; $h <= $endHour; $h += $step) {
            if ($h < 12)       $lbl = "{$h}am";
            elseif ($h === 12) $lbl = "12pm";
            else               $lbl = ($h - 12) . "pm";
            $hours[] = ['label' => $lbl, 'mins' => $h * 60];
        }

        $nowPctDay = ($isToday && $ganttNowMins >= $startMins && $ganttNowMins <= $endMins)
                        ? gantt_pct($ganttNowMins, $startMins, $span) : null;

        $ganttDayData[$i] = compact('pickups', 'returns', 'startMins', 'endMins', 'span', 'hours', 'nowPctDay');
    }
}

$dashSubtitle = $isStaff
    ? "Staff dashboard — today's pickups, active checkouts, and items due back."
    : 'Browse bookable equipment, manage your basket, and review your bookings.';

layout_page_start([
    'active'             => $active,
    'title'              => 'Equipment Booking – Dashboard',
    'pageHeaderTitle'    => 'Equipment Booking',
    'pageHeaderSubtitle' => $dashSubtitle,
]);
?>

        <?php if ($isStaff): ?>

<style>
/* ── Soft accent palette ─────────────────────────────────────────────
   Scoped to .dash-scope so these colours don't leak globally.        */
.dash-scope {
    --dc-pickup:      #3ecf8e;
    --dc-pickup-rgb:  62, 207, 142;
    --dc-return:      #f5a623;
    --dc-return-rgb:  245, 166, 35;
    --dc-overdue:     #e05252;
    --dc-overdue-rgb: 224, 82, 82;
    --dc-today:       #4f8ef7;
    --dc-today-rgb:   79, 142, 247;
    --dc-subtle:      #444;
}

/* ── Stat cards ──────────────────────────────────────────────────────── */
.dash-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .75rem;
    margin-bottom: 1.5rem;
}
.dash-stat {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: .625rem;
    padding: 1.125rem 1.25rem;
}
.dash-stat-label { font-size: .6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.dash-stat-value { font-size: 26px; font-weight: 700; line-height: 1.15; margin: .25rem 0 .125rem; }
.dash-stat-sub      { font-size: .6875rem; color: var(--muted); }
.dash-stat-sub.warn { color: var(--dc-return); }
.dash-stat-sub.bad  { color: var(--dc-overdue); }

/* ── Section header ──────────────────────────────────────────────────── */
.dash-section-hd {
    display: flex; align-items: center; gap: .625rem;
    margin-bottom: .625rem;
}
.dash-section-dot   { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dash-section-title { font-size: .8125rem; font-weight: 600; }
.dash-section-pill  {
    font-size: .6875rem; color: var(--muted);
    background: var(--surface); border: 1px solid var(--border);
    padding: 1px 7px; border-radius: 99px;
}
.dash-section-link,
.dash-section-link:link,
.dash-section-link:visited {
    margin-left: auto; font-size: .6875rem;
    color: var(--muted); text-decoration: none; transition: color .12s;
}
.dash-section-link:hover { color: var(--text); }

/* ── Two-column layout ───────────────────────────────────────────────── */
.dash-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    margin-bottom: 1.5rem;
    align-items: start;
}

/* ── Scrollable column box ───────────────────────────────────────────── */
.dash-col-box {
    border: 1px solid var(--border);
    border-radius: .625rem;
    display: flex;
    flex-direction: column;
    height: 560px;
}
.dash-col-scroll {
    flex: 1;
    overflow-y: auto;
    padding: .75rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
}
.dash-col-scroll::-webkit-scrollbar { width: 5px; }
.dash-col-scroll::-webkit-scrollbar-track { background: transparent; }
.dash-col-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

/* ── Individual card ─────────────────────────────────────────────────── */
.dash-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: .5rem;
    padding: .875rem 1rem;
    position: relative; overflow: hidden;
    transition: background .12s, border-color .12s;
    cursor: pointer;
    display: block;
}
/* All link states — prevents browser visited-link purple */
.dash-card,
.dash-card:link,
.dash-card:visited,
.dash-card:hover,
.dash-card:active {
    color: var(--text);
    text-decoration: none;
}
.dash-card:hover {
    background: var(--surface);
    border-color: var(--dc-subtle);
}
.dash-card::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
}
.dash-card-pickup::before  { background: var(--dc-pickup); }
.dash-card-return::before  { background: var(--dc-return); }
.dash-card-overdue::before { background: var(--dc-overdue); }
.dash-card-today::before   { background: var(--dc-today); }

.dash-card-top {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: .5rem;
    margin-bottom: .625rem;
}
/* Explicit color prevents visited-link inheritance */
.dash-card-name { font-size: 15px; font-weight: 600; line-height: 1.3; color: var(--text); }
.dash-card-sub  { font-size: 13px; color: var(--muted); margin-top: 2px; }

/* ── Badges ──────────────────────────────────────────────────────────── */
.dash-badge {
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    padding: 3px 8px; border-radius: 99px;
    white-space: nowrap; flex-shrink: 0;
}
.dash-badge-today   { background: rgba(var(--dc-today-rgb), .15);   color: var(--dc-today); }
.dash-badge-pickup  { background: rgba(var(--dc-pickup-rgb), .15);  color: var(--dc-pickup); }
.dash-badge-return  { background: rgba(var(--dc-return-rgb), .15);  color: var(--dc-return); }
.dash-badge-overdue { background: rgba(var(--dc-overdue-rgb), .15); color: var(--dc-overdue); }
.dash-badge-pending { background: rgba(100,100,100,.18);            color: var(--muted); }

/* ── Card meta lines ─────────────────────────────────────────────────── */
.dash-card-info {
    display: flex; flex-direction: column; gap: 2px;
    margin-top: .625rem;
}
.dash-card-meta-line {
    display: flex; align-items: center; gap: .3rem;
    font-size: 13px; color: var(--muted);
}
.dash-card-meta-line i { opacity: .55; font-size: .8em; }

/* ── Card bottom row (items + action on same line) ───────────────────── */
.dash-card-meta-bottom {
    display: flex; align-items: center;
}
.dash-card-action {
    font-size: 12px; color: var(--muted);
    margin-left: auto; flex-shrink: 0;
    transition: color .12s;
}
.dash-card:hover .dash-card-action          { color: var(--text); }
.dash-card-action-overdue                   { color: var(--dc-overdue); }
.dash-card:hover .dash-card-action-overdue  { opacity: .85; }

/* ── Empty state ─────────────────────────────────────────────────────── */
.dash-empty {
    flex: 1;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 2rem 1rem;
    font-size: .75rem; color: var(--muted);
}
.dash-empty i { font-size: 1.75rem; opacity: .3; margin-bottom: .5rem; }

/* ── Gantt day selector ──────────────────────────────────────────────── */
.gantt-panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: .625rem;
    margin-bottom: 1.5rem;
}
.gantt-day-selector {
    display: flex;
    border-bottom: 1px solid var(--border);
    padding: .5rem .75rem 0;
    gap: .125rem;
}
.gantt-day-btn {
    flex: 1;
    display: flex; flex-direction: column; align-items: center;
    padding: .375rem .25rem .3rem;
    background: none; border: none;
    border-bottom: 2px solid transparent;
    border-radius: .375rem .375rem 0 0;
    cursor: pointer; color: var(--muted);
    transition: background .12s, color .12s;
    margin-bottom: -1px;
}
.gantt-day-btn:hover  { background: var(--surface); color: var(--text); }
.gantt-day-btn.active { color: var(--dc-today); border-bottom-color: var(--dc-today); }
.gantt-day-name { font-size: .6rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.gantt-day-num  { font-size: .8125rem; font-weight: 600; line-height: 1.4; }

/* ── Gantt body ──────────────────────────────────────────────────────── */
.gantt-body  { padding: .875rem 1rem .5rem; overflow-x: auto; }
.gantt-inner { min-width: 460px; }
.gantt-row-wrap { display: flex; gap: .625rem; }
.gantt-label-col {
    width: 52px; flex-shrink: 0;
    display: flex; flex-direction: column;
}
.gantt-label-cell {
    height: 52px; display: flex; align-items: center;
    justify-content: flex-end;
    font-size: .675rem; text-transform: uppercase;
    letter-spacing: .04em; color: var(--muted); padding-right: .25rem;
}
.gantt-tracks { flex: 1; position: relative; }
.gantt-track  { position: relative; height: 52px; }
.gantt-track-line {
    position: absolute; top: 50%; left: 0; right: 0;
    height: 2px; background: var(--border); transform: translateY(-50%);
}
.gantt-gridline {
    position: absolute; top: 0; bottom: 0; width: 1px;
    background: var(--border); opacity: .3;
    z-index: 0; pointer-events: none;
}
.gantt-event {
    position: absolute; top: 0;
    transform: translateX(-50%);
    display: flex; flex-direction: column; align-items: center;
    z-index: 2; pointer-events: none;
}
.gantt-dot {
    width: 10px; height: 10px; border-radius: 50%;
    flex-shrink: 0; margin-top: 21px;
}
.gantt-event-label {
    font-size: .6rem; color: var(--muted);
    white-space: nowrap; margin-top: 3px;
    line-height: 1.25; text-align: center;
}
.gantt-now-line {
    position: absolute; top: 0; bottom: 0; width: 2px;
    background: rgba(var(--dc-overdue-rgb), .7);
    z-index: 3; pointer-events: none;
}
.gantt-axis-row    { display: flex; gap: .625rem; margin-top: .875rem; }
.gantt-axis-spacer { width: 52px; flex-shrink: 0; }
.gantt-axis {
    flex: 1; position: relative; height: 1.25rem;
    border-top: 1px solid var(--border);
}
.gantt-axis-tick {
    position: absolute; transform: translateX(-50%);
    font-size: .6rem; color: var(--muted);
    padding-top: .2rem; white-space: nowrap;
}
.gantt-event-past { opacity: .3; }

.gantt-legend {
    display: flex; gap: 1rem;
    padding: .5rem 0 .25rem calc(52px + .625rem);
    flex-wrap: wrap;
}
.gantt-legend-item { display: flex; align-items: center; gap: .3rem; font-size: .675rem; color: var(--muted); }
.gantt-legend-dot  { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.gantt-legend-line { width: 2px; height: 10px; display: inline-block; background: rgba(var(--dc-overdue-rgb), .7); }

/* ── Responsive ──────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .dash-stats   { grid-template-columns: repeat(2, 1fr); }
    .dash-columns { grid-template-columns: 1fr; }
    .dash-col-box { height: auto; max-height: 560px; }
}
@media (max-width: 480px) {
    .dash-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="dash-scope">

<!-- ── Stat cards ──────────────────────────────────────────────────── -->
<div class="dash-stats">
    <div class="dash-stat">
        <div class="dash-stat-label">Active Checkouts</div>
        <div class="dash-stat-value"><?= $activeCount ?></div>
        <div class="dash-stat-sub">&nbsp;</div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-label">Pickups Today</div>
        <div class="dash-stat-value"><?= $pendingCount ?></div>
        <div class="dash-stat-sub"><?= $nextPickupTime ? 'Next at ' . h($nextPickupTime) : '&nbsp;' ?></div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-label">Returns Today</div>
        <div class="dash-stat-value"><?= $dueCount ?></div>
        <div class="dash-stat-sub"><?= $nextReturnTime ? 'Next due at ' . h($nextReturnTime) : '&nbsp;' ?></div>
    </div>
    <div class="dash-stat">
        <div class="dash-stat-label">Overdue</div>
        <div class="dash-stat-value" style="color: var(--dc-overdue);"><?= $overdueCount ?></div>
        <div class="dash-stat-sub">&nbsp;</div>
    </div>
</div>

<!-- ── Two columns ─────────────────────────────────────────────────── -->
<div class="dash-columns">

    <!-- Pickups column -->
    <div>
        <div class="dash-section-hd">
            <div class="dash-section-dot" style="background: var(--dc-pickup);"></div>
            <div class="dash-section-title">Upcoming Pickups</div>
            <div class="dash-section-pill"><?= count($upcomingPickups) ?></div>
            <a href="reservations.php?tab=today" class="dash-section-link">View all →</a>
        </div>
        <div class="dash-col-box">
            <div class="dash-col-scroll">
                <?php if (empty($upcomingPickups)): ?>
                    <div class="dash-empty"><i class="bi bi-calendar2-check"></i>No upcoming pickups this week.</div>
                <?php else: ?>
                    <?php foreach ($upcomingPickups as $pickup):
                        $dtStart = new DateTime($pickup['start_datetime'], new DateTimeZone('UTC'));
                        $dtStart->setTimezone($tz);
                        $pickupDate = $dtStart->format('Y-m-d');

                        if ($pickupDate === $todayStr) {
                            $badge = 'TODAY ' . $dtStart->format('H:i');
                            $badgeType = 'today'; $cardType = 'today';
                        } elseif ($pickupDate === $tomorrowStr) {
                            $badge = 'TOMORROW'; $badgeType = 'return'; $cardType = 'return';
                        } else {
                            $badge = strtoupper($dtStart->format('M j'));
                            $badgeType = 'pending'; $cardType = 'return';
                        }

                        $periodText = $dtStart->format('M j');
                        if (!empty($pickup['end_datetime'])) {
                            $dtEnd = new DateTime($pickup['end_datetime'], new DateTimeZone('UTC'));
                            $dtEnd->setTimezone($tz);
                            $days = max(1, (int)round(abs($dtEnd->getTimestamp() - $dtStart->getTimestamp()) / 86400));
                            $periodText = $dtStart->format('M j') . ' – ' . $dtEnd->format('M j')
                                . ' (' . $days . ' day' . ($days !== 1 ? 's' : '') . ')';
                        }

                        $resItems = $upcomingResItems[(int)$pickup['id']] ?? [];
                        $totalQty = array_sum(array_column($resItems, 'qty'));
                        $subtitle = $pickup['name'] ?: build_items_summary_text($resItems);
                    ?>
                    <a href="reservations.php?tab=today&res=<?= (int)$pickup['id'] ?>"
                       class="dash-card dash-card-<?= h($cardType) ?>">
                        <div class="dash-card-top">
                            <div>
                                <div class="dash-card-name"><?= h($pickup['user_name']) ?></div>
                                <div class="dash-card-sub"><?= h($subtitle ?: '—') ?></div>
                            </div>
                            <span class="dash-badge dash-badge-<?= h($badgeType) ?>"><?= h($badge) ?></span>
                        </div>
                        <div class="dash-card-info">
                            <div class="dash-card-meta-line"><i class="bi bi-calendar3"></i> <?= h($periodText) ?></div>
                            <div class="dash-card-meta-bottom">
                                <?php if ($totalQty > 0): ?><span class="dash-card-meta-line"><i class="bi bi-box-seam"></i> <?= $totalQty ?> item<?= $totalQty !== 1 ? 's' : '' ?></span><?php endif; ?>
                                <span class="dash-card-action">Process →</span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Returns column -->
    <div>
        <div class="dash-section-hd">
            <div class="dash-section-dot" style="background: var(--dc-return);"></div>
            <div class="dash-section-title">Upcoming Returns</div>
            <div class="dash-section-pill"><?= count($dueSoon) + count($overdueItems) ?></div>
            <a href="reservations.php?tab=checkout_history" class="dash-section-link">View all →</a>
        </div>
        <div class="dash-col-box">
            <div class="dash-col-scroll">
                <?php if (empty($dueSoon) && empty($overdueItems)): ?>
                    <div class="dash-empty"><i class="bi bi-check2-all"></i>No returns due this week.</div>
                <?php else: ?>

                    <?php foreach ($dueSoon as $row):
                        $dtEnd = new DateTime($row['end_datetime'], new DateTimeZone('UTC'));
                        $dtEnd->setTimezone($tz);
                        $returnDate = $dtEnd->format('Y-m-d');

                        if ($returnDate === $todayStr) {
                            $badge = 'TODAY ' . $dtEnd->format('H:i');
                            $badgeType = 'today'; $cardType = 'today';
                        } elseif ($returnDate === $tomorrowStr) {
                            $badge = 'TOMORROW'; $badgeType = 'return'; $cardType = 'return';
                        } else {
                            $badge = strtoupper($dtEnd->format('M j'));
                            $badgeType = 'pending'; $cardType = 'return';
                        }

                        $coName = $row['checkout_name'] ?: ($row['reservation_name'] ?: ($row['asset_name_cache'] ?: null));
                        if (!$coName) {
                            $coItems = $dueCheckoutItems[(int)$row['checkout_id']] ?? [];
                            if (!empty($coItems)) {
                                $coName = implode(', ', array_filter(array_column($coItems, 'asset_name')));
                            }
                        }
                        $subtitle  = $coName ?: null;
                        $itemCount = (int)$row['item_count'];
                        $dtRStart  = (new DateTime($row['start_datetime'], new DateTimeZone('UTC')))->setTimezone($tz);
                        $dtREnd    = (new DateTime($row['end_datetime'],   new DateTimeZone('UTC')))->setTimezone($tz);
                        $rDays     = max(1, (int)round(abs($dtREnd->getTimestamp() - $dtRStart->getTimestamp()) / 86400));
                        $periodText = $dtRStart->format('M j') . ' – ' . $dtREnd->format('M j')
                            . ' (' . $rDays . ' day' . ($rDays !== 1 ? 's' : '') . ')';

                        $linkUrl = !empty($row['snipeit_user_id'])
                            ? 'quick_checkin.php?user=' . (int)$row['snipeit_user_id']
                            : 'reservations.php?tab=checkout_history';
                    ?>
                    <a href="<?= h($linkUrl) ?>" class="dash-card dash-card-<?= h($cardType) ?>">
                        <div class="dash-card-top">
                            <div>
                                <div class="dash-card-name"><?= h($row['user_name']) ?></div>
                                <div class="dash-card-sub"><?= h($subtitle ?: '—') ?></div>
                            </div>
                            <span class="dash-badge dash-badge-<?= h($badgeType) ?>"><?= h($badge) ?></span>
                        </div>
                        <div class="dash-card-info">
                            <div class="dash-card-meta-line"><i class="bi bi-calendar3"></i> <?= h($periodText) ?></div>
                            <div class="dash-card-meta-bottom">
                                <?php if ($itemCount > 0): ?><span class="dash-card-meta-line"><i class="bi bi-box-seam"></i> <?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?></span><?php endif; ?>
                                <span class="dash-card-action">Check In →</span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>

                    <?php foreach ($overdueItems as $row):
                        $dtEnd = new DateTime($row['end_datetime'], new DateTimeZone('UTC'));
                        $dtEnd->setTimezone($tz);
                        $nowLocal  = new DateTime('now', $tz);
                        $daysLate  = max(0, (int)floor(($nowLocal->getTimestamp() - $dtEnd->getTimestamp()) / 86400));
                        $badge     = $daysLate >= 1
                            ? $daysLate . ' DAY' . ($daysLate !== 1 ? 'S' : '') . ' LATE'
                            : 'DUE ' . $dtEnd->format('H:i');

                        $coName = $row['checkout_name'] ?: ($row['reservation_name'] ?: ($row['asset_name_cache'] ?: null));
                        if (!$coName) {
                            $coItems = $overdueCheckoutItems[(int)$row['checkout_id']] ?? [];
                            if (!empty($coItems)) {
                                $coName = implode(', ', array_filter(array_column($coItems, 'asset_name')));
                            }
                        }
                        $subtitle  = $coName ?: null;
                        $itemCount = (int)$row['item_count'];
                        $dtOStart  = (new DateTime($row['start_datetime'], new DateTimeZone('UTC')))->setTimezone($tz);
                        $dtOEnd    = (new DateTime($row['end_datetime'],   new DateTimeZone('UTC')))->setTimezone($tz);
                        $oDays     = max(1, (int)round(abs($dtOEnd->getTimestamp() - $dtOStart->getTimestamp()) / 86400));
                        $periodText = $dtOStart->format('M j') . ' – ' . $dtOEnd->format('M j')
                            . ' (' . $oDays . ' day' . ($oDays !== 1 ? 's' : '') . ')';

                        $linkUrl = !empty($row['snipeit_user_id'])
                            ? 'quick_checkin.php?user=' . (int)$row['snipeit_user_id']
                            : 'reservations.php?tab=checkout_history';
                    ?>
                    <a href="<?= h($linkUrl) ?>" class="dash-card dash-card-overdue">
                        <div class="dash-card-top">
                            <div>
                                <div class="dash-card-name"><?= h($row['user_name']) ?></div>
                                <div class="dash-card-sub"><?= h($subtitle ?: '—') ?></div>
                            </div>
                            <span class="dash-badge dash-badge-overdue"><?= h($badge) ?></span>
                        </div>
                        <div class="dash-card-info">
                            <div class="dash-card-meta-line"><i class="bi bi-calendar3"></i> <?= h($periodText) ?></div>
                            <div class="dash-card-meta-bottom">
                                <?php if ($itemCount > 0): ?><span class="dash-card-meta-line"><i class="bi bi-box-seam"></i> <?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?></span><?php endif; ?>
                                <span class="dash-card-action dash-card-action-overdue">Check In →</span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /.dash-columns -->

<!-- ── Schedule ──────────────────────────────────────────────────────── -->
<div class="dash-section-hd">
    <div class="dash-section-dot" style="background: var(--dc-today);"></div>
    <div class="dash-section-title">Schedule</div>
</div>

<div class="gantt-panel">

    <!-- Day selector -->
    <div class="gantt-day-selector">
        <?php foreach ($ganttWeekDays as $i => $wd): ?>
            <button class="gantt-day-btn<?= $wd['isToday'] ? ' active' : '' ?>" data-day="<?= $i ?>">
                <span class="gantt-day-name"><?= h($wd['label']) ?></span>
                <span class="gantt-day-num"><?= h($wd['dayNum']) ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- One panel per day -->
    <?php foreach ($ganttWeekDays as $i => $wd):
        $gd      = $ganttDayData[$i];
        $isToday = $wd['isToday'];
    ?>
    <div class="gantt-day-panel" data-day="<?= $i ?>"<?= $isToday ? '' : ' style="display:none"' ?>>
        <div class="gantt-body">
            <div class="gantt-inner">
                <div class="gantt-row-wrap">
                    <div class="gantt-label-col">
                        <div class="gantt-label-cell">Pickups</div>
                        <div class="gantt-label-cell">Returns</div>
                    </div>
                    <div class="gantt-tracks">
                        <?php foreach ($gd['hours'] as $gh): ?>
                            <div class="gantt-gridline" style="left:<?= gantt_pct($gh['mins'], $gd['startMins'], $gd['span']) ?>;"></div>
                        <?php endforeach; ?>

                        <!-- Pickup track -->
                        <div class="gantt-track">
                            <div class="gantt-track-line"></div>
                            <?php foreach ($gd['pickups'] as $p):
                                $pct   = gantt_pct($p['mins'], $gd['startMins'], $gd['span']);
                                $first = explode(' ', $p['user'])[0];
                            ?>
                                <div class="gantt-event<?= ($p['past'] || $p['done']) ? ' gantt-event-past' : '' ?>" style="left:<?= $pct ?>;" title="<?= h($p['user']) ?>">
                                    <div class="gantt-dot" style="background:var(--dc-pickup);"></div>
                                    <div class="gantt-event-label"><?= h($first) ?><br><?= h($p['time']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Return track -->
                        <div class="gantt-track">
                            <div class="gantt-track-line"></div>
                            <?php foreach ($gd['returns'] as $r):
                                $pct      = gantt_pct($r['mins'], $gd['startMins'], $gd['span']);
                                $dotColor = $r['overdue'] ? 'var(--dc-overdue)' : 'var(--dc-return)';
                                $first    = explode(' ', $r['user'])[0];
                            ?>
                                <div class="gantt-event<?= ($r['past'] || $r['done']) ? ' gantt-event-past' : '' ?>" style="left:<?= $pct ?>;" title="<?= h($r['user']) ?>">
                                    <div class="gantt-dot" style="background:<?= $dotColor ?>;"></div>
                                    <div class="gantt-event-label"><?= h($first) ?><br><?= h($r['time']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($gd['nowPctDay'] !== null): ?>
                            <div class="gantt-now-line" style="left:<?= $gd['nowPctDay'] ?>;"></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="gantt-axis-row">
                    <div class="gantt-axis-spacer"></div>
                    <div class="gantt-axis">
                        <?php foreach ($gd['hours'] as $gh): ?>
                            <span class="gantt-axis-tick" style="left:<?= gantt_pct($gh['mins'], $gd['startMins'], $gd['span']) ?>;"><?= h($gh['label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="gantt-legend">
                    <span class="gantt-legend-item"><span class="gantt-legend-dot" style="background:var(--dc-pickup);"></span> Pickup</span>
                    <span class="gantt-legend-item"><span class="gantt-legend-dot" style="background:var(--dc-return);"></span> Return due</span>
                    <span class="gantt-legend-item"><span class="gantt-legend-dot" style="background:var(--dc-overdue);"></span> Overdue</span>
                    <?php if ($gd['nowPctDay'] !== null): ?><span class="gantt-legend-item"><span class="gantt-legend-line"></span> Now</span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div><!-- /.gantt-panel -->

</div><!-- /.dash-scope -->

        <!-- Items detail modal -->
        <div id="dashItemsBackdrop" style="display:none; position:fixed; inset:0; background:var(--backdrop-modal); z-index:1050;" onclick="closeDashItemsModal()"></div>
        <div id="dashItemsModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeDashItemsModal()">
            <div style="width:fit-content; max-width:90vw; margin:0 auto; background:var(--panel); border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(var(--black-rgb), 0.15);">
                <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid var(--border);">
                    <h5 style="margin:0;" id="dashItemsTitle">Items</h5>
                    <button type="button" onclick="closeDashItemsModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
                </div>
                <div id="dashItemsBody" style="padding:1rem; max-height:70vh; overflow-y:auto;"></div>
            </div>
        </div>

<script>
var dashCheckoutItems    = <?= json_encode($dueCheckoutItems,    JSON_HEX_TAG) ?>;
var dashOverdueItems     = <?= json_encode($overdueCheckoutItems, JSON_HEX_TAG) ?>;
var dashReservationItems = <?= json_encode($upcomingResItems,    JSON_HEX_TAG) ?>;

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

function showDashItems(type, id) {
    var title = document.getElementById('dashItemsTitle');
    var body  = document.getElementById('dashItemsBody');
    var items, html;

    if (type === 'checkout' || type === 'overdue') {
        items = (type === 'overdue' ? dashOverdueItems[id] : dashCheckoutItems[id]) || [];
        title.textContent = type === 'overdue' ? 'Overdue Items' : 'Checkout Items';
        html = '<table class="table table-sm mb-0"><thead><tr><th>Asset Tag</th><th>Name</th><th>Model</th><th>Status</th></tr></thead><tbody>';
        items.forEach(function(ci) {
            var status = ci.checked_in_at ? '<span class="badge bg-secondary">Returned</span>' : '<span class="badge bg-success">Out</span>';
            html += '<tr><td>' + escHtml(ci.asset_tag || '') + '</td><td>' + escHtml(ci.asset_name || '') + '</td><td>' + escHtml(ci.model_name || '') + '</td><td>' + status + '</td></tr>';
        });
        html += '</tbody></table>';
    } else {
        items = dashReservationItems[id] || [];
        title.textContent = 'Reservation Items';
        html = '<table class="table table-sm mb-0"><thead><tr><th>Model</th><th>Qty</th></tr></thead><tbody>';
        items.forEach(function(ri) {
            html += '<tr><td>' + escHtml(ri.name || '') + '</td><td>' + (ri.qty || 0) + '</td></tr>';
        });
        html += '</tbody></table>';
    }

    if (!items.length) {
        html = '<p class="text-muted mb-0">No items found.</p>';
    }

    body.innerHTML = html;
    document.getElementById('dashItemsBackdrop').style.display = 'block';
    document.getElementById('dashItemsModal').style.display    = 'block';
}

function closeDashItemsModal() {
    document.getElementById('dashItemsBackdrop').style.display = 'none';
    document.getElementById('dashItemsModal').style.display    = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('dashItemsModal').style.display === 'block') {
        closeDashItemsModal();
    }
});

// ── Gantt day selector ────────────────────────────────────────────────
(function() {
    var btns   = document.querySelectorAll('.gantt-day-btn');
    var panels = document.querySelectorAll('.gantt-day-panel');
    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            btns.forEach(function(b)   { b.classList.remove('active'); });
            panels.forEach(function(p) { p.style.display = 'none'; });
            this.classList.add('active');
            var panel = document.querySelector('.gantt-day-panel[data-day="' + this.dataset.day + '"]');
            if (panel) panel.style.display = 'block';
        });
    });
})();
</script>

        <?php else: ?>

        <!-- Non-staff user view (unchanged) -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">Browse equipment</h5>
                        <p class="card-text">
                            View the catalogue of equipment models available for users to book.
                            Add items to your basket and request them for specific dates.
                        </p>
                        <a href="catalogue.php" class="btn btn-primary mt-auto">
                            Go to catalogue
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">My Reservations</h5>
                        <p class="card-text">
                            See all of your upcoming and past reservations, including which models you
                            requested, and cancel future bookings where allowed.
                        </p>
                        <a href="my_bookings.php" class="btn btn-outline-primary mt-auto">
                            View my reservations
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php endif; ?>

<?php
$welcomeEnabled = $config['app']['welcome_enabled'] ?? true;
if ($welcomeEnabled):
?>
<div id="welcomeBackdrop" style="display:none; position:fixed; inset:0; background:var(--backdrop-modal); z-index:1050;" onclick="closeWelcomeModal()"></div>
<div id="welcomeModal" style="display:none; position:fixed; inset:0; z-index:1055; overflow-y:auto; padding:1.75rem;" onclick="if(event.target===this)closeWelcomeModal()">
    <div style="max-width:550px; margin:0 auto; background:var(--panel); border-radius:.5rem; box-shadow:0 .5rem 1rem rgba(var(--black-rgb), 0.15);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid var(--border);">
            <h5 style="margin:0;">Welcome to <?= h($config['app']['name'] ?? 'SnipeScheduler') ?></h5>
            <button type="button" onclick="closeWelcomeModal()" style="background:none; border:none; font-size:1.5rem; line-height:1; cursor:pointer; padding:0;">&times;</button>
        </div>
        <div style="padding:1rem;">
            <p class="mb-3">Here's how the equipment booking system works:</p>
            <ol class="mb-3" style="padding-left:1.25rem;">
                <li class="mb-2">
                    <strong>Browse &amp; reserve</strong><br>
                    <span class="text-muted">Visit the catalogue to see available equipment. The catalogue defaults to available kits; to see individual items, click on the "Equipment" tab. Add items to your basket, choose your dates, and submit a reservation request.</span>
                </li>
                <li class="mb-2">
                    <strong>Authorisation</strong><br>
                    <span class="text-muted">Some equipment requires certifications or specific access levels. If an item is restricted, you'll see a badge on it &mdash; contact staff to get authorised.</span>
                </li>
                <li class="mb-2">
                    <strong>Collect &amp; return</strong><br>
                    <span class="text-muted">Pick up your equipment at the start of your reservation. Return it by the scheduled end time and check in with staff.</span>
                </li>
                <li class="mb-2">
                    <strong>Need help?</strong><br>
                    <span class="text-muted">If something is missing from the catalogue or you have questions, please contact staff.</span>
                </li>
            </ol>
            <div class="text-end">
                <button type="button" class="btn btn-primary" onclick="closeWelcomeModal()">Get started</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('snipesched_welcome_dismissed')) return;
    document.getElementById('welcomeBackdrop').style.display = 'block';
    document.getElementById('welcomeModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
});
function closeWelcomeModal() {
    document.getElementById('welcomeBackdrop').style.display = 'none';
    document.getElementById('welcomeModal').style.display = 'none';
    document.body.style.overflow = '';
    localStorage.setItem('snipesched_welcome_dismissed', '1');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('welcomeModal').style.display === 'block') {
        closeWelcomeModal();
    }
});
</script>
<?php endif; ?>

<?php layout_page_end(); ?>
