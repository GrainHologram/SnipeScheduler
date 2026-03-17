<?php
// cron_mark_missed.php
// Mark reservations as "missed" if they were not checked out within a cutoff window.
//
// Run via cron, e.g.:
//   */10 * * * * /usr/bin/php /path/to/scripts/cron_mark_missed.php >> /var/log/layout_missed.log 2>&1

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/notifications.php';

$config = load_config();

$appCfg         = $config['app'] ?? [];
$cutoffMinutes  = isset($appCfg['missed_cutoff_minutes']) ? (int)$appCfg['missed_cutoff_minutes'] : 60;
$cutoffMinutes  = max(1, $cutoffMinutes);

// Ensure the status column includes 'missed' in the ENUM definition.
try {
    $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = $col['Type'] ?? '';
    if ($type !== '' && stripos($type, 'missed') === false) {
        $pdo->exec("
            ALTER TABLE reservations
            MODIFY status ENUM('pending','confirmed','fulfilled','cancelled','missed')
            NOT NULL DEFAULT 'pending'
        ");
        echo "[" . date('Y-m-d H:i:s') . "] Updated reservations.status enum to include 'missed'\n";
    }
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Warning: could not verify/alter status column: " . $e->getMessage() . "\n";
}

// Use DB server time to avoid PHP/DB drift.
$sql = "
    UPDATE reservations
       SET status = 'missed'
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
";

$pdo->beginTransaction();

$selectStmt = $pdo->prepare("
    SELECT id
      FROM reservations
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
");
$selectStmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$selectStmt->execute();
$missedIds = $selectStmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$stmt->execute();

$affected = $stmt->rowCount();

$pdo->commit();

// Build asset summaries per reservation for logging.
$assetsByReservation = [];
$missedIdInts = array_values(array_filter(array_map('intval', $missedIds), static function (int $id): bool {
    return $id > 0;
}));
if (!empty($missedIdInts)) {
    $placeholders = implode(',', array_fill(0, count($missedIdInts), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT reservation_id, model_name_cache, quantity
          FROM reservation_items
         WHERE reservation_id IN ({$placeholders})
    ");
    $itemsStmt->execute($missedIdInts);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $rid = (int)($row['reservation_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $name = trim((string)($row['model_name_cache'] ?? ''));
        $qty = (int)($row['quantity'] ?? 0);
        if ($name === '') {
            $name = 'Item';
        }
        $label = $qty > 1 ? ($name . ' (x' . $qty . ')') : $name;
        $assetsByReservation[$rid][] = $label;
    }
}

// Fetch user info for missed reservations (for notifications)
$missedReservations = [];
if (!empty($missedIdInts)) {
    $placeholders2 = implode(',', array_fill(0, count($missedIdInts), '?'));
    $resStmt = $pdo->prepare("SELECT id, user_name, user_email FROM reservations WHERE id IN ({$placeholders2})");
    $resStmt->execute($missedIdInts);
    foreach ($resStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $missedReservations[(int)$r['id']] = $r;
    }
}

foreach ($missedIds as $missedId) {
    $resId = (int)$missedId;
    if ($resId > 0) {
        activity_log_event('reservation_missed', 'Reservation marked as missed', [
            'subject_type' => 'reservation',
            'subject_id'   => $resId,
            'metadata'     => [
                'assets' => $assetsByReservation[$resId] ?? [],
                'cutoff_minutes' => $cutoffMinutes,
            ],
        ]);

        // Send missed notification
        $missedRes = $missedReservations[$resId] ?? [];
        $missedUserEmail = $missedRes['user_email'] ?? '';
        $missedUserName  = $missedRes['user_name'] ?? $missedUserEmail;
        $missedAssets    = implode(', ', $assetsByReservation[$resId] ?? []);
        $missedBodyLines = [
            "Reservation #{$resId} has been marked as missed.",
            $missedAssets !== '' ? "Items: {$missedAssets}" : '',
            "The reservation was not collected within the {$cutoffMinutes}-minute window.",
        ];
        if ($missedUserEmail !== '') {
            $dmData = get_user_discord_dm_data($missedUserEmail);
            send_notification('user', 'reservation_missed', [
                'user_email' => $missedUserEmail,
                'user_name'  => $missedUserName,
                'subject'    => 'Reservation missed',
                'body_lines' => $missedBodyLines,
                'discord_embeds' => [build_discord_embed(
                    'Reservation Missed',
                    "Reservation #{$resId} has been marked as missed.",
                    DISCORD_COLOR_RED,
                    array_filter([
                        $missedAssets !== '' ? ['name' => 'Items', 'value' => $missedAssets, 'inline' => false] : null,
                    ])
                )],
            ] + $dmData);
        }
        send_notification('staff', 'reservation_missed', [
            'discord_embeds' => [build_discord_embed(
                'Reservation Missed',
                "**{$missedUserName}** missed reservation #{$resId}",
                DISCORD_COLOR_RED,
                array_filter([
                    $missedAssets !== '' ? ['name' => 'Items', 'value' => $missedAssets, 'inline' => false] : null,
                ])
            )],
        ]);
    }
}

echo sprintf(
    "[%s] Marked %d reservation(s) as missed (cutoff %d minutes)\n",
    date('Y-m-d H:i:s'),
    $affected,
    $cutoffMinutes
);
