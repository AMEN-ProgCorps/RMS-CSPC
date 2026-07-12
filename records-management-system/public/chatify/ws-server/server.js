const WebSocket = require('ws');
const crypto = require('crypto');

const PORT = process.env.PORT || 8080;
// Fallback key matches public/chatify/config/db.php CHAT_SHARED_SECRET default
const CHAT_SHARED_SECRET = process.env.CHAT_SHARED_SECRET || '7f5b84c8a2bf6d91cd4a9c68aef2bc7e4c925d8864b85abef95a720cf12a32cd';

const wss = new WebSocket.Server({ port: PORT });
console.log(`WebSocket server starting on port ${PORT}...`);

// Keep track of connected clients
// Map of ws -> { accountId, name, authenticated }
const clients = new Map();

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
    console.log(`Token expired: token expires at ${expires}, current time is ${currentTimestamp}`);
    return false;
  }
  const payload = accountId + '|' + expires;
  const expectedToken = crypto
    .createHmac('sha256', CHAT_SHARED_SECRET)
    .update(payload)
    .digest('hex');
  return timingSafeEqual(expectedToken, token);
}

wss.on('connection', (ws) => {
  console.log('New client connection initiated...');

  // Enforce authentication within 10 seconds, otherwise terminate connection
  const authTimeout = setTimeout(() => {
    const state = clients.get(ws);
    if (!state || !state.authenticated) {
      console.log('Authentication timeout, closing socket.');
      ws.close(4001, 'Authentication Timeout');
    }
  }, 10000);

  ws.on('message', (message) => {
    let data;
    try {
      data = JSON.parse(message);
    } catch (e) {
      console.error('Failed to parse message payload:', e);
      return;
    }

    const state = clients.get(ws) || { authenticated: false };

    // 1. Authenticate Handshake
    if (data.type === 'auth') {
      const { account_id, expires, token, name } = data;
      if (verifyToken(account_id, expires, token)) {
        clearTimeout(authTimeout);
        clients.set(ws, {
          accountId: Number(account_id),
          name: name || `User ${account_id}`,
          authenticated: true
        });
        console.log(`Client authenticated: account_id=${account_id}, name="${name}"`);
        ws.send(JSON.stringify({ type: 'auth_success' }));
      } else {
        console.log(`Authentication failed: account_id=${account_id}`);
        ws.send(JSON.stringify({ type: 'auth_failure', error: 'Invalid or expired token' }));
        ws.close(4003, 'Invalid Token');
      }
      return;
    }

    // Reject all other messages if the connection has not authenticated yet
    if (!state.authenticated) {
      console.log('Ignored message from unauthenticated socket.');
      return;
    }

    // 2. Handle Message Dispatched Event
    if (data.type === 'message') {
      const { chat_type, recipient_id } = data;
      console.log(`Broadcasting message event: type=${chat_type}, sender_id=${state.accountId}`);
      
      // Propagate update notice to other connected clients
      for (const [clientWs, clientState] of clients.entries()) {
        if (!clientState.authenticated) continue;

        if (chat_type === 'global') {
          // Send update notification to all other users
          if (clientState.accountId !== state.accountId) {
            clientWs.send(JSON.stringify({
              type: 'message',
              chat_type: 'global',
              sender_id: state.accountId
            }));
          }
        } else if (chat_type === 'private') {
          // Send update notification only to recipient (and other sessions of the sender if any) or the admin (1) for spymode
          if (clientState.accountId === Number(recipient_id) || clientState.accountId === state.accountId || clientState.accountId === 1) {
            clientWs.send(JSON.stringify({
              type: 'message',
              chat_type: 'private',
              sender_id: state.accountId,
              recipient_id: recipient_id
            }));
          }
        }
      }
      return;
    }

    // 3. Handle Typing Indicator Event
    if (data.type === 'typing') {
      const { recipient_id, is_typing } = data;
      console.log(`Routing typing status: from=${state.accountId}, to=${recipient_id}, typing=${is_typing}`);
      
      // Dispatch typing state exclusively to the active DM recipient
      for (const [clientWs, clientState] of clients.entries()) {
        if (clientState.authenticated && clientState.accountId === Number(recipient_id)) {
          clientWs.send(JSON.stringify({
            type: 'typing',
            sender_id: state.accountId,
            sender_name: state.name,
            is_typing: is_typing
          }));
        }
      }
      return;
    }
  });

  ws.on('close', (code, reason) => {
    const state = clients.get(ws);
    if (state && state.authenticated) {
      console.log(`Client disconnected: account_id=${state.accountId}, code=${code}`);
      
      // Clean up the typing indicator for this user immediately on disconnect
      for (const [clientWs, clientState] of clients.entries()) {
        if (clientState.authenticated && clientWs !== ws) {
          clientWs.send(JSON.stringify({
            type: 'typing',
            sender_id: state.accountId,
            sender_name: state.name,
            is_typing: false
          }));
        }
      }
    }
    clients.delete(ws);
  });

  ws.on('error', (err) => {
    console.error('Socket connection error:', err);
  });
});
