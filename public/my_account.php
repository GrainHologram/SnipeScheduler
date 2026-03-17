<?php
// my_account.php — User account page with Discord linking and notification preferences.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';

$config  = load_config();
$botCfg  = $config['discord_bot'] ?? [];
$dmEnabled = !empty($botCfg['dm_enabled']) && !empty($botCfg['bot_token']);
$oauthConfigured = trim($botCfg['oauth_client_id'] ?? '') !== '' && trim($botCfg['oauth_client_secret'] ?? '') !== '';

$active  = 'my_account.php';
$isStaff = !empty($currentUser['is_staff']) || !empty($currentUser['is_admin']);
$isAdmin = !empty($currentUser['is_admin']);
$localUserId = (int)($currentUser['id'] ?? 0);

$messages = [];
$errors   = [];

// Flash messages
if (isset($_GET['linked'])) {
    $messages[] = 'Discord account linked successfully.';
}
if (isset($_GET['unlinked'])) {
    $messages[] = 'Discord account unlinked.';
}
if (isset($_GET['saved'])) {
    $messages[] = 'Notification preferences saved.';
}
if (isset($_SESSION['discord_link_error'])) {
    $errors[] = $_SESSION['discord_link_error'];
    unset($_SESSION['discord_link_error']);
}

// Load current user data from DB
$userData = null;
if ($localUserId > 0) {
    $stmt = $pdo->prepare("SELECT discord_user_id, discord_dm_enabled, discord_embed_style FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $localUserId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
}

$discordUserId   = $userData['discord_user_id'] ?? null;
$discordDmEnabled = (bool)($userData['discord_dm_enabled'] ?? true);
$embedStyle       = $userData['discord_embed_style'] ?? 'rich';
$isLinked         = $discordUserId !== null && $discordUserId !== '';

// DM event keys and labels
$dmEvents = [
    'reservation_created'     => 'Reservation created',
    'checkout_created'        => 'Checkout created',
    'checkout_returned'       => 'Items returned',
    'checkout_partial_return' => 'Partial return',
    'late_items'              => 'Late item reminders',
    'account_status'          => 'Account status',
];

// Load per-event preferences
$eventPrefs = [];
if ($isLinked && $localUserId > 0) {
    $stmt = $pdo->prepare("SELECT event_key, enabled FROM user_discord_preferences WHERE user_id = :uid");
    $stmt->execute([':uid' => $localUserId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eventPrefs[$row['event_key']] = (bool)$row['enabled'];
    }
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'unlink_discord') {
        $stmt = $pdo->prepare("UPDATE users SET discord_user_id = NULL WHERE id = :id");
        $stmt->execute([':id' => $localUserId]);
        $_SESSION['user']['discord_user_id'] = null;
        header('Location: my_account.php?unlinked=1');
        exit;
    }

    if ($action === 'save_preferences') {
        // Update global toggles
        $newDmEnabled = isset($_POST['discord_dm_enabled']) ? 1 : 0;
        $newEmbedStyle = ($_POST['discord_embed_style'] ?? 'rich') === 'plain' ? 'plain' : 'rich';

        $stmt = $pdo->prepare("UPDATE users SET discord_dm_enabled = :dme, discord_embed_style = :des WHERE id = :id");
        $stmt->execute([':dme' => $newDmEnabled, ':des' => $newEmbedStyle, ':id' => $localUserId]);

        // Upsert per-event preferences
        $upsertStmt = $pdo->prepare("
            INSERT INTO user_discord_preferences (user_id, event_key, enabled)
            VALUES (:uid, :ek, :en)
            ON DUPLICATE KEY UPDATE enabled = :en2
        ");
        foreach ($dmEvents as $key => $label) {
            $enabled = isset($_POST['dm_event_' . $key]) ? 1 : 0;
            $upsertStmt->execute([':uid' => $localUserId, ':ek' => $key, ':en' => $enabled, ':en2' => $enabled]);
        }

        header('Location: my_account.php?saved=1');
        exit;
    }
}

$appName = h($config['app']['name'] ?? 'SnipeScheduler');
$pageTitle = 'My Account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> - <?= $appName ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body>
<?= layout_logo_tag() ?>
<?= layout_render_nav($active, $isStaff, $isAdmin) ?>

<div class="container py-4" style="max-width:700px;">
    <h4 class="mb-3"><?= h($pageTitle) ?></h4>

    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-success py-2"><?= h($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger py-2"><?= h($err) ?></div>
    <?php endforeach; ?>

    <!-- Discord Account Section -->
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="card-title mb-3">Discord Account</h5>

            <?php if ($isLinked): ?>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-success">Linked</span>
                    <span class="text-muted">Discord ID: <?= h($discordUserId) ?></span>
                </div>
                <form method="post" onsubmit="return confirm('Unlink your Discord account? You will stop receiving DM notifications.');">
                    <input type="hidden" name="action" value="unlink_discord">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Unlink Discord</button>
                </form>
            <?php else: ?>
                <?php if ($oauthConfigured): ?>
                    <p class="text-muted mb-2">Link your Discord account to receive instant DM notifications.</p>
                    <a href="discord_link.php" class="btn btn-primary btn-sm">Link Discord</a>
                <?php else: ?>
                    <p class="text-muted mb-0">Discord account linking is not configured by the administrator.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$dmEnabled): ?>
                <p class="text-muted small mt-2 mb-0">DM notifications are not yet enabled by the administrator.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notification Preferences Section -->
    <?php if ($isLinked): ?>
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="card-title mb-3">Notification Preferences</h5>
            <form method="post">
                <input type="hidden" name="action" value="save_preferences">

                <!-- Global toggle -->
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="discord_dm_enabled" name="discord_dm_enabled" <?= $discordDmEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="discord_dm_enabled">Enable Discord DM notifications</label>
                </div>

                <!-- Embed style -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Message style</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="discord_embed_style" id="style_rich" value="rich" <?= $embedStyle === 'rich' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="style_rich">Rich embeds (coloured cards with fields)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="discord_embed_style" id="style_plain" value="plain" <?= $embedStyle === 'plain' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="style_plain">Plain text</label>
                    </div>
                </div>

                <!-- Per-event toggles -->
                <label class="form-label fw-semibold">Events</label>
                <table class="table table-sm table-bordered mb-3">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th class="text-center" style="width:80px;">DM</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dmEvents as $key => $label):
                            $checked = ($eventPrefs[$key] ?? true) ? 'checked' : '';
                        ?>
                        <tr>
                            <td><?= h($label) ?></td>
                            <td class="text-center">
                                <input type="checkbox" name="dm_event_<?= $key ?>" <?= $checked ?>>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <button type="submit" class="btn btn-primary btn-sm">Save preferences</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php layout_footer(); ?>
</body>
</html>
