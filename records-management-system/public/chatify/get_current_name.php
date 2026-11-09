<?php
require_once __DIR__ . '/bootstrap.php';
session_start();
header('Content-Type: application/json');

// Same auth guard as index.php — any logged-in user, not just admin.
if (!Auth::check()) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

$_current_account_id = (int) ($_SESSION['account_id'] ?? 0);

if ($_current_account_id <= 0) {
    echo json_encode(['error' => 'no_account']);
    exit();
}

try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM ' . Database::t('account_details') . ' WHERE account_id = ? LIMIT 1');
    $stmt->execute([$_current_account_id]);
    $row = $stmt->fetch();

    if ($row) {
        $fullName = trim(preg_replace('/\s+/', ' ', $row['first_name'] . ' ' . $row['last_name']));

        // Keep the session in sync too, same as index.php does on full page load.
        $_SESSION['name']      = $fullName;
        $_SESSION['full_name'] = $fullName;

        echo json_encode(['name' => $fullName]);
        exit();
    }
} catch (Throwable $e) {
    // Non-fatal — just report failure, front-end will keep the old value.
}

echo json_encode(['name' => null]);