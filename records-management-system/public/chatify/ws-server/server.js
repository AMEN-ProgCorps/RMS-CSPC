const WebSocket = require('ws');
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

const AUTH_TIMEOUT_MS = 10000;
const HEARTBEAT_INTERVAL_MS = 30000;
const METRICS_INTERVAL_MS = 60000;
// Small JSON control/chat messages don't benefit from permessage-deflate —
// the CPU cost of compressing every frame is worse than the bytes saved,
// and at a few thousand concurrent sockets that CPU cost is what actually
// limits how many connections one process can serve.
const MAX_PAYLOAD_BYTES = 32 * 1024; // 32KB ceiling — plenty for chat/typing/control JSON
const MAX_CONNECTIONS = parseInt(process.env.MAX_CONNECTIONS || '5000', 10);

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

const wss = new WebSocket.Server({
  port: PORT,
  perMessageDeflate: false,
  maxPayload: MAX_PAYLOAD_BYTES,
  // We maintain our own richer tracking (clients/accountSockets below), so
  // we don't need the library's built-in wss.clients bookkeeping too.
  clientTracking: false
});
console.log(`WebSocket server starting on port ${PORT} (max connections: ${MAX_CONNECTIONS})...`);

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

// ── Rate limiting (per account, fixed window) ───────────────────────────
// Cheap spam/flood protection: a runaway client (buggy tab stuck in a
// loop, or someone intentionally hammering the socket) can't drown out
// the server or other users' events.
const RATE_LIMITS = {
  typing: { max: 5, windowMs: 1000 },
  message: { max: 10, windowMs: 1000 },
  control: { max: 20, windowMs: 1000 } // update_name, chat_cleared, all_cleared
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
          authenticated: true
        });
        indexSocket(ws, accountId);
        log(`Client authenticated: account_id=${account_id}, name="${name}"`);
        ws.send(JSON.stringify({ type: 'auth_success' }));
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

    // 2. Handle Message Dispatched Event
    if (data.type === 'message') {
      if (isRateLimited(state.accountId, 'message')) return;

      const { chat_type, recipient_id, msg_uuid } = data;
      log(`Broadcasting message event: type=${chat_type}, sender_id=${state.accountId}, msg_uuid=${msg_uuid || ''}`);

      if (chat_type === 'global') {
        const payloadStr = JSON.stringify({
          type: 'message',
          chat_type: 'global',
          sender_id: state.accountId,
          msg_uuid: msg_uuid || null
        });
        broadcastToAll(payloadStr, state.accountId);
      } else if (chat_type === 'private') {
        const payloadStr = JSON.stringify({
          type: 'message',
          chat_type: 'private',
          sender_id: state.accountId,
          recipient_id: recipient_id,
          msg_uuid: msg_uuid || null
        });
        // Recipient, any other session of the sender, and admin (1) for spymode
        broadcastToAccounts([Number(recipient_id), state.accountId, 1], payloadStr);
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

    // 5. Handle Chat Cleared Event (admin cleared one specific DM/conversation)
    if (data.type === 'chat_cleared') {
      if (isRateLimited(state.accountId, 'control')) return;

      const { chat_type, recipient_id, user_a, user_b } = data;
      log(`Broadcasting chat_cleared event: chat_type=${chat_type}, by=${state.accountId}`);

      if (chat_type === 'private') {
        const payloadStr = JSON.stringify({
          type: 'chat_cleared',
          chat_type: 'private',
          sender_id: state.accountId,
          recipient_id: recipient_id
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
  if (eventLoopMonitor) eventLoopMonitor.disable();
});

process.on('SIGTERM', () => {
  clearInterval(heartbeatTimer);
  clearInterval(metricsTimer);
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