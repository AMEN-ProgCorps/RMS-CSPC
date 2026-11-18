<?php
require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$accountId = Auth::accountId();
if ($accountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid account']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$incomingEmojis = $data['emojis'] ?? null;
if (is_string($incomingEmojis)) {
    $incomingEmojis = json_decode($incomingEmojis, true);
}

$normalized = Reactions::normalizeUserReactionEmojis($incomingEmojis);
$jsonEncoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);

try {
    $pdo = Database::getConnection();

    SelfHealCache::once('account_details_reaction_emojis_col', function () use ($pdo) {
        $table = Database::t('account_details');
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS chatify_reaction_emojis TEXT DEFAULT '[\"😆\",\"❤️\",\"👍\"]'");
        $pdo->exec("ALTER TABLE messify_chat_reactions ALTER COLUMN emoji TYPE VARCHAR(32)");
    });

    $stmt = $pdo->prepare('
        UPDATE ' . Database::t('account_details') . '
        SET chatify_reaction_emojis = :emojis
        WHERE account_id = :id
    ');
    $stmt->execute([
        ':emojis' => $jsonEncoded,
        ':id'     => $accountId,
    ]);

    echo json_encode([
        'success' => true,
        'emojis'  => $normalized,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database update failed',
        'message' => $e->getMessage(),
    ]);
}
