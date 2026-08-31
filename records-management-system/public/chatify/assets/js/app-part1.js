// ── All DOM element references first ──────────────────────────────────────

    // Shared mobile/desktop breakpoint — matches the CSS layout breakpoint.
    // Every viewport-width check in the app (toast-vs-bell, sidebar
    // collapse, etc.) should go through this instead of comparing against
    // 991 directly, so there's a single place to change it.
    const MOBILE_BREAKPOINT = 991;
    function isMobileViewport() {
      return window.innerWidth <= MOBILE_BREAKPOINT;
    }

    const chatBox         = document.getElementById("chat-box");
    const nameInput       = document.getElementById("nameInput");
    const messageInput    = document.getElementById("messageInput");
    const sendButton      = document.getElementById("sendButton");
    const clearButton     = document.getElementById("clearButton"); // removed from header; now null, kept for legacy references
    const confirmModal    = document.getElementById("confirmModal");
    const cancelClear     = document.getElementById("cancelClear");
    const confirmClear    = document.getElementById("confirmClear");
    const scrollIndicator = document.getElementById("scrollIndicator");
    const secretInput     = document.getElementById("secretInput");
    const secretError     = document.getElementById("secretError");
    const darkModeToggle  = document.getElementById("darkModeToggle");
    const sidebarUsers    = document.getElementById('sidebarUsers');
    const searchInput     = document.getElementById('searchInput');
    const adminSearchInput = document.getElementById('adminSearchInput');

    const chatHeaderTitle = document.getElementById('chatHeaderTitle');
    const chatHeaderAvatar = document.getElementById('chatHeaderAvatar');
    const sidebar         = document.getElementById('sidebar');
    const backButton      = document.getElementById('backButton');
    const burgerButton    = document.getElementById('burgerButton');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');

    // The cancel-edit X button now lives inline in the message row itself
    // (next to Send), so no separate width-sync against #sendButton is
    // needed anymore — that was only for lining up the old two-row layout.
    let editingMsgId = null;
    let chatFullyLoaded = false;

    function showEditBanner(msgId) {
      editingMsgId = msgId;
      const xBtn = document.getElementById('cancelEditXBtn');
      if (xBtn) xBtn.style.display = 'flex';
    }

    function hideEditBanner() {
      editingMsgId = null;
      const xBtn = document.getElementById('cancelEditXBtn');
      if (xBtn) xBtn.style.display = 'none';
    }
    const notifyModal        = document.getElementById('notifyModal');
    const notifyTargetName   = document.getElementById('notifyTargetName');
    const notifyMessageInput = document.getElementById('notifyMessageInput');
    const notifyCharCount    = document.getElementById('notifyCharCount');
    const notifyCancel       = document.getElementById('notifyCancel');
    const notifySend         = document.getElementById('notifySend');
    const notifyToastContainer = document.getElementById('notifyToastContainer');
    const notifyContentModal   = document.getElementById('notifyContentModal');
    const notifyContentTitle   = document.getElementById('notifyContentTitle');
    const notifyContentBody    = document.getElementById('notifyContentBody');
    const notifyContentClose   = document.getElementById('notifyContentClose');
    const notificationBellBtn   = document.getElementById('notificationBellBtn');
    const notificationBellBadge = document.getElementById('notificationBellBadge');
    const notificationBellModal = document.getElementById('notificationBellModal');
    const notificationBellList  = document.getElementById('notificationBellList');
    const notificationBellClose = document.getElementById('notificationBellClose');
    const readMoreModal        = document.getElementById('readMoreModal');
    const readMoreModalBody    = document.getElementById('readMoreModalBody');
    const readMoreModalClose   = document.getElementById('readMoreModalClose');

    // Eye icon used to mark admin "spy" conversations (avoid emoji rendering
    // inconsistently across OS/browsers — use a proper inline SVG instead).
    const EYE_ICON_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';

    // Notify parent window on user interaction to unlock parent AudioContext
    const chatifyIframeUnlockEvents = ['click', 'touchstart', 'keydown', 'pointerdown'];
    function notifyParentIframeInteraction() {
      if (window.parent && window.parent !== window) {
        try {
          window.parent.postMessage({ type: 'CHATIFY_USER_INTERACTION' }, '*');
        } catch (e) {}
      }
      chatifyIframeUnlockEvents.forEach(evt => document.removeEventListener(evt, notifyParentIframeInteraction));
    }
    chatifyIframeUnlockEvents.forEach(evt => document.addEventListener(evt, notifyParentIframeInteraction, { passive: true }));

    // DM Sidebar state
    let activeDM = null;
    let activeDMAccountId = null; // Track recipient's account ID

    // WebSocket state variables
    let ws = null;
    let wsReconnectTimer = null;
    let wsPollInterval = null;
    let localIsTyping = false;
    let localTypingTimeout = null;
    let localTypingHeartbeat = null;
    let typingTimer = null;

    // Debounce timers for coalescing bursts of incoming WS 'message' events
    // (e.g. someone sending several messages in quick succession) into a
    // single load_dm.php / loadAdminConv fetch instead of one HTTP round
    // trip per message. Without this, N messages arriving within a few
    // hundred ms triggers N full loadChat(true) calls (each pulling the
    // whole recent window AND firing mark_read.php) — needless load on the
    // server for something that only needs to happen once per burst.
    let dmLoadDebounceTimer = null;
    let adminConvLoadDebounceTimer = null;
    const WS_LOAD_DEBOUNCE_MS = 350;

    function scheduleDmReload() {
      if (dmLoadDebounceTimer) return; // a reload is already queued for this burst
      dmLoadDebounceTimer = setTimeout(function() {
        dmLoadDebounceTimer = null;
        loadChat(true);
      }, WS_LOAD_DEBOUNCE_MS);
    }

    function scheduleAdminConvReload(convKey) {
      if (adminConvLoadDebounceTimer) return;
      adminConvLoadDebounceTimer = setTimeout(function() {
        adminConvLoadDebounceTimer = null;
        // Guard against the admin having switched conversations while this
        // was queued.
        if (activeAdminConv === convKey) loadAdminConv(convKey, true);
      }, WS_LOAD_DEBOUNCE_MS);
    }


    // Exponential backoff state
    let wsAttempts = 0;
    const WS_BASE_DELAY = 1000;
    const WS_MAX_DELAY = 30000;

    function connectWebSocket() {
      if (ws) {
        // Prevent onclose trigger loop when closing manually
        ws.onclose = null;
        ws.onerror = null;
        try { ws.close(); } catch(e) {}
        ws = null;
      }

      if (wsReconnectTimer) {
        clearTimeout(wsReconnectTimer);
        wsReconnectTimer = null;
      }

      const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const wsUrl = `${wsProtocol}//${window.location.host}/ws`;

      console.log('Connecting to WebSocket server:', wsUrl);
      ws = new WebSocket(wsUrl);

      ws.onopen = function() {
        console.log('WebSocket connection established.');
        wsAttempts = 0; // Reset exponential backoff counter on success
        if (wsReconnectTimer) {
          clearTimeout(wsReconnectTimer);
          wsReconnectTimer = null;
        }

        // Keep hybrid background polling running as safety net
        startPollingFallback();

        // Authenticate connection
        ws.send(JSON.stringify({
          type: 'auth',
          account_id: wsConfig.accountId,
          name: wsConfig.name,
          expires: wsConfig.expires,
          token: wsConfig.token,
          avatar_url: wsConfig.avatarUrl || null,
          comm_settings: window.currentUserCommSettings
        }));
      };
    function renderAndAppendWsMessage(msgData) {
      if (!chatBox) return;
      const msgId = msgData.msg_uuid || msgData.id;
      if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
        return; // Already rendered
      }
      if (typeof dmMessageCache !== 'undefined' && activeDM) {
        dmMessageCache.delete(activeDM);
      }

      const emptyNotice = chatBox.querySelector('.empty-chat');
      if (emptyNotice) emptyNotice.remove();

      // Captured BEFORE the new message is appended below — checking after
      // appendChild is wrong because scrollHeight has already grown by the
      // incoming message's own height at that point, so a taller message
      // (e.g. a reply, which stacks an extra quoted bubble on top of the
      // real one) can push the computed gap past isAtBottom()'s threshold
      // even though the user hadn't scrolled away at all. That made replies
      // specifically prone to silently skipping the auto-scroll and just
      // showing the "new message" indicator instead.
      const wasAtBottom = isAtBottom();

      const isSentByMe = Number(msgData.sender_id) === wsConfig.accountId;
      const container = document.createElement('div');
      container.className = 'message-container ' + (isSentByMe ? 'sent' : 'received') + ' msg-animate-' + (isSentByMe ? 'sent' : 'received');
      if (msgId) container.setAttribute('data-msg-id', msgId);
      if (msgData.sender_id) container.setAttribute('data-sender-id', String(msgData.sender_id));
      container.addEventListener('animationend', () => container.classList.remove('msg-animate-sent', 'msg-animate-received'), { once: true });

      const msgText = msgData.message || msgData.plaintext || '';
      const emojiOnlyClass = isEmojiOnly(msgText) ? ' emoji-only' : '';
      const senderUser = allUsersData.find(u => Number(u.account_id) === Number(msgData.sender_id));
      const displayName = msgData.sender_name || (senderUser ? (senderUser.full_name || senderUser.username) : (isSentByMe ? wsConfig.name : 'User'));
      const initials = getInitials(displayName);
      // Prefer the avatar embedded in the WS payload (sender_avatar), then fall back
      // to allUsersData lookup so avatars show even before the sidebar has loaded.
      const senderAvatarUrl = isSentByMe
        ? wsConfig.avatarUrl
        : (msgData.sender_avatar || (senderUser ? senderUser.avatar_url : null));

      let timeDisplay = '';
      if (msgData.created_at) {
        const d = new Date(msgData.created_at);
        const dateStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'long', day: 'numeric', year: 'numeric' });
        const timeStr = d.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
        timeDisplay = `${dateStr} at ${timeStr}`;
      } else {
        timeDisplay = getCurrentTime();
      }

      // The reply snippet normally arrives pre-computed in msgData.reply_snippet
      // (server-side WsPush push in send.php/send_dm.php, or the sender's own
      // ws.send() echo). But if that ever comes through empty/missing — a relay
      // hiccup, an older client, etc. — and the original message being replied
      // to already happens to be rendered on screen (very likely, since you can
      // only reply to a message you can see), resolve the quote text locally
      // from the DOM instead of leaving the receiver's bubble with no quote
      // until the next full reload.
      let replySnippetText = msgData.reply_snippet || '';
      if (msgData.reply_to_msg_uuid && !replySnippetText) {
        const replyTargetContainer = chatBox.querySelector(
          `.message-container[data-msg-id="${msgData.reply_to_msg_uuid}"]`
        );
        if (replyTargetContainer && typeof getReplySnippet === 'function') {
          replySnippetText = getReplySnippet(replyTargetContainer);
        }
      }

      // Build the reply-quote bubble: image thumbnail for image: snippets, plain text otherwise.
      let replyQuoteHtml = '';
      if (msgData.reply_to_msg_uuid && replySnippetText) {
        if (String(replySnippetText).startsWith('image:')) {
          const imgFile = String(replySnippetText).slice(6);
          const imgSrc  = 'uploads/' + imgFile;
          replyQuoteHtml = `<div class="reply-quote reply-quote-image-container"><img src="${escapeHtml(imgSrc)}" class="reply-quote-image" alt="" referrerpolicy="no-referrer" draggable="false" onerror="this.closest('.reply-quote-image-container,.reply-quote')?.remove()"></div>`;
        } else {
          replyQuoteHtml = `<div class="reply-quote"><div class="reply-quote-text">${escapeHtml(String(replySnippetText).slice(0, 120))}</div></div>`;
        }
      }

      container.innerHTML = `
        <div class="message-avatar">${avatarInnerHtml(senderAvatarUrl, initials)}</div>
        <div class="bubble-wrapper">
          <div class="message-click-timestamp">${escapeHtml(timeDisplay)}</div>
          ${replyQuoteHtml}
          <div class="message-bubble${emojiOnlyClass}">
            <div class="message-content">${escapeHtml(msgText)}</div>
          </div>
        </div>
      `;

      chatBox.appendChild(container);

      const contentEl = container.querySelector('.message-content');
      if (contentEl) {
        linkifyContent(contentEl);
        applyReadMoreToElement(contentEl);
      }

      // Cap the DOM at MAX_WINDOW visible messages so real-time
      // WebSocket pushes never grow the chat window without bound.
      const trimmed = trimChatMessages(MAX_WINDOW);
      if (trimmed) refreshCursorAfterTopTrim();

      applyAdminBadges();
      if (wasAtBottom || isSentByMe) {
        scrollToBottom(true, true);
      } else {
        showScrollIndicator(1);
      }
      updateSeenIndicator();
    }

    // Admin spy-mode equivalent of renderAndAppendWsMessage() above. Text
    // messages arrive over WS with plaintext already included (see
    // send_dm.php's WsPush::push(..., 'message', ['message' => plaintext...]),
    // pushed to the admin account too), so we can append them directly here
    // instead of routing through scheduleAdminConvReload() -> loadAdminConv()
    // with isAutoPoll=true. That reload path is deliberately blocked by
    // `adminConvViewingOlder` once the admin has scrolled back into older
    // history (same guard DM/Global Chat have on their own poll) — but unlike
    // DM/Global Chat, admin spy conv had no other path to show a live update,
    // so new messages silently stopped appearing entirely once the admin
    // scrolled past the initial 50. Appending directly here — bypassing the
    // guard the same way DM's live WS append already does — fixes that.
    function renderAndAppendAdminWsMessage(msgData) {
      if (!chatBox || !activeAdminConv) return;
      const msgId = msgData.msg_uuid || msgData.id;
      if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
        return; // Already rendered
      }

      const emptyNotice = chatBox.querySelector('.empty-chat');
      if (emptyNotice) emptyNotice.remove();

      // Captured BEFORE appendChild below — see the matching comment in
      // renderAndAppendWsMessage() above for why checking after append is
      // wrong (a taller incoming reply bubble skews the result).
      const wasAtBottom = isAtBottom();

      // Admin spy view always renders every message "received"-style,
      // regardless of who sent it — matches load_dm_admin.php's renderer.
      const container = document.createElement('div');
      container.className = 'message-container received msg-animate-received';
      if (msgId) container.setAttribute('data-msg-id', msgId);
      if (msgData.sender_id) container.setAttribute('data-sender-id', String(msgData.sender_id));
      container.addEventListener('animationend', () => container.classList.remove('msg-animate-received'), { once: true });

      const msgText = msgData.message || msgData.plaintext || '';
      const senderUser = allUsersData.find(u => Number(u.account_id) === Number(msgData.sender_id));
      const displayName = msgData.sender_name || (senderUser ? (senderUser.full_name || senderUser.username) : 'User');
      const initials = getInitials(displayName);
      const senderAvatarUrl = msgData.sender_avatar || (senderUser ? senderUser.avatar_url : null);
      const senderLabel = escapeHtml(displayName.toLowerCase());

      let timeDisplay = '';
      if (msgData.created_at) {
        const d = new Date(msgData.created_at);
        const dateStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'long', day: 'numeric', year: 'numeric' });
        const timeStr = d.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
        timeDisplay = `${dateStr} at ${timeStr}`;
      } else {
        timeDisplay = getCurrentTime();
      }

      let replySnippetText = msgData.reply_snippet || '';
      if (msgData.reply_to_msg_uuid && !replySnippetText) {
        const replyTargetContainer = chatBox.querySelector(
          `.message-container[data-msg-id="${msgData.reply_to_msg_uuid}"]`
        );
        if (replyTargetContainer && typeof getReplySnippet === 'function') {
          replySnippetText = getReplySnippet(replyTargetContainer);
        }
      }

      let replyQuoteHtml = '';
      if (msgData.reply_to_msg_uuid && replySnippetText) {
        if (String(replySnippetText).startsWith('image:')) {
          const imgFile = String(replySnippetText).slice(6);
          const imgSrc  = 'uploads/' + imgFile;
          replyQuoteHtml = `<div class="reply-quote reply-quote-image-container"><img src="${escapeHtml(imgSrc)}" class="reply-quote-image" alt="" referrerpolicy="no-referrer" draggable="false" onerror="this.closest('.reply-quote-image-container,.reply-quote')?.remove()"></div>`;
        } else {
          replyQuoteHtml = `<div class="reply-quote"><div class="reply-quote-text">${escapeHtml(String(replySnippetText).slice(0, 120))}</div></div>`;
        }
      }

      container.innerHTML = `
        <div class="message-avatar">${avatarInnerHtml(senderAvatarUrl, initials)}</div>
        <div class="bubble-wrapper">
          <div class="message-click-timestamp">${escapeHtml(timeDisplay)}</div>
          ${replyQuoteHtml}
          <div class="message-bubble">
            <div class="message-content">${escapeHtml(msgText)}</div>
            <div class="message-info"><span class="message-sender">${senderLabel}</span></div>
          </div>
        </div>
      `;

      chatBox.appendChild(container);

      const contentEl = container.querySelector('.message-content');
      if (contentEl) {
        linkifyContent(contentEl);
        applyReadMoreToElement(contentEl);
      }

      // Same MAX_WINDOW cap as DM/Global Chat — only trim while looking at the
      // live/latest window, never while paged back into older history.
      if (!adminConvViewingOlder) {
        const trimmed = trimChatMessages(MAX_WINDOW);
        if (trimmed) refreshCursorAfterTopTrim();
      }

      applyAdminBadges();
      if (wasAtBottom) {
        scrollToBottom(true, true);
      } else {
        showScrollIndicator(1);
      }
    }

    ws.onmessage = function(event) {
        let data;
        try {
          data = JSON.parse(event.data);
        } catch (e) {
          return;
        }

        if (data.type === 'auth_success') {
          // The socket is now actually authenticated as us, so any 'notify'
          // WS push from this point on will reach us live. But a mention
          // that landed WHILE we were offline/reconnecting only exists as
          // an unseen row in chat_notifications — nothing re-sends it over
          // WS once we're back, so pull it once here. This is the missing
          // half of "notify toasts show up" — without it, a mention sent
          // while your tab was closed/backgrounded/reconnecting never
          // shows a toast at all.
          catchUpMissedNotifications();
        } else if (data.type === 'users_dm_response') {
          processUsersDmPayload(data.data || {});
        } else if (data.type === 'messages_response') {
          if (data.chat_type === 'global' && isGlobalChat) {
            processGlobalChatData(data.data || {});
          } else if (data.chat_type === 'private' && activeDM && (
            (activeDMAccountId && Number(data.target_id) > 0 && activeDMAccountId === Number(data.target_id)) ||
            (data.target_user && data.target_user === activeDM)
          )) {
            processChatData(data.data || {}, activeDM);
          }
        } else if (data.type === 'message_edited') {
          // Another client edited a message — patch the bubble in-place immediately
          // without any server fetch so the update is instant for all viewers.
          const targetContainer = chatBox.querySelector(
            `.message-container[data-msg-id="${data.msg_uuid}"]`
          );
          if (targetContainer) {
            const contentEl = targetContainer.querySelector('.message-bubble .message-content');
            if (contentEl) {
              contentEl.textContent = data.message;
              delete contentEl.dataset.linkified;
              linkifyContent(contentEl);
              reapplyReadMore(contentEl);
            }

            const bubbleWrapper = targetContainer.querySelector('.bubble-wrapper');
            if (bubbleWrapper && !bubbleWrapper.querySelector('.message-edited-label')) {
              const label = document.createElement('div');
              label.className = 'message-edited-label';
              label.style.cssText = 'font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;';
              label.textContent = 'edited';
              bubbleWrapper.insertBefore(label, bubbleWrapper.firstChild);
            }
          }
        } else if (data.type === 'reaction_updated') {
          // Another client (or our own second tab) toggled a reaction —
          // invalidate cached snapshot so reopening any chat fetches fresh state,
          // and patch that one message's badge row in place immediately.
          if (typeof dmMessageCache !== 'undefined') {
            dmMessageCache.clear();
          }
          const fn = window.renderReactionsForMessage || (typeof renderReactionsForMessage === 'function' ? renderReactionsForMessage : null);
          if (fn && data.msg_uuid) {
            fn(data.msg_uuid, data.reactions || {});
          }
        } else if (data.type === 'message') {
          console.log('Received WebSocket real-time update notice:', data);
          // Deduplication: if message is already rendered in chatBox, skip fetching!
          const targetMsgId = data.msg_uuid || data.id;
          if (targetMsgId && chatBox.querySelector(`.message-container[data-msg-id="${targetMsgId}"]`)) {
            return;
          }
          if (data.chat_type === 'global') {
            if (Array.isArray(data.mentioned_ids) && data.mentioned_ids.map(Number).includes(Number(wsConfig.accountId))) {
              catchUpMissedNotifications();
            }
            if (isGlobalChat) {
              if (data.has_upload) {
                isLoadingGC = false;
                loadGlobalChat(false);
              } else {
                renderAndAppendWsMessage(data);
              }
            }
          } else if (data.chat_type === 'private') {
            if (typeof dmMessageCache !== 'undefined') {
              const sAccId = Number(data.sender_id);
              const rAccId = Number(data.recipient_id);
              const sUserObj = allUsersData.find(u => Number(u.account_id) === sAccId);
              const rUserObj = allUsersData.find(u => Number(u.account_id) === rAccId);
              if (sUserObj && sUserObj.username) dmMessageCache.delete(sUserObj.username);
              if (rUserObj && rUserObj.username) dmMessageCache.delete(rUserObj.username);
            }
            if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              const s = Number(data.sender_id);
              const r = Number(data.recipient_id);
              if ((s === parts[0] && r === parts[1]) || (s === parts[1] && r === parts[0])) {
                if (data.has_upload) {
                  // Need the real attachment markup from the server — force a
                  // fresh, non-auto-poll load (bypasses the adminConvViewingOlder
                  // guard entirely, same as DM's loadChatForced()) instead of the
                  // debounced scheduleAdminConvReload(), which that guard blocks
                  // once the admin has scrolled back into older history.
                  loadAdminConv(activeAdminConv, false);
                } else {
                  renderAndAppendAdminWsMessage(data);
                }
              }
            } else if (activeDM) {
              const sender = Number(data.sender_id);
              const recip  = Number(data.recipient_id);
              // Check if this message belongs to the active DM conversation
              const isForThisConv =
                (activeDMAccountId && ((sender === wsConfig.accountId && recip === activeDMAccountId) || (sender === activeDMAccountId && recip === wsConfig.accountId)));
              if (isForThisConv) {
                if (sender !== wsConfig.accountId) {
                  // Incoming message from the other person — render it via WS
                  if (data.has_upload) {
                    if (typeof dmMessageCache !== 'undefined' && activeDM) dmMessageCache.delete(activeDM);
                    loadChatForced();
                  } else {
                    renderAndAppendWsMessage(data);
                    if (!document.hidden) markRead(activeDM);
                  }
                } else {
                  // This is an echo of our own sent message (WS server broadcasts back to sender).
                  // The XHR optimistic path already rendered it — skip renderAndAppendWsMessage
                  // to avoid duplicate bubbles. Only do a forced reload if somehow the optimistic
                  // bubble is missing (e.g. attachment upload where has_upload=true).
                  if (data.has_upload) {
                    loadChatForced();
                  }
                }
              }
            }
            // Admin spy mode: keep conversations list live
            if (isAdminAllChatsView && adminSpyType === 'conversations' && adminSpyTargetUser) {
              const spyId = Number(adminSpyTargetUser.account_id);
              const s2 = Number(data.sender_id);
              const r2 = Number(data.recipient_id);
              if (s2 === spyId || r2 === spyId) {
                fetchAdminConvs('', 0, false, spyId);
              }
            }
            if (Number(data.recipient_id) === wsConfig.accountId && Number(data.sender_id) !== wsConfig.accountId) {
              if (window.parent && window.parent !== window) {
                try {
                  window.parent.postMessage({ type: 'CHATIFY_NEW_MESSAGE', sender_id: data.sender_id }, '*');
                } catch (e) {}
              }
              const otherUser = allUsersData.find(u => Number(u.account_id) === Number(data.sender_id));
              if (otherUser) {
                bumpSidebarUser(otherUser.username, { incrementUnread: true });
              } else {
                fetchUsers();
              }
            }
          }
        } else if (data.type === 'typing') {
          if (activeDM && activeDMAccountId === Number(data.sender_id) && (!data.recipient_id || Number(data.recipient_id) === wsConfig.accountId)) {
            const senderUser = allUsersData.find(u => Number(u.account_id) === Number(data.sender_id));
            const senderName = (senderUser && (senderUser.name || senderUser.full_name))
              ? (senderUser.name || senderUser.full_name)
              : (data.sender_name || `User ${data.sender_id}`);
            showTypingIndicator(senderName, data.is_typing);
          }
        } else if (data.type === 'typing_preview') {
          handleIncomingTypingPreview(data);
        } else if (data.type === 'typing_preview_cleared') {
          handleIncomingTypingPreviewCleared(data);
        } else if (data.type === 'typing_preview_sent') {
          handleIncomingTypingPreviewSent(data);
        } else if (data.type === 'presence' || data.type === 'user_online' || data.type === 'user_offline' || data.type === 'user_status') {
          handlePresenceEvent(data);
        } else if (data.type === 'presence_snapshot') {
          handlePresenceSnapshot(data);
        } else if (data.type === 'message_read') {
          // The other participant just read up through data.last_msg_uuid —
          // update the Messenger-style "Seen" indicator instantly, no poll needed.
          if (activeDM && activeDMAccountId === Number(data.reader_id)) {
            if (data.last_msg_uuid) {
              // DB-confirmed value (arrived via the HTTP-persisted path) —
              // always authoritative.
              dmReadUpTo = data.last_msg_uuid;
            } else {
              // Instant WS-only ping, sent with no id attached (see
              // markRead() above) — the other participant is actively here
              // right now, so flag whatever we've most recently sent as
              // seen using OUR OWN chatBox (accurate on this side), rather
              // than waiting for the slower DB round trip to confirm it.
              // Read state only ever moves forward, never backward.
              let newestSentId = null;
              chatBox.querySelectorAll('.message-container.sent[data-msg-id]').forEach(el => {
                const id = el.getAttribute('data-msg-id');
                if (id && (!newestSentId || id > newestSentId)) newestSentId = id;
              });
              if (newestSentId && (!dmReadUpTo || newestSentId > dmReadUpTo)) {
                dmReadUpTo = newestSentId;
              }
            }
            updateSeenIndicator();
          }
        } else if (data.type === 'chat_cleared') {
          console.log('Received WebSocket real-time update notice:', data);

          // ── Global Chat clear (super admin "/clear" while in Global Chat) ──
          // Distinct from the private/admin_conv cases below — there's no
          // partner account to resolve, and the sidebar list itself isn't
          // affected (no conversation entry disappears).
          if (data.chat_type === 'global') {
            gcCursor = '';
            gcViewingOlder = false;
            removePaginationBtn();
            // If this user happens to have an attachment open in the
            // lightbox at the exact moment Global Chat gets cleared, close
            // it too — otherwise a since-deleted image would keep sitting
            // on screen even though the message pane behind it is empty.
            if (typeof closeImageViewer === 'function') closeImageViewer();
            if (isGlobalChat) {
              if (chatBox) chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
              isFirstLoad = true;
              chatFullyLoaded = false;
              loadGlobalChat(false, false);
            }
            fetchUsers();
            return;
          }

          const _ca = Number(data.user_a);
          const _cb = Number(data.user_b);
          const _senderId    = Number(data.sender_id    || 0);
          const _recipientId = Number(data.recipient_id || 0);
          const myId = wsConfig.accountId;

          // Determine the partner account ID from whatever fields are present.
          // Primary: user_a / user_b pair. Fallback: sender_id / recipient_id.
          let _partnerId = null;
          if (_ca > 0 && _cb > 0) {
            _partnerId = (_ca === myId) ? _cb : (_cb === myId) ? _ca : null;
          }
          if (_partnerId === null && (_senderId > 0 || _recipientId > 0)) {
            _partnerId = (_senderId === myId) ? _recipientId
                       : (_recipientId === myId) ? _senderId
                       : (_senderId > 0 ? _senderId : _recipientId);
          }

          // ── 1. Immediately strip the cleared entry from the sidebar ──────────
          if (_partnerId !== null && _partnerId > 0) {
            const idx = allUsersData.findIndex(u => Number(u.account_id) === _partnerId);
            if (idx !== -1) {
              dmMessageCache.delete(allUsersData[idx].username); // drop the now-stale snapshot
              allUsersData.splice(idx, 1);
              renderSidebarUsers();
            }
          }

          // ── 2. If the user is currently viewing this conversation, wipe the
          //       chat pane immediately — don't wait for resetToHome()'s async
          //       fetchUsers() to repaint. Abort any in-flight load_dm.php XHR
          //       so stale messages can't come back over the top. ───────────────
          const _currentDMIsCleared =
            activeDM && (
              // Match by account-ID pair (most reliable)
              (_ca > 0 && _cb > 0 && activeDMAccountId &&
                ((_ca === myId && _cb === activeDMAccountId) ||
                 (_cb === myId && _ca === activeDMAccountId))) ||
              // Fallback 1: sender/recipient ID matches
              (_partnerId !== null && activeDMAccountId === _partnerId) ||
              // Fallback 2: username match via allUsersData lookup
              (_partnerId !== null && (() => {
                const partnerUser = allUsersData.find(u => Number(u.account_id) === Number(_partnerId));
                return partnerUser && partnerUser.username === activeDM;
              })())
            );

          const _currentAdminConvIsCleared =
            activeAdminConv && _ca > 0 && _cb > 0 && (() => {
              const parts = activeAdminConv.split('_').map(Number);
              return (parts[0] === _ca && parts[1] === _cb) ||
                     (parts[0] === _cb && parts[1] === _ca);
            })();

          if (_currentDMIsCleared || _currentAdminConvIsCleared) {
            // Abort any in-flight chat XHR immediately so it can't overwrite
            if (typeof chatXhr !== 'undefined' && chatXhr) {
              chatXhr.abort();
              chatXhr = null;
            }
            if (typeof adminConvXhr !== 'undefined' && adminConvXhr) {
              adminConvXhr.abort();
              adminConvXhr = null;
            }
            // Wipe the pane now — resetToHome() will confirm it, but this
            // makes the clear visually instant without waiting for XHR round-trips.
            if (chatBox) {
              chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
            }
            resetToHome();
          }

          // ── 3. Admin spy mode counts ────────────────────────────────────────
          if (isAdminAllChatsView && adminSpyType === 'conversations' && adminSpyTargetUser) {
            const spyId = Number(adminSpyTargetUser.account_id);
            let touchesSpyTarget = false;
            if (data.chat_type === 'private') {
              touchesSpyTarget = (Number(data.sender_id) === spyId || Number(data.recipient_id) === spyId);
            } else if (data.chat_type === 'admin_conv') {
              touchesSpyTarget = (Number(data.user_a) === spyId || Number(data.user_b) === spyId);
            }
            if (touchesSpyTarget) {
              fetchAdminConvs('', 0, false, spyId);
            }
          }
          fetchUsers();
        } else if (data.type === 'all_cleared') {
          console.log('Received WebSocket real-time update notice:', data);
          dmMessageCache.clear(); // every conversation's snapshot is now stale
          gcCursor = ''; dmCursor = '';
          gcViewingOlder = false; dmViewingOlder = false;
          allConvsData = [];
          localStorage.removeItem('activeSpyConv');
          localStorage.removeItem('activeDM');
          removePaginationBtn();
          const gcItem = document.getElementById('globalChatItem');
          if (gcItem) gcItem.classList.remove('active');
          if (isGlobalChat) {
            // If viewing global chat, reload it (now empty)
            loadGlobalChat(false);
          } else {
            resetToHome();
          }
          allUsersData = [];
          renderSidebarUsers();
          if (serverIsAdmin) renderAdminConvs();
          fetchUsers();
        } else if (data.type === 'notify') {
          // Pushed directly by the server the instant someone notifies/mentions
          // us. This is the only delivery path now — no HTTP fallback poll.
          console.log('Received WebSocket real-time update notice:', data);
          if (window.parent && window.parent !== window) {
            try {
              window.parent.postMessage({ type: 'CHATIFY_NEW_MESSAGE', notify_id: data.id }, '*');
            } catch (e) {}
          }
          showNotifyToast(data);
          if (data && data.id && !bellNotifications.some(function(x) { return x.id === data.id; })) {
            bellNotifications.unshift(data); // newest first
            updateBellBadge();
            renderBellList();
          }
        } else if (data.type === 'session_kicked') {
          // Pushed by the server the instant another device logs into this
          // account — no more waiting on the 5s checkSession() poll.
          console.log('Received WebSocket real-time update notice:', data);
          showSessionKickedOverlay(data.reason || 'kicked');
        } else if (data.type === 'name_updated') {
          // Pushed by the server the instant this account's name changes
          // elsewhere — no more waiting on the 5s refreshOwnName() poll.
          console.log('Received WebSocket real-time update notice:', data);
          applyOwnNameUpdate(data.name);
        } else if (data.type === 'users_changed') {
          // Pushed by the server whenever something outside the chat itself
          // affects the sidebar list — account created/deleted, visibility
          // changed, etc. Message-driven sidebar refreshes already happen
          // via the 'message'/'chat_cleared'/'all_cleared' cases above; this
          // covers everything else without needing a blind poll for it.
          console.log('Received WebSocket real-time update notice:', data);
          fetchUsers();
        } else if (data.type === 'verification_update') {
          // Broadcast from set_verification.php when Super Admin toggles a user's badge
          const changedId = Number(data.account_id);
          const nowVerified = !!data.is_verified;
          if (nowVerified) {
            verifiedAccountIds.add(changedId);
          } else {
            verifiedAccountIds.delete(changedId);
          }
          // Re-render sidebar badges
          renderSidebarUsers();
          // Re-apply header badge for current DM if it's the changed user
          if (activeDMAccountId === changedId) {
            applyHeaderAdminBadge();
          }
          // Re-apply badges on all visible message-sender elements
          applyAdminBadges();
          // If the User Verification modal is open, sync toggle rows in real-time
          if (typeof window._syncVerifyModalRow === 'function') {
            window._syncVerifyModalRow(changedId, nowVerified);
          }
        }
      };

      ws.onclose = function() {
        console.warn('WebSocket connection lost.');
        showTypingIndicator('', false);
        ws = null;
        
        // Start polling fallback immediately when connection is lost
        startPollingFallback();

        if (!wsReconnectTimer) {
          wsAttempts++;
          const delay = Math.min(WS_MAX_DELAY, WS_BASE_DELAY * Math.pow(2, wsAttempts - 1)) + Math.floor(Math.random() * 500);
          console.log(`Scheduling WebSocket reconnect in ${delay}ms (attempt ${wsAttempts})...`);
          wsReconnectTimer = setTimeout(connectWebSocket, delay);
        }
      };

      ws.onerror = function(err) {
        console.error('WebSocket connection error:', err);
      };
    }

    // Real-Time WebSocket Presence & Active Status Plumbing
    const onlineAccountsSet = new Set();
    let hasReceivedPresenceSnapshot = false;

    function formatActiveStatus(isOnline, lastOnlineTime) {
      if (isOnline) {
        return 'Active now';
      }
      if (!lastOnlineTime) {
        return 'Offline';
      }

      let ts = 0;
      if (typeof lastOnlineTime === 'number') {
        ts = lastOnlineTime;
        if (ts > 2000000000) ts = Math.floor(ts / 1000);
      } else if (typeof lastOnlineTime === 'string') {
        const str = lastOnlineTime.trim();
        if (/^\d+$/.test(str)) {
          ts = parseInt(str, 10);
          if (ts > 2000000000) ts = Math.floor(ts / 1000);
        } else {
          // SQL datetime string "YYYY-MM-DD HH:MM:SS" stored as UTC — must append Z
          // so JS doesn't interpret it as local time (which is +08:00 here, causing
          // an 8-hour offset making "just now" show as "8 hours ago").
          let iso = str.replace(' ', 'T');
          if (!iso.endsWith('Z') && !/[+\-]\d{2}:\d{2}$/.test(iso)) {
            iso += 'Z';
          }
          const d = new Date(iso);
          if (!isNaN(d.getTime())) {
            ts = Math.floor(d.getTime() / 1000);
          }
        }
      }

      if (!ts) return 'Offline';

      const now = Math.floor(Date.now() / 1000);
      const diffSec = Math.max(0, now - ts);
      const diffMin = Math.floor(diffSec / 60);
      const diffHour = Math.floor(diffMin / 60);
      const diffDay = Math.floor(diffHour / 24);

      if (diffSec < 60) {
        return `Active ${diffSec} ${diffSec === 1 ? 'second' : 'seconds'} ago`;
      } else if (diffMin < 60) {
        return `Active ${diffMin} ${diffMin === 1 ? 'minute' : 'minutes'} ago`;
      } else if (diffHour < 24) {
        return `Active ${diffHour} ${diffHour === 1 ? 'hour' : 'hours'} ago`;
      } else {
        return `Active ${diffDay} ${diffDay === 1 ? 'day' : 'days'} ago`;
      }
    }
    window.formatActiveStatus = formatActiveStatus;

    function updateHeaderActiveStatus(user) {
      const el = document.getElementById('headerActiveStatus');
      if (!el) return;

      const headerEl = document.querySelector('.header');

      if (isGlobalChat || activeAdminConv) {
        el.textContent = '';
        if (headerEl) {
          if (isGlobalChat) headerEl.classList.add('is-global-chat');
          if (activeAdminConv) headerEl.classList.add('is-single-title');
        }
        return;
      }

      if (headerEl) {
        headerEl.classList.remove('is-single-title');
        headerEl.classList.remove('is-global-chat');
      }
      if (!user && activeDMAccountId) {
        user = allUsersData.find(u => Number(u.account_id) === Number(activeDMAccountId));
      }
      if (!user) {
        el.textContent = '';
        return;
      }

      const accId = Number(user.account_id);

      // Fully WS-driven now — no fallback to the DB-fetched
      // is_currently_online/last_online_time fields. Before the WS
      // connection has delivered its first presence_snapshot we genuinely
      // don't know this user's status yet, so say so rather than guessing
      // from a value that (per the earlier bug) can be stale for anyone
      // who didn't log out cleanly.
      if (!hasReceivedPresenceSnapshot) {
        el.textContent = 'Connecting…';
        return;
      }

      const isOnline = onlineAccountsSet.has(accId);
      // last-seen time also comes only from WS data now: either a
      // presence:offline event received live this session, or the
      // last_seen map handed over in presence_snapshot for someone who
      // was already offline when we connected (see server.js).
      const lastTime = wsLastOnlineTime.get(accId) || user.lastTimestamp;
      el.textContent = formatActiveStatus(isOnline, lastTime);
    }
    window.updateHeaderActiveStatus = updateHeaderActiveStatus;

    // Periodic ticker: refresh "Active X seconds/minutes ago" every second for real-time counting
    setInterval(function() {
      if (activeDM && activeDMAccountId) {
        updateHeaderActiveStatus();
      }
    }, 1000);

    function handlePresenceEvent(data) {
      const accId = Number(data.account_id || data.sender_id || data.user_id);
      if (!accId) return;

      const isOnline = (data.status === 'online' || data.type === 'user_online');
      if (isOnline) {
        onlineAccountsSet.add(accId);
      } else {
        onlineAccountsSet.delete(accId);
      }

      const user = allUsersData.find(u => Number(u.account_id) === accId);
      if (user) {
        user.status = isOnline ? 'online' : 'offline';
        user.is_currently_online = isOnline;
        if (!isOnline && data.timestamp) {
          user.last_online_time = data.timestamp;
          user.lastTimestamp = data.timestamp;
          // Persist in map so fetchUsers() re-fetches can't overwrite it
          wsLastOnlineTime.set(accId, data.timestamp);
        }
        if (isOnline) {
          wsLastOnlineTime.delete(accId);
        }

        const item = sidebarUserItems.get(user.username);
        if (item) {
          const dot = item.querySelector('.status-dot') || item.querySelector('.user-status-dot');
          if (dot) {
            if (isOnline) {
              dot.classList.add('online');
              dot.classList.remove('offline');
            } else {
              dot.classList.remove('online');
              dot.classList.add('offline');
            }
          }
        }
      }

      if (activeDM && Number(activeDMAccountId) === accId) {
        updateHeaderActiveStatus(user);
      }
    }

    function handlePresenceSnapshot(data) {
      hasReceivedPresenceSnapshot = true;
      onlineAccountsSet.clear();
      if (Array.isArray(data.online_accounts)) {
        data.online_accounts.forEach(id => onlineAccountsSet.add(Number(id)));
      }

      // Last-seen times for accounts that were ALREADY offline before this
      // client connected — sourced entirely from the WS server's in-memory
      // record (see server.js), not from the DB. Without this, someone who
      // went offline before we connected would have no "Active X ago" data
      // at all this session.
      if (data.last_seen && typeof data.last_seen === 'object') {
        Object.keys(data.last_seen).forEach(id => {
          wsLastOnlineTime.set(Number(id), data.last_seen[id]);
        });
      }

      allUsersData.forEach(user => {
        const accId = Number(user.account_id);
        const isOnline = onlineAccountsSet.has(accId);
        user.status = isOnline ? 'online' : 'offline';
        user.is_currently_online = isOnline;
        if (!isOnline && wsLastOnlineTime.has(accId)) {
          user.last_online_time = wsLastOnlineTime.get(accId);
          user.lastTimestamp = wsLastOnlineTime.get(accId);
        }

        const item = sidebarUserItems.get(user.username);
        if (item) {
          const dot = item.querySelector('.status-dot') || item.querySelector('.user-status-dot');
          if (dot) {
            if (isOnline) {
              dot.classList.add('online');
              dot.classList.remove('offline');
            } else {
              dot.classList.remove('online');
              dot.classList.add('offline');
            }
          }
        }
      });

      if (activeDM && activeDMAccountId) {
        updateHeaderActiveStatus();
      }
    }

    function sendTypingStatus(isTyping) {
      if (ws && ws.readyState === WebSocket.OPEN && activeDM && activeDMAccountId) {
        ws.send(JSON.stringify({
          type: 'typing',
          recipient_id: activeDMAccountId,
          is_typing: isTyping
        }));
      }
    }

    // Caps a display name to a max length so it can't blow out the
    // typing indicator's layout (long names, or names with repeated
    // characters like "Officeeeeeeeeeee") — cut and add an ellipsis.
    function truncateTypingName(name, maxLen) {
      maxLen = maxLen || 22;
      if (!name) return name;
      name = String(name).trim();
      return name.length > maxLen ? name.slice(0, maxLen).trim() + '...' : name;
    }

    // Returns only the first word of a full name (e.g. "Juan Dela Cruz" -> "Juan").
    // Used so the typing indicator shows "Juan is typing" rather than the full name.
    function getFirstNameOnly(name) {
      if (!name) return name;
      return String(name).trim().split(/\s+/)[0] || String(name).trim();
    }

    function showTypingIndicator(senderName, isTyping) {
      const indicator = document.getElementById('typingIndicator');
      const textEl = document.getElementById('typingIndicatorText');

      if (typingTimer) {
        clearTimeout(typingTimer);
        typingTimer = null;
      }

      if (isTyping && activeDM) {
        textEl.textContent = 'typing';
        indicator.classList.add('active');

        // Auto-expire after 4 seconds as a safety cleanup
        typingTimer = setTimeout(() => {
          indicator.classList.remove('active');
        }, 4000);
      } else {
        indicator.classList.remove('active');
      }
    }

    function startPollingFallback() {
      if (wsPollInterval) return;
      console.log('Starting hybrid message polling...');
      wsPollInterval = setInterval(function() {
        if (document.hidden) return; // skip each tick while hidden
        if (isGlobalChat) {
          loadGlobalChat(true);
        } else if (activeDM) {
          loadChat(true);
        }
        if (activeAdminConv) {
          loadAdminConv(activeAdminConv, true);
        }
      }, 4000);
    }

    function stopPollingFallback() {
      if (wsPollInterval) {
        console.log('Stopping backup message polling.');
        clearInterval(wsPollInterval);
        wsPollInterval = null;
      }
    }



    // Mobile layout setup - defined here but called AFTER chatBox is declared
    function setupMobileLayout() {
      if (isMobileViewport()) {
        if (!activeDM && !activeAdminConv && !isGlobalChat) {
          sidebar.classList.add('open');
          const backdrop = document.getElementById('sidebarBackdrop');
          if (backdrop) backdrop.classList.add('visible');
          burgerButton.style.display = 'inline-flex';
          backButton.style.display = 'none';
        } else {
          burgerButton.style.display = 'none';
          backButton.style.display = 'inline-flex';
        }
        closeSidebarBtn.style.display = 'inline-flex';
      } else {
        burgerButton.style.display = 'none';
        backButton.style.display = 'none';
        closeSidebarBtn.style.display = 'none';
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
      }
    }
    window.addEventListener('resize', setupMobileLayout);

    burgerButton.addEventListener('click', () => {
      sidebar.classList.add('open');
      const backdrop = document.getElementById('sidebarBackdrop');
      if (backdrop) backdrop.classList.add('visible');
    });
    closeSidebarBtn.addEventListener('click', () => {
      sidebar.classList.remove('open');
      const backdrop = document.getElementById('sidebarBackdrop');
      if (backdrop) backdrop.classList.remove('visible');
    });

    // Backdrop click listener to close sidebar
    const backdropEl = document.getElementById('sidebarBackdrop');
    if (backdropEl) {
      backdropEl.addEventListener('click', () => {
        sidebar.classList.remove('open');
        backdropEl.classList.remove('visible');
      });
    }

    // Global data
    let allUsersData = [];
    let allConvsData = [];
    let serverIsAdmin = false;
    let hasAutoSelected = false;

    let userSearchHasMore = false;

    // Username to restore as a DM after the next fetchUsers() populates
    // allUsersData.  Set by the spy-mode exit handler when allUsersData was
    // empty at exit time (spy mode hides the regular sidebar so allUsersData
    // is never populated while spy mode is active).
    let pendingRestoreDM = null;

    // Stores WS-authoritative last-seen timestamps per account_id.
    // Set when presence: offline event arrives. Preserved across fetchUsers() calls
    // so the stale DB last_online_time can't overwrite the real WS value.
    const wsLastOnlineTime = new Map();

    function applyWsPresenceToAllUsers() {
      if (!hasReceivedPresenceSnapshot) return;
      allUsersData.forEach(user => {
        const accId = Number(user.account_id);
        const isOnline = onlineAccountsSet.has(accId);
        user.status = isOnline ? 'online' : 'offline';
        user.is_currently_online = isOnline;
        // Last-seen time is WS-only from here on — either a live
        // presence:offline event this session, or the last_seen map handed
        // over in presence_snapshot. If neither has an entry (e.g. this
        // account hasn't disconnected since the WS server last restarted),
        // we deliberately have no last-seen time rather than falling back
        // to the DB-fetched last_online_time, which is what used to cause
        // stale/incorrect "Active X ago" text.
        user.last_online_time = isOnline ? null : (wsLastOnlineTime.get(accId) || null);
        user.lastTimestamp = user.last_online_time;
      });
    }

    function processUsersDmPayload(data) {
      if (Array.isArray(data)) {
        allUsersData = data;
        userSearchHasMore = false;
      } else {
        allUsersData = data.users || [];
        userSearchHasMore = !!data.hasMore;
        serverIsAdmin = !!(data.currentUser && data.currentUser.is_admin);
      }

      // Immediately strip whatever presence-ish fields the server response
      // included — the active-status indicator no longer reads is_currently_online/
      // last_online_time/status from a DB fetch at all, only from the WS
      // layer (onlineAccountsSet / wsLastOnlineTime). Clearing them here
      // means there's no leftover DB value anywhere in allUsersData for a
      // pre-snapshot render to accidentally pick up.
      allUsersData.forEach(user => {
        delete user.is_currently_online;
        delete user.last_online_time;
        delete user.status;
      });

      // Re-apply WS presence state now that the fields above are gone —
      // applyWsPresenceToAllUsers() is a no-op until hasReceivedPresenceSnapshot
      // is true, at which point it's the only thing that ever sets
      // status/is_currently_online/last_online_time again.
      applyWsPresenceToAllUsers();

      if (activeDM) {
        const activeUser = (allUsersData || []).find(u => u.username === activeDM);
        if (activeUser) activeUser.unreadCount = 0;
      }
      renderSidebarUsers();

      // Note: we no longer warm dmMessageCache here. selectDM/performSelectDM
      // paints only from the real load_dm.php response now (same single-paint
      // flow as selectGlobalChat), so pre-fetching snapshots nobody reads
      // anymore would just be wasted requests.

      // Deferred restore: spy-mode exit sets this when allUsersData was empty at
      // exit time.  Now that we have a fresh user list, open the DM immediately.
      if (pendingRestoreDM) {
        const pending = pendingRestoreDM;
        pendingRestoreDM = null;
        const matchedUser = allUsersData.find(u => u.username === pending);
        if (matchedUser) {
          selectDM(matchedUser);
          return;
        }
        // User genuinely not in the list — show empty state
        chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
        return;
      }

      if (!hasAutoSelected) {
        hasAutoSelected = true;

        // Reopen whichever chat was active before the tab was
        // refreshed, instead of always dropping back to the
        // placeholder screen.
        const savedActiveDM = (!isAdminAllChatsView) ? localStorage.getItem('activeDM') : null;
        if (savedActiveDM) {
          restoreActiveConversation(savedActiveDM);
        } else {
          chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
        }
      }
    }

    function fetchUsers(query = '') {
      const currentInput = searchInput ? searchInput.value.trim() : '';
      const q = query !== '' ? query : currentInput;

      // Always use XHR for reliability — WS real-time events (message,
      // chat_cleared, all_cleared, users_changed) trigger fetchUsers() which
      // then does a fresh XHR pull.  We do NOT go via the WS internal-fetch
      // bridge here because that path depends on internalFetchPhp successfully
      // reaching the PHP server, which can fail silently.
      const xhr = new XMLHttpRequest();
      const url = q !== '' ? 'fetch_users_dm.php?q=' + encodeURIComponent(q) : 'fetch_users_dm.php';
      xhr.open('GET', url, true);
      xhr.onload = function() {
        if (this.status === 200) {
          try {
            const data = JSON.parse(this.responseText);
            processUsersDmPayload(data);
          } catch(e){ console.error('fetchUsers parse error', e); }
        }
      };
      xhr.send();
    }

    // Reopens the conversation the person had open before a refresh. This is
    // called from inside fetchUsers()'s own onload, right after allUsersData
    // has just been populated from an unfiltered (q='') request — so the
    // match is looked up directly in that in-memory list instead of firing
    // a second, duplicate fetch_users_dm.php request for the same data.
    function restoreActiveConversation(savedActiveDM) {
      if (savedActiveDM === '__global__') {
        selectGlobalChat();
        return;
      }

      const matchedUser = allUsersData.find(u => u.username === savedActiveDM) || null;
      if (matchedUser) {
        selectDM(matchedUser);
      } else {
        // The saved conversation partner no longer exists / isn't reachable
        // anymore — don't keep pointing at a chat we can't reopen.
        localStorage.removeItem('activeDM');
        chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
      }
    }

    const sidebarUserItems = new Map(); // username -> item element
    let latestTotalUnread = 0;

    // In-memory snapshot of the last-rendered page of messages per DM
    // partner, so re-opening a conversation already visited this session
    // paints instantly (no waiting on load_dm.php) instead of showing the
    // old conversation's messages until the round trip finishes. A
    // background loadChat() still runs on every open to reconcile with
    // whatever changed server-side since the snapshot was taken — this is
    // purely a "paint something correct immediately" optimization, not a
    // replacement for the real fetch. Capped and LRU-evicted (Map preserves
    // insertion order) so a long session doesn't grow this unbounded.
    const dmMessageCache = new Map(); // username -> {html, hasMore, nextCursor, readUpTo, _raw}
    const DM_CACHE_LIMIT = 30;
    const dmPrefetchInFlight = new Set(); // username set for active prefetch requests
    const dmPrefetchPromises = new Map(); // username -> Promise
    window.dmPrefetchPromises = dmPrefetchPromises;

    function cacheDmSnapshot(username, data) {
      if (!username) return;
      dmMessageCache.delete(username); // re-insert at the end = most-recently-used
      dmMessageCache.set(username, {
        html: data.html || '',
        hasMore: data.hasMore || false,
        nextCursor: data.nextCursor || '',
        readUpTo: (typeof data.readUpTo !== 'undefined') ? data.readUpTo : null,
        _raw: data
      });
      if (dmMessageCache.size > DM_CACHE_LIMIT) {
        const oldestKey = dmMessageCache.keys().next().value;
        dmMessageCache.delete(oldestKey);
      }
    }

    function speculateConversationCard(user, cardElement) {
      if (!user || !user.username) return;
      if (cardElement && cardElement.dataset.preloaded === 'true') return;
      if (dmMessageCache.has(user.username) || dmPrefetchInFlight.has(user.username) || dmPrefetchPromises.has(user.username)) {
        if (cardElement) cardElement.dataset.preloaded = 'true';
        return;
      }
      if (cardElement) cardElement.dataset.preloaded = 'true';

      prefetchDmSnapshot(user);

      // Register Speculation Rules API rule dynamically for Chrome's speculative engine
      if (typeof HTMLScriptElement !== 'undefined' && HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
        const url = 'load_dm.php?target_id=' + encodeURIComponent(user.account_id || 0) +
                    '&target_user=' + encodeURIComponent(user.username) + '&before_uuid=&limit=' + INITIAL_LOAD;
        const ruleId = 'speculation-rule-conv-' + String(user.username).replace(/[^a-zA-Z0-9_-]/g, '');
        if (!document.getElementById(ruleId)) {
          try {
            const ruleScript = document.createElement('script');
            ruleScript.id = ruleId;
            ruleScript.type = 'speculationrules';
            ruleScript.textContent = JSON.stringify({
              "prefetch": [
                {
                  "source": "list",
                  "urls": [url],
                  "eagerness": "immediate"
                }
              ]
            });
            document.head.appendChild(ruleScript);
          } catch (e) {}
        }
      }
    }

    // Fetches one conversation's latest page in the background and drops it
    // straight into dmMessageCache — no UI touched, no effect on activeDM/
    // chatBox. Used to warm the cache BEFORE the user clicks, so the click
    // itself (selectDM) can paint from cache immediately instead of only
    // benefiting revisits.
    function prefetchDmSnapshot(user) {
      if (!user || !user.username) return Promise.resolve(null);
      if (dmMessageCache.has(user.username)) return Promise.resolve(dmMessageCache.get(user.username));
      if (dmPrefetchPromises.has(user.username)) return dmPrefetchPromises.get(user.username);

      dmPrefetchInFlight.add(user.username);
      const promise = new Promise(function(resolve) {
        const xhr = new XMLHttpRequest();
        const url = 'load_dm.php?target_id=' + encodeURIComponent(user.account_id || 0) +
                    '&target_user=' + encodeURIComponent(user.username) + '&before_uuid=&limit=' + INITIAL_LOAD;
        xhr.open('GET', url, true);
        xhr.onload = function() {
          dmPrefetchInFlight.delete(user.username);
          dmPrefetchPromises.delete(user.username);
          if (this.status === 200) {
            try { cacheDmSnapshot(user.username, JSON.parse(this.responseText)); }
            catch (e) { /* ignore malformed response */ }
          }
          resolve(dmMessageCache.get(user.username) || null);
        };
        xhr.onerror = function() {
          dmPrefetchInFlight.delete(user.username);
          dmPrefetchPromises.delete(user.username);
          resolve(null);
        };
        xhr.send();
      });

      dmPrefetchPromises.set(user.username, promise);
      return promise;
    }

    // Warms the cache for the most recent conversations right after the
    // sidebar list loads, one request at a time (gentle on the server and
    // doesn't compete with the live WS connection or an in-flight send).
    // This is what makes even a FIRST click on a recent conversation feel
    // instant, not just revisits within the session.
    // Preloading is strictly demand-driven: triggered ONLY when hovering (mouseenter)
    // or touching (pointerdown) a sidebar conversation card.
    function prefetchTopConversations() {
      // Intentionally no-op: preloading happens on card hover
      return;
    }

    // Patches one sidebar row in-place (unread badge, move-to-top ordering)
    // using data we already have from a WS event or our own just-sent
    // message — no fetch_users_dm.php round trip needed.
    // Returns false if that user isn't in the currently loaded/filtered
    // list, so the caller can fall back to a real fetch in that one case.
    function bumpSidebarUser(username, opts) {
      opts = opts || {};
      const idx = allUsersData.findIndex(u => u.username === username);
      if (idx === -1) return false;

      const u = allUsersData[idx];
      if (username === activeDM) {
        u.unreadCount = 0;
      } else if (opts.incrementUnread) {
        u.unreadCount = (u.unreadCount || 0) + 1;
      }
      if (idx > 0) {
        allUsersData.splice(idx, 1);
        allUsersData.unshift(u);
      }
      renderSidebarUsers();
      return true;
    }

    function renderSidebarUsers() {
      const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

      if (activeDM || activeDMAccountId) {
        const activeUser = (allUsersData || []).find(u =>
          (activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) ||
          (activeDM && u.username === activeDM)
        );
        if (activeUser) activeUser.unreadCount = 0;
      }

      if (query === '') {
        latestTotalUnread = (allUsersData || []).reduce((sum, u) => {
          const isAct = (activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) || (activeDM && u.username === activeDM);
          return sum + (isAct ? 0 : (u.unreadCount || 0));
        }, 0);
        updateTabTitle(latestTotalUnread);

        if (!allUsersData || allUsersData.length === 0) {
          sidebarUsers.innerHTML = `<div class="sidebar-empty-state" style="padding:32px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin-bottom:8px;opacity:0.6;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <p style="margin:0;font-weight:500;">Search for a user or office.</p>
          </div>`;
          sidebarUserItems.clear();
          return;
        }
      } else {
        if (!allUsersData || allUsersData.length === 0) {
          sidebarUsers.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No users found matching "${escapeHtml(query)}".</p></div>`;
          sidebarUserItems.clear();
          return;
        }
      }

      const emptyEl = sidebarUsers.querySelector('.sidebar-empty-state');
      if (emptyEl) emptyEl.remove();

      const seen = new Set();

      allUsersData.forEach((u, index) => {
        const isThisActive = (activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) || (activeDM && u.username === activeDM);
        if (isThisActive) u.unreadCount = 0;
        const hasUnread = u.unreadCount > 0 && !isThisActive;
        seen.add(u.username);

        let item = sidebarUserItems.get(u.username);
        let avatar, dot, info, nameRow, nameEl, officeEl, actionsRight;

        if (!item) {
          item = document.createElement('div');
          item.dataset.username = u.username;

          avatar = document.createElement('div');
          avatar.className = 'user-avatar';
          dot = document.createElement('div');
          avatar.appendChild(dot);

          info = document.createElement('div');
          info.className = 'user-info';

          nameRow = document.createElement('div');
          nameRow.className = 'user-name-row';
          nameRow.style.cssText = 'display:flex;align-items:center;justify-content:flex-start;gap:4px;min-width:0;';
          nameEl = document.createElement('div');
          nameEl.className = 'user-name';
          nameRow.appendChild(nameEl);
          info.appendChild(nameRow);

          officeEl = document.createElement('div');
          officeEl.className = 'user-office';
          info.appendChild(officeEl);

          actionsRight = document.createElement('div');
          actionsRight.className = 'user-actions-right';

          item.appendChild(avatar);
          item.appendChild(info);
          item.appendChild(actionsRight);

          item.onclick = () => selectDM(u);
          item.onmouseenter = () => speculateConversationCard(u, item);
          item.onpointerdown = () => speculateConversationCard(u, item);
          item._avatar = avatar;
          item._dot = dot;
          item._info = info;
          item._nameRow = nameRow;
          item._nameEl = nameEl;
          item._officeEl = officeEl;
          item._actionsRight = actionsRight;

          sidebarUserItems.set(u.username, item);
        } else {
          avatar = item._avatar || item.querySelector('.user-avatar');
          dot = item._dot || item.querySelector('.status-dot');
          info = item._info || item.querySelector('.user-info');
          nameRow = item._nameRow || item.querySelector('.user-name-row');
          nameEl = item._nameEl || item.querySelector('.user-name');
          officeEl = item._officeEl || item.querySelector('.user-office');
          actionsRight = item._actionsRight || item.querySelector('.user-actions-right');
          item.onclick = () => selectDM(u);
          item.onmouseenter = () => speculateConversationCard(u, item);
          item.onpointerdown = () => speculateConversationCard(u, item);
        }

        const newClassName = 'user-item' + (activeDM === u.username ? ' active' : '') + (hasUnread ? ' has-unread' : '');
        if (item.className !== newClassName) item.className = newClassName;

        if (avatar.dataset.initials !== u.name || avatar.dataset.avatarUrl !== (u.avatar_url || '')) {
          const initials = getInitials(u.name);
          avatar.innerHTML = avatarInnerHtml(u.avatar_url, initials);
          avatar.appendChild(dot);
          avatar.dataset.initials = u.name;
          avatar.dataset.avatarUrl = u.avatar_url || '';
        }
        if (hasReceivedPresenceSnapshot) {
          const isWsOnline = onlineAccountsSet.has(Number(u.account_id));
          u.status = isWsOnline ? 'online' : 'offline';
          u.is_currently_online = isWsOnline;
        }
        // Fully WS-driven: before the first presence_snapshot arrives we
        // don't actually know this user's status yet, so default the dot
        // to offline rather than trusting whatever the DB-fetched payload
        // said (that DB flag can be stale for anyone who didn't log out
        // cleanly, which is what caused the old online-then-offline flash).
        const effectiveStatus = hasReceivedPresenceSnapshot ? u.status : 'offline';
        const newDotClass = 'status-dot ' + (effectiveStatus || 'offline');
        if (dot.className !== newDotClass) dot.className = newDotClass;

        if (nameEl.textContent !== u.name) nameEl.textContent = u.name;

        if (activeDM === u.username && chatHeaderTitle.textContent !== u.name) {
          chatHeaderTitle.textContent = u.name;
          applyHeaderAdminBadge();
        }
        if (activeDM === u.username) {
          applyHeaderAvatar(u);
        }

        const targetIsVerified = verifiedAccountIds && verifiedAccountIds.has(Number(u.account_id));

        // Verified badge next to verified users' names in the sidebar.
        // Injected into nameRow (a flex sibling of nameEl), NOT nameEl itself.
        // nameEl has text-overflow:ellipsis for long names — appending the
        // badge inside it let the browser's own truncation swallow the SVG
        // whenever the name nearly filled the row, showing "…" instead of
        // the checkmark. nameEl keeps flex:0 1 auto + min-width:0 so it still
        // truncates on its own, while the badge sits outside that box and
        // always stays visible.
        const sidebarBadge = nameRow.querySelector('.verified-badge');
        if (targetIsVerified) {
          if (!sidebarBadge) injectBadge(nameRow);
        } else if (sidebarBadge) {
          sidebarBadge.remove();
        }

        if (officeEl) {
          const newOffice = u.office_name ? u.office_name : 'No office assigned';
          if (officeEl.textContent !== newOffice) {
            officeEl.textContent = newOffice;
          }
          if (u.office_name) {
            officeEl.style.color = '#1b74e4';
            officeEl.style.fontStyle = 'normal';
          } else {
            officeEl.style.color = 'var(--text-secondary)';
            officeEl.style.fontStyle = 'italic';
          }
          officeEl.style.display = 'block';
        }

        if (info) {
          let lastMsgEl = info.querySelector('.user-last-msg');
          if (!lastMsgEl) {
            lastMsgEl = document.createElement('div');
            lastMsgEl.className = 'user-last-msg';
            info.appendChild(lastMsgEl);
          }

          const activeDraft = (typeof clientActivePreviews !== 'undefined') ? clientActivePreviews.get(Number(u.account_id)) : null;
          const canSeeTyping = window.currentUserCommSettings && window.currentUserCommSettings.allow_see_typing_preview;
          if (canSeeTyping && activeDraft && activeDraft.preview) {
            lastMsgEl.textContent = activeDraft.preview;
            lastMsgEl.style.display = 'block';
            lastMsgEl.style.fontStyle = 'italic';
            lastMsgEl.style.color = 'var(--primary-color, #1b74e4)';
          } else {
            lastMsgEl.textContent = '';
            lastMsgEl.style.fontStyle = '';
            lastMsgEl.style.color = '';
            lastMsgEl.style.display = 'block';
          }
        }

        // Unread badge and notify button rendering
        let badge = actionsRight.querySelector('.user-unread-badge');
        let notifyBtn = actionsRight.querySelector('.notify-btn');

        if (notifyBtn) {
          notifyBtn.remove();
          notifyBtn = null;
        }

        if (hasUnread) {
          const badgeText = u.unreadCount > 99 ? '99+' : String(u.unreadCount);
          if (!badge) {
            badge = document.createElement('span');
            badge.className = 'user-unread-badge';
            actionsRight.insertBefore(badge, actionsRight.firstChild);
            badge.textContent = badgeText;
          } else if (badge.textContent !== badgeText) {
            badge.textContent = badgeText;
          }
        } else if (badge) {
          badge.remove();
          badge = null;
        }

        // Clean up any old action buttons if present
        let oldNotifyBtn = actionsRight.querySelector('.notify-btn');
        if (oldNotifyBtn) {
          oldNotifyBtn.remove();
        }

        if (sidebarUsers.children[index] !== item) {
          sidebarUsers.insertBefore(item, sidebarUsers.children[index] || null);
        }
      });

      for (const [username, item] of sidebarUserItems) {
        if (!seen.has(username)) {
          item.remove();
          sidebarUserItems.delete(username);
        }
      }

      const existingNotice = sidebarUsers.querySelector('.search-limit-notice');
      if (existingNotice) existingNotice.remove();

      if (userSearchHasMore) {
        const notice = document.createElement('div');
        notice.className = 'search-limit-notice';
        notice.style.cssText = 'padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center;font-weight:500;border-top:1px dashed var(--border-color);margin-top:4px;';
        notice.textContent = 'Enter a more specific search term.';
        sidebarUsers.appendChild(notice);
      }
    }

    // ── Notify feature: mention + notify any user from the sidebar list ──
    let notifyTargetUser = null; // { account_id, username, name, ... } — same shape as fetch_users_dm.php's user objects

    function openNotifyModal(user) {
      // Defense in depth: never allow the admin (account_id === 1) to be
      // @mentioned/notified by a regular user, even if this gets called
      // some other way besides the sidebar's notify button.
      if (Number(user.account_id) === 1 && !serverIsAdmin) {
        console.warn('Blocked attempt to notify/mention the admin.');
        return;
      }
      notifyTargetUser = user;
      notifyTargetName.textContent = '@' + user.username;
      notifyMessageInput.value = '';
      notifyCharCount.textContent = '0/250';
      notifyCharCount.classList.remove('limit-reached');
      notifyModal.classList.add('active');
      notifyModal.setAttribute('aria-hidden', 'false');
      setTimeout(() => notifyMessageInput.focus(), 50);
    }

    function closeNotifyModal() {
      notifyModal.classList.remove('active');
      notifyModal.setAttribute('aria-hidden', 'true');
      notifyTargetUser = null;
    }

    notifyMessageInput.addEventListener('input', function() {
      const len = notifyMessageInput.value.length;
      notifyCharCount.textContent = len + '/250';
      notifyCharCount.classList.toggle('limit-reached', len >= 250);
    });

    notifyCancel.addEventListener('click', closeNotifyModal);
    notifyModal.addEventListener('click', function(e) {
      if (e.target === notifyModal) closeNotifyModal();
    });

    notifySend.addEventListener('click', function() {
      if (!notifyTargetUser) return;
      const message = notifyMessageInput.value.slice(0, 250).trim();

      notifySend.disabled = true;
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'notify.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        notifySend.disabled = false;
        // Whether it succeeds or fails, don't trap the user in the modal —
        // just close it. Errors are logged for debugging.
        if (this.status !== 200) {
          console.error('Notify failed', this.status, this.responseText);
        }
        closeNotifyModal();
      };
      xhr.onerror = function() {
        notifySend.disabled = false;
        console.error('Notify request error');
        closeNotifyModal();
      };
      xhr.send('recipient_id=' + encodeURIComponent(notifyTargetUser.account_id) + '&message=' + encodeURIComponent(message));
    });

    // Pulls any notify/mention toasts that were sent while we had no live
    // WS connection (page just loaded, tab was backgrounded, brief
    // reconnect gap, etc). fetch_notifications.php returns every is_seen = 0
    // row for us and does NOT mark them seen — that only happens once the
    // user actually opens one (toast click or bell item click) — so this is
    // safe to call on every reconnect: anything already opened never comes
    // back, and anything still unopened keeps toasting/showing in the bell
    // until it is. Called from ws.onmessage's 'auth_success' case, i.e. once
    // per successful (re)connect, not on a timer.
    // Ids we've already popped a toast for, so repeated calls to this
    // function (from the WS-independent poll below, not just the one-shot
    // WS auth_success call) don't keep re-popping the same still-unseen
    // mention every tick. Cleared implicitly by a page reload; that's fine
    // since a fresh load re-toasts anything genuinely still unseen once,
    // which is the desired "you missed this" behavior.
    const toastedMentionIds = new Set();

    function catchUpMissedNotifications() {
      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'fetch_notifications.php', true);
      xhr.onload = function() {
        if (this.status !== 200) return;
        try {
          const res = JSON.parse(this.responseText);
          const list = res.notifications || [];
          list.forEach(function(n) {
            if (toastedMentionIds.has(n.id)) return;
            toastedMentionIds.add(n.id);
            showNotifyToast(n);
          });
          // Server response is the full, authoritative set of this user's
          // currently-unseen mentions — replace the bell's cache with it
          // (newest first for display) rather than merging.
          bellNotifications = list.slice().reverse();
          updateBellBadge();
          renderBellList();
        } catch (e) { /* ignore malformed response */ }
      };
      xhr.send();
    }

    // ── WS-independent notification poll ────────────────────────────────
    // catchUpMissedNotifications() used to only run once per WS
    // (re)connect (see ws.onmessage 'auth_success' below). That's fine when
    // the WebSocket actually connects, but on some deployments (VPS behind
    // Nginx without a /ws proxy block, Apache without mod_proxy_wstunnel,
    // ws-server not kept running, firewall blocking the upgrade, etc.) the
    // socket never connects at all — and in that case 'auth_success' never
    // fires, so @mentions silently never toast/badge even though they ARE
    // saved and even though the bell shows them once manually opened.
    //
    // This interval makes mention delivery work independently of the
    // WebSocket: it's a plain HTTP poll, so it works over any web server /
    // proxy setup with zero extra configuration. When the WS *is* healthy,
    // 'notify' pushes still deliver instantly and this just acts as a
    // redundant safety net (deduped, so no double toasts).
    let notificationPollInterval = null;
    function startNotificationPoll() {
      if (notificationPollInterval) return;
      notificationPollInterval = setInterval(function() {
        if (document.hidden) return;
        catchUpMissedNotifications();
      }, 2500);
    }

    // Max characters to show in the toast preview before truncating with "..."
    const TOAST_PREVIEW_LIMIT = 80;

    // Only ONE mention toast is ever on screen at a time. If more mentions
    // arrive while it's still showing (several people mentioning at once,
    // someone spamming @you, a burst of catch-up notifications on
    // reconnect, etc.) we don't stack a new toast per mention — we just
    // update the existing one based on how many DISTINCT people are behind
    // the mentions folded into it so far:
    //   1 distinct sender (however many times they've mentioned you)
    //                       -> "Name mentioned you"
    //   2+ distinct senders -> "N others mentioned you" (N = distinct
    //                          senders, name dropped once it's more than
    //                          one person)
    // N is capped at "99+" so the UI never grows no matter how many land.
    const MENTION_TOAST_COUNT_CAP = 99;
    let activeMentionToast = null;
    let mentionToastSenders = new Set(); // distinct sender names folded into the current toast
    let mentionToastFirstSender = null;
    let mentionToastLatestData = null;
    let mentionToastDismissTimer = null;

    function mentionToastLabel() {
      if (mentionToastSenders.size <= 1) {
        return '<strong>' + escapeHtml(mentionToastFirstSender) + '</strong> mentioned you';
      }
      const count = mentionToastSenders.size;
      const countLabel = count > MENTION_TOAST_COUNT_CAP
        ? (MENTION_TOAST_COUNT_CAP + '+')
        : String(count);
      return '<strong>' + countLabel + '</strong> others mentioned you';
    }

    function dismissMentionToast() {
      if (!activeMentionToast) return;
      const toast = activeMentionToast;
      activeMentionToast = null;
      mentionToastSenders = new Set();
      mentionToastFirstSender = null;
      mentionToastLatestData = null;
      if (mentionToastDismissTimer) { clearTimeout(mentionToastDismissTimer); mentionToastDismissTimer = null; }
      toast.classList.add('hide');
      setTimeout(() => toast.remove(), 200);
    }

    function showNotifyToast(n) {
      // Toast popup disabled — notifications now only surface via the bell
      // icon (badge/list). Bell updates happen independently of this
      // function at both call sites, so returning early here doesn't lose
      // the notification, it just never pops up as a toast.
      return;

      // Always keep the most recent mention's content — that's what opens
      // when the toast (or its content modal) is clicked.
      mentionToastLatestData = n;

      if (activeMentionToast) {
        // A toast is already up — fold this one into it instead of adding
        // another toast to the stack. Only a NEW distinct sender changes
        // the label; the same person mentioning you again just refreshes
        // the timer and keeps showing their name.
        mentionToastSenders.add(n.sender);
        if (mentionToastSenders.size <= 1) {
          mentionToastFirstSender = n.sender;
        }
        activeMentionToast.innerHTML = mentionToastLabel();
        // A fresh mention just came in, so give the person a full new
        // window to notice it rather than letting the original timer cut
        // it off mid-burst.
        if (mentionToastDismissTimer) clearTimeout(mentionToastDismissTimer);
        mentionToastDismissTimer = setTimeout(dismissMentionToast, 6000);
        return;
      }

      mentionToastFirstSender = n.sender;
      mentionToastSenders = new Set([n.sender]);

      const toast = document.createElement('div');
      toast.className = 'notify-toast';
      toast.innerHTML = mentionToastLabel();
      toast.onclick = () => {
        mentionModalOpenedFromBell = false;
        showNotifyContentModal(mentionToastLatestData);
        dismissMentionToast();
      };
      notifyToastContainer.appendChild(toast);
      activeMentionToast = toast;
      mentionToastDismissTimer = setTimeout(dismissMentionToast, 6000);
    }

    const notifyContentSender = document.getElementById('notifyContentSender');

    // Tracks whether the currently-open notifyContentModal was opened from
    // the notification bell list (vs. a toast click). When true, closing
    // the mention modal should return the user to the bell modal — but only
    // if there are still unread mentions left in it; otherwise both modals
    // stay closed. Reset on every open so stale state can't leak across
    // opens.
    let mentionModalOpenedFromBell = false;

    // ── Modal shown when a notification toast is clicked ──
    // Same pattern as openReadMoreModal — textContent + linkify.
    function showNotifyContentModal(n) {
      if (!notifyContentModal) return;

      // Live WS-pushed mentions (the normal case — see ChatNotifier::
      // notifyMention()) never pass through fetch_notifications.php, so
      // is_seen is still 0 in the DB at this point. Mark it seen now that
      // the user has actually opened it. Fire-and-forget: a failed/slow
      // request here shouldn't block showing the modal.
      if (n && n.id) {
        const seenXhr = new XMLHttpRequest();
        seenXhr.open('POST', 'mark_mention_seen.php', true);
        seenXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        seenXhr.send('id=' + encodeURIComponent(n.id));

        // This is the one moment a mention actually counts as "seen" —
        // drop it from the bell's cache right away so the badge count and
        // list reflect it without waiting on a re-fetch.
        bellNotifications = bellNotifications.filter(function(x) { return x.id !== n.id; });
        updateBellBadge();
        renderBellList();
      }

      // Header: static "Mention" title + sender subtitle
      notifyContentTitle.textContent = 'Mention';
      if (notifyContentSender) {
        notifyContentSender.textContent = n.sender ? (n.sender + ' mentioned you') : '';
      }

      // Body: message text or "No messages." fallback — same as readMoreModal body
      const rawContent = (n.message || '').trim();
      notifyContentBody.textContent = rawContent || 'No messages.';
      if (rawContent) {
        delete notifyContentBody.dataset.linkified;
        linkifyContent(notifyContentBody);
      }

      notifyContentModal.classList.add('active');
      notifyContentModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeNotifyContentModal() {
      if (!notifyContentModal) return;
      notifyContentModal.classList.remove('active');
      notifyContentModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';

      // If this mention was opened from the bell list, go back to it —
      // unless that was the last unread one, in which case there's nothing
      // left to show and both modals should just stay closed.
      if (mentionModalOpenedFromBell) {
        mentionModalOpenedFromBell = false;
        if (bellNotifications.length > 0) {
          openNotificationBellModal();
        }
      }
    }

    if (notifyContentClose) {
      notifyContentClose.addEventListener('click', closeNotifyContentModal);
    }
    if (notifyContentModal) {
      notifyContentModal.addEventListener('click', function(e) {
        if (e.target === notifyContentModal) closeNotifyContentModal();
      });
    }

    // ── Notification bell: modal listing every unopened @mention ──────────
    // The toast (above) is transient — if it's missed (offline, backgrounded
    // tab, dismissed after 6s, folded into a "N others" toast that only
    // carries the latest one's data) the mention isn't lost: it just stays
    // is_seen = 0 and sits here until the user opens it from the bell.
    // bellNotifications is a local cache of {id, sender, message, time},
    // newest first, kept in sync by:
    //   - catchUpMissedNotifications()   → replaces it wholesale (server truth)
    //   - the WS 'notify' handler        → unshifts the new one in real time
    //   - showNotifyContentModal()       → removes one the instant it's opened
    //   - refreshBellNotifications()     → re-syncs from the server on open
    let bellNotifications = [];

    const MENTION_TIME_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    // fetch_notifications.php sends `time` as "YYYY-MM-DD HH24:MI:SS", already
    // converted to Asia/Manila wall-clock time server-side (to_char ... AT TIME
    // ZONE 'Asia/Manila'). Parse the components directly instead of building a
    // Date from it — going through Date/timeZone conversion here would shift
    // it again on top of the server-side shift. Renders as e.g.
    // "August 16, 2026 at 9:22 pm".
    function formatMentionTime(raw) {
      if (!raw) return '';
      const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(raw);
      if (!m) return raw;
      const year  = m[1];
      const month = MENTION_TIME_MONTHS[parseInt(m[2], 10) - 1];
      const day   = parseInt(m[3], 10);
      const min   = m[5];
      let hour    = parseInt(m[4], 10);
      const ampm  = hour >= 12 ? 'pm' : 'am';
      hour = hour % 12;
      if (hour === 0) hour = 12;
      return month + ' ' + day + ', ' + year + ' at ' + hour + ':' + min + ' ' + ampm;
    }

    function updateBellBadge() {
      if (!notificationBellBadge) return;
      const count = bellNotifications.length;
      if (count > 0) {
        notificationBellBadge.textContent = count > 100 ? '99+' : String(count);
        notificationBellBadge.style.display = 'flex';
      } else {
        notificationBellBadge.style.display = 'none';
      }
    }

    function renderBellList() {
      if (!notificationBellList) return;
      if (bellNotifications.length === 0) {
        notificationBellList.innerHTML = '<div class="bell-empty">No notifications</div>';
        return;
      }
      notificationBellList.innerHTML = bellNotifications.map(function(n) {
        const preview = (n.message || '').trim();
        const previewHtml = preview
          ? escapeHtml(preview.length > TOAST_PREVIEW_LIMIT ? preview.slice(0, TOAST_PREVIEW_LIMIT) + '…' : preview)
          : '<em>No message</em>';
        const safeId = escapeHtml(String(n.id == null ? '' : n.id));
        return '<div class="bell-item" data-id="' + safeId + '">' +
                 '<div class="bell-item-sender">' + escapeHtml(n.sender || 'A user') + ' mentioned you</div>' +
                 '<div class="bell-item-preview">' + previewHtml + '</div>' +
                 (n.time ? '<div class="bell-item-time">' + escapeHtml(formatMentionTime(n.time)) + '</div>' : '') +
               '</div>';
      }).join('');
    }

    // Re-syncs the bell's cache from the server — the source of truth for
    // "still unseen". Used on open so the list is correct even if another
    // tab/device already opened one of these mentions.
    function refreshBellNotifications() {
      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'fetch_notifications.php', true);
      xhr.onload = function() {
        if (this.status !== 200) return;
        try {
          const res = JSON.parse(this.responseText);
          bellNotifications = (res.notifications || []).slice().reverse();
          updateBellBadge();
          renderBellList();
        } catch (e) { /* ignore malformed response */ }
      };
      xhr.send();
    }

    function openNotificationBellModal() {
      if (!notificationBellModal) return;
      renderBellList(); // show the cached list immediately, no loading flash
      notificationBellModal.classList.add('active');
      notificationBellModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      refreshBellNotifications();
    }

    function closeNotificationBellModal() {
      if (!notificationBellModal) return;
      notificationBellModal.classList.remove('active');
      notificationBellModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (notificationBellBtn) {
      notificationBellBtn.addEventListener('click', openNotificationBellModal);
    }
    if (notificationBellClose) {
      notificationBellClose.addEventListener('click', closeNotificationBellModal);
    }
    if (notificationBellModal) {
      notificationBellModal.addEventListener('click', function(e) {
        if (e.target === notificationBellModal) closeNotificationBellModal();
      });
    }
    // Clicking an entry opens the same mention modal a toast click would —
    // that call marks it seen and drops it from bellNotifications for us.
    // Flagged as bell-opened so closeNotifyContentModal() knows to return
    // to the bell modal afterward (see mentionModalOpenedFromBell above).
    if (notificationBellList) {
      notificationBellList.addEventListener('click', function(e) {
        const item = e.target.closest('.bell-item');
        if (!item) return;
        const id = parseInt(item.getAttribute('data-id'), 10);
        const n = bellNotifications.find(function(x) { return x.id === id; });
        if (!n) return;
        closeNotificationBellModal();
        mentionModalOpenedFromBell = true;
        showNotifyContentModal(n);
      });
    }

    // ── Modal shown when tapping "Read more..." on a long chat message ──
    // Renders the complete message (with clickable links) — used for both
    // Global Chat and Private (DM) chat, since both feed the same bubbles.
    function openReadMoreModal(fullText) {
      if (!readMoreModal || !readMoreModalBody) return;
      readMoreModalBody.textContent = fullText || '';
      delete readMoreModalBody.dataset.linkified; // allow re-linkifying on every open
      linkifyContent(readMoreModalBody);
      readMoreModal.classList.add('active');
      readMoreModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeReadMoreModal() {
      if (!readMoreModal) return;
      readMoreModal.classList.remove('active');
      readMoreModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (readMoreModalClose) {
      readMoreModalClose.addEventListener('click', closeReadMoreModal);
    }
    if (readMoreModal) {
      readMoreModal.addEventListener('click', function(e) {
        if (e.target === readMoreModal) closeReadMoreModal();
      });
    }

    // ── "Read more" truncation for long messages ──────────────────────────
    // Applies uniformly to Global Chat and Private (DM) chat — both funnel
    // into the same .message-content elements inside #chatBox. A message
    // over the limits below gets visually truncated with a "Read more..."
    // affordance; tapping it opens the modal above with the full text.
    const READMORE_CHAR_LIMIT = 400;
    const READMORE_LINE_LIMIT = 8;

    function readMoreNeeded(text) {
      if (!text) return false;
      if (text.length > READMORE_CHAR_LIMIT) return true;
      let lines = 1;
      for (let i = 0; i < text.length; i++) {
        if (text.charCodeAt(i) === 10) {
          lines++;
          if (lines > READMORE_LINE_LIMIT) return true;
        }
      }
      return false;
    }

    function readMorePreview(text) {
      let preview = text.split('\n').slice(0, READMORE_LINE_LIMIT).join('\n');
      if (preview.length > READMORE_CHAR_LIMIT) {
        preview = preview.slice(0, READMORE_CHAR_LIMIT);
      }
      return preview.replace(/\s+$/, '');
    }

    // Truncates contentEl's text in-place and appends a "Read more..." link
    // if needed. Marks the element as checked so repeated calls (from every
    // poll/reconcile) don't redo the work. Called from applyEmojiOnly() for
    // bulk-rendered/polled messages, and directly wherever a message is
    // rendered outside that path (live WS push, optimistic send bubble).
    function applyReadMoreToElement(contentEl) {
      if (!contentEl || contentEl.dataset.readmoreChecked === '1') return;
      if (contentEl.classList.contains('sending-dots')) return; // "..." placeholder, not real text

      const fullText = contentEl.textContent || '';
      contentEl.dataset.readmoreChecked = '1';
      if (!readMoreNeeded(fullText)) return;

      contentEl.dataset.fullText = fullText;
      contentEl.textContent = readMorePreview(fullText) + ' ';

      const link = document.createElement('span');
      link.className = 'read-more-link';
      link.textContent = 'Read More...';
      link.setAttribute('role', 'button');
      link.setAttribute('tabindex', '0');
      link.addEventListener('click', function(e) {
        e.stopPropagation();
        openReadMoreModal(contentEl.dataset.fullText || '');
      });
      link.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          e.stopPropagation();
          openReadMoreModal(contentEl.dataset.fullText || '');
        }
      });
      contentEl.appendChild(link);

      // Re-linkify just the (now truncated) visible text so any URL still
      // shown in the preview stays clickable.
      delete contentEl.dataset.linkified;
      linkifyContent(contentEl);
    }

    // Re-checks a message-content element after its text changed (e.g. an
    // edit) so a message that grows past the limit gets "Read more..." too,
    // and one that shrinks below it loses a stale truncation.
    function reapplyReadMore(contentEl) {
      delete contentEl.dataset.readmoreChecked;
      applyReadMoreToElement(contentEl);
    }

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = String(str == null ? '' : str);
      return div.innerHTML;
    }

    // Renders either an <img> (Google/account avatar_url) or plain initials
    // text as the inner content of a .user-avatar / .message-avatar circle.
    function avatarInnerHtml(avatarUrl, initials) {
      if (avatarUrl) {
        return `<img src="${escapeHtml(avatarUrl)}" class="avatar-img" alt="" loading="eager" referrerpolicy="no-referrer">`;
      }
      return escapeHtml(initials);
    }

    // Tab title notification system
    let originalTitle = document.title;
    let titleFlashInterval = null;
    let currentUnreadCount = 0; // live count the flash interval reads, so it never goes stale

    function updateTabTitle(totalUnread) {
      currentUnreadCount = totalUnread;

      if (totalUnread > 0) {
        document.title = '(' + totalUnread + ') ' + originalTitle;
        // Flash title if tab is hidden
        if (document.hidden && !titleFlashInterval) {
          let toggled = false;
          titleFlashInterval = setInterval(() => {
            // Read currentUnreadCount live instead of the totalUnread closure
            // argument, so the count stays accurate as new messages arrive
            // while the tab remains hidden.
            document.title = toggled ? '(' + currentUnreadCount + ') ' + originalTitle : 'New message!';
            toggled = !toggled;
          }, 1200);
        }
      } else {
        document.title = originalTitle;
        if (titleFlashInterval) { clearInterval(titleFlashInterval); titleFlashInterval = null; }
      }
    }

    // Stop flashing when user returns to tab
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && titleFlashInterval) {
        clearInterval(titleFlashInterval);
        titleFlashInterval = null;
      }
    });

    let markReadHttpDebounceTimer = null;

    function markRead(targetUsername) {
      if (!targetUsername) return;
      const u = allUsersData.find(x => x.username === targetUsername || (activeDMAccountId && Number(x.account_id) === activeDMAccountId));
      if (u) u.unreadCount = 0;
      if (activeDM === targetUsername || (activeDMAccountId && u && Number(u.account_id) === activeDMAccountId)) {
        const activeU = allUsersData.find(x => x.username === activeDM || (activeDMAccountId && Number(x.account_id) === activeDMAccountId));
        if (activeU) activeU.unreadCount = 0;
      }
      renderSidebarUsers();

      const targetId = activeDMAccountId || (u ? Number(u.account_id) : 0);
      if (!targetId) return;

      // Resolve newest message ID currently present in chatBox
      let newestMsgId = null;
      if (chatBox) {
        chatBox.querySelectorAll('.message-container[data-msg-id]').forEach(el => {
          const id = el.getAttribute('data-msg-id');
          if (id && (!newestMsgId || id > newestMsgId)) newestMsgId = id;
        });
      }

      // Real-time WebSocket relay: ALWAYS fire immediately with last_msg_uuid
      if (ws && ws.readyState === WebSocket.OPEN && targetId) {
        ws.send(JSON.stringify({
          type: 'mark_read',
          target_id: targetId,
          last_msg_uuid: newestMsgId || null
        }));
      }

      // Durable path: persist to Postgres via HTTP (debounced to avoid HTTP floods)
      if (markReadHttpDebounceTimer) clearTimeout(markReadHttpDebounceTimer);
      markReadHttpDebounceTimer = setTimeout(() => {
        markReadHttpDebounceTimer = null;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'mark_read.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.send('target_id=' + encodeURIComponent(targetId) + '&target_user=' + encodeURIComponent(targetUsername));
      }, 150);
    }

    // Messenger-style "Seen" indicator: shown under the newest message WE sent
    // that the other participant has actually read (dmReadUpTo). Re-run this
    // any time dmReadUpTo changes or the message list is re-rendered — it's
    // cheap (one DOM query over the currently-loaded page) and always fully
    // recomputes rather than trying to patch the previous position, so it can
    // never end up stuck under a stale message.
    function updateSeenIndicator() {
      const existing = chatBox.querySelector('.seen-indicator');
      if (existing) existing.remove();

      if (!dmReadUpTo || !activeDM || isGlobalChat) return;

      // msg_uuid values are fixed-width hex ("msg_" + 10 hex ms + 6 hex rnd),
      // so plain string comparison sorts them the same as chronological order.
      const sentMessages = chatBox.querySelectorAll('.message-container.sent[data-msg-id]');
      let target = null;
      sentMessages.forEach(el => {
        const id = el.getAttribute('data-msg-id');
        if (id && id <= dmReadUpTo) target = el; // keep the newest qualifying one
      });
      if (!target) return;

      const indicator = document.createElement('div');
      indicator.className = 'seen-indicator';
      indicator.innerHTML = '<span class="seen-indicator-text">seen</span>';
      target.insertAdjacentElement('afterend', indicator);
    }


    // State for global chat
    let isGlobalChat = false;
    // ── Infinite-scroll window constants ─────────────────────────────────────
    // INITIAL_LOAD  — messages fetched when first opening a conversation.
    // BACKREAD_BATCH — messages fetched per auto-triggered scroll-up fetch.
    // MAX_WINDOW    — maximum messages kept in the DOM at once. When the user
    //                 keeps scrolling up, older pages are prepended and the
    //                 same count is trimmed from the bottom so the DOM never
    //                 grows past this cap.  "Go to bottom" always snaps back
    //                 to a fresh INITIAL_LOAD-sized window.
    const INITIAL_LOAD   = 100;
    const BACKREAD_BATCH = 50;
    const MAX_WINDOW     = 300;  // ~100 initial + 4 backreads; safe for mid-range Android
    // Legacy alias — kept so every existing trimWindowFromTop/Bottom call site
    // that still references PAGE_SIZE continues to compile without changes.
    // New code should prefer the explicit constants above.
    const PAGE_SIZE = BACKREAD_BATCH;
    let gcCursor = '';
    let gcHasMore = false;
    let gcViewingOlder = false; // true once the user has loaded an older window
    let dmCursor  = '';
    let dmHasMore = false;
    let dmViewingOlder = false; // true once the user has loaded an older window
    // msg_uuid of the newest message the OTHER participant has read, or null.
    // Drives the Messenger-style "Seen" indicator under our own last-read sent message.
    let dmReadUpTo = null;

    // Spamming clicks across the conversation list used to make the chat
    // pane visibly jump: every single click synchronously swapped chatBox's
    // whole innerHTML (from the cache) and snapped/restored scroll position,
    // so a burst of clicks played back as a rapid series of scroll jumps
    // before finally landing on the last-clicked conversation. Instead, the
    // heavy part (painting messages + loadChat + scroll) is debounced to
    // only run once the clicks settle, while the row highlight updates
    // instantly on every click and the chat pane just gives a quick "blink"
    // so spamming still feels responsive without the jumping.
    let selectDMDebounceTimer = null;
    let pendingSelectUser = null;
    const SELECT_DM_DEBOUNCE_MS = 180;

    function selectDM(u) {
      pendingSelectUser = u;

      // Instant, lightweight feedback: highlight the clicked row right away,
      // without touching messages/scroll yet.
      isGlobalChat = false;
      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.remove('is-global-chat');
      activeDM = u.username;
      activeDMAccountId = Number(u.account_id);
      activeAdminConv = null;
      u.unreadCount = 0;
      if (!allUsersData.find(function(x) { return Number(x.account_id) === Number(u.account_id); })) {
        allUsersData.unshift(u);
      }
      chatHeaderTitle.textContent = u.name;
      applyHeaderAdminBadge();
      applyHeaderAvatar(u);
      renderSidebarUsers();
      document.getElementById('globalChatItem').classList.remove('active');
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();

      if (selectDMDebounceTimer) clearTimeout(selectDMDebounceTimer);
      selectDMDebounceTimer = setTimeout(function() {
        selectDMDebounceTimer = null;
        performSelectDM(pendingSelectUser);
      }, SELECT_DM_DEBOUNCE_MS);
    }

    function performSelectDM(u) {
      isGlobalChat = false;
      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.remove('is-global-chat');
      activeDM = u.username;
      activeDMAccountId = Number(u.account_id);
      activeAdminConv = null;
      u.unreadCount = 0;

      // Ensure the selected user is in allUsersData so avatar lookups work
      // immediately on new chats where this person hasn't been loaded yet.
      if (!allUsersData.find(function(x) { return Number(x.account_id) === Number(u.account_id); })) {
        allUsersData.unshift(u);
      }
      updateClearChatButtonVisibility();
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      
      // Reset local typing indicator state
      if (localTypingTimeout) {
        clearTimeout(localTypingTimeout);
        localTypingTimeout = null;
      }
      if (localTypingHeartbeat) {
        clearInterval(localTypingHeartbeat);
        localTypingHeartbeat = null;
      }
      if (localIsTyping) {
        localIsTyping = false;
        sendTypingStatus(false);
      }
      showTypingIndicator('', false);

      localStorage.setItem('activeDM', u.username);
      chatHeaderTitle.textContent = u.name;
      applyHeaderAdminBadge();
      applyHeaderAvatar(u);
      updateHeaderActiveStatus(u);
      // Mirror selectGlobalChat's render flow exactly: blank the pane and do
      // a single, deterministic paint once the real data comes back. The old
      // approach (instant-paint from a cached snapshot, then reconcile again
      // against whatever the network returned) meant two separate scroll
      // adjustments could land back-to-back — that's what made the chat pane
      // visibly jump when switching conversations quickly.
      chatBox.innerHTML = '';
      removePaginationBtn();
      hideScrollIndicator();
      const _htp = document.getElementById('headerTypingPreview');
      if (_htp) { _htp.textContent = ''; _htp.classList.remove('active'); }

      dmCursor = '';
      dmHasMore = false;
      dmReadUpTo = null;
      isFirstLoad = true; // snap straight to bottom once the new conversation's messages arrive
      chatFullyLoaded = false; // suppress scroll buttons until new chat finishes loading
      dmViewingOlder = false;
      markRead(u.username);
      renderSidebarUsers();
      loadChat(false, false, true); // force: abort any in-flight request rather than drop this one
      // Global Chat item deactivate
      document.getElementById('globalChatItem').classList.remove('active');
      
      // Mobile/Tablet: hide sidebar when chat is selected
      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
        burgerButton.style.display = 'none';
        backButton.style.display = 'inline-flex';
      } else {
        // Desktop only: auto-focus the message input so the cursor is ready
        // without an extra click. Skipped on mobile so opening a conversation
        // doesn't immediately pop the on-screen keyboard over half the chat.
        setTimeout(() => messageInput.focus(), 0);
      }
    }

    function selectGlobalChat() {
      isGlobalChat = true;
      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.add('is-global-chat');
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      gcCursor = '';
      gcHasMore = false;
      gcViewingOlder = false;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      updateHeaderActiveStatus();
      isFirstLoad = true;
      chatFullyLoaded = false; // suppress scroll buttons until global chat finishes loading
      hideScrollIndicator();
      const _htpGc = document.getElementById('headerTypingPreview');
      if (_htpGc) { _htpGc.textContent = ''; _htpGc.classList.remove('active'); }
      // Reset local typing indicator state
      if (localTypingTimeout) {
        clearTimeout(localTypingTimeout);
        localTypingTimeout = null;
      }
      if (localTypingHeartbeat) {
        clearInterval(localTypingHeartbeat);
        localTypingHeartbeat = null;
      }
      if (localIsTyping) {
        localIsTyping = false;
        sendTypingStatus(false);
      }
      showTypingIndicator('', false);

      localStorage.setItem('activeDM', '__global__');
      chatHeaderTitle.innerHTML = `Global Chat`;
      applyHeaderAdminBadge(); // activeDMAccountId is null here — clears any leftover badge from the previous DM
      applyHeaderAvatar(null); // no single DM partner — hide the header avatar
      chatBox.innerHTML = '';

      removePaginationBtn();
      renderSidebarUsers();
      document.getElementById('globalChatItem').classList.add('active');
      loadGlobalChat();
      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
        burgerButton.style.display = 'none';
        backButton.style.display = 'inline-flex';
      } else {
        // Desktop only: auto-focus the message input so the cursor is ready
        // without an extra click. Skipped on mobile so opening a conversation
        // doesn't immediately pop the on-screen keyboard over half the chat.
        setTimeout(() => messageInput.focus(), 0);
      }
    }

    function removePaginationBtn() {
      const existing = document.getElementById('loadOlderBtn');
      if (existing) existing.remove();
      const notice = document.getElementById('noMoreOlderNotice');
      if (notice) notice.remove();
    }

    function showNoMoreOlderNotice() {
      removePaginationBtn();
      const notice = document.createElement('div');
      notice.id = 'noMoreOlderNotice';
      notice.style.cssText = `
        display:block;text-align:center;width:calc(100% - 32px);margin:10px 16px;
        padding:8px 16px;color:var(--text-secondary);font-size:12.5px;font-weight:500;
      `;
      notice.textContent = 'No older messages';
      chatBox.insertBefore(notice, chatBox.firstChild);
    }

    // Compares a freshly-fetched "latest window" of messages (newMessages) against
    // everything currently rendered on screen (currentMessages, which may include
    // older messages the user pulled in via "Load Older Messages"). Rather than
    // blindly replacing the whole chat box on every poll — which used to wipe out
    // any older messages the user had already loaded — this finds the longest
    // overlap between the tail of what's on screen and the head of what's fresh,
    // and only appends the genuinely new trailing messages.
    function reconcilePoll(newMessages, currentMessages, newKeys, curKeys) {
      if (newKeys.join('~~') === curKeys.join('~~')) {
        return { type: 'nochange' };
      }
      const maxL = Math.min(curKeys.length, newKeys.length);
      for (let L = maxL; L > 0; L--) {
        const curTail = curKeys.slice(curKeys.length - L).join('~~');
        const newHead = newKeys.slice(0, L).join('~~');
        if (curTail === newHead) {
          return { type: 'append', overlap: L, items: newMessages.slice(L) };
        }
      }
      return { type: 'replace' };
    }

    // Helper: synchronize reactions of visible messages with a freshly-fetched HTML payload
    function syncReactionsFromNewHtml(newMessages) {
      if (!chatBox || !newMessages) return;
      newMessages.forEach(function(newEl) {
        if (!newEl || typeof newEl.getAttribute !== 'function') return;
        const msgId = newEl.getAttribute('data-msg-id');
        if (!msgId) return;

        const curEl = chatBox.querySelector('.message-container[data-msg-id="' + msgId + '"]');
        if (!curEl) return;

        const newReactions = newEl.querySelector('.msg-reactions');
        const curReactions = curEl.querySelector('.msg-reactions');
        const curBubbleWrapper = curEl.querySelector('.bubble-wrapper');

        if (newReactions) {
          if (curReactions) {
            if (curReactions.innerHTML !== newReactions.innerHTML) {
              curReactions.innerHTML = newReactions.innerHTML;
              curReactions.className = newReactions.className;
            }
          } else {
            const cloned = newReactions.cloneNode(true);
            (curBubbleWrapper || curEl).appendChild(cloned);
          }
          if (curBubbleWrapper) curBubbleWrapper.classList.add('has-reactions');
          curEl.classList.add('has-reactions');
        } else {
          if (curReactions) {
            curReactions.remove();
          }
          if (curBubbleWrapper) curBubbleWrapper.classList.remove('has-reactions');
          curEl.classList.remove('has-reactions');
        }
      });
    }

    // Historical no-op kept so the many call sites that mark "older messages
    // are available for this chat" (gcHasMore/dmHasMore/adminConvHasMore are
    // what actually drive the auto-load-on-scroll-to-top behavior now — see
    // maybeAutoLoadOlderMessages() in app-part3.js) don't need to be touched
    // one by one. There's no more floating/inline "Load Older" button to
    // show; the next older page is fetched automatically as the user
    // backreads to the top instead.
    function insertLoadOlderBtn() {}

    // Backread top loader — shown for the duration of an auto-triggered
    // older-history fetch so scrolling to the top gives visual feedback
    // instead of the next batch just silently appearing once it lands.
    // Absolutely positioned (see CSS) so it never becomes part of
    // #chat-box's flow/scrollHeight, keeping every scroll-preserving
    // calculation around the older-message insert untouched.
    function showBackreadTopLoader() {
      if (!chatBox || document.getElementById('backreadTopLoader')) return;
      const el = document.createElement('div');
      el.id = 'backreadTopLoader';
      el.className = 'backread-top-loader';
      el.innerHTML = '<span class="backread-spinner"></span>';
      chatBox.insertBefore(el, chatBox.firstChild);
    }

    function hideBackreadTopLoader() {
      const el = document.getElementById('backreadTopLoader');
      if (el && el.parentNode) el.parentNode.removeChild(el);
    }

    // After trimming oldest messages from the top of the DOM, update the
    // pagination cursor to the UUID of the new oldest visible message so
    // that "Load Older" correctly fetches the trimmed messages on the next
    // request. Also marks hasMore so the button stays/becomes visible.
    function refreshCursorAfterTopTrim() {
      const oldest = chatBox.querySelector('.message-container[data-msg-id]');
      if (!oldest) return;
      const uuid = oldest.getAttribute('data-msg-id');
      if (!uuid) return;
      if (isGlobalChat) {
        gcCursor  = uuid;
        gcHasMore = true;
        if (!document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) {
          insertLoadOlderBtn();
        }
      } else if (activeAdminConv) {
        adminConvCursor  = uuid;
        adminConvHasMore = true;
        if (!document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) {
          insertLoadOlderBtn();
        }
      } else if (activeDM) {
        dmCursor  = uuid;
        dmHasMore = true;
        if (!document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) {
          insertLoadOlderBtn();
        }
      }
    }

    function unobserveImagesIn(el) {
      if (typeof scrollAnchorObserver === 'undefined' || !scrollAnchorObserver || !el || !el.querySelectorAll) return;
      el.querySelectorAll('img').forEach(img => scrollAnchorObserver.unobserve(img));
    }

    // ── Messenger-Style Scroll Anchor Lock ────────────────────────────────────
    // Captures the current topmost visible message node and its relative offset
    // inside #chat-box before inserting older history.
    function captureScrollAnchor() {
      if (!chatBox) return null;
      const boxRect = chatBox.getBoundingClientRect();
      const messages = Array.from(chatBox.querySelectorAll('.message-container'));
      if (messages.length === 0) return null;

      let anchorEl = null;
      for (let i = 0; i < messages.length; i++) {
        const rect = messages[i].getBoundingClientRect();
        if (rect.bottom >= boxRect.top + 5) {
          anchorEl = messages[i];
          break;
        }
      }
      if (!anchorEl) anchorEl = messages[0];

      const anchorTop = anchorEl.getBoundingClientRect().top - boxRect.top;
      return { el: anchorEl, offsetTop: anchorTop };
    }

    // Restores scroll position so anchorEl remains at the exact pixel offset,
    // and attaches image load listeners to prepended media to compensate for
    // layout shifts as images load.
    function restoreScrollAnchor(anchorInfo, prependedItems) {
      if (!chatBox || !anchorInfo || !anchorInfo.el || !anchorInfo.el.parentNode) return;

      const boxRect = chatBox.getBoundingClientRect();
      const currentTop = anchorInfo.el.getBoundingClientRect().top - boxRect.top;
      const shift = currentTop - anchorInfo.offsetTop;
      if (Math.abs(shift) > 0.5) {
        chatBox.scrollTop += shift;
      }

      if (prependedItems && prependedItems.length > 0) {
        prependedItems.forEach(item => {
          if (!item.querySelectorAll) return;
          item.querySelectorAll('img').forEach(img => {
            if (!img.complete && !img.dataset.anchorBound) {
              img.dataset.anchorBound = '1';
              img.addEventListener('load', function() {
                if (anchorInfo.el && anchorInfo.el.parentNode) {
                  const curBoxRect = chatBox.getBoundingClientRect();
                  const nowTop = anchorInfo.el.getBoundingClientRect().top - curBoxRect.top;
                  const imgShift = nowTop - anchorInfo.offsetTop;
                  if (Math.abs(imgShift) > 0.5) {
                    chatBox.scrollTop += imgShift;
                  }
                }
              }, { once: true });
            }
          });
        });
      }
    }

    // Keeps the chat window capped at maxCount messages by trimming the
    // trailing (newest/bottom) ones — used right after prepending an older
    // page so loading history swaps the window instead of growing it forever.
    function trimWindowFromBottom(maxCount = MAX_WINDOW) {
      if (!chatBox) return false;
      const items = Array.from(chatBox.querySelectorAll('.message-container'));
      if (items.length <= maxCount) return false;

      const excess = items.length - maxCount;
      for (let i = 0; i < excess; i++) {
        const el = items[items.length - 1 - i];
        if (el && el.parentNode) {
          unobserveImagesIn(el);
          const prev = el.previousElementSibling;
          if (prev && prev.classList && prev.classList.contains('date-divider')) {
            prev.remove();
          }
          el.parentNode.removeChild(el);
        }
      }
      return true;
    }

    // Keeps the chat window capped at maxCount messages by trimming the
    // leading (oldest/top) ones — used during normal poll / initial load
    // so the message list doesn't grow forever.
    function trimWindowFromTop(maxCount = MAX_WINDOW) {
      return trimChatMessages(maxCount);
    }

    // Reusable real-time trim helper: caps the number of visible
    // `.message-container` nodes in chatBox at `maxMessages`, always
    // dropping the OLDEST ones from the top first so the newest message
    // (the one that was just appended) stays visible.
    function trimChatMessages(maxMessages = MAX_WINDOW) {
      if (!chatBox) return false;
      const items = Array.from(chatBox.querySelectorAll('.message-container'));
      if (items.length <= maxMessages) return false;

      const excess = items.length - maxMessages;
      const prevScrollTop = chatBox.scrollTop;
      const prevScrollHeight = chatBox.scrollHeight;

      for (let i = 0; i < excess; i++) {
        const el = items[i];
        if (el && el.parentNode) {
          unobserveImagesIn(el);
          const prev = el.previousElementSibling;
          if (prev && prev.classList && prev.classList.contains('date-divider')) {
            const next = el.nextElementSibling;
            if (!next || (next.classList && next.classList.contains('date-divider'))) {
              prev.remove();
            }
          }
          el.parentNode.removeChild(el);
        }
      }

      const scrollDelta = prevScrollHeight - chatBox.scrollHeight;
      if (scrollDelta !== 0 && chatBox.scrollTop > 0) {
        chatBox.scrollTop = Math.max(0, prevScrollTop - scrollDelta);
      }
      return true;
    }

    // ── Admin: render all conversations spy panel (Search-First Architecture) ──
    const adminConvItems = new Map(); // convId/userId -> item element
    let adminSpyType = 'none';        // 'none', 'users', or 'conversations'
    let adminSpyTargetUser = null;    // null or selected user object { account_id, full_name, email, ... }
    let adminSpyUsers = [];           // array of user search result objects
    let adminSpyConvs = [];           // array of conversation objects for target user
    let adminSpyHasMore = false;
    let adminSpyOffset = 0;
    let adminSpyIsLoading = false;
    let adminSearchTimeout = null;

    function getInitialsFromFullName(name) {
      if (!name) return '??';
      const parts = name.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    // =========================================================================
    // @Mention (Global Chat compose box)
    // =========================================================================
    // Typing "@" (start of message, or right after whitespace) opens the
    // Mention modal — same look/behavior as the User Verification modal:
    // search happens inside the modal's own input, and mention_search.php
    // intentionally returns AT MOST ONE user, so exactly one result row is
    // ever rendered — never a full list.
    //
    // Picking that row swaps the "@" the user typed for "@Full Name " in
    // the compose box and remembers it in activeMentions, so the highlight
    // layer (#messageInputHighlight, sitting right behind the now fully
    // transparent #messageInput — see .message-input-wrap in style.css)
    // can render it live as a blue .mention-tag pill.
    //
    // On send (app-part3.js), every mention still present in the final
    // text is sent to send.php as mentioned_ids, which persists each one
    // to chat_message_mentions and notifies the mentioned user — see
    // GlobalChatManager::addTextMessage()/recordMentions() and
    // core/ChatNotifier.php.
    const messageInputHighlight = document.getElementById('messageInputHighlight');
    const mentionModal          = document.getElementById('mentionModal');
    const mentionSearchInput    = document.getElementById('mentionSearchInput');
    const mentionSearchResults  = document.getElementById('mentionSearchResults');

    // { account_id, name } for every mention currently in the box. Re-synced
    // on every keystroke (recomputeActiveMentions) — deleting/editing away a
    // mention's "@Name" text drops it from here too, so it won't be notified.
    let activeMentions = [];
    // Char offset of the lone "@" that opened the modal, so picking a user
    // knows exactly what to replace. Null while the modal is closed.
    let mentionTriggerPos = null;
    let mentionSearchTimer = null;
    let mentionSearchSeq = 0; // guards a slow response from overwriting a newer one

    // Rebuilds the highlight layer from the textarea's current text, wrapping
    // every still-present "@Name" (longest name first, so e.g. "Juan" can't
    // shadow-match inside "Juan Dela Cruz") in the same .mention-tag blue
    // pill used to render mentions in already-sent messages.
    function renderMentionHighlight() {
      const text = messageInput.value;
      messageInput.style.color = 'transparent';

      const names = (activeMentions || [])
        .map(function(m) { return m.name; })
        .filter(function(n, i, arr) { return arr.indexOf(n) === i; })
        .sort(function(a, b) { return b.length - a.length; });

      if (names.length === 0 || text.indexOf('@') === -1) {
        messageInputHighlight.textContent = text.endsWith('\n') ? text + ' ' : text;
        messageInputHighlight.scrollTop = messageInput.scrollTop;
        messageInputHighlight.scrollLeft = messageInput.scrollLeft;
        return;
      }

      let html = '';
      let plainRun = '';
      let i = 0;
      while (i < text.length) {
        let matchedToken = null;
        if (text[i] === '@') {
          for (const name of names) {
            const token = '@' + name;
            if (text.startsWith(token, i)) { matchedToken = token; break; }
          }
        }
        if (matchedToken) {
          if (plainRun) { html += escapeHtml(plainRun); plainRun = ''; }
          html += '<span class="mention-tag">' + escapeHtml(matchedToken) + '</span>';
          i += matchedToken.length;
        } else {
          plainRun += text[i];
          i++;
        }
      }
      if (plainRun) html += escapeHtml(plainRun);
      if (text.endsWith('\n')) html += '&nbsp;';
      messageInputHighlight.innerHTML = html;
      messageInputHighlight.scrollTop = messageInput.scrollTop;
      messageInputHighlight.scrollLeft = messageInput.scrollLeft;
    }

    // Fully resets the compose box back to its empty/placeholder state.
    // Clearing messageInput.value alone isn't enough: renderMentionHighlight()
    // makes the real textarea text transparent and paints the visible text
    // (including blue @mention pills) onto #messageInputHighlight sitting
    // behind it. If a message contained a mention and got cleared (sent,
    // edit cancelled, /clear, /backup, etc.) without also clearing that
    // overlay, its stale "@Name" pill stays rendered on screen, floating on
    // top of the now-empty textarea's placeholder ("Type a message...") —
    // the two visibly overlap. Every place that resets the compose box to
    // empty should call this instead of setting messageInput.value directly.
    function resetMessageInputVisualState() {
      messageInput.value = '';
      messageInput.style.color = '';
      activeMentions = [];
      if (messageInputHighlight) messageInputHighlight.innerHTML = '';

      const headerPreview = document.getElementById('headerTypingPreview');
      if (headerPreview) {
        headerPreview.textContent = '';
        headerPreview.classList.remove('active');
      }
      if (typeof sendTypingPreview === 'function') {
        sendTypingPreview();
      }
    }
    window.resetMessageInputVisualState = resetMessageInputVisualState;

    function recomputeActiveMentions() {
      const text = messageInput.value;
      activeMentions = activeMentions.filter(function(m) {
        return text.indexOf('@' + m.name) !== -1;
      });
    }

    function syncMentionHighlightScroll() {
      messageInputHighlight.scrollTop = messageInput.scrollTop;
    }
    messageInput.addEventListener('scroll', syncMentionHighlightScroll);

    window.closeMentionModal = function() {
      if (!mentionModal) return;
      mentionModal.classList.remove('active');
      mentionModal.setAttribute('aria-hidden', 'true');
      mentionTriggerPos = null;
      if (mentionSearchTimer) { clearTimeout(mentionSearchTimer); mentionSearchTimer = null; }
      messageInput.focus();
    };

    function renderMentionResultState(state, usersData) {
      // state: 'searching' | 'empty' | 'result'
      // Single-suggestion UI: only ever render the single best match, even
      // if the caller hands us more than one candidate. The person keeps
      // typing to narrow down to the right person instead of picking from
      // a list.
      const users = Array.isArray(usersData) ? usersData.slice(0, 1) : (usersData ? [usersData] : []);
      if (state === 'result' && users.length > 0) {
        const user = users[0];
        const initials = getInitialsFromFullName(user.name);
        const html =
          '<div class="mention-user-row" id="mentionUserRow" data-user-idx="0">' +
            '<div class="mention-user-avatar">' + avatarInnerHtml(user.avatar_url, initials) + '</div>' +
            '<div class="mention-user-info">' +
              '<div class="mention-user-name">' + escapeHtml(user.name) + '</div>' +
              '<div class="mention-user-sub">' + escapeHtml(user.office || user.username || '') + '</div>' +
            '</div>' +
          '</div>';
        mentionSearchResults.innerHTML = html;
        const row = document.getElementById('mentionUserRow');
        if (row) {
          row.addEventListener('click', function() {
            selectMentionUser(user);
          });
        }
      } else if (state === 'searching') {
        mentionSearchResults.innerHTML = '<div style="font-size:13px;color:var(--text-secondary);padding:8px 0;">Searching…</div>';
      } else {
        mentionSearchResults.innerHTML = '<div style="font-size:13px;color:var(--text-secondary);padding:8px 0;">No users found.</div>';
      }
    }

    function runMentionSearch(query) {
      if (query === '') {
        mentionSearchResults.innerHTML = '';
        return;
      }
      renderMentionResultState('searching');
      const seq = ++mentionSearchSeq;
      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'mention_search.php?q=' + encodeURIComponent(query), true);
      xhr.onload = function() {
        if (seq !== mentionSearchSeq) return; // stale — a newer query has since been typed
        if (this.status !== 200) { renderMentionResultState('empty'); return; }
        try {
          const res = JSON.parse(this.responseText);
          // Only ever surface the single best match — see renderMentionResultState.
          const best = (res && res.user) ? res.user : ((res && res.users && res.users[0]) ? res.users[0] : null);
          renderMentionResultState(best ? 'result' : 'empty', best ? [best] : []);
        } catch (e) {
          renderMentionResultState('empty');
        }
      };
      xhr.onerror = function() {
        if (seq === mentionSearchSeq) renderMentionResultState('empty');
      };
      xhr.send();
    }

    if (mentionSearchInput) {
      mentionSearchInput.addEventListener('input', function() {
        clearTimeout(mentionSearchTimer);
        const q = this.value.trim();
        if (q === '') { mentionSearchResults.innerHTML = ''; return; }
        mentionSearchTimer = setTimeout(function() { runMentionSearch(q); }, 300);
      });
      // Enter picks the single result row currently shown, same as clicking it.
      mentionSearchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          const row = document.getElementById('mentionUserRow');
          if (row) row.click();
        }
      });
    }

    // Backdrop click closes the modal (leaves the typed "@" as-is, same as
    // dismissing the User Verification modal doesn't undo anything).
    if (mentionModal) {
      mentionModal.addEventListener('click', function(e) {
        if (e.target === this) closeMentionModal();
      });
    }

    // Swaps the lone "@" that opened the modal for "@Full Name " and
    // remembers it as an active mention.
    function selectMentionUser(user) {
      if (!mentionTriggerPos) { closeMentionModal(); return; }
      const text = messageInput.value;
      const before = text.slice(0, mentionTriggerPos.start);
      const after  = text.slice(mentionTriggerPos.end);
      const insertion = '@' + user.name + ' ';
      messageInput.value = before + insertion + after;

      const caretPos = before.length + insertion.length;

      if (!activeMentions.some(function(m) { return m.account_id === user.account_id && m.name === user.name; })) {
        activeMentions.push({ account_id: user.account_id, name: user.name });
      }

      closeMentionModal(); // also refocuses messageInput
      messageInput.setSelectionRange(caretPos, caretPos);
      renderMentionHighlight();
      if (typeof autoResizeMessageInput === 'function') autoResizeMessageInput();
    }

    // Fires the moment "@" is typed as the very last character, and only
    // when it's at the start of the box or right after whitespace (so
    // emails / mid-word "@" never trigger it) — opens the Mention modal
    // exactly once per "@", same trigger UX as any other slash/at command.
    messageInput.addEventListener('input', function() {
      recomputeActiveMentions();
      renderMentionHighlight();

      if (!isGlobalChat) return; // mentions are a Global Chat feature only
      const caret = this.selectionStart;
      const justTypedAt = this.value[caret - 1] === '@';
      const charBeforeAt = caret >= 2 ? this.value[caret - 2] : undefined;
      if (justTypedAt && (charBeforeAt === undefined || /\s/.test(charBeforeAt))) {
        mentionTriggerPos = { start: caret - 1, end: caret };
        mentionModal.classList.add('active');
        mentionModal.setAttribute('aria-hidden', 'false');
        mentionSearchResults.innerHTML = '';
        if (mentionSearchInput) {
          mentionSearchInput.value = '';
          setTimeout(function() { mentionSearchInput.focus(); }, 80);
        }
      }
    });

    messageInput.addEventListener('scroll', syncMentionHighlightScroll);

    function fetchAdminConvs(query = '', offset = 0, isAppend = false, targetId = 0) {
      // Use isAdmin (available synchronously from PHP on page load) as a fallback,
      // since serverIsAdmin is only confirmed later via an async AJAX response —
      // without this fallback, a call made during the initial page load (e.g. when
      // restoring a persisted spy-mode view on refresh) would bail out too early
      // and never render the spy-mode search panel.
      if (!serverIsAdmin && !isAdmin) return;

      const trimmedQuery = query.trim();
      const currentTargetId = targetId || (adminSpyTargetUser ? adminSpyTargetUser.account_id : 0);

      // If no query and no target user selected: empty state, zero network calls
      if (trimmedQuery === '' && currentTargetId === 0) {
        adminSpyType = 'none';
        adminSpyUsers = [];
        adminSpyConvs = [];
        adminSpyHasMore = false;
        adminSpyOffset = 0;
        adminSpyTargetUser = null;
        renderAdminConvs();
        return;
      }

      adminSpyIsLoading = true;
      const xhr = new XMLHttpRequest();
      let url = "fetch_users_dm.php?spy_mode=1";

      if (currentTargetId > 0) {
        url += "&admin_target_id=" + currentTargetId;
      } else {
        url += "&admin_q=" + encodeURIComponent(trimmedQuery);
      }

      xhr.open("GET", url, true);
      xhr.onload = function() {
        adminSpyIsLoading = false;
        if (this.status === 200) {
          try {
            const data = JSON.parse(this.responseText);
            const adminData = data.adminConvs || {};
            adminSpyType = adminData.type || 'none';
            adminSpyHasMore = !!adminData.hasMore;

            if (adminSpyType === 'users') {
              adminSpyUsers = adminData.users || [];
            } else if (adminSpyType === 'conversations') {
              if (adminData.targetUser) {
                adminSpyTargetUser = adminData.targetUser;
              }
              adminSpyConvs = adminData.conversations || [];
            }

            renderAdminConvs();
          } catch(e) { console.error('fetchAdminConvs parse error', e); }
        }
      };
      xhr.onerror = function() { adminSpyIsLoading = false; };
      xhr.send();
    }

    function selectAdminSpyTargetUser(user) {
      adminSpyTargetUser = user;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      chatHeaderTitle.textContent = '';
      applyHeaderAdminBadge();
      applyHeaderAvatar(null);
      updateHeaderActiveStatus();
      adminSpyConvs = [];
      fetchAdminConvs('', 0, false, user.account_id);
    }

    function clearAdminSpyTargetUser() {
      adminSpyTargetUser = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      chatHeaderTitle.textContent = '';
      applyHeaderAdminBadge();
      applyHeaderAvatar(null);
      updateHeaderActiveStatus();
      adminSpyConvs = [];
      const query = adminSearchInput ? adminSearchInput.value.trim() : '';
      if (query !== '') {
        fetchAdminConvs(query, 0, false, 0);
      } else {
        adminSpyType = 'none';
        adminSpyUsers = [];
        renderAdminConvs();
      }
    }

    function renderAdminConvs() {
      const section = document.getElementById('adminConvsSection');
      const list    = document.getElementById('adminConvsList');
      const headerTitle = document.getElementById('adminConvsHeaderTitle');
      if (!section || !list) return;

      if (!isAdminAllChatsView) {
        section.style.display = 'none';
        list.innerHTML = '';
        adminConvItems.clear();
        return;
      }

      section.style.display = 'flex';

      // Update Section Header Title
      if (headerTitle) {
        if (adminSpyTargetUser) {
          headerTitle.innerHTML = `<div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:6px;">
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Conversations for: ${escapeHtml(adminSpyTargetUser.full_name)}</span>
            <button onclick="clearAdminSpyTargetUser()" style="background:none;border:none;color:#1b74e4;cursor:pointer;font-size:11px;font-weight:600;padding:2px 6px;border-radius:4px;white-space:nowrap;">← Back</button>
          </div>`;
        } else {
          headerTitle.textContent = '';
        }
      }

      // State A: Empty initial state (No search query, no target user)
      if (adminSpyType === 'none') {
        list.innerHTML = `<div class="sidebar-empty-state" style="padding:32px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin-bottom:8px;opacity:0.6;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <p style="margin:0;font-weight:500;">Search for a user or office to spy on.</p>
        </div>`;
        adminConvItems.clear();
        return;
      }

      // State B: Render User Search Results (Max 10)
      if (adminSpyType === 'users') {
        if (!adminSpyUsers || adminSpyUsers.length === 0) {
          const q = adminSearchInput ? adminSearchInput.value.trim() : '';
          list.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No users found matching "${escapeHtml(q)}".</p></div>`;
          adminConvItems.clear();
          return;
        }

        const emptyEl = list.querySelector('.sidebar-empty-state, .empty-chat');
        if (emptyEl) emptyEl.remove();

        const seen = new Set();
        adminSpyUsers.forEach(u => {
          const key = 'user_' + u.account_id;
          seen.add(key);
          let item = adminConvItems.get(key);
          let nameEl, msgEl;

          if (!item) {
            item = document.createElement('div');
            item.className = 'user-item';

            const avatar = document.createElement('div');
            avatar.className = 'user-avatar';
            if (!u.avatar_url) {
              avatar.style.background = 'linear-gradient(135deg, #1b74e4, #00c3ff)';
            }
            avatar.innerHTML = avatarInnerHtml(u.avatar_url, getInitialsFromFullName(u.full_name));

            const info = document.createElement('div');
            info.className = 'user-info';

            nameEl = document.createElement('div');
            nameEl.className = 'user-name';
            nameEl.style.fontSize = '13px';
            info.appendChild(nameEl);

            msgEl = document.createElement('div');
            msgEl.className = 'user-last-msg';
            info.appendChild(msgEl);

            item.appendChild(avatar);
            item.appendChild(info);

            item._nameEl = nameEl;
            item._msgEl = msgEl;

            adminConvItems.set(key, item);
          } else {
            nameEl = item._nameEl || item.querySelector('.user-name');
            msgEl = item._msgEl || item.querySelector('.user-last-msg');
          }

          item.onclick = () => selectAdminSpyTargetUser(u);
          item.onmouseenter = () => speculateConversationCard(u, item);
          item.onpointerdown = () => speculateConversationCard(u, item);

          if (nameEl.textContent !== u.full_name) nameEl.textContent = u.full_name;

          let subText = u.email || '';
          if (u.office_code) subText += ' • ' + u.office_code;
          else if (u.office_name) subText += ' • ' + u.office_name;

          if (msgEl.textContent !== subText) msgEl.textContent = subText;

          list.appendChild(item);
        });

        for (const [key, item] of adminConvItems) {
          if (!seen.has(key)) {
            item.remove();
            adminConvItems.delete(key);
          }
        }

        const existingNotice = list.querySelector('.search-limit-notice');
        if (existingNotice) existingNotice.remove();

        if (adminSpyHasMore) {
          const notice = document.createElement('div');
          notice.className = 'search-limit-notice';
          notice.style.cssText = 'padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center;font-weight:500;border-top:1px dashed var(--border-color);margin-top:4px;';
          notice.textContent = 'Showing the first 10 matches. Enter a more specific search term.';
          list.appendChild(notice);
        }
        return;
      }

      // State C: Render Selected User's Conversations (Max 50 latest)
      if (adminSpyType === 'conversations') {
        if (!adminSpyConvs || adminSpyConvs.length === 0) {
          const name = adminSpyTargetUser ? adminSpyTargetUser.full_name : 'selected user';
          list.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No active conversations found for ${escapeHtml(name)}.</p></div>`;
          adminConvItems.clear();
          return;
        }

        const emptyEl = list.querySelector('.sidebar-empty-state, .empty-chat');
        if (emptyEl) emptyEl.remove();

        const seen = new Set();
        adminSpyConvs.forEach(c => {
          const key = 'conv_' + c.convId;
          seen.add(key);
          let item = adminConvItems.get(key);
          let nameEl, msgEl;

          if (!item) {
            item = document.createElement('div');

            const avatar = document.createElement('div');
            avatar.className = 'user-avatar';
            avatar.innerHTML = EYE_ICON_SVG;

            const info = document.createElement('div');
            info.className = 'user-info';

            nameEl = document.createElement('div');
            nameEl.className = 'user-name';
            nameEl.style.fontSize = '13px';
            info.appendChild(nameEl);

            msgEl = document.createElement('div');
            msgEl.className = 'user-last-msg';
            info.appendChild(msgEl);

            item.appendChild(avatar);
            item.appendChild(info);

            item._nameEl = nameEl;
            item._msgEl = msgEl;

            adminConvItems.set(key, item);
          } else {
            nameEl = item._nameEl || item.querySelector('.user-name');
            msgEl = item._msgEl || item.querySelector('.user-last-msg');
          }

          item.onclick = () => openAdminConv(c);

          const newClassName = 'user-item' + (activeAdminConv === c.convId ? ' active' : '');
          if (item.className !== newClassName) item.className = newClassName;

          const nameDisplay = c.name1 + ' ↔ ' + c.name2;
          if (nameEl.textContent !== nameDisplay) nameEl.textContent = nameDisplay;

          const count = c.msgCount || 1;
          const newMsg = (count > 99)
            ? '99+ messages'
            : (count + ' msg' + (count !== 1 ? 's' : '') + (c.lastMessage ? ' · ' + c.lastMessage : ''));
          if (msgEl.textContent !== newMsg) msgEl.textContent = newMsg;

          list.appendChild(item);
        });

        for (const [key, item] of adminConvItems) {
          if (!seen.has(key)) {
            item.remove();
            adminConvItems.delete(key);
          }
        }

        const existingNotice = list.querySelector('.search-limit-notice');
        if (existingNotice) existingNotice.remove();

        if (adminSpyHasMore) {
          const notice = document.createElement('div');
          notice.className = 'search-limit-notice';
          notice.style.cssText = 'padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center;font-weight:500;border-top:1px dashed var(--border-color);margin-top:4px;';
          notice.textContent = 'Showing the first 50 matches. Enter a more specific search term.';
          list.appendChild(notice);
        }
      }
    }

    if (adminSearchInput) {
      adminSearchInput.addEventListener('input', () => {
        if (adminSearchTimeout) clearTimeout(adminSearchTimeout);
        adminSpyTargetUser = null; // Reset user selection when typing new search
        const query = adminSearchInput.value.trim();

        adminSearchTimeout = setTimeout(() => {
          fetchAdminConvs(query, 0, false, 0);
        }, 250);
      });
    }



    let activeAdminConv = null; // convId string when admin is spying

    // ── Contextual "Clear Chat" header button (Super Admin Spy Mode only) ──────
    // Visible only while isAdmin is true AND a specific spied conversation is
    // loaded (activeAdminConv set). Hidden for the all-conversations list view,
    // when no conversation is selected, and for non-admins. Call this any time
    // activeAdminConv changes so the button stays in sync without a refresh.
    function updateClearChatButtonVisibility() {
      const btn = document.getElementById('clearChatHeaderBtn');
      if (!btn) return;
      btn.style.display = (typeof isAdmin !== 'undefined' && isAdmin && !!activeAdminConv) ? 'inline-flex' : 'none';
    }
    let adminConvCursor = '';
    let adminConvHasMore = false;
    let adminConvViewingOlder = false; // true once the user has loaded an older window
    let isLoadingAdminConv = false;   // separate flag so admin spy never blocks DM loads
    let adminConvXhr = null;          // track in-flight XHR so stale responses can be discarded

    function loadAdminConv(convId, isAutoPoll = false, loadOlderMode = false) {
      if (isAutoPoll && !loadOlderMode && adminConvViewingOlder) return;
      if (isLoadingAdminConv) {
        // For non-poll (explicit open) calls, abort any in-flight request and proceed
        if (!isAutoPoll && adminConvXhr) { adminConvXhr.abort(); adminConvXhr = null; isLoadingAdminConv = false; }
        else return;
      }
      isLoadingAdminConv = true;

      const wasAtBottom = isAtBottom();
      const requestedConv = activeAdminConv;
      const cursor     = loadOlderMode ? adminConvCursor : '';
      const limitParam = loadOlderMode ? BACKREAD_BATCH : INITIAL_LOAD;
      const url = 'load_dm_admin.php?conv_id=' + encodeURIComponent(convId)
                + '&before_uuid=' + encodeURIComponent(cursor)
                + '&limit=' + limitParam;

      const xhr = new XMLHttpRequest();
      adminConvXhr = xhr;
      xhr.open('GET', url, true);
      xhr.onload = function() {
        isLoadingAdminConv = false;
        if (adminConvXhr === xhr) adminConvXhr = null;
        if (loadOlderMode) hideBackreadTopLoader();
        if (this.status !== 200) return;
        if (requestedConv !== activeAdminConv) return; // stale response
        
        let data;
        try {
          data = JSON.parse(this.responseText);
        } catch(e) {
          return;
        }
        
        const newHtml = data.html || '';
        adminConvHasMore = data.hasMore || false;
        
        if (loadOlderMode) {
          shouldAutoScroll = false;
          userScrolledUp = true;
          adminConvCursor = data.nextCursor || '';
          adminConvViewingOlder = true;

          const anchor = captureScrollAnchor();

          const temp = document.createElement('div');
          temp.innerHTML = newHtml;
          const oldItems = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
          const btn = document.getElementById('loadOlderBtn');
          const firstChild = chatBox.firstChild;
          oldItems.reverse().forEach(el => {
            if (el.classList.contains('message-container')) {
              el.classList.add('msg-animate-older');
              el.addEventListener('animationend', () => el.classList.remove('msg-animate-older'), { once: true });
            }
            if (btn) chatBox.insertBefore(el, btn.nextSibling);
            else chatBox.insertBefore(el, firstChild);
          });

          trimWindowFromBottom(MAX_WINDOW);
          restoreScrollAnchor(anchor, oldItems);

          if (!adminConvHasMore) showNoMoreOlderNotice(); else if (!document.getElementById('loadOlderBtn')) insertLoadOlderBtn();
          applyAdminBadges();
          applyEmojiOnly();
          attachImageLoadListeners();

          if (adminConvHasMore && chatBox.scrollTop <= AUTO_LOAD_OLDER_THRESHOLD_PX) {
            requestAnimationFrame(function() {
              if (typeof maybeAutoLoadOlderMessages === 'function') {
                maybeAutoLoadOlderMessages();
              }
            });
          }
          return;
        }

        if (!newHtml.trim()) {
          chatBox.innerHTML = '';
          isFirstLoad = false;
          return;
        }

        if (adminConvCursor === '') adminConvCursor = data.nextCursor || ''; // establish cursor pointer
        const temp = document.createElement('div');
        temp.innerHTML = newHtml;
        const newMessages = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
        const currentMessages = Array.from(chatBox.querySelectorAll('.message-container:not([data-sending-uid]):not([data-upload-uid]), .empty-chat'));
        const newKeys = newMessages.map(getMessageKey);
        const curKeys = currentMessages.map(getMessageKey);

        const rec = reconcilePoll(newMessages, currentMessages, newKeys, curKeys);
        syncReactionsFromNewHtml(newMessages);

        if (rec.type === 'nochange') {
          if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
          if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
          return;
        }

        if (rec.type === 'append') {
          rec.items.forEach(el => {
            if (el.classList.contains('message-container')) {
              const msgId = el.getAttribute('data-msg-id');
              if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
                return;
              }
              const animClass = el.classList.contains('sent') ? 'msg-animate-sent' : 'msg-animate-received';
              el.classList.add(animClass);
              el.addEventListener('animationend', () => el.classList.remove(animClass), { once: true });
            }
            chatBox.appendChild(el);
          });
          const prevScrollTop = chatBox.scrollTop;
          const prevScrollHeight = chatBox.scrollHeight;
          const newScrollHeight = chatBox.scrollHeight;
          chatBox.scrollTop = Math.max(0, prevScrollTop + newScrollHeight - prevScrollHeight);
          if (!adminConvViewingOlder) {
            if (trimWindowFromTop(MAX_WINDOW)) refreshCursorAfterTopTrim();
          }
          if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
          else if (!adminConvViewingOlder && wasAtBottom) requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
          else showScrollIndicator(rec.items.filter(el => el.classList.contains('message-container')).length);
          applyAdminBadges();
          applyEmojiOnly();
          attachImageLoadListeners();
          if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
          return;
        }

        // Full re-render
        const prevSTF = chatBox.scrollTop; const prevSHF = chatBox.scrollHeight;
        const curKeySetF = new Set(curKeys);
        const genuinelyNewCountF = newMessages.filter(el =>
          el.classList.contains('message-container') && !curKeySetF.has(getMessageKey(el))
        ).length;
        currentMessages.forEach(el => el.remove());

        // Deduplicate newMessages during full re-render
        const renderedIdsF = new Set();
        newMessages.forEach(el => {
          if (el.classList.contains('message-container')) {
            const msgId = el.getAttribute('data-msg-id');
            if (msgId) {
              if (renderedIdsF.has(msgId)) return;
              renderedIdsF.add(msgId);
            }
          }
          chatBox.appendChild(el);
        });

        chatBox.scrollTop = Math.max(0, prevSTF + chatBox.scrollHeight - prevSHF);
        const mc = chatBox.querySelectorAll('.message-container').length;
        if (mc > 0 && !adminConvViewingOlder && (wasAtBottom || isFirstLoad)) {
          const doInstant = isFirstLoad;
          isFirstLoad = false;
          if (doInstant) handleFirstLoadScroll();
          else requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
        } else {
          isFirstLoad = false;
          if (genuinelyNewCountF > 0) showScrollIndicator(genuinelyNewCountF);
        }
        applyAdminBadges();
        applyEmojiOnly();
        attachImageLoadListeners();
        adminConvCursor = data.nextCursor || '';
        adminConvViewingOlder = false;
        if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
      };
      xhr.onerror = function() { isLoadingAdminConv = false; adminConvXhr = null; if (loadOlderMode) hideBackreadTopLoader(); };
      xhr.send();
    }

    function openAdminConv(c) {
      activeAdminConv = c.convId;
      activeDM = null;
      activeDMAccountId = null;
      updateClearChatButtonVisibility();
      isGlobalChat = false; // must reset — otherwise polling/visibilitychange keep re-loading Global Chat over the spy view
      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.remove('is-global-chat');
      
      adminConvCursor = '';
      adminConvHasMore = false;
      adminConvViewingOlder = false;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      isFirstLoad = true;

      // Reset local typing indicator state
      if (localTypingTimeout) {
        clearTimeout(localTypingTimeout);
        localTypingTimeout = null;
      }
      if (localTypingHeartbeat) {
        clearInterval(localTypingHeartbeat);
        localTypingHeartbeat = null;
      }
      if (localIsTyping) {
        localIsTyping = false;
        sendTypingStatus(false);
      }
      showTypingIndicator('', false);

      // Persist spied conversation separately so activeDM is never lost
      localStorage.setItem('activeSpyConv', c.convId);

      chatHeaderTitle.textContent = c.name1 + ' & ' + c.name2;
      updateHeaderActiveStatus();
      applyHeaderAdminBadge(); // activeDMAccountId is null for spied conversations — clears any leftover badge
      applyHeaderAvatar(null); // two participants, no single avatar to show
      chatBox.innerHTML = '<div class="empty-chat"><p>Loading...</p></div>';
      
      removePaginationBtn();
      renderAdminConvs();
      loadAdminConv(c.convId, false);

      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
        burgerButton.style.display = 'none';
        backButton.style.display = 'inline-flex';
      }
    }
    
    let searchTimeout = null;
    searchInput.addEventListener('input', () => {
      if (searchTimeout) clearTimeout(searchTimeout);
      const query = searchInput.value.trim();

      if (query === '') {
        // Immediately clear stale search results so sidebar shows loading state
        allUsersData = [];
        renderSidebarUsers();
        fetchUsers();
      } else {
        searchTimeout = setTimeout(() => {
          fetchUsers(query);
        }, 250);
      }
    });

    function resetToHome() {
      if (activeDM) {
        const currentActive = activeDM;
        const currentActiveId = activeDMAccountId;
        const u = allUsersData.find(x => x.username === currentActive || (currentActiveId && Number(x.account_id) === currentActiveId));
        if (u) u.unreadCount = 0;

        if (currentActiveId) {
          if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'mark_read', target_id: currentActiveId }));
          }
          const xhr = new XMLHttpRequest();
          xhr.open('POST', 'mark_read.php', true);
          xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
          xhr.send('target_id=' + encodeURIComponent(currentActiveId) + '&target_user=' + encodeURIComponent(currentActive));
        }
      }

      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      isGlobalChat = false;
      const _hEl = document.querySelector('.header');
      if (_hEl) {
        _hEl.classList.remove('is-global-chat');
        _hEl.classList.remove('is-single-title');
      }
      
      updateClearChatButtonVisibility();
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      
      // Reset local typing indicator state
      if (localTypingTimeout) {
        clearTimeout(localTypingTimeout);
        localTypingTimeout = null;
      }
      if (localTypingHeartbeat) {
        clearInterval(localTypingHeartbeat);
        localTypingHeartbeat = null;
      }
      if (localIsTyping) {
        localIsTyping = false;
        sendTypingStatus(false);
      }
      showTypingIndicator('', false);

      localStorage.removeItem('activeDM');
      localStorage.removeItem('activeSpyConv');
      removePaginationBtn();

      // Reset header to default home state
      if (chatHeaderTitle) {
        chatHeaderTitle.textContent = '';
      }
      const _headerActiveStatus = document.getElementById('headerActiveStatus');
      if (_headerActiveStatus) _headerActiveStatus.textContent = '';
      if (typeof applyHeaderAvatar === 'function') {
        applyHeaderAvatar(null);
      }
      if (typeof applyHeaderAdminBadge === 'function') {
        applyHeaderAdminBadge();
      }

      // Mobile/Tablet sidebar adjustments
      if (isMobileViewport()) {
        if (sidebar) sidebar.classList.add('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.add('visible');
        if (backButton) backButton.style.display = 'none';
        if (burgerButton) burgerButton.style.display = 'inline-flex';
      }

      if (chatBox) {
        chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
      }
      const gcItem = document.getElementById('globalChatItem');
      if (gcItem) gcItem.classList.remove('active');
      
      renderSidebarUsers();
      if (serverIsAdmin) renderAdminConvs();
      fetchUsers();
      if ((typeof serverIsAdmin !== 'undefined' ? serverIsAdmin : isAdmin) && typeof adminSpyTargetUser !== 'undefined' && adminSpyTargetUser) {
        fetchAdminConvs('', 0, false, adminSpyTargetUser.account_id);
      }
    }

    backButton.addEventListener('click', resetToHome);

    // Helper: get or create the floating sending overlay container
    function getSendingOverlay() {
      let overlay = document.getElementById('sending-overlay-container');
      if (!overlay) {
        const inputArea = document.querySelector('.input-area');
        if (!inputArea) return null;
        overlay = document.createElement('div');
        overlay.id = 'sending-overlay-container';
        inputArea.appendChild(overlay);
      }
      return overlay;
    }

    // Clear all pending send bubbles when switching conversations so they
    // don't bleed into the freshly-opened chat pane.
    function clearSendingOverlay() {
      const overlay = document.getElementById('sending-overlay-container');
      if (overlay) overlay.innerHTML = '';
      // Also sweep any stragglers that landed directly in chatBox
      document.querySelectorAll('[data-sending-uid]').forEach(el => el.remove());
    }