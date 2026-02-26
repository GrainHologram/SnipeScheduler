<?php
// booking_helpers.php
// Shared helpers for working with reservations & items.

require_once __DIR__ . '/snipeit_client.php';

/**
 * Fetch all items for a reservation, with human-readable names.
 *
 * Returns an array of:
 *   [
 *     ['model_id' => 123, 'name' => 'Canon 5D', 'qty' => 2, 'image' => '/uploads/models/...'],
 *     ...
 *   ]
 *
 * Assumes reservation_items has: reservation_id, model_id, quantity.
 * Uses Snipe-IT get_model($modelId) to resolve names.
 */
function get_reservation_items_with_names(PDO $pdo, int $reservationId): array
{
    // Adjust columns / table name here if yours differ:
    $sql = "
        SELECT model_id, quantity
        FROM reservation_items
        WHERE reservation_id = :res_id
          AND deleted_at IS NULL
        ORDER BY model_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':res_id' => $reservationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $items = [];
    static $modelCache = [];

    foreach ($rows as $row) {
        $modelId = isset($row['model_id']) ? (int)$row['model_id'] : 0;
        $qty     = isset($row['quantity']) ? (int)$row['quantity'] : 0;

        if ($modelId <= 0 || $qty <= 0) {
            continue;
        }

        if (!isset($modelCache[$modelId])) {
            try {
                // Uses Snipe-IT API client function we already have
                $modelCache[$modelId] = get_model($modelId);
            } catch (Exception $e) {
                $modelCache[$modelId] = null;
            }
        }

        $model = $modelCache[$modelId];
        $name  = $model['name'] ?? ('Model #' . $modelId);
        $image = $model['image'] ?? '';

        $items[] = [
            'model_id' => $modelId,
            'name'     => $name,
            'qty'      => $qty,
            'image'    => $image,
        ];
    }

    return $items;
}

/**
 * Batch-fetch reservation items for multiple reservations in a single query.
 *
 * Uses model_name_cache from the reservation_items table — no Snipe-IT API calls.
 *
 * @return array  Keyed by reservation_id: [$resId => [['model_id'=>X, 'name'=>'...', 'qty'=>N], ...], ...]
 */
function batch_get_reservation_items(PDO $pdo, array $reservationIds): array
{
    if (empty($reservationIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
    $stmt = $pdo->prepare("
        SELECT reservation_id, model_id, model_name_cache, quantity
          FROM reservation_items
         WHERE reservation_id IN ($placeholders)
           AND deleted_at IS NULL
         ORDER BY model_id
    ");
    $stmt->execute(array_values($reservationIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $resId   = (int)$row['reservation_id'];
        $modelId = (int)($row['model_id'] ?? 0);
        $qty     = (int)($row['quantity'] ?? 0);

        if ($modelId <= 0 || $qty <= 0) {
            continue;
        }

        $grouped[$resId][] = [
            'model_id' => $modelId,
            'name'     => $row['model_name_cache'] ?: ('Model #' . $modelId),
            'qty'      => $qty,
        ];
    }

    return $grouped;
}

/**
 * Build a single-line text summary from an items array.
 *
 * Example:
 *   "Canon 5D (2), Tripod (1), LED Panel (3)"
 */
function build_items_summary_text(array $items): string
{
    if (empty($items)) {
        return '';
    }

    $parts = [];
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        $qty  = isset($item['qty']) ? (int)$item['qty'] : 0;

        if ($name === '' || $qty <= 0) {
            continue;
        }

        $parts[] = $qty > 1
            ? sprintf('%s (%d)', $name, $qty)
            : $name;
    }

    return implode(', ', $parts);
}

/**
 * Get all checkout_items for a checkout.
 *
 * @return array  Array of checkout_item rows
 */
function get_checkout_items(PDO $pdo, int $checkoutId): array
{
    $stmt = $pdo->prepare("
        SELECT * FROM checkout_items
         WHERE checkout_id = :cid
         ORDER BY id
    ");
    $stmt->execute([':cid' => $checkoutId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all checkouts linked to a reservation.
 *
 * @return array  Array of checkout rows
 */
function get_checkouts_for_reservation(PDO $pdo, int $reservationId): array
{
    $stmt = $pdo->prepare("
        SELECT * FROM checkouts
         WHERE reservation_id = :rid
         ORDER BY created_at DESC
    ");
    $stmt->execute([':rid' => $reservationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all checkouts related to a reservation via parent/child chain.
 *
 * Walks the checkout family: direct checkouts for this reservation,
 * then follows parent_checkout_id and child checkouts to find related
 * checkouts that belong to other reservations.
 *
 * @return array ['direct' => [...], 'related' => [...]]
 */
function get_checkout_family_for_reservation(PDO $pdo, int $reservationId): array
{
    $direct = get_checkouts_for_reservation($pdo, $reservationId);

    if (empty($direct)) {
        return ['direct' => [], 'related' => []];
    }

    // Collect all checkout IDs we've seen
    $seenIds = [];
    foreach ($direct as $co) {
        $seenIds[(int)$co['id']] = true;
    }

    $related = [];

    // For each direct checkout, walk to parent and siblings/children
    foreach ($direct as $co) {
        $parentId = !empty($co['parent_checkout_id']) ? (int)$co['parent_checkout_id'] : null;

        // Walk up to root parent
        $rootId = (int)$co['id'];
        if ($parentId) {
            $rootId = $parentId;
            // Check if root parent itself has a parent (shouldn't normally, but be safe)
            $pStmt = $pdo->prepare("SELECT id, parent_checkout_id FROM checkouts WHERE id = :id");
            $pStmt->execute([':id' => $parentId]);
            $parentRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($parentRow && !empty($parentRow['parent_checkout_id'])) {
                $rootId = (int)$parentRow['parent_checkout_id'];
            }
        }

        // Fetch root if not already seen
        if (!isset($seenIds[$rootId])) {
            $rStmt = $pdo->prepare("SELECT * FROM checkouts WHERE id = :id");
            $rStmt->execute([':id' => $rootId]);
            $rootRow = $rStmt->fetch(PDO::FETCH_ASSOC);
            if ($rootRow) {
                $related[] = $rootRow;
                $seenIds[$rootId] = true;
            }
        }

        // Fetch all children of the root
        $cStmt = $pdo->prepare("SELECT * FROM checkouts WHERE parent_checkout_id = :pid ORDER BY created_at");
        $cStmt->execute([':pid' => $rootId]);
        $children = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($children as $child) {
            $childId = (int)$child['id'];
            if (!isset($seenIds[$childId])) {
                $related[] = $child;
                $seenIds[$childId] = true;
            }
        }
    }

    return ['direct' => $direct, 'related' => $related];
}

/**
 * Update a checkout's status based on its items' checked_in_at state.
 *
 * @return string  The new status ('open', 'partial', or 'closed')
 */
function recompute_checkout_status(PDO $pdo, int $checkoutId): string
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) AS returned
          FROM checkout_items
         WHERE checkout_id = :cid
    ");
    $stmt->execute([':cid' => $checkoutId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $total    = (int)($row['total'] ?? 0);
    $returned = (int)($row['returned'] ?? 0);

    if ($total === 0 || $returned === 0) {
        $newStatus = 'open';
    } elseif ($returned >= $total) {
        $newStatus = 'closed';
    } else {
        $newStatus = 'partial';
    }

    $upd = $pdo->prepare("UPDATE checkouts SET status = :s WHERE id = :id");
    $upd->execute([':s' => $newStatus, ':id' => $checkoutId]);

    return $newStatus;
}
