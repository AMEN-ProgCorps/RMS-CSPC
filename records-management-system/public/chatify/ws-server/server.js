const WebSocket = require('ws');
const http = require('http');
const https = require('https');
const urlModule = require('url');
const crypto = require('crypto');
let monitorEventLoopDelay;
try {
  ({ monitorEventLoopDelay } = require('perf_hooks'));
} catch (e) {
  monitorEventLoopDelay = null; // very old Node — metrics will just skip lag
}

const PORT = process.env.PORT || 8080;
// Fallback key matches public/chatify/config/db.php CHAT_SHARED_SECRET default
const CHAT_SHARED_SECRET = process.env.CHAT_SHARED_SECRET || '7f5b84c8a2bf6d91cd4a9c68aef2bc7e4c925d8864b85abef95a720cf12a32cd';

// Secret PHP uses to authenticate server-to-server pushes (see handleInternalPush
// below). Defaults to the same shared secret as client auth for convenience, but
// set INTERNAL_PUSH_SECRET separately in production if you want to be able to
// rotate them independently.
const INTERNAL_PUSH_SECRET = process.env.INTERNAL_PUSH_SECRET || CHAT_SHARED_SECRET;
const INTERNAL_PUSH_MAX_BODY_BYTES = 2 * 1024 * 1024;
const PHP_APP_BASE_URL = process.env.PHP_APP_BASE_URL || 'http://127.0.0.1';

function internalFetchPhp(endpointPath, queryParams, accountId, callback) {
  let queryString = '';
  if (queryParams && typeof queryParams === 'object') {
    const params = new URLSearchParams();
    for (const key in queryParams) {
      if (queryParams[key] !== undefined && queryParams[key] !== null) {
        params.append(key, queryParams[key]);
      }
    }
    queryString = '?' + params.toString();
  }

  const pathsToTry = [
    endpointPath,
    '/chatify' + endpointPath,
    '/public/chatify' + endpointPath
  ];

  let parsedUrl;
  try {
    parsedUrl = new urlModule.URL(PHP_APP_BASE_URL);
  } catch (e) {
    parsedUrl = new urlModule.URL('http://127.0.0.1');
  }

  const basePort = parsedUrl.port ? parseInt(parsedUrl.port, 10) : (parsedUrl.protocol === 'https:' ? 443 : 80);
  const portsToTry = Array.from(new Set([basePort, 80, 8000, 8080, 3000]));

  const targets = [];
  for (const p of portsToTry) {
    for (const path of pathsToTry) {
      targets.push({ port: p, path: path });
    }
  }

  function tryNextTarget(index) {
    if (index >= targets.length) {
      return callback(new Error('Internal PHP request failed for all target ports/paths'), null);
    }

    const t = targets[index];
    const httpModule = parsedUrl.protocol === 'https:' ? https : http;
    const reqOpts = {
      hostname: parsedUrl.hostname,
      port: t.port,
      path: t.path + queryString,
      method: 'GET',
      headers: {
        'X-Internal-Secret': INTERNAL_PUSH_SECRET,
        'X-Internal-Account-Id': String(accountId),
        'User-Agent': 'Chatify-WS-Server/1.0'
      },
      timeout: 2000
    };

    const req = httpModule.request(reqOpts, (res) => {
      if ((res.statusCode === 404 || res.statusCode === 502 || res.statusCode === 503) && index < targets.length - 1) {
        res.resume();
        return tryNextTarget(index + 1);
      }
      let rawData = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => { rawData += chunk; });
      res.on('end', () => {
        if (res.statusCode !== 200) {
          return tryNextTarget(index + 1);
        }
        try {
          const parsed = JSON.parse(rawData);
          callback(null, parsed);
        } catch (e) {
          callback(e, null);
        }
      });
    });

    req.on('error', () => { tryNextTarget(index + 1); });
    req.on('timeout', () => { req.destroy(); tryNextTarget(index + 1); });
    req.end();
  }

  tryNextTarget(0);
}

const AUTH_TIMEOUT_MS = 10000;
const HEARTBEAT_INTERVAL_MS = 30000;
const METRICS_INTERVAL_MS = 60000;
// Small JSON control/chat messages don't benefit from permessage-deflate —
// the CPU cost of compressing every frame is worse than the bytes saved,
// and at a few thousand concurrent sockets that CPU cost is what actually
// limits how many connections one process can serve.
const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024; // 2MB ceiling — handles long text/conversations and control JSON
const MAX_CONNECTIONS = parseInt(process.env.MAX_CONNECTIONS || '10000', 10);

// ── Logging ──────────────────────────────────────────────────────────────
// Debug-level logs (one per message/typing/auth event) are gated off in
// production — at high load, console.log itself becomes a meaningful chunk
// of CPU time. Real errors and the periodic metrics line always print.
const IS_PROD = process.env.NODE_ENV === 'production';
function log(...args) {
  if (!IS_PROD) console.log(...args);
}
function logError(...args) {
  console.error(...args);
}

