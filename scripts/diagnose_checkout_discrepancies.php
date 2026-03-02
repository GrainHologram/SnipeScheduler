<?php
// scripts/diagnose_checkout_discrepancies.php
// Finds discrepancies between Snipe-IT checked-out assets and local checkout records.
//
// The consistency bug: an asset gets checked out via the Snipe-IT API but no local
// checkout_items row is created (due to double-submit, page reload, or DB error).
//
// This script compares the checked_out_asset_cache (Snipe-IT truth) against
// checkout_items (local truth) to find orphaned assets.
//
// Run the sync cron first to ensure the cache is fresh:
//   php scripts/sync_checked_out_assets.php
//   php scripts/diagnose_checkout_discrepancies.php
//
// CLI only.

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';

// ─── 1. Assets checked out in Snipe-IT with no open local checkout_items row ───

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
        c.last_checkout,
        c.expected_checkin
    FROM checked_out_asset_cache c
    LEFT JOIN checkout_items ci
        ON ci.asset_id = c.asset_id
        AND ci.checked_in_at IS NULL
    WHERE ci.id IS NULL
    ORDER BY c.assigned_to_name, c.last_checkout
")->fetchAll(PDO::FETCH_ASSOC);

// ─── 2. Open checkout_items for assets NOT in the cache (returned in Snipe-IT) ───
// The sync cron normally catches these, but list them in case sync hasn't run.

