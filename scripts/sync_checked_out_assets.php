<?php
// scripts/sync_checked_out_assets.php
// Sync checked-out assets from Snipe-IT into the local cache table.
//
// CLI only; intended for cron.
//
// Example cron:
// /usr/bin/php /path/to/scripts/sync_checked_out_assets.php >> /var/log/snipe_checked_out_sync.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

function sync_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

function sync_err(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

try {
    $assets = fetch_checked_out_assets_from_snipeit(false, 0);
} catch (Throwable $e) {
    sync_err("[error] Failed to load checked-out assets: {$e->getMessage()}");
    exit(1);
}

try {
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Could not start database transaction.');
    }
    $pdo->exec('TRUNCATE TABLE checked_out_asset_cache');

    $stmt = $pdo->prepare("
        INSERT INTO checked_out_asset_cache (
            asset_id,
            asset_tag,
            asset_name,
            model_id,
            model_name,
            assigned_to_id,
            assigned_to_name,
            assigned_to_email,
            assigned_to_username,
            status_label,
            last_checkout,
            expected_checkin,
            updated_at
        ) VALUES (
            :asset_id,
            :asset_tag,
            :asset_name,
            :model_id,
            :model_name,
            :assigned_to_id,
            :assigned_to_name,
            :assigned_to_email,
            :assigned_to_username,
            :status_label,
            :last_checkout,
            :expected_checkin,
            NOW()
        )
    ");

    $seenAssetIds = [];
    foreach ($assets as $asset) {
        $assetId = (int)($asset['id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }
        if (isset($seenAssetIds[$assetId])) {
            continue;
        }
        $seenAssetIds[$assetId] = true;

        $assetTag  = $asset['asset_tag'] ?? '';
        $assetName = $asset['name'] ?? '';
        $modelId   = (int)($asset['model']['id'] ?? 0);
        $modelName = $asset['model']['name'] ?? '';

        $assigned = $asset['assigned_to'] ?? ($asset['assigned_to_fullname'] ?? '');
        $assignedId = 0;
        $assignedName = '';
        $assignedEmail = '';
        $assignedUsername = '';
        if (is_array($assigned)) {
            $assignedId = (int)($assigned['id'] ?? 0);
            $assignedName = $assigned['name'] ?? ($assigned['username'] ?? '');
            $assignedEmail = $assigned['email'] ?? '';
            $assignedUsername = $assigned['username'] ?? '';
        } elseif (is_string($assigned)) {
            $assignedName = $assigned;
        }

        $statusLabel = $asset['status_label'] ?? '';
        if (is_array($statusLabel)) {
            $statusLabel = $statusLabel['name'] ?? ($statusLabel['status_meta'] ?? ($statusLabel['label'] ?? ''));
        }

        $lastCheckout = $asset['_last_checkout_norm'] ?? ($asset['last_checkout'] ?? '');
        if (is_array($lastCheckout)) {
            $lastCheckout = $lastCheckout['datetime'] ?? ($lastCheckout['date'] ?? '');
        }
        $expectedCheckin = $asset['_expected_checkin_norm'] ?? ($asset['expected_checkin'] ?? '');
        if (is_array($expectedCheckin)) {
            $expectedCheckin = $expectedCheckin['datetime'] ?? ($expectedCheckin['date'] ?? '');
        }

        // Prefer custom field value (full datetime) over date-only expected_checkin
        $customField = snipe_get_expected_checkin_custom_field();
        if ($customField !== null) {
            $customFields = $asset['custom_fields'] ?? [];
            if (is_array($customFields)) {
                foreach ($customFields as $cf) {
                    if (is_array($cf) && ($cf['field'] ?? '') === $customField) {
                        $cfValue = trim((string)($cf['value'] ?? ''));
                        if ($cfValue !== '') {
                            $expectedCheckin = $cfValue;
                        }
                        break;
                    }
                }
            }
        }

        $stmt->execute([
            ':asset_id' => $assetId,
            ':asset_tag' => $assetTag,
            ':asset_name' => $assetName,
            ':model_id' => $modelId,
            ':model_name' => $modelName,
            ':assigned_to_id' => $assignedId > 0 ? $assignedId : null,
            ':assigned_to_name' => $assignedName !== '' ? $assignedName : null,
            ':assigned_to_email' => $assignedEmail !== '' ? $assignedEmail : null,
            ':assigned_to_username' => $assignedUsername !== '' ? $assignedUsername : null,
            ':status_label' => $statusLabel !== '' ? $statusLabel : null,
            ':last_checkout' => $lastCheckout !== '' ? $lastCheckout : null,
            ':expected_checkin' => $expectedCheckin !== '' ? $expectedCheckin : null,
        ]);
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    sync_log("[done] Synced " . count($assets) . " checked-out asset(s).");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sync_err("[error] Failed to sync checked-out assets: {$e->getMessage()}");
    exit(1);
}

// Transition checked_out reservations to completed when all assets have been returned.
// Runs after the cache sync so the cache reflects the current Snipe-IT state.
try {
    $resStmt = $pdo->query("
        SELECT id, asset_name_cache
          FROM reservations
         WHERE status = 'checked_out'
    ");
    $checkedOutReservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);
    $completedCount = 0;

    foreach ($checkedOutReservations as $res) {
        $cache = $res['asset_name_cache'] ?? '';
        if ($cache === '') {
            continue;
        }

        // Parse asset tags from cache: format is "TAG (Model), TAG2 (Model2)"
        $resTags = [];
        $parts = array_map('trim', explode(',', $cache));
        foreach ($parts as $part) {
            // Extract tag before first " (" or use whole string
            if (preg_match('/^(\S+)\s*\(/', $part, $m)) {
                $resTags[] = $m[1];
            } else {
                $resTags[] = $part;
            }
        }
        $resTags = array_filter($resTags, function ($t) { return $t !== ''; });
        if (empty($resTags)) {
            continue;
        }

        // Check if any of the reservation's asset tags are still in the cache
        $placeholders = implode(',', array_fill(0, count($resTags), '?'));
        $tagStmt = $pdo->prepare("
            SELECT COUNT(*) FROM checked_out_asset_cache
             WHERE asset_tag IN ({$placeholders})
        ");
        $tagStmt->execute(array_values($resTags));
        $stillOut = (int)$tagStmt->fetchColumn();

        if ($stillOut === 0) {
            $complStmt = $pdo->prepare("
                UPDATE reservations
                   SET status = 'completed'
                 WHERE id = :id
                   AND status = 'checked_out'
            ");
            $complStmt->execute([':id' => (int)$res['id']]);

            activity_log_event('reservation_completed', 'Reservation completed (all assets returned)', [
                'subject_type' => 'reservation',
                'subject_id'   => (int)$res['id'],
                'metadata'     => [
                    'completed_via' => 'sync_script',
                ],
            ]);
            $completedCount++;
        }
    }

    if ($completedCount > 0) {
        sync_log("[done] Completed {$completedCount} reservation(s) (all assets returned).");
    }
} catch (Throwable $e) {
    sync_err("[warn] Reservation completion check failed: {$e->getMessage()}");
}
