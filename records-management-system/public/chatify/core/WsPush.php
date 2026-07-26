<?php
// =============================================================================
// core/WsPush.php - Server-to-server push to the ws-server's /internal/push
// =============================================================================
// Use this whenever an account's RMS/Chatify session is invalidated
// (logout, admin-forced logout, RMS session killed) so any open WebSocket
// for that account is actually torn down — not just informed. Without this,
// a live socket keeps working until its own token happens to lapse.
// =============================================================================

class WsPush
{
    /**
     * Force-disconnect every open socket for one account. Sends a
     * 'session_kicked' event first (so the client can show a friendly
     * overlay) and tells the ws-server to close the socket shortly after.
     *
     * Best-effort / fire-and-forget: failures here must never break the
     * calling request (e.g. logout.php must still log the user out even if
     * the ws-server is down or unreachable).
     */
    public static function forceDisconnect(int $accountId, string $reason = 'logged_out'): void
    {
        self::send([
            'secret'           => INTERNAL_PUSH_SECRET,
            'type'             => 'session_kicked',
            'account_id'       => $accountId,
            'force_disconnect' => true,
            'data'             => ['reason' => $reason],
        ]);
    }

    private static function send(array $payload): void
    {
        $body = json_encode($payload);
        if ($body === false) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $body,
                'timeout'       => 2, // seconds — never let a down ws-server stall the caller
                'ignore_errors' => true,
            ],
        ]);

        try {
            @file_get_contents(WS_INTERNAL_PUSH_URL, false, $context);
        } catch (Throwable $e) {
            // Non-fatal — the socket will still be caught by the
            // token-expiry sweep on the ws-server side (worst case, a few
            // minutes later) even if this immediate push fails.
        }
    }
}
