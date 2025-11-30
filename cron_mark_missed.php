<?php
// cron_mark_missed.php
// Mark reservations as "missed" if they were not checked out within a cutoff window.
//
// Run via cron, e.g.:
//   */10 * * * * /usr/bin/php /path/to/cron_mark_missed.php >> /var/log/reserveit_missed.log 2>&1

require_once __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

$appCfg         = $config['app'] ?? [];
$cutoffMinutes  = isset($appCfg['missed_cutoff_minutes']) ? (int)$appCfg['missed_cutoff_minutes'] : 60;
$cutoffMinutes  = max(1, $cutoffMinutes);

// Use DB server time to avoid PHP/DB drift.
$sql = "
    UPDATE reservations
       SET status = 'missed'
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$stmt->execute();

$affected = $stmt->rowCount();

echo sprintf(
    "[%s] Marked %d reservation(s) as missed (cutoff %d minutes)\n",
    date('Y-m-d H:i:s'),
    $affected,
    $cutoffMinutes
);