// Plain HTTP server that the WS upgrade attaches to. We also use it to expose
// a small internal-only POST endpoint (/internal/push) that PHP calls to push
// events — session kicks, name changes, notifications — straight to a user's
// already-open socket instead of the client having to poll for them.
const httpServer = http.createServer((req, res) => {
  handleInternalPush(req, res);
});

const wss = new WebSocket.Server({
  server: httpServer,
  perMessageDeflate: false,
  maxPayload: MAX_PAYLOAD_BYTES,
  // We maintain our own richer tracking (clients/accountSockets below), so
  // we don't need the library's built-in wss.clients bookkeeping too.
  clientTracking: false
});

httpServer.listen(PORT, () => {
  console.log(`WebSocket server starting on port ${PORT} (max connections: ${MAX_CONNECTIONS})...`);
});

// Map of ws -> { accountId, name, authenticated }
const clients = new Map();

// Reverse index: accountId -> Set<ws>
//
// Without this, every private message, typing event, or admin
// clear/delete notice would have to walk the ENTIRE connected-client list
// just to find the one or two sockets that actually care. That's fine at
// a few dozen users; at a few thousand it means every keystroke's typing
// event does thousands of wasted Map iterations. This index turns those
// lookups into O(1) (or O(sessions-for-that-account), which is tiny).
const accountSockets = new Map();

function indexSocket(ws, accountId) {
  let set = accountSockets.get(accountId);
  if (!set) {
    set = new Set();
    accountSockets.set(accountId, set);
  }
  set.add(ws);
}

function unindexSocket(ws, accountId) {
  const set = accountSockets.get(accountId);
  if (!set) return;
  set.delete(ws);
  if (set.size === 0) accountSockets.delete(accountId);
}

// ── Real-Time Typing Preview (Memory-Only Architecture) ─────────────────
// Key format: `${senderId}:${recipientId}`
const activeTypingPreviews = new Map();
const userCommSettings = new Map();

function getUserCommSettings(accountId) {
  return userCommSettings.get(Number(accountId)) || {
    allow_typing_preview: true,
    allow_see_typing_preview: true
  };
}

function clearPreview(key, notify = false, reason = 'cleared') {
  const entry = activeTypingPreviews.get(key);
  if (!entry) return;
  if (entry.timeoutId) clearTimeout(entry.timeoutId);
  activeTypingPreviews.delete(key);

  if (notify) {
    const payloadStr = JSON.stringify({
      type: 'typing_preview_cleared',
      sender_id: entry.sender_id,
      recipient_id: entry.recipient_id,
      reason: reason
    });
    broadcastToAccounts([entry.recipient_id, entry.sender_id], payloadStr);
  }
}

// ── Rate limiting (per account, fixed window) ───────────────────────────
// Cheap spam/flood protection: a runaway client (buggy tab stuck in a
// loop, or someone intentionally hammering the socket) can't drown out
// the server or other users' events.
const RATE_LIMITS = {
  typing: { max: 5, windowMs: 1000 },
  typing_preview: { max: 60, windowMs: 1000 },
  message: { max: 10, windowMs: 1000 },
  control: { max: 20, windowMs: 1000 }, // update_name, chat_cleared, all_cleared
  mark_read: { max: 10, windowMs: 1000 }
};
const rateState = new Map(); // accountId -> { [category]: { count, windowStart } }

function isRateLimited(accountId, category) {
  const limits = RATE_LIMITS[category];
  if (!limits) return false;

  let state = rateState.get(accountId);
  if (!state) {
    state = {};
    rateState.set(accountId, state);
  }

  const now = Date.now();
  let bucket = state[category];
  if (!bucket || now - bucket.windowStart >= limits.windowMs) {
    bucket = { count: 0, windowStart: now };
    state[category] = bucket;
  }
  bucket.count++;

  if (bucket.count > limits.max) {
    // Only log the moment a client crosses the line, not every dropped
    // event after that — an abusive/buggy client can fire hundreds/sec.
    if (bucket.count === limits.max + 1) {
      log(`Rate limit hit: account_id=${accountId}, category=${category}`);
    }
    return true;
  }
  return false;
}

function clearRateState(accountId) {
  // Only clear once no session for this account remains connected.
  if (!accountSockets.has(accountId)) {
    rateState.delete(accountId);
  }
}

// Helper for timing-safe string comparison
function timingSafeEqual(a, b) {
  try {
    const bufA = Buffer.from(a, 'utf8');
    const bufB = Buffer.from(b, 'utf8');
    if (bufA.length !== bufB.length) {
      return false;
    }
    return crypto.timingSafeEqual(bufA, bufB);
  } catch (e) {
    return false;
  }
}

