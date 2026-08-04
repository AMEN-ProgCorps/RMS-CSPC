<?php
// =============================================================================
// get_chat_backup.php — Admin-only: Download chatify_chat_backup as plain-text
//                       JSON for audit purposes.
// =============================================================================
// GET params:
//   session_id   (string, optional) — ISO timestamp prefix to filter a specific
//                backup session (the archived_at group). If omitted, returns ALL
//                backup records.
//   format       'json' (default) — returns application/json download
//
// Returns: JSON file download  |  { error: string } on failure
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

session_start();
Auth::require();
session_write_close();

if (!Auth::isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden — admin only']);
    exit;
}

try {
    $pdo      = Database::getConnection();
    $archived = isset($_GET['archived_at']) ? trim($_GET['archived_at']) : null;

    // ── Build query ──────────────────────────────────────────────────────────
    $sql    = 'SELECT
                    b.id,
                    b.conv_id,
                    b.sender_id,
                    b.receiver_id,
                    b.message,
                    b.msg_type,
                    b.created_at,
                    b.updated_at,
                    b.msg_uuid,
                    b.archived_at,
                    b.archived_by,
                    ad_sender.first_name   AS sender_first,
                    ad_sender.last_name    AS sender_last,
                    acc_sender.username    AS sender_username,
                    ad_recv.first_name     AS receiver_first,
                    ad_recv.last_name      AS receiver_last,
                    acc_recv.username      AS receiver_username
               FROM chatify_chat_backup b
               LEFT JOIN account_details ad_sender   ON b.sender_id   = ad_sender.account_id
               LEFT JOIN account         acc_sender   ON b.sender_id   = acc_sender.id
               LEFT JOIN account_details ad_recv      ON b.receiver_id = ad_recv.account_id
               LEFT JOIN account         acc_recv      ON b.receiver_id = acc_recv.id';
    $params = [];

    if ($archived) {
        // Filter by a date-prefix, e.g. "2026-08-03" or a full ISO timestamp
        $sql .= ' WHERE b.archived_at::date = :archived_date';
        $params[':archived_date'] = substr($archived, 0, 10);
    }

    $sql .= ' ORDER BY b.archived_at ASC, b.created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Decrypt messages and format output ──────────────────────────────────
    $output = [];
    foreach ($rows as $row) {
        $plainText = ($row['msg_type'] === 'upload')
            ? '[file: ' . safeDecrypt($row['message'] ?? '', '[file]') . ']'
            : safeDecrypt($row['message'] ?? '', '[encrypted]');

        $senderName = trim(($row['sender_first'] ?? '') . ' ' . ($row['sender_last'] ?? ''))
                        ?: ('User #' . $row['sender_id']);
        $receiverName = $row['receiver_id']
            ? (trim(($row['receiver_first'] ?? '') . ' ' . ($row['receiver_last'] ?? '')) ?: ('User #' . $row['receiver_id']))
            : null;

        $output[] = [
            'backup_id'       => (int) $row['id'],
            'conv_id'         => $row['conv_id'],
            'msg_uuid'        => $row['msg_uuid'],
            'msg_type'        => $row['msg_type'],
            'sender_id'       => (int) $row['sender_id'],
            'sender_name'     => $senderName,
            'sender_username' => $row['sender_username'] ?? null,
            'receiver_id'     => $row['receiver_id'] ? (int) $row['receiver_id'] : null,
            'receiver_name'   => $receiverName,
            'message'         => $plainText,
            'sent_at'         => $row['created_at'],
            'edited_at'       => $row['updated_at'] !== $row['created_at'] ? $row['updated_at'] : null,
            'archived_at'     => $row['archived_at'],
            'archived_by_id'  => (int) $row['archived_by'],
        ];
    }

    $meta = [
        'exported_by'     => Auth::accountId(),
        'exported_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        'filter_date'     => $archived ? substr($archived, 0, 10) : null,
        'total_records'   => count($output),
        'system'          => 'CSPC RMS — Chatify Backup Export',
    ];

    $payload = json_encode([
        'meta'     => $meta,
        'messages' => $output,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $filename = 'chatify_backup_' . ($archived ? substr($archived, 0, 10) . '_' : '') . gmdate('Ymd_His') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($payload));
    header('Cache-Control: no-store, no-cache');
    echo $payload;

} catch (Throwable $e) {
    error_log('get_chat_backup.php — ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to export backup.']);
}
