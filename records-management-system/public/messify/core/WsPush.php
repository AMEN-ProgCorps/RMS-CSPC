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
     * Send the HTTP response that's already been echo'd/json_encode'd to
     * the client NOW, then run $work() afterwards.
     *
     * Why this exists: WsPush::push()/broadcast() are "fire-and-forget"
     * pushes to the ws-server, but the underlying call was a *synchronous*
     * file_get_contents() with up to a multi-second timeout — so a slow or
     * momentarily-busy ws-server directly added that latency to whatever
     * the browser was waiting on (e.g. mark_read.php measured ~480ms in
     * production for what should be a single-row INSERT). The client
     * doesn't need to wait for this at all.
     *
     * Call this from an endpoint AFTER it has echo'd its real JSON
     * response, wrapping the WsPush call(s):
     *
     *   echo json_encode([...]);
     *   WsPush::flushResponseThenRun(function () use (...) {
     *       WsPush::push([...], 'message_read', [...]);
     *   });
     *
     * Uses fastcgi_finish_request() (PHP-FPM) to actually close the
     * connection to the client while the worker keeps running in the
     * background; falls back to a plain flush() elsewhere (Apache mod_php,
     * built-in dev server) which won't fully detach but at least doesn't
     * change behavior there.
     */
    public static function flushResponseThenRun(callable $work): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('flush')) {
            @flush();
        }

        try {
            $work();
        } catch (Throwable $t) {
            error_log('WsPush::flushResponseThenRun() — ' . $t->getMessage());
        }
    }

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

    /**
     * Generic best-effort push to one or more accounts' already-open sockets.
     * `type` + `data` are merged and handed straight to the client's
     * ws.onmessage handler (see ws-server/server.js handleInternalPush).
     *
     * Fire-and-forget: failures here must never break the calling request.
     */
    public static function push(array $accountIds, string $type, array $data = []): void
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        if (empty($accountIds)) {
            return;
        }

        self::send([
            'secret'      => INTERNAL_PUSH_SECRET,
            'type'        => $type,
            'account_ids' => $accountIds,
            'data'        => $data,
        ]);
    }

    /**
     * System-wide broadcast to all connected WebSocket clients.
     */
    public static function broadcast(string $type, array $data = []): void
    {
        self::send([
            'secret'    => INTERNAL_PUSH_SECRET,
            'type'      => $type,
            'broadcast' => true,
            'data'      => $data,
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
                'timeout'       => 1.0, // seconds
                'ignore_errors' => true,
            ],
        ]);

        $urls = [WS_INTERNAL_PUSH_URL];
        if (strpos(WS_INTERNAL_PUSH_URL, 'websocket') !== false) {
            $urls[] = str_replace('websocket', '127.0.0.1', WS_INTERNAL_PUSH_URL);
        } elseif (strpos(WS_INTERNAL_PUSH_URL, '127.0.0.1') !== false) {
            $urls[] = str_replace('127.0.0.1', 'websocket', WS_INTERNAL_PUSH_URL);
        }

        $pushed = false;
        foreach ($urls as $url) {
            try {
                $res = @file_get_contents($url, false, $context);
                if ($res !== false) {
                    $pushed = true;
                    break;
                }
            } catch (Throwable $e) {
                // Try next url candidate
            }
        }

        if (!$pushed) {
            error_log('WsPush::send() failed pushing payload to ' . WS_INTERNAL_PUSH_URL);
        }
    }
}
