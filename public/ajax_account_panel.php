<?php
// ajax_account_panel.php — Account panel content for the v2 slide-in panel.
// GET:  returns HTML fragment rendered into the panel body.
// POST: handles account actions (unlink_discord, save_preferences) and returns JSON.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$config  = load_config();
$botCfg  = $config['discord_bot'] ?? [];
$dmEnabled       = !empty($botCfg['dm_enabled']) && !empty($botCfg['bot_token']);
$oauthConfigured = trim($botCfg['oauth_client_id'] ?? '') !== '' && trim($botCfg['oauth_client_secret'] ?? '') !== '';

$localUserId = (int)($currentUser['id'] ?? 0);

$dmEvents = [
    'reservation_created'     => 'Reservation created',
    'checkout_created'        => 'Checkout created',
    'checkout_returned'       => 'Items returned',
    'checkout_partial_return' => 'Partial return',
    'late_items'              => 'Late item reminders',
    'account_status'          => 'Account status',
];

// ── POST: handle actions ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'unlink_discord') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET discord_user_id = NULL WHERE id = :id");
            $stmt->execute([':id' => $localUserId]);
            $_SESSION['user']['discord_user_id'] = null;
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'save_preferences') {
        try {
            $newDmEnabled  = isset($_POST['discord_dm_enabled']) ? 1 : 0;
            $newEmbedStyle = ($_POST['discord_embed_style'] ?? 'rich') === 'plain' ? 'plain' : 'rich';

            $stmt = $pdo->prepare("UPDATE users SET discord_dm_enabled = :dme, discord_embed_style = :des WHERE id = :id");
            $stmt->execute([':dme' => $newDmEnabled, ':des' => $newEmbedStyle, ':id' => $localUserId]);

            $upsertStmt = $pdo->prepare("
                INSERT INTO user_discord_preferences (user_id, event_key, enabled)
                VALUES (:uid, :ek, :en)
                ON DUPLICATE KEY UPDATE enabled = :en2
            ");
            foreach ($dmEvents as $key => $label) {
                $enabled = isset($_POST['dm_event_' . $key]) ? 1 : 0;
                $upsertStmt->execute([':uid' => $localUserId, ':ek' => $key, ':en' => $enabled, ':en2' => $enabled]);
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Database error.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// ── GET: render HTML fragment ─────────────────────────────────────────────────

header('Content-Type: text/html; charset=UTF-8');

// Load user data
$userData = null;
if ($localUserId > 0) {
    $stmt = $pdo->prepare("SELECT discord_user_id, discord_dm_enabled, discord_embed_style FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $localUserId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
}

$discordUserId    = $userData['discord_user_id'] ?? null;
$discordDmEnabled = (bool)($userData['discord_dm_enabled'] ?? true);
$embedStyle       = $userData['discord_embed_style'] ?? 'rich';
$isLinked         = $discordUserId !== null && $discordUserId !== '';

$eventPrefs = [];
if ($isLinked && $localUserId > 0) {
    $stmt = $pdo->prepare("SELECT event_key, enabled FROM user_discord_preferences WHERE user_id = :uid");
    $stmt->execute([':uid' => $localUserId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $eventPrefs[$row['event_key']] = (bool)$row['enabled'];
    }
}
?>
<div class="card mb-3">
    <div class="card-body">
        <h6 class="card-title mb-3">Discord Account</h6>

        <?php if ($isLinked): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge bg-success">Linked</span>
                <span class="text-muted small">ID: <?= h($discordUserId) ?></span>
            </div>
            <form method="post" data-panel-form
                  onsubmit="return confirm('Unlink your Discord account? You will stop receiving DM notifications.');">
                <input type="hidden" name="action" value="unlink_discord">
                <p class="panel-form-msg text-danger small d-none mb-2"></p>
                <button type="submit" class="btn btn-outline-danger btn-sm">Unlink Discord</button>
            </form>
        <?php else: ?>
            <?php if ($oauthConfigured): ?>
                <p class="text-muted small mb-2">Link your Discord account to receive instant DM notifications.</p>
                <a href="discord_link.php" class="btn btn-primary btn-sm">Link Discord</a>
            <?php else: ?>
                <p class="text-muted small mb-0">Discord account linking is not configured.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$dmEnabled): ?>
            <p class="text-muted small mt-2 mb-0">DM notifications are not yet enabled by the administrator.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($isLinked): ?>
<div class="card">
    <div class="card-body">
        <h6 class="card-title mb-3">Notification Preferences</h6>
        <form method="post" data-panel-form>
            <input type="hidden" name="action" value="save_preferences">
            <p class="panel-form-msg text-danger small d-none mb-2"></p>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="discord_dm_enabled"
                       name="discord_dm_enabled" <?= $discordDmEnabled ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold small" for="discord_dm_enabled">
                    Enable Discord DM notifications
                </label>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Message style</label>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="discord_embed_style"
                           id="style_rich" value="rich" <?= $embedStyle === 'rich' ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="style_rich">Rich embeds</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="discord_embed_style"
                           id="style_plain" value="plain" <?= $embedStyle === 'plain' ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="style_plain">Plain text</label>
                </div>
            </div>

            <label class="form-label fw-semibold small">Events</label>
            <div class="mb-3">
                <?php foreach ($dmEvents as $key => $label):
                    $checked = ($eventPrefs[$key] ?? true) ? 'checked' : '';
                ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="dm_event_<?= h($key) ?>" id="dm_<?= h($key) ?>" <?= $checked ?>>
                    <label class="form-check-label small" for="dm_<?= h($key) ?>"><?= h($label) ?></label>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Save preferences</button>
        </form>
    </div>
</div>
<?php endif; ?>
