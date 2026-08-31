<?php
// =============================================================================
// get_reactions.php — Fetch detailed reaction list for a message
// =============================================================================
// Returns JSON list of users who reacted to a specific message, formatted
// for the "Message reactions" modal.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

$msgUuid = trim($_GET['msg_uuid'] ?? $_POST['msg_uuid'] ?? '');
if ($msgUuid === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing message UUID.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare(
        "SELECT r.account_id, r.emoji, r.reacted_at,
                ad.first_name, ad.last_name, ad.avatar_url, ad.email
         FROM chat_reactions r
         JOIN ' . Database::t('account_details') . ' ad ON ad.account_id = r.account_id
         WHERE r.msg_uuid = :msg_uuid
         ORDER BY r.reacted_at ASC"
    );
    $stmt->execute([':msg_uuid' => $msgUuid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reactions = [];
    foreach ($rows as $row) {
        $emoji = $row['emoji'];
        // Normalize heart variants to red heart '❤️'
        if ($emoji === '❤' || $emoji === '🖤' || $emoji === '🤍') {
            $emoji = '❤️';
        }
        $reactions[] = [
            'account_id' => (int) $row['account_id'],
            'full_name'  => trim($row['first_name'] . ' ' . $row['last_name']),
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'avatar_url' => $row['avatar_url'] ?? null,
            'emoji'      => $emoji,
            'reacted_at' => $row['reacted_at'],
        ];
    }

    echo json_encode([
        'success'     => true,
        'msg_uuid'    => $msgUuid,
        'total_count' => count($reactions),
        'reactions'   => $reactions,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
