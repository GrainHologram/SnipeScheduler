<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/notifications.php';

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$email         = trim($_POST['email'] ?? '');

if (!$reservationId || $email === '') {
    die('Invalid request.');
}

// Load reservation
$sql = "
    SELECT *
    FROM reservations
    WHERE id = :id
      AND user_email = :email
      AND status IN ('pending','confirmed')
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id'    => $reservationId,
    ':email' => $email,
]);
$res = $stmt->fetch();

if (!$res) {
    die('Booking not found or cannot be cancelled.');
}

// Check that start time is still in the future
$start = new DateTime($res['start_datetime']);
$now   = new DateTime();

if ($start <= $now) {
    die('You cannot cancel a booking that has already started.');
}

// Update status to cancelled
$upd = $pdo->prepare("
    UPDATE reservations
    SET status = 'cancelled'
    WHERE id = :id
");
$upd->execute([':id' => $reservationId]);

activity_log_event('reservation_cancelled', 'Reservation cancelled', [
    'subject_type' => 'reservation',
    'subject_id'   => $reservationId,
    'metadata'     => [
        'email' => $email,
    ],
]);

// Notifications
$userName = $res['user_name'] ?? $email;
$dmData = get_user_discord_dm_data($email);
send_notification('user', 'reservation_cancelled', [
    'user_email' => $email,
    'user_name'  => $userName,
    'subject'    => 'Reservation cancelled',
    'body_lines' => [
        "Reservation #{$reservationId} has been cancelled.",
    ],
    'discord_embeds' => [build_discord_embed(
        'Reservation Cancelled',
        "Reservation #{$reservationId} has been cancelled.",
        DISCORD_COLOR_GREY,
        []
    )],
] + $dmData);
send_notification('staff', 'reservation_cancelled', [
    'discord_embeds' => [build_discord_embed(
        'Reservation Cancelled',
        "**{$userName}** cancelled reservation #{$reservationId}",
        DISCORD_COLOR_GREY,
        []
    )],
]);

header('Location: my_bookings.php?email=' . urlencode($email) . '&cancelled=1');
exit;
