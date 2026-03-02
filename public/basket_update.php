<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: basket.php');
    exit;
}

$modelId  = (int)($_POST['model_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);

if ($modelId <= 0 || empty($_SESSION['basket'])) {
    header('Location: basket.php');
    exit;
}

if ($quantity <= 0) {
    // Remove the model entirely (same logic as basket_remove.php for a single model)
    unset($_SESSION['basket'][$modelId]);

    // Clean up kit group references
    if (!empty($_SESSION['basket_kit_groups'])) {
        foreach ($_SESSION['basket_kit_groups'] as $kid => &$batches) {
            foreach ($batches as $bi => &$batch) {
                $batch = array_filter($batch, function ($entry) use ($modelId) {
                    return (int)($entry['model_id'] ?? 0) !== $modelId;
                });
                $batch = array_values($batch);
            }
            unset($batch);
            $batches = array_filter($batches, function ($b) { return !empty($b); });
            $batches = array_values($batches);
        }
        unset($batches);
        foreach ($_SESSION['basket_kit_groups'] as $kid => $batches) {
            if (empty($batches)) {
                unset($_SESSION['basket_kit_groups'][$kid]);
                unset($_SESSION['basket_kit_names'][$kid]);
            }
        }
    }
} else {
    $newQty = min(100, $quantity);
    $_SESSION['basket'][$modelId] = $newQty;

    // Update kit group tracking so "Remove kit" subtracts the correct amount
    if (!empty($_SESSION['basket_kit_groups'])) {
        foreach ($_SESSION['basket_kit_groups'] as $kid => &$batches) {
            foreach ($batches as &$batch) {
                foreach ($batch as &$entry) {
                    if ((int)($entry['model_id'] ?? 0) === $modelId) {
                        $entry['quantity'] = $newQty;
                    }
                }
                unset($entry);
            }
            unset($batch);
        }
        unset($batches);
    }
}

// Preserve date query string so availability preview persists
$qs = '';
$start = $_GET['start_datetime'] ?? ($_POST['start_datetime'] ?? '');
$end   = $_GET['end_datetime'] ?? ($_POST['end_datetime'] ?? '');
if ($start !== '' && $end !== '') {
    $qs = '?start_datetime=' . urlencode($start) . '&end_datetime=' . urlencode($end);
}

header('Location: basket.php' . $qs);
exit;
