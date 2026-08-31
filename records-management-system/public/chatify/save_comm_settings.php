<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$accountId = (int) ($_SESSION['account_id'] ?? 0);
if ($accountId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid account']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
}

$allowTypingPreview   = !empty($data['allow_typing_preview']);
$allowSeeTypingPreview = !empty($data['allow_see_typing_preview']);

try {
    $pdo = Database::getConnection();
    
    // Ensure columns exist just in case
    @$pdo->exec("
        ALTER TABLE ' . Database::t('account_details') . ' ADD COLUMN IF NOT EXISTS allow_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE ' . Database::t('account_details') . ' ADD COLUMN IF NOT EXISTS allow_see_typing_preview BOOLEAN DEFAULT TRUE;
    ");

    $stmt = $pdo->prepare('
        UPDATE ' . Database::t('account_details') . '
        SET allow_typing_preview = :a,
            allow_see_typing_preview = :b
        WHERE account_id = :id
    ');
    $stmt->execute([
        ':a'  => $allowTypingPreview ? 'true' : 'false',
        ':b'  => $allowSeeTypingPreview ? 'true' : 'false',
        ':id' => $accountId,
    ]);

    // Push updated settings to WebSocket server
    WsPush::push([$accountId], 'update_comm_settings', [
        'account_id'               => $accountId,
        'allow_typing_preview'     => $allowTypingPreview,
        'allow_see_typing_preview' => $allowSeeTypingPreview,
    ]);

    echo json_encode([
        'success'  => true,
        'settings' => [
            'allow_typing_preview'     => $allowTypingPreview,
            'allow_see_typing_preview' => $allowSeeTypingPreview,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database update failed', 'message' => $e->getMessage()]);
}
