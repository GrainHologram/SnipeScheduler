<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

$modelId = (int)($_GET['model_id'] ?? 0);
$kitId   = (int)($_GET['kit_id'] ?? 0);

if (empty($_SESSION['basket'])) {
    header('Location: basket.php');
    exit;
}

if ($kitId > 0) {
    // Remove all models contributed by this kit
    $kitGroups = $_SESSION['basket_kit_groups'][$kitId] ?? [];
    foreach ($kitGroups as $batch) {
        foreach ($batch as $entry) {
            $mid = (int)($entry['model_id'] ?? 0);
            $qty = (int)($entry['quantity'] ?? 0);
            if ($mid > 0 && isset($_SESSION['basket'][$mid])) {
                $_SESSION['basket'][$mid] -= $qty;
                if ($_SESSION['basket'][$mid] <= 0) {
                    unset($_SESSION['basket'][$mid]);
                }
            }
        }
    }
    unset($_SESSION['basket_kit_groups'][$kitId]);
    unset($_SESSION['basket_kit_names'][$kitId]);
} elseif ($modelId > 0) {
    unset($_SESSION['basket'][$modelId]);

    // Clean up kit group references for this model
    if (!empty($_SESSION['basket_kit_groups'])) {
        foreach ($_SESSION['basket_kit_groups'] as $kid => &$batches) {
            foreach ($batches as $bi => &$batch) {
                $batch = array_filter($batch, function ($entry) use ($modelId) {
                    return (int)($entry['model_id'] ?? 0) !== $modelId;
                });
                $batch = array_values($batch);
            }
            unset($batch);
            // Remove empty batches
            $batches = array_filter($batches, function ($b) { return !empty($b); });
            $batches = array_values($batches);
        }
        unset($batches);
        // Remove kit groups that are now empty
        foreach ($_SESSION['basket_kit_groups'] as $kid => $batches) {
            if (empty($batches)) {
                unset($_SESSION['basket_kit_groups'][$kid]);
                unset($_SESSION['basket_kit_names'][$kid]);
            }
        }
    }
}

header('Location: basket.php');
exit;
