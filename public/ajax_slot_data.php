<?php
// ajax_slot_data.php
// JSON endpoint for the slot picker. Two modes:
//   ?month=2026-03       → open/closed status for each day in the month
//   ?date=2026-03-02     → time slots with capacity for a single day
// Optional: bypass_capacity=1 (staff), bypass_closed=1 (admin)

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/opening_hours.php';

header('Content-Type: application/json; charset=utf-8');

$config  = load_config();
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$appTz   = app_get_timezone($config);

$intervalMinutes = max(1, (int)($config['app']['slot_interval_minutes'] ?? 15));
$slotCapacity    = max(0, (int)($config['app']['slot_capacity'] ?? 0)); // 0 = unlimited

$bypassCapacity = !empty($_GET['bypass_capacity']) && $isStaff;
$bypassClosed   = !empty($_GET['bypass_closed']) && $isAdmin;

// -------------------------------------------------------
// Month mode
// -------------------------------------------------------
if (!empty($_GET['month'])) {
    $monthStr = $_GET['month'];
    if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid month format. Use YYYY-MM.']);
        exit;
    }

    $year  = (int)substr($monthStr, 0, 4);
    $month = (int)substr($monthStr, 5, 2);
    if ($month < 1 || $month > 12) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid month.']);
        exit;
    }

    $daysInMonth = (int)(new DateTime("$year-$month-01"))->format('t');
    $days = [];

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $hours = oh_get_hours_for_date($dateStr);

        if ($hours['is_closed']) {
            $days[$dateStr] = ['is_closed' => true];
        } else {
            $days[$dateStr] = [
                'is_closed'  => false,
                'open_time'  => $hours['open_time'] ? substr($hours['open_time'], 0, 5) : null,
                'close_time' => $hours['close_time'] ? substr($hours['close_time'], 0, 5) : null,
            ];
        }
    }

    echo json_encode(['month' => $monthStr, 'days' => $days]);
    exit;
}

// -------------------------------------------------------
// Day mode
// -------------------------------------------------------
if (!empty($_GET['date'])) {
    $dateStr = $_GET['date'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
        exit;
    }

    $hours = oh_get_hours_for_date($dateStr);

    // Determine slot window
    if ($hours['is_closed'] && !$bypassClosed) {
        echo json_encode(['date' => $dateStr, 'slots' => [], 'is_closed' => true]);
        exit;
    }

    if ($hours['is_closed'] && $bypassClosed) {
        // Admin override: treat closed day as fully open
        $openTime  = '00:00';
        $closeTime = '23:59';
    } else {
        $openTime  = $hours['open_time'] ? substr($hours['open_time'], 0, 5) : '00:00';
        $closeTime = $hours['close_time'] ? substr($hours['close_time'], 0, 5) : '23:59';
    }

    // Build slot list
    $slotStart = new DateTime($dateStr . ' ' . $openTime, $appTz);
    $slotEnd   = new DateTime($dateStr . ' ' . $closeTime, $appTz);
    $interval  = new DateInterval('PT' . $intervalMinutes . 'M');

    $slots = [];
    $slotTimes = [];
    $cursor = clone $slotStart;
    while ($cursor <= $slotEnd) {
        $slotTimes[] = clone $cursor;
        $cursor->add($interval);
    }

    if (empty($slotTimes)) {
        echo json_encode(['date' => $dateStr, 'slots' => [], 'is_closed' => false]);
        exit;
    }

    // -------------------------------------------------------
    // Capacity counting — single-pass approach
    // -------------------------------------------------------
    // Convert day window to UTC for DB queries
    $windowStartUtc = (clone $slotStart)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $windowEndUtc   = (clone $slotEnd)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    // 1. Active reservations (pending/confirmed) overlapping this day's window
    $stmtRes = $pdo->prepare("
        SELECT start_datetime, end_datetime
        FROM reservations
        WHERE status IN ('pending', 'confirmed')
          AND start_datetime < :window_end
          AND end_datetime > :window_start
    ");
    $stmtRes->execute([
        ':window_start' => $windowStartUtc,
        ':window_end'   => $windowEndUtc,
    ]);
    $reservations = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

    // 2. Active checkouts (open/partial) overlapping this day's window
    $stmtCo = $pdo->prepare("
        SELECT c.start_datetime, c.end_datetime
        FROM checkouts c
        WHERE c.status IN ('open', 'partial')
          AND c.start_datetime < :window_end
          AND c.end_datetime > :window_start
    ");
    $stmtCo->execute([
        ':window_start' => $windowStartUtc,
        ':window_end'   => $windowEndUtc,
    ]);
    $checkouts = $stmtCo->fetchAll(PDO::FETCH_ASSOC);

    // Bucket bookings into slots
    // Each slot represents a point in time; a booking overlaps the slot if
    // booking.start < slot + interval AND booking.end > slot
    foreach ($slotTimes as $slotDt) {
        $timeLabel = $slotDt->format('H:i');
        $slotUtc      = (clone $slotDt)->setTimezone(new DateTimeZone('UTC'));
        $slotEndDt    = (clone $slotDt)->add($interval);
        $slotEndUtc   = (clone $slotEndDt)->setTimezone(new DateTimeZone('UTC'));

        $slotUtcStr    = $slotUtc->format('Y-m-d H:i:s');
        $slotEndUtcStr = $slotEndUtc->format('Y-m-d H:i:s');

        $booked = 0;

        // Count reservations overlapping this slot
        foreach ($reservations as $r) {
            if ($r['start_datetime'] < $slotEndUtcStr && $r['end_datetime'] > $slotUtcStr) {
                $booked++;
            }
        }

        // Count active checkouts overlapping this slot
        foreach ($checkouts as $co) {
            if ($co['start_datetime'] < $slotEndUtcStr && $co['end_datetime'] > $slotUtcStr) {
                $booked++;
            }
        }

        if ($slotCapacity <= 0) {
            // Unlimited capacity
            $remaining = null;
        } elseif ($bypassCapacity) {
            $remaining = $slotCapacity;
        } else {
            $remaining = max(0, $slotCapacity - $booked);
        }

        $slots[] = [
            'time'      => $timeLabel,
            'capacity'  => $slotCapacity,
            'booked'    => $booked,
            'remaining' => $remaining,
        ];
    }

    echo json_encode(['date' => $dateStr, 'slots' => $slots, 'is_closed' => false]);
    exit;
}

// No valid mode
http_response_code(400);
echo json_encode(['error' => 'Provide either ?month=YYYY-MM or ?date=YYYY-MM-DD']);