$staleLocal = $pdo->query("
    SELECT
        ci.id AS checkout_item_id,
        ci.checkout_id,
        ci.asset_id,
        ci.asset_tag,
        ci.asset_name,
        ci.model_name,
        ci.checked_out_at,
        co.user_name,
        co.user_email,
        co.status AS checkout_status
    FROM checkout_items ci
    JOIN checkouts co ON co.id = ci.checkout_id
    LEFT JOIN checked_out_asset_cache c ON c.asset_id = ci.asset_id
    WHERE ci.checked_in_at IS NULL
      AND co.status IN ('open', 'partial')
      AND c.asset_id IS NULL
    ORDER BY ci.checked_out_at
")->fetchAll(PDO::FETCH_ASSOC);

// ─── 3. Fulfilled reservations with no checkout record ───

$fulfilledNoCheckout = $pdo->query("
    SELECT
        r.id AS reservation_id,
        r.user_name,
        r.user_email,
        r.start_datetime,
        r.end_datetime,
        r.asset_name_cache,
        r.created_at
    FROM reservations r
    LEFT JOIN checkouts co ON co.reservation_id = r.id
    WHERE r.status = 'fulfilled'
      AND co.id IS NULL
    ORDER BY r.start_datetime
")->fetchAll(PDO::FETCH_ASSOC);

// ─── 4. Checkouts with zero checkout_items ───

$emptyCheckouts = $pdo->query("
    SELECT
        co.id AS checkout_id,
        co.reservation_id,
        co.user_name,
        co.user_email,
        co.start_datetime,
        co.end_datetime,
        co.status,
        co.created_at
    FROM checkouts co
    LEFT JOIN checkout_items ci ON ci.checkout_id = co.id
    WHERE ci.id IS NULL
    ORDER BY co.created_at
")->fetchAll(PDO::FETCH_ASSOC);

// ─── 5. Cache freshness ───

$cacheAge = $pdo->query("
    SELECT
        COUNT(*) AS total_cached,
        MIN(updated_at) AS oldest_update,
        MAX(updated_at) AS newest_update
    FROM checked_out_asset_cache
")->fetch(PDO::FETCH_ASSOC);

// ─── Output ───

$separator = str_repeat('─', 80);

echo "\n{$separator}\n";
echo "  CHECKOUT DISCREPANCY REPORT\n";
echo "  Generated: " . date('Y-m-d H:i:s T') . "\n";
echo "{$separator}\n\n";

// Cache info
echo "CACHE STATUS\n";
echo "  Cached assets: {$cacheAge['total_cached']}\n";
echo "  Oldest update: " . ($cacheAge['oldest_update'] ?? 'n/a') . "\n";
echo "  Newest update: " . ($cacheAge['newest_update'] ?? 'n/a') . "\n";
if ($cacheAge['newest_update']) {
    $age = time() - strtotime($cacheAge['newest_update']);
    if ($age > 300) {
        echo "  ⚠ Cache is " . round($age / 60) . " min old. Run sync_checked_out_assets.php first for accurate results.\n";
    }
}
echo "\n";

// Section 1: Orphaned in Snipe-IT
echo "{$separator}\n";
echo "1. ASSETS CHECKED OUT IN SNIPE-IT WITHOUT LOCAL RECORD (" . count($orphaned) . ")\n";
echo "   (Checked out via API but no checkout_items row — likely caused by the bug)\n";
echo "{$separator}\n";
if (empty($orphaned)) {
    echo "  None found. ✓\n";
} else {
    foreach ($orphaned as $row) {
        echo "\n  Asset #{$row['asset_id']}  {$row['asset_tag']}";
        if ($row['asset_name']) echo "  ({$row['asset_name']})";
        echo "\n";
        echo "    Model:       #{$row['model_id']} {$row['model_name']}\n";
        echo "    Assigned to: {$row['assigned_to_name']}";
        if ($row['assigned_to_email']) echo " <{$row['assigned_to_email']}>";
        echo " (Snipe-IT user #{$row['assigned_to_id']})\n";
        echo "    Last checkout:     {$row['last_checkout']}\n";
        echo "    Expected checkin:  " . ($row['expected_checkin'] ?: 'none') . "\n";
    }
}
echo "\n";

// Section 2: Stale local records
echo "{$separator}\n";
echo "2. LOCAL CHECKOUT_ITEMS WITH NO MATCHING SNIPE-IT CHECKOUT (" . count($staleLocal) . ")\n";
echo "   (Asset returned in Snipe-IT but checkout_items.checked_in_at still NULL)\n";
echo "{$separator}\n";
if (empty($staleLocal)) {
    echo "  None found. ✓\n";
} else {
    foreach ($staleLocal as $row) {
        echo "\n  checkout_items #{$row['checkout_item_id']}  (checkout #{$row['checkout_id']})\n";
        echo "    Asset:       #{$row['asset_id']} {$row['asset_tag']}";
        if ($row['asset_name']) echo " ({$row['asset_name']})";
        echo "\n";
        echo "    User:        {$row['user_name']} <{$row['user_email']}>\n";
        echo "    Checked out: {$row['checked_out_at']}\n";
        echo "    Checkout status: {$row['checkout_status']}\n";
    }
}
echo "\n";

// Section 3: Fulfilled reservations without checkout
echo "{$separator}\n";
echo "3. FULFILLED RESERVATIONS WITH NO CHECKOUT RECORD (" . count($fulfilledNoCheckout) . ")\n";
echo "   (Reservation marked fulfilled but no checkouts row linked to it)\n";
echo "{$separator}\n";
if (empty($fulfilledNoCheckout)) {
    echo "  None found. ✓\n";
} else {
    foreach ($fulfilledNoCheckout as $row) {
        echo "\n  Reservation #{$row['reservation_id']}\n";
        echo "    User:    {$row['user_name']} <{$row['user_email']}>\n";
        echo "    Dates:   {$row['start_datetime']} → {$row['end_datetime']}\n";
        echo "    Items:   " . ($row['asset_name_cache'] ?: 'n/a') . "\n";
        echo "    Created: {$row['created_at']}\n";
    }
}
echo "\n";

// Section 4: Empty checkouts
echo "{$separator}\n";
echo "4. CHECKOUTS WITH ZERO ITEMS (" . count($emptyCheckouts) . ")\n";
echo "   (Checkout header created but no checkout_items rows)\n";
echo "{$separator}\n";
if (empty($emptyCheckouts)) {
    echo "  None found. ✓\n";
} else {
    foreach ($emptyCheckouts as $row) {
        echo "\n  Checkout #{$row['checkout_id']}";
        if ($row['reservation_id']) echo "  (reservation #{$row['reservation_id']})";
        echo "\n";
        echo "    User:    {$row['user_name']} <{$row['user_email']}>\n";
        echo "    Dates:   {$row['start_datetime']} → {$row['end_datetime']}\n";
        echo "    Status:  {$row['status']}\n";
        echo "    Created: {$row['created_at']}\n";
    }
}
echo "\n";

// Summary
$totalIssues = count($orphaned) + count($staleLocal) + count($fulfilledNoCheckout) + count($emptyCheckouts);
echo "{$separator}\n";
if ($totalIssues === 0) {
    echo "  SUMMARY: No discrepancies found.\n";
} else {
    echo "  SUMMARY: {$totalIssues} discrepancy(ies) found.\n";
    echo "  Section 1 items are most likely caused by the double-submit bug.\n";
}
echo "{$separator}\n\n";

exit($totalIssues > 0 ? 1 : 0);
