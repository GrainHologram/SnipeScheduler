<?php
/**
 * db.php
 *
 * Single PDO connection for the booking app database (snipeit_reservations).
 * This file no longer connects to the live Snipe-IT database at all.
 */

require_once __DIR__ . '/bootstrap.php';

$config = load_config();

if (!is_array($config) || empty($config['db_booking'])) {
    throw new RuntimeException('Booking database configuration (db_booking) is missing in config.php');
}

$db = $config['db_booking'];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['dbname'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO(
        $dsn,
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    throw new RuntimeException('Could not connect to booking database: ' . $e->getMessage(), 0, $e);
}

// Align the MySQL session timezone with the app's configured timezone so that
// CURRENT_TIMESTAMP (used by created_at columns) matches PHP-generated dates.
$appTz = app_get_timezone();
if ($appTz) {
    $offset = (new DateTime('now', $appTz))->format('P'); // e.g. "-05:00"
    $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
}