// Verify HMAC token issued by PHP
function verifyToken(accountId, expires, token) {
  const currentTimestamp = Math.floor(Date.now() / 1000);
  if (currentTimestamp > parseInt(expires)) {
    log(`Token expired: token expires at ${expires}, current time is ${currentTimestamp}`);
    return false;
  }
  const payload = accountId + '|' + expires;
  const expectedToken = crypto
    .createHmac('sha256', CHAT_SHARED_SECRET)
    .update(payload)
    .digest('hex');
  return timingSafeEqual(expectedToken, token);
}

// Send that never throws and never lets one slow/dead client block or
// crash a broadcast loop touching thousands of others.
function safeSend(ws, payloadStr) {
  if (ws.readyState !== WebSocket.OPEN) return;
  // Backpressure guard: if a client's outgoing buffer is already piling up
  // (slow network, stalled tab), skip this send instead of letting memory
  // balloon for one bad connection. That client's own reconnect/poll
  // fallback covers the gap.
  if (ws.bufferedAmount > 1_000_000) return;
  try {
    ws.send(payloadStr);
  } catch (e) {
    logError('Send failed:', e.message);
  }
}

// Notify specific accountIds (dedup'd), using the O(1) index instead of
// scanning every connected client.
function broadcastToAccounts(accountIds, payloadStr) {
  const seen = new Set();
  for (const id of accountIds) {
    if (seen.has(id)) continue;
    seen.add(id);
    const set = accountSockets.get(id);
    if (!set) continue;
    for (const ws of set) safeSend(ws, payloadStr);
  }
}

// Fan out to every authenticated client (optionally skipping one account) —
// inherently O(n), but only used for the handful of events that really are
// system-wide (global chat, delete-all, disconnect typing-cleanup).
function broadcastToAll(payloadStr, excludeAccountId) {
  for (const [clientWs, clientState] of clients.entries()) {
    if (!clientState.authenticated) continue;
    if (excludeAccountId !== undefined && clientState.accountId === excludeAccountId) continue;
    safeSend(clientWs, payloadStr);
  }
}

// ── Internal push endpoint ───────────────────────────────────────────────
// PHP calls this (server-to-server, not exposed to browsers) right after it
// does its own DB write, so the change reaches an already-open socket
// immediately instead of the client having to poll for it. Expected JSON
// body: { secret, type, account_id (or account_ids: []), data: {...} }.
// `type` + `data` are merged into the payload sent to the client's existing
// ws.onmessage handler, so add a matching `data.type === '...'` case there
// for anything new pushed through here.
function handleInternalPush(req, res) {
  if (req.method !== 'POST' || req.url !== '/internal/push') {
    res.writeHead(404);
    res.end();
    return;
  }

  let body = '';
  let rejected = false;
  req.on('data', (chunk) => {
    if (rejected) return;
    body += chunk;
    if (body.length > INTERNAL_PUSH_MAX_BODY_BYTES) {
      rejected = true;
      res.writeHead(413);
      res.end('Payload too large');
      req.destroy();
    }
  });

  req.on('end', () => {
    if (rejected) return;

    let payload;
    try {
      payload = JSON.parse(body);
    } catch (e) {
      res.writeHead(400);
      res.end('Invalid JSON');
      return;
    }

    if (!timingSafeEqual(String(payload.secret || ''), INTERNAL_PUSH_SECRET)) {
      log('Rejected internal push: bad or missing secret');
      res.writeHead(403);
      res.end('Forbidden');
      return;
    }

    const { type, account_id, account_ids, broadcast, data: eventData } = payload;
    if (!type) {
      res.writeHead(400);
      res.end('Missing "type"');
      return;
    }

    const outPayloadStr = JSON.stringify(Object.assign({ type }, eventData || {}));

    // force_disconnect=true means "this account's RMS session is gone —
    // don't just notify the client, make sure the socket can't be used
    // again." Used for explicit logout and session-kick events. The
    // message is still sent first so the client can show a friendly
    // overlay before the socket goes away out from under it.
    const shouldForceDisconnect = payload.force_disconnect === true;

    function closeTargets(accountIds) {
      const seen = new Set();
      for (const id of accountIds) {
        if (seen.has(id)) continue;
        seen.add(id);
        const set = accountSockets.get(id);
        if (!set) continue;
        for (const clientWs of Array.from(set)) {
          try { clientWs.close(4002, 'Session invalidated'); } catch (e) {}
        }
      }
    }

    if (broadcast === true) {
      // System-wide sidebar-affecting event (e.g. account created/deleted)
      // that isn't scoped to a specific recipient.
      broadcastToAll(outPayloadStr);
      log(`Internal push relayed: type=${type}, targets=all`);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, delivered_to: 'all' }));
      return;
    }

    const targets = Array.isArray(account_ids)
      ? account_ids.map(Number)
      : (account_id !== undefined ? [Number(account_id)] : []);

    if (targets.length === 0) {
      res.writeHead(400);
      res.end('Missing "account_id", "account_ids", or "broadcast: true"');
      return;
    }

    broadcastToAccounts(targets, outPayloadStr);
    log(`Internal push relayed: type=${type}, targets=${targets.join(',')}`);

    // Real DM sends go through send_dm.php -> here (not the client-originated
    // WS 'message' handler below), so that handler's typing-preview-clear
    // logic never ran for them — leaving the sidebar stuck showing the
    // last-typed preview text after the message was actually sent. Mirror
    // that same clearing here for private messages relayed from PHP.
    if (type === 'message' && eventData && eventData.chat_type === 'private') {
      const senderId = Number(eventData.sender_id);
      const recipientId = Number(eventData.recipient_id);
      if (senderId && recipientId) {
        const previewKey = `${senderId}:${recipientId}`;
        if (activeTypingPreviews.has(previewKey)) {
          clearPreview(previewKey, false);
          const sentPayloadStr = JSON.stringify({
            type: 'typing_preview_sent',
            sender_id: senderId,
            recipient_id: recipientId
          });
          broadcastToAccounts([recipientId, senderId], sentPayloadStr);
        }
      }
    }

    if (shouldForceDisconnect) {
      // Small delay so the message above has a chance to reach the client
      // before the socket disappears — the overlay is cosmetic, but the
      // disconnect itself must happen regardless of whether the client
      // reacts to the message.
      setTimeout(() => closeTargets(targets), 250);
    }

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, delivered_to: targets }));
  });

  req.on('error', () => {
    // Client hung up mid-request — nothing to clean up, the 'end' handler
    // above simply never fires.
  });
}

