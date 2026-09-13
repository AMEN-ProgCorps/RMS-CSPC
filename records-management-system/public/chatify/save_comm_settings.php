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

$helperParseBool = function ($val, $fallback = true) {
    if ($val === null) return $fallback;
    if (is_bool($val)) return $val;
    if (in_array($val, [false, 0, '0', 'f', 'false', 'off', 'no'], true)) return false;
    if (in_array($val, [true, 1, '1', 't', 'true', 'on', 'yes'], true)) return true;
    return filter_var($val, FILTER_VALIDATE_BOOLEAN);
};

try {
    $pdo = Database::getConnection();

    // Fetch existing settings first so partial updates don't overwrite the other setting
    $currentStmt = $pdo->prepare('SELECT allow_typing_preview, allow_see_typing_preview FROM ' . Database::t('account_details') . ' WHERE account_id = ? LIMIT 1');
    $currentStmt->execute([$accountId]);
    $curr = $currentStmt->fetch();

    $currTyping = true;
    $currSee    = true;
    if ($curr) {
        $currTyping = $helperParseBool($curr['allow_typing_preview'] ?? null, true);
        $currSee    = $helperParseBool($curr['allow_see_typing_preview'] ?? null, true);
    }

    $allowTypingPreview   = array_key_exists('allow_typing_preview', $data)
        ? $helperParseBool($data['allow_typing_preview'], true)
        : $currTyping;

    $allowSeeTypingPreview = array_key_exists('allow_see_typing_preview', $data)
        ? $helperParseBool($data['allow_see_typing_preview'], true)
        : $currSee;

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
