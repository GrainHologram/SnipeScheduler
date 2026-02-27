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
$cooldownSlots   = max(0, (int)($config['app']['cooldown_slots'] ?? 0));

$bypassCapacity = !empty($_GET['bypass_capacity']) && $isStaff;
$bypassClosed   = !empty($_GET['bypass_closed']) && $isAdmin;

// -------------------------------------------------------
// Next-open mode: find the next open slot from now (start)
// and the next open slot >= 23 hours later (end).
// -------------------------------------------------------
if (!empty($_GET['next_open'])) {
    $now = new DateTime('now', $appTz);

    // Helper: find the first available slot on or after $fromDt.
    // Searches up to 14 days ahead. Returns 'YYYY-MM-DDTHH:MM' or null.
    $findNextOpenSlot = function (DateTime $fromDt) use ($appTz, $intervalMinutes, $bypassClosed) {
        for ($dayOffset = 0; $dayOffset < 14; $dayOffset++) {
            $checkDate = (clone $fromDt)->modify("+{$dayOffset} days");
            $dateStr = $checkDate->format('Y-m-d');
            $hours = oh_get_hours_for_date($dateStr);

            if ($hours['is_closed'] && !$bypassClosed) {
                continue;
            }

            if ($hours['is_closed'] && $bypassClosed) {
                $openTime  = '00:00';
                $closeTime = '23:59';
            } else {
                $openTime  = $hours['open_time'] ? substr($hours['open_time'], 0, 5) : '00:00';
                $closeTime = $hours['close_time'] ? substr($hours['close_time'], 0, 5) : '23:59';
            }

            // Build slots for this day
            $slotStart = new DateTime($dateStr . ' ' . $openTime, $appTz);
            $slotEnd   = new DateTime($dateStr . ' ' . $closeTime, $appTz);
            $interval  = new DateInterval('PT' . $intervalMinutes . 'M');

            $cursor = clone $slotStart;
            while ($cursor <= $slotEnd) {
                if ($cursor >= $fromDt) {
                    return $cursor->format('Y-m-d\TH:i');
                }
                $cursor->add($interval);
            }
        }
        return null;
    };

    $startStr = $findNextOpenSlot($now);
    if ($startStr === null) {
        echo json_encode(['error' => 'No open slots found in the next 14 days.']);
        exit;
    }

    $minEndDt = new DateTime($startStr, $appTz);
    $minEndDt->modify('+23 hours');
    $endStr = $findNextOpenSlot($minEndDt);

    echo json_encode([
        'start' => $startStr,
        'end'   => $endStr, // may be null if nothing found
    ]);
    exit;
}

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
    while (true) {
        $slotEndTime = clone $cursor;
        $slotEndTime->add($interval);
        if ($slotEndTime > $slotEnd) {
            break;
        }
        $slotTimes[] = clone $cursor;
        $cursor->add($interval);
    }

    if (empty($slotTimes)) {
        echo json_encode(['date' => $dateStr, 'slots' => [], 'is_closed' => false]);
        exit;
    }

    // -------------------------------------------------------
    // Capacity counting — event-based approach
    // -------------------------------------------------------
    // Slot capacity limits concurrent scheduling events (pickups and
    // returns) at a given time, NOT how many ongoing checkouts exist.
    // A reservation starting Mon 9am only counts against Mon's 9:00
    // slot; a checkout ending Fri 3pm only counts against Fri's 3:00
    // slot.  The old overlap approach incorrectly counted multi-day
    // bookings against every slot in between.
    // -------------------------------------------------------

    // Convert day window to UTC for DB queries
    $windowStartUtc = (clone $slotStart)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $windowEndUtc   = (clone $slotEnd)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    // 1. Reservations (pending/confirmed) with start or end in today's window
    $stmtRes = $pdo->prepare("
        SELECT start_datetime, end_datetime
        FROM reservations
        WHERE status IN ('pending', 'confirmed')
          AND (
            (start_datetime >= :ws1 AND start_datetime < :we1)
            OR (end_datetime >= :ws2 AND end_datetime < :we2)
          )
    ");
    $stmtRes->execute([
        ':ws1' => $windowStartUtc, ':we1' => $windowEndUtc,
        ':ws2' => $windowStartUtc, ':we2' => $windowEndUtc,
    ]);
    $reservations = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

    // 2. Active checkouts (open/partial) with start or end in today's window
    $stmtCo = $pdo->prepare("
        SELECT c.start_datetime, c.end_datetime
        FROM checkouts c
        WHERE c.status IN ('open', 'partial')
          AND (
            (c.start_datetime >= :ws1 AND c.start_datetime < :we1)
            OR (c.end_datetime >= :ws2 AND c.end_datetime < :we2)
          )
    ");
    $stmtCo->execute([
        ':ws1' => $windowStartUtc, ':we1' => $windowEndUtc,
        ':ws2' => $windowStartUtc, ':we2' => $windowEndUtc,
    ]);
    $checkouts = $stmtCo->fetchAll(PDO::FETCH_ASSOC);

    // Build slot counts map
    $slotCounts = [];
    foreach ($slotTimes as $slotDt) {
        $slotCounts[$slotDt->format('H:i')] = 0;
    }

    // Helper: bucket a UTC datetime string into the matching slot
    $bucketEvent = function (string $utcStr) use ($appTz, $dateStr, $openTime, $closeTime, $intervalMinutes, &$slotCounts) {
        $dt = new DateTime($utcStr, new DateTimeZone('UTC'));
        $dt->setTimezone($appTz);

        // Must fall on the target date
        if ($dt->format('Y-m-d') !== $dateStr) {
            return;
        }
        $time = $dt->format('H:i');
        if ($time < $openTime || $time > $closeTime) {
            return;
        }

        // Round down to nearest slot interval
        $totalMin = (int)$dt->format('H') * 60 + (int)$dt->format('i');
        $slotMin  = (int)(floor($totalMin / $intervalMinutes) * $intervalMinutes);
        $slotKey  = sprintf('%02d:%02d', intdiv($slotMin, 60), $slotMin % 60);

        if (isset($slotCounts[$slotKey])) {
            $slotCounts[$slotKey]++;
        }
    };

    // Bucket reservation starts and ends
    foreach ($reservations as $r) {
        $bucketEvent($r['start_datetime']);
        $bucketEvent($r['end_datetime']);
    }

    // Bucket checkout starts and ends
    foreach ($checkouts as $co) {
        $bucketEvent($co['start_datetime']);
        $bucketEvent($co['end_datetime']);
    }

    // Build slot response
    $totalSlots = count($slotTimes);
    $cooldownStart = ($cooldownSlots > 0 && $slotCapacity > 0)
        ? max(0, $totalSlots - $cooldownSlots)
        : $totalSlots; // no cooldown

    foreach ($slotTimes as $slotIdx => $slotDt) {
        $timeLabel = $slotDt->format('H:i');
        $booked = $slotCounts[$timeLabel] ?? 0;
        $isCooldown = ($slotIdx >= $cooldownStart);
        $effectiveCapacity = $isCooldown
            ? (int)ceil($slotCapacity / 2)
            : $slotCapacity;

        if ($slotCapacity <= 0) {
            // Unlimited capacity
            $remaining = null;
        } elseif ($bypassCapacity) {
            $remaining = $effectiveCapacity;
        } else {
            $remaining = max(0, $effectiveCapacity - $booked);
        }

        $slot = [
            'time'      => $timeLabel,
            'capacity'  => $effectiveCapacity,
            'booked'    => $booked,
            'remaining' => $remaining,
        ];
        if ($isCooldown) {
            $slot['cooldown'] = true;
        }
        $slots[] = $slot;
    }

    echo json_encode(['date' => $dateStr, 'slots' => $slots, 'is_closed' => false]);
    exit;
}

// No valid mode
http_response_code(400);
echo json_encode(['error' => 'Provide either ?month=YYYY-MM or ?date=YYYY-MM-DD']);