wss.on('connection', (ws) => {
  // ── Connection cap ── reject new sockets once at capacity, so a bug or
  // an attack can't OOM the process. Existing users keep working; new
  // connection attempts get a clean close instead of the server falling over.
  if (clients.size >= MAX_CONNECTIONS) {
    log(`Connection cap reached (${MAX_CONNECTIONS}), rejecting new connection.`);
    ws.close(1013, 'Server at capacity, please try again shortly');
    return;
  }

  // Native ws heartbeat plumbing (see the interval below). This is the
  // library's own ping/pong frames — no custom app-level heartbeat
  // message type needed.
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });

  log('New client connection initiated...');

  // Enforce authentication within 10 seconds, otherwise terminate connection
  const authTimeout = setTimeout(() => {
    const state = clients.get(ws);
    if (!state || !state.authenticated) {
      log('Authentication timeout, closing socket.');
      ws.close(4001, 'Authentication Timeout');
    }
  }, AUTH_TIMEOUT_MS);

  ws.on('message', (message) => {
    // Belt-and-suspenders payload size guard. `maxPayload` on the server
    // already makes `ws` terminate oversized frames before this handler
    // even runs, but this keeps the check explicit and self-contained in
    // case that option is ever changed or the server sits behind a proxy
    // that reframes messages.
    if (message.length > MAX_PAYLOAD_BYTES) {
      log('Oversized payload received, closing socket.');
      ws.close(1009, 'Payload too large');
      return;
    }

    let data;
    try {
      data = JSON.parse(message);
    } catch (e) {
      logError('Failed to parse message payload:', e.message);
      return;
    }

    const state = clients.get(ws) || { authenticated: false };

    // 1. Authenticate Handshake
    if (data.type === 'auth') {
      const { account_id, expires, token, name } = data;
      if (verifyToken(account_id, expires, token)) {
        clearTimeout(authTimeout);
        const accountId = Number(account_id);
        clients.set(ws, {
          accountId,
          name: name || `User ${account_id}`,
          avatarUrl: data.avatar_url || null,
          authenticated: true,
          expires: parseInt(expires, 10) // re-checked continuously, see sweep below
        });
        indexSocket(ws, accountId);
        if (data.comm_settings && typeof data.comm_settings === 'object') {
          userCommSettings.set(accountId, {
            allow_typing_preview: !!data.comm_settings.allow_typing_preview,
            allow_see_typing_preview: !!data.comm_settings.allow_see_typing_preview
          });
        }
        log(`Client authenticated: account_id=${account_id}, name="${name}"`);
        ws.send(JSON.stringify({ type: 'auth_success' }));

        // Sync active typing previews for this recipient (handles page refresh)
        for (const [key, entry] of activeTypingPreviews.entries()) {
          if (entry.recipient_id === accountId) {
            const recSettings = getUserCommSettings(accountId);
            if (recSettings.allow_see_typing_preview) {
              safeSend(ws, JSON.stringify({
                type: 'typing_preview',
                sender_id: entry.sender_id,
                recipient_id: entry.recipient_id,
                preview: entry.preview
              }));
            }
          }
        }
      } else {
        log(`Authentication failed: account_id=${account_id}`);
        ws.send(JSON.stringify({ type: 'auth_failure', error: 'Invalid or expired token' }));
        ws.close(4003, 'Invalid Token');
      }
      return;
    }

    // Reject all other messages if the connection has not authenticated yet
    if (!state.authenticated) {
      log('Ignored message from unauthenticated socket.');
      return;
    }

    // 1b. Reauth — the client re-runs Auth::check() server-side (via
    // refresh_ws_token.php) every few minutes and pushes the freshly
    // minted token here to extend the socket's validity. If the RMS
    // session is gone, the client never gets a fresh token to send, the
    // old one simply expires, and the sweep below closes the socket.
    // If someone sends a bad/forged token here, close immediately —
    // same treatment as a failed initial handshake.
    if (data.type === 'reauth') {
      const { account_id, expires, token } = data;
      if (Number(account_id) === state.accountId && verifyToken(account_id, expires, token)) {
        state.expires = parseInt(expires, 10);
        log(`Reauth OK: account_id=${state.accountId}, new expiry=${state.expires}`);
      } else {
        log(`Reauth failed: account_id=${state.accountId} — closing socket.`);
        ws.close(4003, 'Invalid Reauth Token');
      }
      return;
    }

    // 1c. WebSocket query for DM user list / sidebar
    if (data.type === 'fetch_users_dm') {
      const q = (data.q || '').trim();
      internalFetchPhp('/fetch_users_dm.php', { q }, state.accountId, (err, resData) => {
        if (err) {
          logError('fetch_users_dm WS error:', err.message);
          return;
        }
        safeSend(ws, JSON.stringify({
          type: 'users_dm_response',
          req_id: data.req_id || null,
          data: resData
        }));
      });
      return;
    }

    // 1d. WebSocket query for chat messages (global or DM)
    if (data.type === 'fetch_messages') {
      const chatType = data.chat_type || 'global';
      const beforeUuid = data.before_uuid || '';
      const endpoint = chatType === 'global' ? '/load.php' : '/load_dm.php';
      const query = chatType === 'global'
        ? { before_uuid: beforeUuid }
        : { target_id: data.target_id || 0, target_user: data.target_user || '', before_uuid: beforeUuid };

      internalFetchPhp(endpoint, query, state.accountId, (err, resData) => {
        if (err) {
          logError('fetch_messages WS error:', err.message);
          return;
        }
        safeSend(ws, JSON.stringify({
          type: 'messages_response',
          req_id: data.req_id || null,
          chat_type: chatType,
          target_id: data.target_id || 0,
          data: resData
        }));
      });
      return;
    }

    // 2. Handle Message Dispatched Event
    if (data.type === 'message') {
      if (isRateLimited(state.accountId, 'message')) return;

      const { chat_type, recipient_id, msg_uuid, message, created_at, has_upload, reply_to_msg_uuid, reply_snippet } = data;
      log(`Broadcasting message event: type=${chat_type}, sender_id=${state.accountId}, msg_uuid=${msg_uuid || ''}, has_upload=${has_upload || false}`);

      const payloadObj = {
        type: 'message',
        chat_type: chat_type || 'global',
        sender_id: state.accountId,
        sender_name: state.name,
        sender_avatar: state.avatarUrl || null,
        recipient_id: recipient_id || null,
        msg_uuid: msg_uuid || null,
        message: message || '',
        created_at: created_at || new Date().toISOString(),
        has_upload: !!has_upload,
        reply_to_msg_uuid: reply_to_msg_uuid || null,
        reply_snippet: reply_snippet || null
      };
      const payloadStr = JSON.stringify(payloadObj);

      if (chat_type === 'global') {
        broadcastToAll(payloadStr, state.accountId);
      } else if (chat_type === 'private') {
        // Recipient, any other session of the sender, and admin (1) for spymode
        broadcastToAccounts([Number(recipient_id), state.accountId, 1], payloadStr);
        // Clear active typing preview on message send
        const previewKey = `${state.accountId}:${Number(recipient_id)}`;
        if (activeTypingPreviews.has(previewKey)) {
          clearPreview(previewKey, false);
          const sentPayloadStr = JSON.stringify({
            type: 'typing_preview_sent',
            sender_id: state.accountId,
            recipient_id: Number(recipient_id)
          });
          broadcastToAccounts([Number(recipient_id), state.accountId], sentPayloadStr);
        }
      }
      return;
    }

    // 2b. Handle Message Edited Event
    if (data.type === 'message_edited') {
      if (isRateLimited(state.accountId, 'message')) return;

      const { chat_type, recipient_id, msg_uuid, message } = data;
      log(`Broadcasting message_edited event: msg_uuid=${msg_uuid}, sender_id=${state.accountId}`);

      const payloadStr = JSON.stringify({
        type: 'message_edited',
        msg_uuid,
        message,
        chat_type,
        sender_id: state.accountId
      });

      if (chat_type === 'global') {
        broadcastToAll(payloadStr, state.accountId);
      } else if (chat_type === 'private') {
        // Recipient, any other session of the sender, and admin (1) for spymode
        broadcastToAccounts([Number(recipient_id), state.accountId, 1], payloadStr);
      }
      return;
    }

    // 3. Handle Name Update Event
    if (data.type === 'update_name') {
      if (isRateLimited(state.accountId, 'control')) return;

      const newName = (data.name || '').trim();
      if (newName) {
        log(`Name updated: account_id=${state.accountId}, "${state.name}" → "${newName}"`);
        state.name = newName;
      }
      return;
    }

    // 4. Handle Typing Indicator Event
    if (data.type === 'typing') {
      if (isRateLimited(state.accountId, 'typing')) return;

      const { recipient_id, is_typing } = data;

      const payloadStr = JSON.stringify({
        type: 'typing',
        sender_id: state.accountId,
        sender_name: state.name,
        is_typing: !!is_typing
      });

      broadcastToAccounts([Number(recipient_id)], payloadStr);

      return;
    }

    // 4a2. Handle Communication Settings Update Event
    if (data.type === 'update_comm_settings') {
      const targetAccId = Number(data.account_id || state.accountId);
      const prevSettings = getUserCommSettings(targetAccId);
      const newSettings = {
        allow_typing_preview: !!data.allow_typing_preview,
        allow_see_typing_preview: !!data.allow_see_typing_preview
      };
      userCommSettings.set(targetAccId, newSettings);

      log(`Communication settings updated for account ${targetAccId}: typing_preview=${newSettings.allow_typing_preview}, see_preview=${newSettings.allow_see_typing_preview}`);

      // If user turned OFF allow_typing_preview, clear previews sent by this user
      if (!newSettings.allow_typing_preview) {
        for (const [key, entry] of activeTypingPreviews.entries()) {
          if (entry.sender_id === targetAccId) {
            clearPreview(key, true, 'settings_disabled');
          }
        }
      }

      // If user turned OFF allow_see_typing_preview, clear all previews from this user's view
      if (!newSettings.allow_see_typing_preview) {
        for (const [key, entry] of activeTypingPreviews.entries()) {
          if (entry.recipient_id === targetAccId) {
            const clearStr = JSON.stringify({
              type: 'typing_preview_cleared',
              sender_id: entry.sender_id,
              recipient_id: targetAccId,
              reason: 'settings_disabled'
            });
            broadcastToAccounts([targetAccId], clearStr);
          }
        }
      }

      // If user just turned ON allow_see_typing_preview, replay any active previews immediately
      if (newSettings.allow_see_typing_preview && !prevSettings.allow_see_typing_preview) {
        for (const [key, entry] of activeTypingPreviews.entries()) {
          if (entry.recipient_id === targetAccId) {
            const senderSettings = getUserCommSettings(entry.sender_id);
            if (senderSettings.allow_typing_preview) {
              broadcastToAccounts([targetAccId], JSON.stringify({
                type: 'typing_preview',
                sender_id: entry.sender_id,
                recipient_id: targetAccId,
                preview: entry.preview
              }));
            }
          }
        }
      }

      return;
    }

    // 4a3. Handle Real-Time Typing Preview Event (Memory-Only)
    if (data.type === 'typing_preview') {
      if (isRateLimited(state.accountId, 'typing_preview')) return;

      const recipientId = Number(data.recipient_id);
      if (!recipientId) return;

      // Update in-memory user settings if passed with event
      if (data.allow_typing_preview !== undefined) {
        const existing = getUserCommSettings(state.accountId);
        userCommSettings.set(state.accountId, {
          ...existing,
          allow_typing_preview: !!data.allow_typing_preview
        });
      }
      if (data.allow_see_typing_preview !== undefined) {
        const existing = getUserCommSettings(state.accountId);
        userCommSettings.set(state.accountId, {
          ...existing,
          allow_see_typing_preview: !!data.allow_see_typing_preview
        });
      }

      const senderSettings = getUserCommSettings(state.accountId);
      const recipientSettings = getUserCommSettings(recipientId);

      const key = `${state.accountId}:${recipientId}`;
      const rawText = data.preview || '';
      const text = rawText.substring(0, 1000); // 1000 character draft limit

      if (!text || text.trim() === '') {
        clearPreview(key, true, 'empty');
        return;
      }

      // Server-Side Permission Check:
      // Sender MUST allow typing preview AND Recipient MUST allow seeing typing preview
      const isAllowedToBroadcast = senderSettings.allow_typing_preview && recipientSettings.allow_see_typing_preview;

      // Clear existing timer if present
      const existingEntry = activeTypingPreviews.get(key);
      if (existingEntry && existingEntry.timeoutId) {
        clearTimeout(existingEntry.timeoutId);
      }

      // Reset 5-second inactivity timeout
      const timeoutId = setTimeout(() => {
        clearPreview(key, true, 'timeout');
      }, 5000);

      activeTypingPreviews.set(key, {
        sender_id: state.accountId,
        recipient_id: recipientId,
        preview: text,
        timeoutId
      });

      if (isAllowedToBroadcast) {
        const payloadStr = JSON.stringify({
          type: 'typing_preview',
          sender_id: state.accountId,
          sender_name: state.name,
          recipient_id: recipientId,
          preview: text
        });
        // Target recipient sessions and sender sessions (multi-device support)
        broadcastToAccounts([recipientId, state.accountId], payloadStr);
      }
      return;
    }

    // 4b. Handle Seen/Read Receipt Event — relayed live over the socket the
    // instant the reader's client marks the conversation read, so the
    // sender's "Seen" indicator updates with no HTTP round trip and no
    // debounce delay (same pattern as the 'typing' event above). This is
    // the FAST, ephemeral path; the client also still calls mark_read.php
    // separately over HTTP so the read marker is durably persisted in
    // Postgres (this ws-server has no DB connection of its own and must
    // never be the only place the read state lives — a page reload or a
    // second tab has to see the correct state too).
    if (data.type === 'mark_read') {
      if (isRateLimited(state.accountId, 'mark_read')) return;

      const { target_id, last_msg_uuid } = data;
      const targetId = Number(target_id);
      if (!targetId) return;

      const payloadStr = JSON.stringify({
        type: 'message_read',
        reader_id: state.accountId,
        target_id: targetId,
        last_msg_uuid: last_msg_uuid || null
      });
      // Notify the sender's live socket(s) immediately, plus admin (1) for
      // spymode parity with the HTTP-triggered push in mark_read.php.
      broadcastToAccounts([targetId, 1], payloadStr);
      return;
    }

    // 5. Handle Chat Cleared Event (admin cleared one specific DM/conversation)
    if (data.type === 'chat_cleared') {
      if (isRateLimited(state.accountId, 'control')) return;

      const { chat_type, recipient_id, user_a, user_b } = data;
      log(`Broadcasting chat_cleared event: chat_type=${chat_type}, by=${state.accountId}`);

      if (chat_type === 'private') {
        const a = Math.min(state.accountId, Number(recipient_id));
        const b = Math.max(state.accountId, Number(recipient_id));
        const payloadStr = JSON.stringify({
          type: 'chat_cleared',
          chat_type: 'private',
          sender_id: state.accountId,
          recipient_id: recipient_id,
          user_a: a,
          user_b: b
        });
        // Same audience as a private 'message' event: the recipient, any
        // other session of the person who cleared it, and admin (1) for spymode
        broadcastToAccounts([Number(recipient_id), state.accountId, 1], payloadStr);
      } else if (chat_type === 'admin_conv') {
        const a = Number(user_a);
        const b = Number(user_b);
        const payloadStr = JSON.stringify({
          type: 'chat_cleared',
          chat_type: 'admin_conv',
          user_a: a,
          user_b: b
        });
        // Both participants of the cleared conversation, any other admin
        // (spymode), and the admin session that triggered this
        broadcastToAccounts([a, b, 1, state.accountId], payloadStr);
      }
      return;
    }

    // 6. Handle Delete-All Event (admin wiped every message in the system)
    if (data.type === 'all_cleared') {
      if (isRateLimited(state.accountId, 'control')) return;

      log(`Broadcasting all_cleared event: triggered_by=${state.accountId}`);
      const payloadStr = JSON.stringify({ type: 'all_cleared' });
      broadcastToAll(payloadStr);
      return;
    }
  });

  ws.on('close', (code, reason) => {
    clearTimeout(authTimeout);
    const state = clients.get(ws);
    if (state && state.authenticated) {
      log(`Client disconnected: account_id=${state.accountId}, code=${code}`);
      unindexSocket(ws, state.accountId);
      clearRateState(state.accountId);

      // Clean up active typing previews sent by this disconnected user
      for (const [key, entry] of activeTypingPreviews.entries()) {
        if (entry.sender_id === state.accountId) {
          clearPreview(key, true, 'disconnect');
        }
      }

      // Deliberately NOT broadcasting an is_typing:false to everyone here.
      // That used to be an O(n) fan-out to every connected client on every
      // single disconnect — at 5,000 users, 100 near-simultaneous drops
      // (e.g. a campus-wide wifi hiccup) meant 500,000 sends in an
      // instant, enough to spike event-loop lag and cascade into timeouts
      // for otherwise-healthy connections. Typing indicators are best-
      // effort, not mission-critical: the frontend's own showTypingIndicator()
      // already auto-hides the indicator a few seconds after the last
      // typing packet, so a disconnected user's indicator disappears on
      // its own shortly after, without the server needing to announce it.
    }
    clients.delete(ws);
  });

  ws.on('error', (err) => {
    logError('Socket connection error:', err.message);
  });
});

