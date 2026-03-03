<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

header('Content-Type: application/json');

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$category = trim($_POST['category'] ?? '');
$message  = trim($_POST['message'] ?? '');

$allowedCategories = ['bug', 'feature_request', 'general'];
if (!in_array($category, $allowedCategories, true)) {
    echo json_encode(['error' => 'Invalid category.']);
    exit;
}

if (strlen($message) < 10) {
    echo json_encode(['error' => 'Message must be at least 10 characters.']);
    exit;
}

// Handle screenshot upload
$screenshotPath = null;
if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
    $tmpFile = $_FILES['screenshot']['tmp_name'];
    $fileSize = $_FILES['screenshot']['size'];

    if ($fileSize > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'Screenshot must be under 5MB.']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpFile);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mime])) {
        echo json_encode(['error' => 'Screenshot must be JPEG, PNG, GIF, or WebP.']);
        exit;
    }

    $ext = $allowedMimes[$mime];
    $uploadDir = CONFIG_PATH . '/uploads/feedback';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Insert row first to get ID, then rename with ID
    // We'll use a temp name, then update after insert
    $tempName = 'feedback_tmp_' . time() . '.' . $ext;
    $tempPath = $uploadDir . '/' . $tempName;

    if (!move_uploaded_file($tmpFile, $tempPath)) {
        echo json_encode(['error' => 'Failed to save screenshot.']);
        exit;
    }

    // Will be renamed after insert
    $screenshotPath = $tempName;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO feedback (user_id, user_name, user_email, category, message, screenshot_path)
        VALUES (:user_id, :user_name, :user_email, :category, :message, :screenshot_path)
    ");
    $stmt->execute([
        ':user_id'         => $currentUser['id'] ?? '',
        ':user_name'       => trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')),
        ':user_email'      => $currentUser['email'] ?? '',
        ':category'        => $category,
        ':message'         => $message,
        ':screenshot_path' => $screenshotPath,
    ]);
    $feedbackId = (int)$pdo->lastInsertId();

    // Rename screenshot with feedback ID
    if ($screenshotPath !== null) {
        $ext = pathinfo($screenshotPath, PATHINFO_EXTENSION);
        $finalName = 'feedback_' . $feedbackId . '_' . time() . '.' . $ext;
        $uploadDir = CONFIG_PATH . '/uploads/feedback';
        rename($uploadDir . '/' . $screenshotPath, $uploadDir . '/' . $finalName);

        $updateStmt = $pdo->prepare("UPDATE feedback SET screenshot_path = :path WHERE id = :id");
        $updateStmt->execute([':path' => $finalName, ':id' => $feedbackId]);
    }

    activity_log_event('feedback_submitted', 'Feedback submitted: ' . ucfirst(str_replace('_', ' ', $category)), [
        'subject_type' => 'feedback',
        'subject_id'   => $feedbackId,
        'metadata'     => ['category' => $category],
    ]);

    echo json_encode(['success' => true, 'id' => $feedbackId]);
} catch (Throwable $e) {
    // Clean up temp file on error
    if ($screenshotPath !== null) {
        $uploadDir = CONFIG_PATH . '/uploads/feedback';
        @unlink($uploadDir . '/' . $screenshotPath);
    }
    error_log('Feedback insert failed: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to save feedback.']);
}
