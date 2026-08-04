<?php
// =============================================================================
// log_chat_action.php — Shared fire-and-forget helper used by other endpoints
//                        to record user actions in chatify_audit_logs.
// =============================================================================
// This file is NOT a standalone HTTP endpoint. It is included by other
// Chatify endpoints AFTER they have called Auth::require() and closed the
// session lock. Call like:
//
//   ChatAuditLogger::log($senderId, 'send_message', $msgUuid, ['chat_type' => 'global']);
//
// All failures are silently swallowed — audit logging must never interrupt
// the primary operation.
// =============================================================================

class ChatAuditLogger
{
    /**
     * Record an action in chatify_audit_logs.
     *
     * @param int         $accountId  The acting user's account ID.
     * @param string      $action     Action slug (send_message, edit_message, …).
     * @param string|null $targetId   Optional reference (msg_uuid, conv_id, filename).
     * @param array       $meta       Optional extra key/value pairs stored as JSONB.
     */
    public static function log(
        int $accountId,
        string $action,
        ?string $targetId = null,
        array $meta = []
    ): void {
        try {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['HTTP_X_REAL_IP']
                ?? $_SERVER['REMOTE_ADDR']
                ?? null;
            if ($ip) {
                $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
            }

            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO chatify_audit_logs
                    (account_id, action, target_id, meta, ip_address, created_at)
                 VALUES
                    (:account_id, :action, :target_id, :meta, :ip, NOW())'
            );
            $stmt->execute([
                ':account_id' => $accountId,
                ':action'     => substr($action, 0, 40),
                ':target_id'  => $targetId ? substr($targetId, 0, 100) : null,
                ':meta'       => !empty($meta) ? json_encode($meta) : null,
                ':ip'         => $ip,
            ]);
        } catch (Throwable $e) {
            // Non-fatal — audit log failure must NEVER break the primary operation
        }
    }
}