// ── Heartbeat: reap dead connections (native ws ping/pong) ──────────────
// At a few thousand long-lived sockets, connections that die without a
// clean close (phone put to sleep, wifi drop, laptop lid closed)
// otherwise sit in `clients`/`accountSockets` forever — quietly wasting
// memory and, worse, making broadcasts try to write to a socket that's
// actually gone. This sweep uses the WebSocket protocol's own ping/pong
// control frames (no custom app-level message type) every 30s to detect
// and terminate anything that didn't answer the previous ping.
const heartbeatTimer = setInterval(() => {
  for (const [clientWs, clientState] of clients.entries()) {
    if (clientWs.isAlive === false) {
      log(`Terminating stale connection: account_id=${clientState.accountId ?? 'unauthenticated'}`);
      clientWs.terminate(); // triggers 'close', which does the map/index cleanup
      continue;
    }
    clientWs.isAlive = false;
    try {
      clientWs.ping();
    } catch (e) {
      // Socket already gone — 'close' will handle cleanup
    }
  }
}, HEARTBEAT_INTERVAL_MS);

// ── Token-expiry sweep ────────────────────────────────────────────────────
// Enforces the 'expires' claim on an ongoing basis instead of only at the
// initial handshake. A socket whose token has lapsed without a successful
// 'reauth' (see message handler above) means the client either went dark
// or its RMS session stopped validating — either way this connection must
// not be trusted to keep sending/receiving chat traffic.
const EXPIRY_SWEEP_INTERVAL_MS = 30000;
const expirySweepTimer = setInterval(() => {
  const nowSec = Math.floor(Date.now() / 1000);
  for (const [clientWs, clientState] of clients.entries()) {
    if (!clientState.authenticated || !clientState.expires) continue;
    if (nowSec > clientState.expires) {
      log(`Token expired without reauth: account_id=${clientState.accountId} — forcing disconnect.`);
      safeSend(clientWs, JSON.stringify({ type: 'session_kicked', reason: 'expired' }));
      try { clientWs.close(4002, 'Session expired'); } catch (e) {}
    }
  }
}, EXPIRY_SWEEP_INTERVAL_MS);

