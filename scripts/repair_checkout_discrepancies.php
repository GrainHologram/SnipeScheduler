<?php
// scripts/repair_checkout_discrepancies.php
// Creates missing local checkout records for assets that are checked out in
// Snipe-IT but have no corresponding checkout_items row.
//
// Run the sync cron and diagnosis first:
//   php scripts/sync_checked_out_assets.php
//   php scripts/diagnose_checkout_discrepancies.php
//   php scripts/repair_checkout_discrepancies.php
//
// Use --dry-run to preview without making changes.
// CLI only.

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

function repair_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ─── Find orphaned assets ───

$orphaned = $pdo->query("
    SELECT
        c.asset_id,
        c.asset_tag,
        c.asset_name,
        c.model_id,
        c.model_name,
        c.assigned_to_id,
        c.assigned_to_name,
        c.assigned_to_email,
        c.assigned_to_username,
        c.last_checkout,
        c.expected_checkin
    FROM checked_out_asset_cache c
    LEFT JOIN checkout_items ci
        ON ci.asset_id = c.asset_id
        AND ci.checked_in_at IS NULL
    WHERE ci.id IS NULL
    ORDER BY c.assigned_to_id, c.last_checkout
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($orphaned)) {
    repair_log("No orphaned assets found. Nothing to repair.");
    exit(0);
}

repair_log("Found " . count($orphaned) . " orphaned asset(s)." . ($dryRun ? ' (DRY RUN)' : ''));

// ─── Group by assigned user ───

$byUser = [];
foreach ($orphaned as $row) {
    $uid = (int)$row['assigned_to_id'];
    if ($uid <= 0) {
        repair_log("  SKIP asset #{$row['asset_id']} {$row['asset_tag']} — no assigned user in cache.");
        continue;
    }
    $byUser[$uid][] = $row;
}

// ─── Prepare statements ───

$findActiveCheckout = $pdo->prepare("
    SELECT id, start_datetime, end_datetime
      FROM checkouts
     WHERE snipeit_user_id = :uid
       AND parent_checkout_id IS NULL
       AND status IN ('open', 'partial')
     ORDER BY created_at DESC
     LIMIT 1
");

$findLocalUser = $pdo->prepare("
    SELECT user_id, name, email
      FROM users
     WHERE email = :email
     LIMIT 1
");

$insertCheckout = $pdo->prepare("
    INSERT INTO checkouts
        (reservation_id, parent_checkout_id, user_id, user_name, user_email,
         snipeit_user_id, start_datetime, end_datetime, status, created_at)
    VALUES
        (NULL, NULL, :uid, :uname, :uemail,
         :suid, :start, :end, 'open', NOW())
");

$insertItem = $pdo->prepare("
    INSERT INTO checkout_items
        (checkout_id, asset_id, asset_tag, asset_name, model_id, model_name, checked_out_at)
    VALUES
        (:cid, :aid, :atag, :aname, :mid, :mname, :checked_out_at)
");

// ─── Process each user group ───

$totalRepaired = 0;
$totalCheckoutsCreated = 0;

foreach ($byUser as $snipeitUserId => $assets) {
    $sample = $assets[0];
    $userName = $sample['assigned_to_name'] ?: ('User #' . $snipeitUserId);
    $userEmail = $sample['assigned_to_email'] ?: '';

    repair_log("\nUser: {$userName} <{$userEmail}> (Snipe-IT #{$snipeitUserId}) — " . count($assets) . " orphaned asset(s)");

    // Check for existing open checkout to append to
    $findActiveCheckout->execute([':uid' => $snipeitUserId]);
    $activeCheckout = $findActiveCheckout->fetch(PDO::FETCH_ASSOC);

    $checkoutId = null;

    if ($activeCheckout) {
        $checkoutId = (int)$activeCheckout['id'];
        repair_log("  Found existing open checkout #{$checkoutId} — will append items.");
    } else {
        // Need to create a new checkout — look up local user_id
        $localUserId = '';
        if ($userEmail) {
            $findLocalUser->execute([':email' => $userEmail]);
            $localUser = $findLocalUser->fetch(PDO::FETCH_ASSOC);
            if ($localUser) {
                $localUserId = $localUser['user_id'];
                $userName = $localUser['name'] ?: $userName;
                $userEmail = $localUser['email'] ?: $userEmail;
            }
        }
        // Fall back to username or email as user_id
        if ($localUserId === '') {
            $localUserId = $sample['assigned_to_username'] ?: $userEmail ?: ('snipeit-' . $snipeitUserId);
        }

        // Use earliest last_checkout as start, latest expected_checkin as end
        $starts = array_filter(array_column($assets, 'last_checkout'));
        $ends = array_filter(array_column($assets, 'expected_checkin'));
        $startDt = !empty($starts) ? min($starts) : date('Y-m-d H:i:s');
        $endDt = !empty($ends) ? max($ends) : date('Y-m-d H:i:s', strtotime('+7 days'));

        repair_log("  Creating new checkout for {$userName} <{$userEmail}>");
        repair_log("    user_id: {$localUserId}, dates: {$startDt} → {$endDt}");

        if (!$dryRun) {
            $insertCheckout->execute([
                ':uid'   => $localUserId,
                ':uname' => $userName,
                ':uemail' => $userEmail,
                ':suid'  => $snipeitUserId,
                ':start' => $startDt,
                ':end'   => $endDt,
            ]);
            $checkoutId = (int)$pdo->lastInsertId();
            $totalCheckoutsCreated++;

            activity_log_event('checkout_created', 'Checkout created by repair script', [
                'subject_type' => 'checkout',
                'subject_id'   => $checkoutId,
                'metadata'     => [
                    'repair_script' => true,
                    'orphaned_assets' => count($assets),
                ],
            ]);
        } else {
            $checkoutId = '(dry-run)';
        }

        repair_log("  Checkout #{$checkoutId} created.");
    }

    // Insert checkout_items for each orphaned asset
    foreach ($assets as $asset) {
        $checkedOutAt = $asset['last_checkout'] ?: date('Y-m-d H:i:s');

        repair_log("  + Asset #{$asset['asset_id']} {$asset['asset_tag']} ({$asset['model_name']}) checked_out_at={$checkedOutAt}");

        if (!$dryRun) {
            $insertItem->execute([
                ':cid'   => $checkoutId,
                ':aid'   => (int)$asset['asset_id'],
                ':atag'  => $asset['asset_tag'],
                ':aname' => $asset['asset_name'] ?: $asset['asset_tag'],
                ':mid'   => (int)$asset['model_id'],
                ':mname' => $asset['model_name'],
                ':checked_out_at' => $checkedOutAt,
            ]);
        }
        $totalRepaired++;
    }
}

// ─── Summary ───

echo "\n";
repair_log("─── SUMMARY ───");
repair_log("Orphaned assets repaired: {$totalRepaired}");
repair_log("New checkouts created: {$totalCheckoutsCreated}");
if ($dryRun) {
    repair_log("DRY RUN — no changes were made. Run without --dry-run to apply.");
}
