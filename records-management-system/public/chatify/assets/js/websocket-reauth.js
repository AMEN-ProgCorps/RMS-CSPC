    // ── Periodic WS reauth ───────────────────────────────────────────────
    // Every 5 minutes (well inside the 15-minute token lifetime), re-run
    // the *server-side* RMS check by calling refresh_ws_token.php, which
    // re-executes Auth::check() against the live RMS session before
    // minting anything. If the RMS session is gone, this call fails and we
    // treat it exactly like a forced session-kick: tear down the socket
    // and send the person to login. If it succeeds, we push the fresh
    // token to the already-open socket so the server extends its validity
    // — the socket is never trusted indefinitely off a single handshake.
    function reauthWebSocket() {
      if (!ws || ws.readyState !== WebSocket.OPEN) return;
      fetch('refresh_ws_token.php', { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error('refresh failed: ' + res.status);
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.token) throw new Error('malformed refresh response');
          wsConfig.expires = data.expires;
          wsConfig.token = data.token;
          if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
              type: 'reauth',
              account_id: wsConfig.accountId,
              expires: wsConfig.expires,
              token: wsConfig.token
            }));
          }
        })
        .catch(function () {
          // RMS session is gone (401/expired) or the request itself
          // failed — do not keep the socket alive on the old token.
          // Reuse the same overlay path as an explicit session kick.
          if (typeof showSessionKickedOverlay === 'function') {
            showSessionKickedOverlay('expired');
          } else {
            window.location.href = 'logout.php';
          }
        });
    }
    setInterval(reauthWebSocket, 5 * 60 * 1000);
