<?php
// src/opening_hours.php
// CRUD and validation helpers for facility opening hours.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/datetime_helpers.php';

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// -------------------------------------------------------
// Default weekly schedule
// -------------------------------------------------------

if (!function_exists('oh_get_default_schedule')) {
    function oh_get_default_schedule(): array
    {
        global $pdo;
        $stmt = $pdo->query('SELECT * FROM opening_hours_default ORDER BY day_of_week ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['day_of_week']] = $row;
        }
        return $indexed;
    }
}

if (!function_exists('oh_save_default_schedule')) {
    function oh_save_default_schedule(array $days): void
    {
        global $pdo;
        $stmt = $pdo->prepare('
            REPLACE INTO opening_hours_default (day_of_week, is_closed, open_time, close_time)
            VALUES (:dow, :closed, :open, :close)
        ');
        foreach ($days as $dow => $info) {
            $isClosed = !empty($info['is_closed']) ? 1 : 0;
            $stmt->execute([
                ':dow'   => (int)$dow,
                ':closed' => $isClosed,
                ':open'  => $isClosed ? null : ($info['open_time'] ?? null),
                ':close' => $isClosed ? null : ($info['close_time'] ?? null),
            ]);
        }
    }
}

// -------------------------------------------------------
// Schedule overrides (recurring weekly periods)
// -------------------------------------------------------

if (!function_exists('oh_get_schedules')) {
    function oh_get_schedules(): array
    {
        global $pdo;
        $stmt = $pdo->query('SELECT * FROM opening_hours_schedules ORDER BY start_date ASC');
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dayStmt = $pdo->prepare('SELECT * FROM opening_hours_schedule_days WHERE schedule_id = :id ORDER BY day_of_week ASC');
        foreach ($schedules as &$sched) {
            $dayStmt->execute([':id' => (int)$sched['id']]);
            $sched['days'] = $dayStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($sched);
        return $schedules;
    }
}

if (!function_exists('oh_get_schedule')) {
    function oh_get_schedule(int $id): ?array
    {
        global $pdo;
        $stmt = $pdo->prepare('SELECT * FROM opening_hours_schedules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $sched = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sched) {
            return null;
        }

        $dayStmt = $pdo->prepare('SELECT * FROM opening_hours_schedule_days WHERE schedule_id = :id ORDER BY day_of_week ASC');
        $dayStmt->execute([':id' => $id]);
        $sched['days'] = $dayStmt->fetchAll(PDO::FETCH_ASSOC);
        return $sched;
    }
}

if (!function_exists('oh_create_schedule')) {
    function oh_create_schedule(string $name, string $startDate, string $endDate, array $days): int
    {
        global $pdo;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO opening_hours_schedules (name, start_date, end_date)
                VALUES (:name, :start, :end)
            ');
            $stmt->execute([':name' => $name, ':start' => $startDate, ':end' => $endDate]);
            $schedId = (int)$pdo->lastInsertId();

            $dayStmt = $pdo->prepare('
                INSERT INTO opening_hours_schedule_days (schedule_id, day_of_week, is_closed, open_time, close_time)
                VALUES (:sid, :dow, :closed, :open, :close)
            ');
            foreach ($days as $dow => $info) {
                $isClosed = !empty($info['is_closed']) ? 1 : 0;
                $dayStmt->execute([
                    ':sid'   => $schedId,
                    ':dow'   => (int)$dow,
                    ':closed' => $isClosed,
                    ':open'  => $isClosed ? null : ($info['open_time'] ?? null),
                    ':close' => $isClosed ? null : ($info['close_time'] ?? null),
                ]);
            }
            $pdo->commit();
            return $schedId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('oh_update_schedule')) {
    function oh_update_schedule(int $id, string $name, string $startDate, string $endDate, array $days): void
    {
        global $pdo;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE opening_hours_schedules SET name = :name, start_date = :start, end_date = :end WHERE id = :id
            ');
            $stmt->execute([':id' => $id, ':name' => $name, ':start' => $startDate, ':end' => $endDate]);

            $pdo->prepare('DELETE FROM opening_hours_schedule_days WHERE schedule_id = :id')->execute([':id' => $id]);

            $dayStmt = $pdo->prepare('
                INSERT INTO opening_hours_schedule_days (schedule_id, day_of_week, is_closed, open_time, close_time)
                VALUES (:sid, :dow, :closed, :open, :close)
            ');
            foreach ($days as $dow => $info) {
                $isClosed = !empty($info['is_closed']) ? 1 : 0;
                $dayStmt->execute([
                    ':sid'   => $id,
                    ':dow'   => (int)$dow,
                    ':closed' => $isClosed,
                    ':open'  => $isClosed ? null : ($info['open_time'] ?? null),
                    ':close' => $isClosed ? null : ($info['close_time'] ?? null),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('oh_delete_schedule')) {
    function oh_delete_schedule(int $id): void
    {
        global $pdo;
        $pdo->prepare('DELETE FROM opening_hours_schedules WHERE id = :id')->execute([':id' => $id]);
    }
}

// -------------------------------------------------------
// One-off overrides
// -------------------------------------------------------

if (!function_exists('oh_get_overrides')) {
    function oh_get_overrides(): array
    {
        global $pdo;
        $stmt = $pdo->query('SELECT * FROM opening_hours_overrides ORDER BY start_datetime ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('oh_create_override')) {
    function oh_create_override(string $label, string $startUtc, string $endUtc, string $type): int
    {
        global $pdo;
        $stmt = $pdo->prepare('
            INSERT INTO opening_hours_overrides (label, start_datetime, end_datetime, override_type)
            VALUES (:label, :start, :end, :type)
        ');
        $stmt->execute([':label' => $label, ':start' => $startUtc, ':end' => $endUtc, ':type' => $type]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('oh_update_override')) {
    function oh_update_override(int $id, string $label, string $startUtc, string $endUtc, string $type): void
    {
        global $pdo;
        $stmt = $pdo->prepare('
            UPDATE opening_hours_overrides
            SET label = :label, start_datetime = :start, end_datetime = :end, override_type = :type
            WHERE id = :id
        ');
        $stmt->execute([':id' => $id, ':label' => $label, ':start' => $startUtc, ':end' => $endUtc, ':type' => $type]);
    }
}

if (!function_exists('oh_delete_override')) {
    function oh_delete_override(int $id): void
    {
        global $pdo;
        $pdo->prepare('DELETE FROM opening_hours_overrides WHERE id = :id')->execute([':id' => $id]);
    }
}

// -------------------------------------------------------
// Resolution: effective hours for a given date
// -------------------------------------------------------

if (!function_exists('oh_get_hours_for_date')) {
    /**
     * Get effective opening hours for a date string (app-timezone, Y-m-d).
     * Priority: one-off overrides > schedule overrides > default.
     *
     * @return array{is_closed: bool, open_time: ?string, close_time: ?string, source: string}
     */
    function oh_get_hours_for_date(string $dateStr): array
    {
        global $pdo;
        $appTz = app_get_timezone();

        // 1. Check one-off overrides whose UTC range covers any part of this app-tz date
        $dayStart = new DateTime($dateStr . ' 00:00:00', $appTz);
        $dayEnd   = new DateTime($dateStr . ' 23:59:59', $appTz);
        $dayStartUtc = (clone $dayStart)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $dayEndUtc   = (clone $dayEnd)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare('
            SELECT * FROM opening_hours_overrides
            WHERE start_datetime <= :day_end AND end_datetime >= :day_start
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([':day_start' => $dayStartUtc, ':day_end' => $dayEndUtc]);
        $override = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($override) {
            $isClosed = ($override['override_type'] === 'closed');
            return [
                'is_closed'  => $isClosed,
                'open_time'  => $isClosed ? null : '00:00:00',
                'close_time' => $isClosed ? null : '23:59:59',
                'source'     => 'override: ' . ($override['label'] ?: 'One-off #' . $override['id']),
            ];
        }

        // 2. Check schedule overrides active on this date
        $dow = (int)(new DateTime($dateStr))->format('N'); // 1=Mon..7=Sun
        $stmt = $pdo->prepare('
            SELECT sd.* FROM opening_hours_schedule_days sd
            JOIN opening_hours_schedules s ON s.id = sd.schedule_id
            WHERE s.start_date <= :date AND s.end_date >= :date
              AND sd.day_of_week = :dow
            ORDER BY s.id DESC LIMIT 1
        ');
        $stmt->execute([':date' => $dateStr, ':dow' => $dow]);
        $schedDay = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($schedDay) {
            return [
                'is_closed'  => (bool)$schedDay['is_closed'],
                'open_time'  => $schedDay['open_time'],
                'close_time' => $schedDay['close_time'],
                'source'     => 'schedule',
            ];
        }

        // 3. Default schedule
        $stmt = $pdo->prepare('SELECT * FROM opening_hours_default WHERE day_of_week = :dow');
        $stmt->execute([':dow' => $dow]);
        $default = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($default) {
            return [
                'is_closed'  => (bool)$default['is_closed'],
                'open_time'  => $default['open_time'],
                'close_time' => $default['close_time'],
                'source'     => 'default',
            ];
        }

        // No data at all — treat as closed
        return ['is_closed' => true, 'open_time' => null, 'close_time' => null, 'source' => 'none'];
    }
}

if (!function_exists('oh_is_open_at')) {
    /**
     * Check if the facility is open at a specific UTC datetime.
     */
    function oh_is_open_at(DateTime $utcDt): bool
    {
        $appTz = app_get_timezone();
        $local = (clone $utcDt)->setTimezone($appTz);
        $dateStr = $local->format('Y-m-d');
        $timeStr = $local->format('H:i:s');

        $hours = oh_get_hours_for_date($dateStr);
        if ($hours['is_closed']) {
            return false;
        }
        if ($hours['open_time'] === null || $hours['close_time'] === null) {
            return false;
        }
        return ($timeStr >= $hours['open_time'] && $timeStr <= $hours['close_time']);
    }
}

if (!function_exists('oh_validate_reservation_window')) {
    /**
     * Validate a reservation start/end (UTC) against opening hours.
     * Returns an array of error strings (empty = valid).
     */
    function oh_validate_reservation_window(DateTime $startUtc, DateTime $endUtc): array
    {
        global $dayNames;
        $errors = [];
        $appTz = app_get_timezone();

        $localStart = (clone $startUtc)->setTimezone($appTz);
        $localEnd   = (clone $endUtc)->setTimezone($appTz);

        // Check start time
        $startDate = $localStart->format('Y-m-d');
        $startTime = $localStart->format('H:i:s');
        $startHours = oh_get_hours_for_date($startDate);
        $startDow = (int)$localStart->format('N');
        $startDayName = $dayNames[$startDow] ?? $startDate;

        if ($startHours['is_closed']) {
            $errors[] = 'Collection date (' . $startDayName . ', ' . app_format_date($startDate) . ') is outside opening hours — the facility is closed.';
        } elseif ($startHours['open_time'] !== null && $startHours['close_time'] !== null) {
            if ($startTime < $startHours['open_time'] || $startTime > $startHours['close_time']) {
                $openFmt = substr($startHours['open_time'], 0, 5);
                $closeFmt = substr($startHours['close_time'], 0, 5);
                $errors[] = 'Collection time (' . $localStart->format('H:i') . ') is outside opening hours on ' . $startDayName . ' (' . $openFmt . ' – ' . $closeFmt . ').';
            }
        }

        // Check end time
        $endDate = $localEnd->format('Y-m-d');
        $endTime = $localEnd->format('H:i:s');
        $endHours = oh_get_hours_for_date($endDate);
        $endDow = (int)$localEnd->format('N');
        $endDayName = $dayNames[$endDow] ?? $endDate;

        if ($endHours['is_closed']) {
            $errors[] = 'Return date (' . $endDayName . ', ' . app_format_date($endDate) . ') is outside opening hours — the facility is closed.';
        } elseif ($endHours['open_time'] !== null && $endHours['close_time'] !== null) {
            if ($endTime < $endHours['open_time'] || $endTime > $endHours['close_time']) {
                $openFmt = substr($endHours['open_time'], 0, 5);
                $closeFmt = substr($endHours['close_time'], 0, 5);
                $errors[] = 'Return time (' . $localEnd->format('H:i') . ') is outside opening hours on ' . $endDayName . ' (' . $openFmt . ' – ' . $closeFmt . ').';
            }
        }

        return $errors;
    }
}

// -------------------------------------------------------
// Slot availability: find the first open slot with capacity
// -------------------------------------------------------

if (!function_exists('oh_first_available_slot')) {
    /**
     * Find the earliest available return slot at or after a UTC datetime.
     *
     * Iterates open hours forward (up to 14 days) in $intervalMinutes increments
     * and returns the first slot that is within open hours AND has remaining
     * capacity (based on reservation end times and active checkout returns
     * scheduled in that slot window).
     *
     * @param DateTime $utcDt           UTC datetime to start searching from
     * @param int      $intervalMinutes Slot size in minutes (default 15)
     * @return DateTime|null            UTC datetime of the first available slot, or null if none within 14 days
     */
    function oh_first_available_slot(DateTime $utcDt, int $intervalMinutes = 15): ?DateTime
    {
        global $pdo;
        require_once SRC_PATH . '/db.php';

        $config = load_config();
        $slotCapacity = (int)($config['app']['slot_capacity'] ?? 0);
        $appTz = app_get_timezone();
        $utcTz = new DateTimeZone('UTC');

        // Work in app timezone for hour comparisons
        $localDt = (clone $utcDt)->setTimezone($appTz);
        $startDate = $localDt->format('Y-m-d');

        $maxDays = 14;
        $intervalSec = $intervalMinutes * 60;

        for ($dayOffset = 0; $dayOffset <= $maxDays; $dayOffset++) {
            $dateStr = (new DateTime($startDate, $appTz))
                ->modify("+{$dayOffset} days")
                ->format('Y-m-d');

            $hours = oh_get_hours_for_date($dateStr);
            if ($hours['is_closed'] || $hours['open_time'] === null || $hours['close_time'] === null) {
                continue;
            }

            $dayOpen  = new DateTime($dateStr . ' ' . $hours['open_time'], $appTz);
            $dayClose = new DateTime($dateStr . ' ' . $hours['close_time'], $appTz);

            // Capacity 0 = unlimited — return first open slot at or after input
            if ($slotCapacity <= 0) {
                $slotLocal = clone $dayOpen;
                if ($dayOffset === 0 && $localDt > $dayOpen) {
                    $secSinceOpen = $localDt->getTimestamp() - $dayOpen->getTimestamp();
                    $slotsToSkip = (int)ceil($secSinceOpen / $intervalSec);
                    $slotLocal->modify('+' . ($slotsToSkip * $intervalSec) . ' seconds');
                }
                if ($slotLocal <= $dayClose) {
                    return (clone $slotLocal)->setTimezone($utcTz);
                }
                continue;
            }

            // Batch-query events for this day (2 queries per day instead of 2 per slot)
            $windowStartUtc = (clone $dayOpen)->setTimezone($utcTz)->format('Y-m-d H:i:s');
            $windowEndUtc   = (clone $dayClose)->setTimezone($utcTz)->format('Y-m-d H:i:s');

            // Reservation starts + ends in this day window
            $stmtRes = $pdo->prepare("
                SELECT start_datetime, end_datetime
                FROM reservations
                WHERE status IN ('pending','confirmed')
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

            // Checkout starts + ends in this day window
            $stmtCo = $pdo->prepare("
                SELECT start_datetime, end_datetime
                FROM checkouts
                WHERE status IN ('open','partial')
                  AND (
                    (start_datetime >= :ws1 AND start_datetime < :we1)
                    OR (end_datetime >= :ws2 AND end_datetime < :we2)
                  )
            ");
            $stmtCo->execute([
                ':ws1' => $windowStartUtc, ':we1' => $windowEndUtc,
                ':ws2' => $windowStartUtc, ':we2' => $windowEndUtc,
            ]);
            $checkouts = $stmtCo->fetchAll(PDO::FETCH_ASSOC);

            // Build slot counts for this day
            $openTimeStr  = substr($hours['open_time'], 0, 5);
            $closeTimeStr = substr($hours['close_time'], 0, 5);
            $slotCounts = [];
            $slotKeys = [];
            $cursor = clone $dayOpen;
            while ($cursor <= $dayClose) {
                $key = $cursor->format('H:i');
                $slotCounts[$key] = 0;
                $slotKeys[] = $key;
                $cursor->modify("+{$intervalMinutes} minutes");
            }

            // Bucket helper
            $bucketEvent = function (string $utcStr) use ($appTz, $dateStr, $openTimeStr, $closeTimeStr, $intervalMinutes, &$slotCounts) {
                $dt = new DateTime($utcStr, new DateTimeZone('UTC'));
                $dt->setTimezone($appTz);
                if ($dt->format('Y-m-d') !== $dateStr) {
                    return;
                }
                $time = $dt->format('H:i');
                if ($time < $openTimeStr || $time > $closeTimeStr) {
                    return;
                }
                $totalMin = (int)$dt->format('H') * 60 + (int)$dt->format('i');
                $slotMin  = (int)(floor($totalMin / $intervalMinutes) * $intervalMinutes);
                $slotKey  = sprintf('%02d:%02d', intdiv($slotMin, 60), $slotMin % 60);
                if (isset($slotCounts[$slotKey])) {
                    $slotCounts[$slotKey]++;
                }
            };

            foreach ($reservations as $r) {
                $bucketEvent($r['start_datetime']);
                $bucketEvent($r['end_datetime']);
            }
            foreach ($checkouts as $co) {
                $bucketEvent($co['start_datetime']);
                $bucketEvent($co['end_datetime']);
            }

            // Find first slot at or after input with remaining capacity
            foreach ($slotKeys as $key) {
                $slotDt = new DateTime($dateStr . ' ' . $key, $appTz);
                // On the first day, skip slots before the input time
                if ($dayOffset === 0 && $slotDt < $localDt) {
                    continue;
                }
                if ($slotCounts[$key] < $slotCapacity) {
                    return (clone $slotDt)->setTimezone($utcTz);
                }
            }
        }

        // No available slot within 14 days
        return null;
    }
}
