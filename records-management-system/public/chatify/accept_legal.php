<?php
// =============================================================================
// accept_legal.php — Record that the current user has accepted the Chatify
//                    legal authorization agreement.
// =============================================================================
// POST params:
//   agreed   (string "true", required)
//
// Returns: { success: true } | { error: string }
//
// Uses UPSERT (INSERT … ON CONFLICT … DO UPDATE) so re-accepting always
// refreshes the agreed_at / ip_address record cleanly.
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$agreed = trim($_POST['agreed'] ?? '');
if ($agreed !== 'true') {
    http_response_code(400);
    echo json_encode(['error' => 'Agreement not confirmed.']);
    exit;
}

$accountId = Auth::accountId();
$ip        = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
if ($ip) {
    // Take only the first IP in case of forwarded chain
    $ip = trim(explode(',', $ip)[0]);
    // Clamp to 45 chars (IPv6 max)
    $ip = substr($ip, 0, 45);
}
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

try {
    $pdo = Database::getConnection();

    // PostgreSQL UPSERT — update the timestamp/IP each time they re-agree
    $stmt = $pdo->prepare(
        'INSERT INTO chatify_legal_agreements (account_id, agreed_at, ip_address, user_agent)
         VALUES (:account_id, NOW(), :ip, :ua)
         ON CONFLICT (account_id)
         DO UPDATE SET agreed_at  = EXCLUDED.agreed_at,
                       ip_address = EXCLUDED.ip_address,
                       user_agent = EXCLUDED.user_agent'
    );
    $stmt->execute([
        ':account_id' => $accountId,
        ':ip'         => $ip,
        ':ua'         => $userAgent ?: null,
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record agreement. Please try again.']);
}