// ── Metrics: periodic operational snapshot ──────────────────────────────
// Cheap enough to always run (even in production) — this is exactly what
// you want in server logs to catch a memory leak or event-loop stall
// before it becomes an outage.
let eventLoopMonitor = null;
if (monitorEventLoopDelay) {
  eventLoopMonitor = monitorEventLoopDelay({ resolution: 20 });
  eventLoopMonitor.enable();
}

function formatUptime(seconds) {
  const d = Math.floor(seconds / 86400);
  const h = Math.floor((seconds % 86400) / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const parts = [];
  if (d) parts.push(`${d}d`);
  if (h || d) parts.push(`${h}h`);
  parts.push(`${m}m`);
  return parts.join(' ');
}

const metricsTimer = setInterval(() => {
  const mem = process.memoryUsage();
  const ramMB = Math.round(mem.rss / (1024 * 1024));
  const uptime = formatUptime(process.uptime());
  let lagLine = '';
  if (eventLoopMonitor) {
    const lagMs = (eventLoopMonitor.mean / 1e6).toFixed(2);
    lagLine = `, Event loop lag: ${lagMs}ms`;
    eventLoopMonitor.reset();
  }
  console.log(
    `[metrics] Connections: ${clients.size}, RAM: ${ramMB}MB, Uptime: ${uptime}${lagLine}`
  );
}, METRICS_INTERVAL_MS);

wss.on('close', () => {
  clearInterval(heartbeatTimer);
  clearInterval(metricsTimer);
  clearInterval(expirySweepTimer);
  if (eventLoopMonitor) eventLoopMonitor.disable();
});

process.on('SIGTERM', () => {
  clearInterval(heartbeatTimer);
  clearInterval(metricsTimer);
  clearInterval(expirySweepTimer);
  wss.close(() => process.exit(0));
});

// ── PM2 / cluster-mode note ──────────────────────────────────────────────
// This process holds no on-disk state and can be killed/restarted freely
// — but `clients`, `accountSockets`, `rateState`, and `lastTypingState`
// are plain in-memory Maps scoped to THIS process. Running
// `pm2 start server.js -i max` spins up multiple independent Node
// processes, each with its own empty copies of those Maps and its own OS
// socket for the same port (PM2/the OS load-balances new connections
// across them round-robin). Two users connected to two different workers
// would never see each other's broadcasts — messages, typing, and
// clear/delete-all notices would silently stop reaching some subset of
// users depending on which worker they landed on.
//
// This file is safe to run as a single instance (`pm2 start server.js -i 1`
// with auto-restart) for any connection count this hardware can hold in
// one process's memory/CPU. To actually scale horizontally across
// multiple workers or machines, the broadcast functions above
// (`broadcastToAccounts` / `broadcastToAll`) need to publish through a
// shared channel — e.g. Redis pub/sub — so every worker learns about
// every event regardless of which worker holds the relevant socket, with
// each worker still only writing to the sockets it personally owns.