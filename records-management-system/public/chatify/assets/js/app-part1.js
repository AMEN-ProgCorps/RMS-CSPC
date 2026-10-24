// ── All DOM element references first ──────────────────────────────────────

    // Shared mobile/desktop breakpoint — matches the CSS layout breakpoint.
    // Every viewport-width check in the app (toast-vs-bell, sidebar
    // collapse, etc.) should go through this instead of comparing against
    // 991 directly, so there's a single place to change it.
    const MOBILE_BREAKPOINT = 991;
    function isMobileViewport() {
      return window.innerWidth <= MOBILE_BREAKPOINT;
    }

    function injectBadge(el) {
      if (!el) return;
      if (el.querySelector && el.querySelector('.verified-badge')) return;
      const badge = document.createElement('span');
      badge.className = 'verified-badge';
      badge.innerHTML = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="12" fill="#1b74e4"/><path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
      el.appendChild(badge);
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
    window.replyJumpActive = false;
    const secretInput     = document.getElementById("secretInput");
    const secretError     = document.getElementById("secretError");
    const darkModeToggle  = document.getElementById("darkModeToggle");
    const sidebarUsers    = document.getElementById('sidebarUsers');
    const searchInput     = document.getElementById('searchInput');
    const adminSearchInput = document.getElementById('adminSearchInput');

    const chatHeaderTitle = document.getElementById('chatHeaderTitle');
    const chatHeaderAvatar = document.getElementById('chatHeaderAvatar');
    if (chatHeaderAvatar) {
      chatHeaderAvatar.setAttribute('draggable', 'false');
      chatHeaderAvatar.addEventListener('dragstart', function(e) {
        e.preventDefault();
        return false;
      });
    }
    const sidebar         = document.getElementById('sidebar');
    const backButton      = document.getElementById('backButton');

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

    function updateRepliesForEditedMessage(msgUuid, newText) {
      if (!msgUuid || typeof newText !== 'string' || !chatBox) return;
      const cleanSnippet = newText.replace(/\s+/g, ' ').trim();
      const truncated = cleanSnippet.length <= 120 ? cleanSnippet : cleanSnippet.slice(0, 120).trim() + '...';

      const quotes = chatBox.querySelectorAll(
        `.reply-quote[data-reply-to="${msgUuid}"], .message-container[data-reply-to="${msgUuid}"] .reply-quote`
      );

      quotes.forEach(function(rq) {
        const textEl = rq.querySelector('.reply-quote-text');
        if (textEl) {
          textEl.textContent = truncated;
        } else if (!rq.querySelector('img')) {
          rq.innerHTML = `<div class="reply-quote-text">${escapeHtml(truncated)}</div>`;
        }
      });
    }
    window.updateRepliesForEditedMessage = updateRepliesForEditedMessage;

    function showGeneralToast(message, isError) {
      const container = document.getElementById('notifyToastContainer');
      if (!container) return;
      const toast = document.createElement('div');
      toast.className = 'notify-toast' + (isError ? ' error' : '');
      if (isError) {
        toast.style.cssText = 'background:#ef4444;color:#ffffff;border:none;box-shadow:0 4px 12px rgba(239,68,68,0.4);font-weight:600;font-size:13px;padding:10px 16px;border-radius:10px;';
      }
      toast.textContent = message;
      container.appendChild(toast);
      setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 250);
      }, 3500);
    }
    window.showGeneralToast = showGeneralToast;
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

        // WebSocket is connected: stop backup message polling
        stopPollingFallback();
        if (typeof window._startSidebarPoll === 'function') window._startSidebarPoll();

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
      const wasAtBottom = isAtBottom() && !window.replyJumpActive;

      const isSentByMe = Number(msgData.sender_id) === wsConfig.accountId;
      const container = document.createElement('div');
      container.className = 'message-container ' + (isSentByMe ? 'sent' : 'received') + ' msg-animate-' + (isSentByMe ? 'sent' : 'received');
      if (msgId) container.setAttribute('data-msg-id', msgId);
      if (msgData.sender_id) container.setAttribute('data-sender-id', String(msgData.sender_id));
      if (msgData.created_at) container.setAttribute('data-created-at', String(msgData.created_at));
      if (msgData.reply_to_msg_uuid) container.setAttribute('data-reply-to', String(msgData.reply_to_msg_uuid));
      container.setAttribute('data-edit-count', String(msgData.edit_count || 0));
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
          replyQuoteHtml = `<div class="reply-quote reply-quote-image-container" data-reply-to="${escapeHtml(msgData.reply_to_msg_uuid)}"><img src="${escapeHtml(imgSrc)}" class="reply-quote-image" alt="" referrerpolicy="no-referrer" draggable="false" onerror="this.closest('.reply-quote-image-container,.reply-quote')?.remove()"></div>`;
        } else {
          replyQuoteHtml = `<div class="reply-quote" data-reply-to="${escapeHtml(msgData.reply_to_msg_uuid)}"><div class="reply-quote-text">${escapeHtml(String(replySnippetText).slice(0, 120))}</div></div>`;
        }
      }

      const senderLabel = escapeHtml(displayName.toLowerCase());

      container.innerHTML = `
        <div class="message-avatar">${avatarInnerHtml(senderAvatarUrl, initials)}</div>
        <div class="bubble-wrapper">
          <div class="message-click-timestamp">${escapeHtml(timeDisplay)}</div>
          ${replyQuoteHtml}
          <div class="message-bubble${emojiOnlyClass}">
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

      // Cap the DOM at MAX_WINDOW visible messages so real-time
      // WebSocket pushes never grow the chat window without bound (only when NOT backreading).
      const viewingOlder = isGlobalChat ? gcViewingOlder : (activeAdminConv ? adminConvViewingOlder : dmViewingOlder);
      if (!viewingOlder) {
        const trimmed = trimChatMessages(MAX_WINDOW);
        if (trimmed) refreshCursorAfterTopTrim();
      }

      applyAdminBadges();
      if ((wasAtBottom || isSentByMe) && !window.replyJumpActive) {
        scrollToBottom(true, true);
      } else {
        showScrollIndicator(isSentByMe ? 0 : 1);
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
      const wasAtBottom = isAtBottom() && !window.replyJumpActive;

      // Admin spy view always renders every message "received"-style,
      // regardless of who sent it — matches load_dm_admin.php's renderer.
      const container = document.createElement('div');
      container.className = 'message-container received msg-animate-received';
      if (msgId) container.setAttribute('data-msg-id', msgId);
      if (msgData.sender_id) container.setAttribute('data-sender-id', String(msgData.sender_id));
      if (msgData.created_at) container.setAttribute('data-created-at', String(msgData.created_at));
      if (msgData.reply_to_msg_uuid) container.setAttribute('data-reply-to', String(msgData.reply_to_msg_uuid));
      container.setAttribute('data-edit-count', String(msgData.edit_count || 0));
      container.addEventListener('animationend', () => container.classList.remove('msg-animate-received'), { once: true });

      const msgText = msgData.message || msgData.plaintext || '';
      const senderUser = allUsersData.find(u => Number(u.account_id) === Number(msgData.sender_id));
      const displayName = msgData.sender_name || (senderUser ? (senderUser.full_name || senderUser.username) : 'User');
      const initials = getInitials(displayName);
      const senderAvatarUrl = msgData.sender_avatar || (senderUser ? senderUser.avatar_url : null);
      const senderLabel = escapeHtml(displayName.toLowerCase());

      let isSenderMoved = false;
      let movedNoticeText = '';
      if (activeAdminConv && activeAdminConv.startsWith('office_')) {
        const spiedOfficeId = Number(activeAdminConv.replace('office_', ''));
        const senderOfficeId = senderUser && senderUser.office_id !== undefined && senderUser.office_id !== null ? Number(senderUser.office_id) : 0;
        if (senderOfficeId !== spiedOfficeId) {
          isSenderMoved = true;
          movedNoticeText = (senderOfficeId <= 0) ? 'No office assigned for this user' : 'This user has been moved to other office';
          container.classList.add('user-moved');
        }
      }

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
          replyQuoteHtml = `<div class="reply-quote reply-quote-image-container" data-reply-to="${escapeHtml(msgData.reply_to_msg_uuid)}"><img src="${escapeHtml(imgSrc)}" class="reply-quote-image" alt="" referrerpolicy="no-referrer" draggable="false" onerror="this.closest('.reply-quote-image-container,.reply-quote')?.remove()"></div>`;
        } else {
          replyQuoteHtml = `<div class="reply-quote" data-reply-to="${escapeHtml(msgData.reply_to_msg_uuid)}"><div class="reply-quote-text">${escapeHtml(String(replySnippetText).slice(0, 120))}</div></div>`;
        }
      }

      const bubbleContentHtml = isSenderMoved
        ? `<div class="message-content" style="font-style:italic;opacity:0.85;">${escapeHtml(movedNoticeText)}</div>`
        : `<div class="message-content">${escapeHtml(msgText)}</div>`;

      container.innerHTML = `
        <div class="message-avatar">${avatarInnerHtml(senderAvatarUrl, initials)}</div>
        <div class="bubble-wrapper">
          <div class="message-click-timestamp">${escapeHtml(timeDisplay)}</div>
          ${replyQuoteHtml}
          <div class="message-bubble">
            ${bubbleContentHtml}
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
      if (wasAtBottom && !window.replyJumpActive) {
        scrollToBottom(true, true);
      } else {
        showScrollIndicator(1);
      }
    }

    const processedWsMsgUuids = new Set();

    ws.onmessage = function(event) {
        let data;
        try {
          data = JSON.parse(event.data);
        } catch (e) {
          return;
        }

        if (data.type === 'auth_success') {
          stopPollingFallback();
          if (typeof window._startSidebarPoll === 'function') window._startSidebarPoll();
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
          if ((data.chat_type === 'global' || data.chat_type === 'office_gc') && isGlobalChat) {
            if (data.conv_id && window.currentOfficeInfo && window.currentOfficeInfo.conv_id && data.conv_id !== window.currentOfficeInfo.conv_id) {
              // Ignore messages for other office GCs
            } else {
              processGlobalChatData(data.data || {});
            }
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
            if (typeof data.edit_count !== 'undefined') {
              targetContainer.setAttribute('data-edit-count', String(data.edit_count));
            } else {
              const prevCount = parseInt(targetContainer.getAttribute('data-edit-count') || '0', 10);
              targetContainer.setAttribute('data-edit-count', String(prevCount + 1));
            }

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

          // Real-time reply reflection: update any reply bubbles referencing this message
          if (typeof updateRepliesForEditedMessage === 'function') {
            updateRepliesForEditedMessage(data.msg_uuid, data.message);
          }

          // If current user is in the middle of replying to this message, update reply banner
          if (typeof replyState !== 'undefined' && replyState && replyState.msgId === data.msg_uuid) {
            replyState.snippet = data.message;
            if (typeof showReplyBanner === 'function') {
              showReplyBanner(data.message);
            }
          }

          if (typeof dmMessageCache !== 'undefined') {
            dmMessageCache.clear();
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
          // Deduplication: if message UUID was already processed, drop duplicate immediately
          const targetMsgId = data.msg_uuid || data.id;
          if (targetMsgId) {
            if (processedWsMsgUuids.has(targetMsgId)) {
              return;
            }
            processedWsMsgUuids.add(targetMsgId);
            if (processedWsMsgUuids.size > 1000) {
              const oldestKey = processedWsMsgUuids.keys().next().value;
              processedWsMsgUuids.delete(oldestKey);
            }
          }
          if (targetMsgId && chatBox.querySelector(`.message-container[data-msg-id="${targetMsgId}"]`)) {
            return;
          }

          // Admin spy mode: keep conversations list and counter live in real-time
          if (typeof isAdminAllChatsView !== 'undefined' && isAdminAllChatsView && adminSpyType === 'conversations') {
            const targetConvId = data.conv_id || (data.chat_type === 'private' ? (
              Math.min(Number(data.sender_id), Number(data.recipient_id)) + '_' +
              Math.max(Number(data.sender_id), Number(data.recipient_id))
            ) : null);
            if (targetConvId && Array.isArray(adminSpyConvs)) {
              const match = adminSpyConvs.find(c => c.convId === targetConvId);
              if (match) {
                match.msgCount = (Number(match.msgCount) || 0) + 1;
                const item = adminConvItems.get('conv_' + targetConvId);
                if (item) {
                  const msgEl = item._msgEl || item.querySelector('.user-last-msg');
                  if (msgEl) {
                    const count = match.msgCount;
                    msgEl.textContent = count > 99 ? '99+ messages' : (count === 1 ? '1 message' : count + ' messages');
                  }
                }
              } else if (adminSpyTargetUser) {
                const spyId = Number(adminSpyTargetUser.account_id);
                const sId = Number(data.sender_id);
                const rId = Number(data.recipient_id);
                if (sId === spyId || rId === spyId || data.conv_id) {
                  fetchAdminConvs('', 0, false, spyId);
                }
              }
            }
          }

          if (data.chat_type === 'global' || data.chat_type === 'office_gc' || data.chat_type === 'custom_gc' || (data.conv_id && data.conv_id.startsWith('gc_'))) {
            const isOfficeMsg = (data.chat_type === 'office_gc') || (data.conv_id && data.conv_id.startsWith('office_'));
            const isCustomGcMsg = (data.chat_type === 'custom_gc') || (data.conv_id && data.conv_id.startsWith('gc_'));
            const msgConvId   = data.conv_id || (isOfficeMsg ? window.currentOfficeInfo?.conv_id : (isCustomGcMsg ? window.activeCustomGc?.conv_id : 'global'));

            const isViewingThisInAdminSpy = (typeof activeAdminConv !== 'undefined' && activeAdminConv === msgConvId);

            // If message is for an office GC:
            // Allow only if Super Admin is actively viewing this GC in spy mode, OR the user belongs to this office GC
            if (isOfficeMsg) {
              const isOwnOffice = Boolean(window.currentOfficeInfo && window.currentOfficeInfo.conv_id && window.currentOfficeInfo.conv_id === msgConvId);
              if (!isViewingThisInAdminSpy && !isOwnOffice) {
                return;
              }
            }

            if (Array.isArray(data.mentioned_ids) && data.mentioned_ids.map(Number).includes(Number(wsConfig.accountId))) {
              catchUpMissedNotifications();
            }

            if (isViewingThisInAdminSpy) {
              if (data.has_upload) {
                loadAdminConv(activeAdminConv, false);
              } else {
                renderAndAppendAdminWsMessage(data);
              }
            } else {
              const curConvId = (typeof getActiveChannelConvId === 'function')
                ? getActiveChannelConvId()
                : (window.activeChannel === 'office' ? window.currentOfficeInfo?.conv_id : (window.activeChannel === 'gc' ? window.activeCustomGc?.conv_id : 'global'));

              if (isGlobalChat && msgConvId === curConvId) {
                const sender = Number(data.sender_id);
                if (sender !== wsConfig.accountId) {
                  if (data.has_upload) {
                    if (gcViewingOlder || window.replyJumpActive) {
                      showScrollIndicator(1);
                    } else {
                      isLoadingGC = false;
                      loadGlobalChat(false);
                    }
                  } else {
                    renderAndAppendWsMessage(data);
                  }
                  if (curConvId && (curConvId.startsWith('gc_') || curConvId.startsWith('office_'))) {
                    markRead(curConvId);
                  }
                } else {
                  // Echo of our own sent message — XHR optimistic path already rendered it in place.
                  if (data.has_upload) {
                    isLoadingGC = false;
                    loadGlobalChat(false);
                  }
                }
              }

              // Bump GC in sidebar if it's an office or custom GC message
              if (msgConvId && (msgConvId.startsWith('gc_') || data.chat_type === 'custom_gc')) {
                const isViewingThisGc = (isGlobalChat && window.activeChannel === 'gc' && window.activeCustomGc && window.activeCustomGc.conv_id === msgConvId);
                bumpSidebarUser(msgConvId, {
                  incrementUnread: !isViewingThisGc && Number(data.sender_id) !== Number(wsConfig.accountId),
                  lastMessage: data.message
                });
              } else if (msgConvId && (msgConvId.startsWith('office_') || data.chat_type === 'office_gc')) {
                const isViewingThisOffice = (isGlobalChat && window.activeChannel === 'office');
                bumpSidebarUser(msgConvId, {
                  incrementUnread: !isViewingThisOffice && Number(data.sender_id) !== Number(wsConfig.accountId),
                  lastMessage: data.message
                });
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
                    if (dmViewingOlder || window.replyJumpActive) {
                      showScrollIndicator(1);
                    } else {
                      loadChatForced();
                    }
                  } else {
                    renderAndAppendWsMessage(data);
                    // renderAndAppendWsMessage already calls showScrollIndicator(1)
                    // internally when the user is scrolled up — don't call it again
                    // here or the counter increments twice per message (+2 instead of +1).
                    // Only markRead needs to be handled separately.
                    if ((typeof isAtBottom === 'function' ? isAtBottom() : false) && shouldMarkReadNow()) {
                      userScrolledUp = false;
                      shouldAutoScroll = true;
                      markRead(activeDM, data.msg_uuid || data.id);
                    }
                  }
                } else {
                  // This is an echo of our own sent message (WS server broadcasts back to sender).
                  // The XHR optimistic path already rendered it — skip renderAndAppendWsMessage
                  // to avoid duplicate bubbles. Only do a forced reload if somehow the optimistic
                  // bubble is missing (e.g. attachment upload where has_upload=true).
                  if (data.has_upload) {
                    if (!dmViewingOlder) loadChatForced();
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
          const readerId = Number(data.reader_id);
          const targetId = Number(data.target_id);

          // Only process this read marker for our private DMs if the other participant was reading
          // OUR conversation (i.e. target_id is OUR account).
          // Otherwise (e.g. admin receiving audit broadcast when reader read someone else's chat),
          // this is a foreign conversation and must NOT corrupt our DM's read marker!
          if (targetId && wsConfig && Number(wsConfig.accountId) > 0 && targetId === Number(wsConfig.accountId)) {
            const readerUser = allUsersData.find(u => Number(u.account_id) === readerId);
            const readerUsername = (readerUser ? readerUser.username : null) || (activeDMAccountId === readerId ? activeDM : null);
            let incomingReadUpTo = data.last_msg_uuid || null;
            if (incomingReadUpTo && readerUsername) {
              dmReadUpToMap.set(readerUsername, incomingReadUpTo);
              const cachedObj = dmMessageCache.get(readerUsername);
              if (cachedObj) {
                cachedObj.readUpTo = incomingReadUpTo;
                if (cachedObj._raw) cachedObj._raw.readUpTo = incomingReadUpTo;
              }
            }
            if (activeDM && (activeDMAccountId === readerId || activeDM === readerUsername)) {
              if (incomingReadUpTo && (incomingReadUpTo !== dmReadUpTo || !chatBox.querySelector('.seen-indicator'))) {
                dmReadUpTo = incomingReadUpTo;
                updateSeenIndicator();
              }
            }
          }
        } else if (data.type === 'chat_cleared') {
          console.log('Received WebSocket real-time update notice:', data);

          const isGlobalClear   = (data.chat_type === 'global') || (data.conv_id === 'global');
          const isOfficeClear   = (data.chat_type === 'office_gc') || (data.conv_id && String(data.conv_id).startsWith('office_'));
          const isCustomGcClear = (data.chat_type === 'custom_gc') || (data.conv_id && String(data.conv_id).startsWith('gc_'));
          const clearedConvId   = data.conv_id || (isOfficeClear ? (data.office_id ? 'office_' + data.office_id : null) : (isGlobalClear ? 'global' : null));

          // ── Case 0: Custom Group Chat clear ───────────────────────────────────
          if (isCustomGcClear) {
            // If admin is spying on this custom GC
            if (typeof activeAdminConv !== 'undefined' && activeAdminConv === clearedConvId) {
              chatBox.innerHTML = '<div class="empty-chat"></div>';
              isFirstLoad = true;
              chatFullyLoaded = false;
              isLoadingAdminConv = false;
              loadAdminConv(activeAdminConv, false);
              return;
            }
            // If current user is viewing this custom GC
            if (isGlobalChat && window.activeChannel === 'gc' && window.activeCustomGc && window.activeCustomGc.conv_id === clearedConvId) {
              globalChatCache = null;
              if (typeof globalChatPrefetchedData !== 'undefined') globalChatPrefetchedData = null;
              gcCursor = '';
              gcViewingOlder = false;
              removePaginationBtn();
              if (typeof closeImageViewer === 'function') closeImageViewer();
              if (chatBox) chatBox.innerHTML = '<div class="empty-chat"></div>';
              isFirstLoad = true;
              chatFullyLoaded = false;
              isLoadingGC = false;
              loadGlobalChat(false, false, true);
            }
            return;
          }

          // ── Case 1: Global Chat clear (super admin "/clear" in Global Chat) ──
          if (isGlobalClear) {
            globalChatCache = null;
            if (typeof globalChatPrefetchedData !== 'undefined') globalChatPrefetchedData = null;
            gcCursor = '';
            gcViewingOlder = false;
            removePaginationBtn();
            if (typeof closeImageViewer === 'function') closeImageViewer();

            if (typeof activeAdminConv !== 'undefined' && activeAdminConv === 'global') {
              loadAdminConv(activeAdminConv, false);
            } else if (isGlobalChat && window.activeChannel === 'global') {
              if (chatBox) chatBox.innerHTML = '<div class="empty-chat"></div>';
              isFirstLoad = true;
              chatFullyLoaded = false;
              isLoadingGC = false;
              loadGlobalChat(false, false, true);
            }
            fetchUsers();
            return;
          }

          // ── Case 2: Office Group Chat clear (admin "/clear" in Office GC) ──
          if (isOfficeClear) {
            if (typeof activeAdminConv !== 'undefined' && activeAdminConv === clearedConvId) {
              loadAdminConv(activeAdminConv, false);
              return;
            }
            // Only affects users belonging to this office
            if (window.currentOfficeInfo && window.currentOfficeInfo.conv_id && clearedConvId !== window.currentOfficeInfo.conv_id) {
              return; // Clear belongs to another office
            }
            globalChatCache = null;
            if (typeof globalChatPrefetchedData !== 'undefined') globalChatPrefetchedData = null;
            gcCursor = '';
            gcViewingOlder = false;
            removePaginationBtn();
            if (typeof closeImageViewer === 'function') closeImageViewer();

            if (isGlobalChat && window.activeChannel === 'office') {
              if (chatBox) chatBox.innerHTML = '<div class="empty-chat"></div>';
              isFirstLoad = true;
              chatFullyLoaded = false;
              isLoadingGC = false;
              loadGlobalChat(false, false, true);
            }
            fetchUsers();
            return;
          }

          let _ca = Number(data.user_a || 0);
          let _cb = Number(data.user_b || 0);
          if ((!_ca || !_cb) && data.conv_id && typeof data.conv_id === 'string' && data.conv_id.includes('_')) {
            const parts = data.conv_id.split('_').map(Number);
            if (parts.length === 2 && !isNaN(parts[0]) && !isNaN(parts[1])) {
              _ca = parts[0];
              _cb = parts[1];
            }
          }
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

          // Pre-capture partner username before splicing allUsersData
          let partnerUsername = null;
          if (_partnerId !== null && _partnerId > 0) {
            const partnerUser = allUsersData.find(u => Number(u.account_id) === Number(_partnerId));
            if (partnerUser) {
              partnerUsername = partnerUser.username;
            }
          }

          // ── 1. Drop stale snapshot from DM cache — do NOT splice allUsersData
          //       here because fetchUsers() will return the user again (soft-delete
          //       only removes messages, not the user), causing a ghost-blink where
          //       the user disappears then immediately re-appears in the sidebar.
          //       Instead, just invalidate the message cache so the next open reads
          //       fresh data, and let fetchUsers() below repaint the sidebar correctly
          //       in one pass without any intermediate flicker. ──────────────────────
          if (_partnerId !== null && _partnerId > 0) {
            if (partnerUsername) dmMessageCache.delete(partnerUsername);
          }

          // ── 2. If the user is currently viewing this conversation, wipe the
          //       chat pane immediately — don't wait for resetToHome()'s async
          //       fetchUsers() to repaint. Abort any in-flight load_dm.php XHR
          //       so stale messages can't come back over the top. ───────────────
          const _currentDMIsCleared =
            (activeDM || activeDMAccountId) && (
              // Match by conv_id
              (data.conv_id && activeDMAccountId && myId &&
                data.conv_id === `${Math.min(myId, activeDMAccountId)}_${Math.max(myId, activeDMAccountId)}`) ||
              // Match by account-ID pair (most reliable)
              (_ca > 0 && _cb > 0 && activeDMAccountId &&
                ((_ca === myId && _cb === activeDMAccountId) ||
                 (_cb === myId && _ca === activeDMAccountId))) ||
              // Fallback 1: sender/recipient ID matches
              (_partnerId !== null && activeDMAccountId === _partnerId) ||
              // Fallback 2: username match via pre-captured username or activeDM
              (partnerUsername !== null && partnerUsername === activeDM)
            );

          const _currentAdminConvIsCleared =
            activeAdminConv && _ca > 0 && _cb > 0 && (() => {
              const parts = activeAdminConv.split('_').map(Number);
              return (parts[0] === _ca && parts[1] === _cb) ||
                     (parts[0] === _cb && parts[1] === _ca);
            })();

          if (_currentAdminConvIsCleared) {
            if (typeof adminConvXhr !== 'undefined' && adminConvXhr) {
              adminConvXhr.abort();
              adminConvXhr = null;
            }
            if (chatBox) {
              chatBox.innerHTML = '<div class="empty-chat"></div>';
            }
            if (typeof loadAdminConv === 'function') {
              loadAdminConv(activeAdminConv, false);
            }
          }

          if (_currentDMIsCleared) {
            // Abort any in-flight chat XHR immediately so it can't overwrite
            if (typeof chatXhr !== 'undefined' && chatXhr) {
              chatXhr.abort();
              chatXhr = null;
            }
            // Wipe the pane immediately — makes the clear visually instant for
            // BOTH the admin who cleared and the recipient on the other side.
            if (chatBox) {
              chatBox.innerHTML = '<div class="empty-chat"></div>';
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
          globalChatCache = null;
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
            // Mark GC-related notifications so we don't show "mentioned you"
            const _gcMsgPatterns = ['added to the group', 'removed from the group', 'disbanded by the admin', 'has been disbanded', 'admin of the group'];
            if (_gcMsgPatterns.some(p => (data.message || '').toLowerCase().includes(p))) {
              data.is_gc_event = true;
            }
            bellNotifications.unshift(data); // newest first
            updateBellBadge();
            renderBellList();
          }
        } else if (data.type === 'office_admin_status_changed') {
          console.log('Received WebSocket real-time update notice:', data);
          if (window.currentOfficeInfo && Number(window.currentOfficeInfo.office_id) === Number(data.office_id)) {
            window.currentOfficeInfo.is_gc_admin = !!data.is_office_admin;
          }
          if (data.message) {
            showNotifyToast({
              id: Date.now(),
              type: 'system',
              title: 'Office GC Admin',
              message: data.message,
              created_at: new Date().toISOString()
            });
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
          // changed, office assigned/removed, etc.
          console.log('Received WebSocket real-time update notice:', data);
          fetchUsers();
          if (typeof reloadOfficeMembersList === 'function') {
            reloadOfficeMembersList(true);
          }

          const changedUserId = Number(data.account_id);
          const newOfficeId   = (data.office_id !== null && data.office_id !== undefined && data.office_id !== '') ? Number(data.office_id) : 0;

          // ── Case 1: Super Admin is currently spying on an Office GC ──
          if (typeof activeAdminConv !== 'undefined' && activeAdminConv && activeAdminConv.startsWith('office_')) {
            const spiedOfficeId = Number(activeAdminConv.replace('office_', ''));

            if (chatBox && changedUserId > 0) {
              const msgContainers = chatBox.querySelectorAll(`.message-container[data-sender-id="${changedUserId}"]`);
              if (newOfficeId !== spiedOfficeId) {
                const noticeText = (newOfficeId <= 0) ? 'No office assigned for this user' : 'This user has been moved to other office';
                const changedMsgIds = new Set();
                msgContainers.forEach(container => {
                  container.classList.add('user-moved');
                  const mid = container.getAttribute('data-msg-id');
                  if (mid) changedMsgIds.add(mid);
                  let contentEl = container.querySelector('.message-content');
                  if (!contentEl) {
                    let bubble = container.querySelector('.message-bubble');
                    if (!bubble) {
                      bubble = document.createElement('div');
                      bubble.className = 'message-bubble';
                      const bubbleWrapper = container.querySelector('.bubble-wrapper');
                      if (bubbleWrapper) bubbleWrapper.appendChild(bubble);
                      else container.appendChild(bubble);
                    }
                    contentEl = document.createElement('div');
                    contentEl.className = 'message-content';
                    bubble.insertBefore(contentEl, bubble.firstChild);
                    if (!bubble.querySelector('.message-info')) {
                      const info = document.createElement('div');
                      info.className = 'message-info';
                      const sender = document.createElement('span');
                      sender.className = 'message-sender';
                      const userObj = allUsersData.find(u => Number(u.account_id) === changedUserId);
                      sender.textContent = userObj ? (userObj.full_name || userObj.username || '').toLowerCase() : 'user';
                      info.appendChild(sender);
                      bubble.appendChild(info);
                    }
                  }
                  if (contentEl) {
                    contentEl.textContent = noticeText;
                    contentEl.style.fontStyle = 'italic';
                    contentEl.style.opacity = '0.85';
                  }
                  container.querySelectorAll('.chat-attachment-container, img.chat-image, video, audio, .message-media').forEach(el => el.remove());
                });

                if (changedMsgIds.size > 0) {
                  chatBox.querySelectorAll('.reply-quote[data-reply-to]').forEach(rq => {
                    const replyTo = rq.getAttribute('data-reply-to');
                    if (replyTo && changedMsgIds.has(replyTo)) {
                      rq.classList.remove('reply-quote-image-container');
                      rq.innerHTML = `<div class="reply-quote-text" style="font-style:italic;opacity:0.8;">${escapeHtml(noticeText)}</div>`;
                    }
                  });
                }
              }
            }

            if (typeof loadAdminConv === 'function') {
              loadAdminConv(activeAdminConv, false);
            }
          }

          // ── Case 2: User is currently viewing Office GC in normal mode ──
          if (window.activeChannel === 'office') {
            const currentOfficeId = window.currentOfficeInfo ? Number(window.currentOfficeInfo.office_id) : 0;
            if (chatBox && changedUserId > 0 && currentOfficeId > 0 && newOfficeId !== currentOfficeId) {
              const noticeText = (newOfficeId <= 0) ? 'No office assigned for this user' : 'This user has been moved to other office';
              const changedMsgIds = new Set();
              const msgContainers = chatBox.querySelectorAll(`.message-container[data-sender-id="${changedUserId}"]`);
              msgContainers.forEach(container => {
                container.classList.add('user-moved');
                const mid = container.getAttribute('data-msg-id');
                if (mid) changedMsgIds.add(mid);
                let contentEl = container.querySelector('.message-content');
                if (!contentEl) {
                  let bubble = container.querySelector('.message-bubble');
                  if (!bubble) {
                    bubble = document.createElement('div');
                    bubble.className = 'message-bubble';
                    const bubbleWrapper = container.querySelector('.bubble-wrapper');
                    if (bubbleWrapper) bubbleWrapper.appendChild(bubble);
                    else container.appendChild(bubble);
                  }
                  contentEl = document.createElement('div');
                  contentEl.className = 'message-content';
                  bubble.insertBefore(contentEl, bubble.firstChild);
                  if (!bubble.querySelector('.message-info')) {
                    const info = document.createElement('div');
                    info.className = 'message-info';
                    const sender = document.createElement('span');
                    sender.className = 'message-sender';
                    const userObj = allUsersData.find(u => Number(u.account_id) === changedUserId);
                    sender.textContent = userObj ? (userObj.full_name || userObj.username || '').toLowerCase() : 'user';
                    info.appendChild(sender);
                    bubble.appendChild(info);
                  }
                }
                if (contentEl) {
                  contentEl.textContent = noticeText;
                  contentEl.style.fontStyle = 'italic';
                  contentEl.style.opacity = '0.85';
                }
                container.querySelectorAll('.chat-attachment-container, img.chat-image, video, audio, .message-media').forEach(el => el.remove());
              });

              if (changedMsgIds.size > 0) {
                chatBox.querySelectorAll('.reply-quote[data-reply-to]').forEach(rq => {
                  const replyTo = rq.getAttribute('data-reply-to');
                  if (replyTo && changedMsgIds.has(replyTo)) {
                    rq.classList.remove('reply-quote-image-container');
                    rq.innerHTML = `<div class="reply-quote-text" style="font-style:italic;opacity:0.8;">${escapeHtml(noticeText)}</div>`;
                  }
                });
              }
            }

            if (typeof loadGlobalChat === 'function') {
              loadGlobalChat(false, false, true);
            }
          }
        } else if (data.type === 'custom_gc_added' || data.type === 'custom_gc_member_added') {
          console.log('Received custom GC update notice:', data);
          const targetGid = Number(data.group_id);

          // Track newly added GC so it highlights and sorts to top
          // Only mark as "new" for members who were added — NOT the creator
          const _myId = (typeof wsConfig !== 'undefined' && wsConfig.accountId) ? Number(wsConfig.accountId) : 0;
          const _creatorId = Number(data.creator_id || 0);
          if (targetGid > 0 && (_myId <= 0 || _myId !== _creatorId)) {
            window.newlyAddedGcIds = window.newlyAddedGcIds || new Set();
            window.newlyAddedGcIds.add(targetGid);
            // Also remove from localStorage openedGcIds so the NEW badge
            // reappears even if this user was previously in this group
            try {
              const _raw = localStorage.getItem('openedGcIds');
              const _set = new Set(_raw ? JSON.parse(_raw).map(Number) : []);
              if (_set.has(targetGid)) {
                _set.delete(targetGid);
                localStorage.setItem('openedGcIds', JSON.stringify([..._set]));
              }
            } catch (e) {}
          }

          // Case 1: Super Admin is currently spying on this Custom GC
          if (targetGid > 0 && typeof activeAdminConv !== 'undefined' && activeAdminConv === ('gc_' + targetGid)) {
            if (typeof loadAdminConv === 'function') {
              loadAdminConv(activeAdminConv, false);
            }
          }

          // Case 2: User is currently viewing this Custom GC — reload members list and reload chat to restore chats of added user
          if (targetGid > 0 && window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === targetGid) {
            if (typeof reloadOfficeMembersList === 'function') {
              reloadOfficeMembersList(true);
            }
            if (typeof loadGlobalChat === 'function') {
              loadGlobalChat(false, false, true);
            }
          }

          if (typeof fetchUsers === 'function') {
            fetchUsers();
          }
          if (typeof loadCustomGcs === 'function') {
            loadCustomGcs();
          }
          if (typeof catchUpMissedNotifications === 'function') {
            catchUpMissedNotifications();
          }
        } else if (data.type === 'custom_gc_removed') {
          console.log('Received custom GC removed notice:', data);
          // If the removed user is currently viewing that GC, show removal message
          if (window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === Number(data.group_id)) {
            const _gcName = escapeHtml(data.name || 'this group');
            if (chatBox) {
              chatBox.innerHTML = '<div class="empty-chat" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;padding:32px;">' +
                '<p style="margin:0;font-size:14px;font-weight:600;color:var(--text-primary);">You have been removed from this group.</p>' +
                '<p style="margin:0;font-size:13px;color:var(--text-secondary);">You no longer have access to <strong>' + _gcName + '</strong>.</p>' +
              '</div>';
            }
            if (chatHeaderTitle) {
              chatHeaderTitle.innerHTML = _gcName;
            }
            if (typeof applyHeaderAdminBadge === 'function') {
              applyHeaderAdminBadge();
            }
            if (chatHeaderAvatar) {
              chatHeaderAvatar.innerHTML = '<img src="cspc.webp" width="32" height="32" class="avatar-img" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;background:transparent;" draggable="false">';
              chatHeaderAvatar.style.display = 'flex';
              chatHeaderAvatar.style.background = 'transparent';
              chatHeaderAvatar.style.backgroundColor = 'transparent';
              chatHeaderAvatar.dataset.avatarUrl = 'cspc.webp';
            }
            window.activeCustomGc = null;
            window.activeChannel = null;
            isGlobalChat = false;
          }
          if (typeof loadCustomGcs === 'function') {
            loadCustomGcs();
          }
          if (typeof catchUpMissedNotifications === 'function') {
            catchUpMissedNotifications();
          }
        } else if (data.type === 'custom_gc_member_removed') {
          console.log('Received custom GC member removed notice:', data);
          const changedUserId = Number(data.removed_id);
          const targetGid = Number(data.group_id);

          // Case 1: Super Admin is currently spying on this Custom GC
          if (typeof activeAdminConv !== 'undefined' && activeAdminConv === ('gc_' + targetGid)) {
            if (typeof markUserRemovedFromGcInChatBox === 'function') {
              markUserRemovedFromGcInChatBox(changedUserId);
            }
            if (typeof loadAdminConv === 'function') {
              loadAdminConv(activeAdminConv, false);
            }
          }

          // Case 2: User is currently viewing this Custom GC
          if (window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === targetGid) {
            if (typeof markUserRemovedFromGcInChatBox === 'function') {
              markUserRemovedFromGcInChatBox(changedUserId);
            }
            if (typeof reloadOfficeMembersList === 'function') {
              reloadOfficeMembersList(true);
            }
            if (typeof loadGlobalChat === 'function') {
              loadGlobalChat(false, false, true);
            }
          }

          if (typeof loadCustomGcs === 'function') {
            loadCustomGcs();
          }
        } else if (data.type === 'custom_gc_disbanded') {
          console.log('Received custom GC disbanded notice:', data);
          if (typeof loadCustomGcs === 'function') {
            loadCustomGcs();
          }
          if (typeof catchUpMissedNotifications === 'function') {
            catchUpMissedNotifications();
          }
          if (window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === Number(data.group_id)) {
            if (typeof closeOfficeMembersModal === 'function') closeOfficeMembersModal();
            if (typeof resetToHome === 'function') {
              resetToHome();
            }
          }
        } else if (data.type === 'custom_gc_renamed') {
          const gid = Number(data.group_id);
          const newName = data.name || '';
          if (Array.isArray(allUsersData)) {
            const u = allUsersData.find(x => x.is_custom_gc && Number(x.gc_id) === gid);
            if (u) {
              u.name = newName;
              u.full_name = newName;
              renderSidebarUsers();
            }
          }
          if (window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === gid) {
            window.activeCustomGc.name = newName;
            if (chatHeaderTitle) chatHeaderTitle.innerHTML = escapeHtml(newName);
            const modalTitleEl = document.getElementById('officeMembersModalTitle');
            if (modalTitleEl) {
              modalTitleEl.textContent = newName.length > 20 ? (newName.slice(0, 20).trimEnd() + '...') : newName;
              modalTitleEl.title = 'Click to rename group (' + newName + ')';
            }
          }
        } else if (data.type === 'custom_gc_admin_changed') {
          const gid = Number(data.group_id);
          const targetAccId = Number(data.target_account_id);
          const newIsAdmin = Boolean(data.is_admin);
          const myId = (typeof wsConfig !== 'undefined' && wsConfig.accountId)
            ? Number(wsConfig.accountId)
            : (typeof myAccountId !== 'undefined' ? Number(myAccountId) : 0);

          // Update in-memory members list immediately
          if (Array.isArray(window.activeCustomGcMembers)) {
            const m = window.activeCustomGcMembers.find(x => Number(x.account_id) === targetAccId);
            if (m) m.is_admin = newIsAdmin;
          }

          // If the current user was promoted or demoted
          if (targetAccId === myId) {
            // Always update in-memory groupInfo regardless of which channel user is viewing
            if (window.activeCustomGcGroupInfo && Number(window.activeCustomGcGroupInfo.id) === gid) {
              window.activeCustomGcGroupInfo.is_gc_admin = newIsAdmin;
            }

            // Immediately update the add button in modal regardless of channel check
            const addBtnInModal = document.getElementById('officeMembersAddUserBtn');
            if (addBtnInModal) {
              addBtnInModal.style.display = newIsAdmin ? 'flex' : 'none';
            }

            // If demoted, immediately close add members modal and rename modal if open
            if (!newIsAdmin) {
              if (typeof closeAddGcMembersModal === 'function') closeAddGcMembersModal();
              if (typeof closeRenameCustomGcModal === 'function') closeRenameCustomGcModal();
            }

            const titleEl = document.getElementById('officeMembersModalTitle');
            if (titleEl) {
              const currentGcName = (window.activeCustomGc ? window.activeCustomGc.name : '') || 'Group Chat';
              if (newIsAdmin) {
                titleEl.style.cursor = 'pointer';
                titleEl.title = 'Click to rename group (' + currentGcName + ')';
                titleEl.onclick = () => openRenameCustomGcModal();
              } else {
                titleEl.style.cursor = 'default';
                titleEl.title = currentGcName;
                titleEl.onclick = null;
              }
            }

            // Always trigger a fresh fetch of the members list so the modal
            // reflects the correct admin state next time it is opened.
            if (typeof reloadOfficeMembersList === 'function') {
              reloadOfficeMembersList(false);
            }
          }

          // Real-time re-render of members list for ALL viewers of this GC
          if (window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === gid) {
            if (typeof renderOfficeMembersList === 'function' && Array.isArray(window.activeCustomGcMembers)) {
              renderOfficeMembersList(window.activeCustomGcMembers, false, window.activeCustomGcGroupInfo);
            }
            // Only call reloadOfficeMembersList for non-target users here
            // (target user already called it above in the targetAccId === myId block)
            if (targetAccId !== myId && typeof reloadOfficeMembersList === 'function') {
              reloadOfficeMembersList(false);
            }
          }
        } else if (data.type === 'verification_update') {
          // Broadcast from set_verification.php when Super Admin toggles a user's badge
          const changedId = Number(data.account_id);
          const nowVerified = !!data.is_verified;
          if (typeof verifiedAccountIds !== 'undefined' && verifiedAccountIds && verifiedAccountIds.add) {
            if (nowVerified) {
              verifiedAccountIds.add(changedId);
            } else {
              verifiedAccountIds.delete(changedId);
            }
          }
          if (Array.isArray(window.verifiedAccountIds)) {
            if (nowVerified && !window.verifiedAccountIds.includes(changedId)) {
              window.verifiedAccountIds.push(changedId);
            } else if (!nowVerified) {
              window.verifiedAccountIds = window.verifiedAccountIds.filter(id => Number(id) !== changedId);
            }
          }
          if (Array.isArray(allUsersData)) {
            const userObj = allUsersData.find(u => Number(u.account_id) === changedId);
            if (userObj) {
              userObj.is_chatify_verified = nowVerified;
            }
          }
          // Re-render sidebar badges
          if (typeof renderSidebarUsers === 'function') {
            renderSidebarUsers();
          }
          // Re-apply header badge for current DM if it's the changed user
          if (Number(activeDMAccountId) === changedId) {
            const fnHeader = (typeof applyHeaderAdminBadge === 'function') ? applyHeaderAdminBadge : window.applyHeaderAdminBadge;
            if (typeof fnHeader === 'function') fnHeader();
          }
          // Re-apply badges on all visible message-sender elements
          const fnBadges = (typeof applyAdminBadges === 'function') ? applyAdminBadges : window.applyAdminBadges;
          if (typeof fnBadges === 'function') fnBadges();
          // If the User Verification modal is open, sync toggle rows in real-time
          if (typeof window._syncVerifyModalRow === 'function') {
            window._syncVerifyModalRow(changedId, nowVerified);
          }
          // If the Members modal (Office GC / Custom GC / DM) is open, sync verification badge in real-time
          if (typeof window.syncOfficeMembersModalVerification === 'function') {
            window.syncOfficeMembersModalVerification(changedId, nowVerified);
          }
        }
      };

      ws.onclose = function() {
        console.warn('WebSocket connection lost.');
        showTypingIndicator('', false);
        ws = null;
        
        // Start polling fallback immediately when connection is lost
        startPollingFallback();
        if (typeof window._startSidebarPoll === 'function') window._startSidebarPoll();

        if (!wsReconnectTimer) {
          wsAttempts++;
          const delay = Math.min(WS_MAX_DELAY, WS_BASE_DELAY * Math.pow(2, wsAttempts - 1)) + Math.floor(Math.random() * 500);
          console.log(`Scheduling WebSocket reconnect in ${delay}ms (attempt ${wsAttempts})...`);
          wsReconnectTimer = setTimeout(connectWebSocket, delay);
        }
      };

      ws.onerror = function(err) {
        console.error('WebSocket connection error:', err);
        startPollingFallback();
        if (typeof window._startSidebarPoll === 'function') window._startSidebarPoll();
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
      } else if (diffDay >= 100) {
        return 'Active 99+ days ago';
      } else {
        return `Active ${diffDay} ${diffDay === 1 ? 'day' : 'days'} ago`;
      }
    }
    window.formatActiveStatus = formatActiveStatus;

    function isWidgetMinimizedOrHidden() {
      try {
        if (window.frameElement) {
          if (window.frameElement.style.display === 'none') return true;
          if (window.frameElement.offsetParent === null && window.frameElement.offsetWidth === 0 && window.frameElement.offsetHeight === 0) {
            return true;
          }
          if (window.parent && window.parent.document) {
            const card = window.parent.document.getElementById('chatify-widget-card');
            if (card) {
              const style = window.parent.getComputedStyle(card);
              if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
                return true;
              }
            }
          }
        }
      } catch (e) {}
      return false;
    }

    function shouldMarkReadNow() {
      // 1. Must have an active DM
      if (!activeDM) return false;

      // 2. Browser tab must not be hidden/minimized
      if (document.hidden) return false;
      try {
        if (window.parent && window.parent !== window && window.parent.document && window.parent.document.hidden) {
          return false;
        }
      } catch (e) {}

      // 3. Floating widget card must not be minimized or hidden
      if (isWidgetMinimizedOrHidden()) return false;

      // 4. On mobile/webview, if the sidebar is open, user is in contacts list, NOT in chat
      if (isMobileViewport()) {
        if (sidebar && sidebar.classList.contains('open')) return false;
      }

      // 5. ChatBox must not be on the empty-chat placeholder screen
      if (chatBox && chatBox.querySelector('.empty-chat')) return false;

      // 6. Must not be backreading older history
      if (typeof dmViewingOlder !== 'undefined' && dmViewingOlder) return false;

      // 7. Must be at the bottom of the conversation
      if (typeof isAtBottom === 'function' && !isAtBottom()) return false;

      if (typeof userScrolledUp !== 'undefined' && userScrolledUp) {
        userScrolledUp = false;
      }
      return true;
    }
    window.shouldMarkReadNow = shouldMarkReadNow;

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
      const lastTime = isOnline ? null : (wsLastOnlineTime.get(accId) || user.last_online_time || user.lastTimestamp);
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
      if (ws && ws.readyState === WebSocket.OPEN) return; // Do not start if WebSocket is healthy!
      console.log('Starting backup message polling (WebSocket offline)...');
      wsPollInterval = setInterval(function() {
        if (document.hidden) return; // skip each tick while hidden
        if (ws && ws.readyState === WebSocket.OPEN) {
          stopPollingFallback();
          return;
        }
        if (isGlobalChat) {
          loadGlobalChat(true);
        } else if (activeDM) {
          loadChat(true);
        }
        if (activeAdminConv) {
          loadAdminConv(activeAdminConv, true);
        }
      }, 5000);
    }
    window.startPollingFallback = startPollingFallback;

    function stopPollingFallback() {
      if (wsPollInterval) {
        console.log('Stopping backup message polling.');
        clearInterval(wsPollInterval);
        wsPollInterval = null;
      }
    }
    window.stopPollingFallback = stopPollingFallback;



    // Mobile layout setup - defined here but called AFTER chatBox is declared
    function setupMobileLayout() {
      if (isMobileViewport()) {
        if (!activeDM && !activeAdminConv && !isGlobalChat) {
          if (sidebar) sidebar.classList.add('open');
          if (backButton) backButton.style.display = 'none';
        } else {
          if (sidebar) sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
      } else {
        if (backButton) backButton.style.display = 'none';
      }
    }
    let lastChatifyWindowWidth = window.innerWidth;
    function handleResizeSetupMobileLayout() {
      if (window.innerWidth !== lastChatifyWindowWidth) {
        lastChatifyWindowWidth = window.innerWidth;
        setupMobileLayout();
      }
    }
    window.addEventListener('resize', handleResizeSetupMobileLayout);

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
        user.last_online_time = isOnline ? null : (wsLastOnlineTime.get(accId) || user.last_online_time || null);
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

        if (data.currentUser) {
          const freshOfficeId = (data.currentUser.office_id !== null && data.currentUser.office_id !== undefined)
            ? Number(data.currentUser.office_id)
            : 0;
          if (window.currentOfficeInfo) {
            const oldOfficeId = window.currentOfficeInfo.office_id ? Number(window.currentOfficeInfo.office_id) : 0;
            window.currentOfficeInfo.office_id = freshOfficeId > 0 ? freshOfficeId : null;
            if (freshOfficeId > 0) {
              window.currentOfficeInfo.conv_id = 'office_' + freshOfficeId;
              if (data.currentUser.office_name) window.currentOfficeInfo.office_name = data.currentUser.office_name;
              if (data.currentUser.office_code) window.currentOfficeInfo.office_code = data.currentUser.office_code;
              window.currentOfficeInfo.display_title = (data.currentUser.office_code || data.currentUser.office_name || 'Office Group Chat');
              const tEl = document.getElementById('officeGcTitle');
              if (tEl) tEl.textContent = window.currentOfficeInfo.display_title;
              const subEl = document.getElementById('officeGcLastMsg');
              if (subEl) subEl.textContent = data.currentUser.office_name || '';
            } else {
              window.currentOfficeInfo.conv_id = null;
              window.currentOfficeInfo.office_name = null;
              window.currentOfficeInfo.office_code = null;
              window.currentOfficeInfo.display_title = null;
            }
            if (oldOfficeId > 0 && freshOfficeId > 0 && oldOfficeId !== freshOfficeId && window.activeChannel === 'office') {
              if (typeof selectOfficeChat === 'function') selectOfficeChat();
            }
          }
          const ocItem = document.getElementById('officeChatItem');
          if (ocItem) {
            const isSpy = (typeof isAdminAllChatsView !== 'undefined') ? isAdminAllChatsView : false;
            ocItem.style.display = (freshOfficeId > 0 && !isSpy) ? '' : 'none';
          }
          if (freshOfficeId <= 0) {
            if (localStorage.getItem('activeDM') === '__office__') {
              localStorage.removeItem('activeDM');
            }
            if (window.activeChannel === 'office') {
              if (typeof closeOfficeMembersModal === 'function') closeOfficeMembersModal();
              selectGlobalChat();
            }
          }
          if (typeof updateOfficeInfoButtonVisibility === 'function') {
            updateOfficeInfoButtonVisibility();
          }
        }
      }

      // Online presence is WS-driven, but we preserve last_online_time from the DB
      // so users who went offline before the WS session display their actual active status.
      allUsersData.forEach(user => {
        delete user.is_currently_online;
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

      // Sort: newly-added custom GCs rise to the top, rest sorted by lastTimestamp
      if (Array.isArray(allUsersData) && allUsersData.length > 0) {
        // Sync localStorage openedGcIds with server truth:
        // - If server says is_newly_added=false → user has opened it before → add to localStorage (badge stays hidden)
        // - If server says is_newly_added=true  → user hasn't opened it yet (or was re-added) → REMOVE from localStorage (badge must show, even after refresh)
        try {
          const _openedKey = 'openedGcIds';
          const _storedRaw = localStorage.getItem(_openedKey);
          const _openedSet = new Set(_storedRaw ? JSON.parse(_storedRaw).map(Number) : []);
          let _changed = false;
          allUsersData.forEach(u => {
            if (!u.is_custom_gc) return;
            const gid = Number(u.gc_id);
            if (!u.is_newly_added) {
              // Server confirmed this GC was already opened — mark as opened locally
              if (!_openedSet.has(gid)) { _openedSet.add(gid); _changed = true; }
            } else {
              // Server says this is newly added (not yet opened) — remove from opened set
              // so the NEW badge always shows after refresh until the user clicks it
              if (_openedSet.has(gid)) { _openedSet.delete(gid); _changed = true; }
            }
          });
          if (_changed) localStorage.setItem(_openedKey, JSON.stringify([..._openedSet]));
        } catch (e) {}

        allUsersData.sort((a, b) => {
          const aTs = Number(a.lastTimestamp) || 0;
          const bTs = Number(b.lastTimestamp) || 0;
          if (bTs !== aTs) return bTs - aTs;
          const aNew = (a.is_custom_gc && (a.is_newly_added || (window.newlyAddedGcIds && window.newlyAddedGcIds.has(Number(a.gc_id))))) ? 1 : 0;
          const bNew = (b.is_custom_gc && (b.is_newly_added || (window.newlyAddedGcIds && window.newlyAddedGcIds.has(Number(b.gc_id))))) ? 1 : 0;
          if (aNew !== bNew) return bNew - aNew;
          return (a.full_name || a.name || '').localeCompare(b.full_name || b.name || '');
        });
      }

      renderSidebarUsers();
      if (typeof reloadOfficeMembersList === 'function') {
        reloadOfficeMembersList(true);
      }

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
        chatBox.innerHTML = '<div class="empty-chat"></div>';
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
          chatBox.innerHTML = '<div class="empty-chat"></div>';
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
      if (savedActiveDM === '__office__') {
        const hasOffice = !!(window.currentOfficeInfo && window.currentOfficeInfo.office_id && Number(window.currentOfficeInfo.office_id) > 0);
        if (hasOffice) {
          selectOfficeChat();
        } else {
          localStorage.removeItem('activeDM');
          selectGlobalChat();
        }
        return;
      }
      if (savedActiveDM && savedActiveDM.startsWith('__gc_') && savedActiveDM.endsWith('__')) {
        const gcId = Number(savedActiveDM.slice(5, -2));
        const matchedGc = allUsersData.find(u => u.is_custom_gc && Number(u.gc_id) === gcId);
        if (matchedGc) {
          selectCustomGc(matchedGc);
          return;
        }
      }

      const matchedUser = allUsersData.find(u => u.username === savedActiveDM) || null;
      if (matchedUser) {
        selectDM(matchedUser);
      } else {
        // The saved conversation partner no longer exists / isn't reachable
        // anymore — don't keep pointing at a chat we can't reopen.
        localStorage.removeItem('activeDM');
        chatBox.innerHTML = '<div class="empty-chat"></div>';
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
      if (!username || !data) return;
      let readUpToVal = (typeof data.readUpTo !== 'undefined' && data.readUpTo !== null)
        ? data.readUpTo
        : ((typeof dmReadUpToMap !== 'undefined' && dmReadUpToMap.has(username)) ? (dmReadUpToMap.get(username) || null) : null);
      if (typeof dmReadUpToMap !== 'undefined' && readUpToVal !== null) {
        dmReadUpToMap.set(username, readUpToVal);
      }
      if (typeof data === 'object' && data !== null && readUpToVal !== null) {
        data.readUpTo = readUpToVal;
      }
      dmMessageCache.delete(username); // re-insert at the end = most-recently-used
      dmMessageCache.set(username, {
        html: data.html || '',
        hasMore: data.hasMore || false,
        nextCursor: data.nextCursor || '',
        readUpTo: readUpToVal,
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
    function bumpSidebarUser(identifier, opts) {
      opts = opts || {};
      const idx = (allUsersData || []).findIndex(u =>
        u.username === identifier ||
        u.conv_id === identifier ||
        (u.is_custom_gc && ('gc_' + u.gc_id) === String(identifier)) ||
        (u.is_office_gc && ('office_' + u.office_id) === String(identifier)) ||
        (u.account_id && Number(u.account_id) === Number(identifier))
      );
      if (idx === -1) {
        if (typeof fetchUsers === 'function') fetchUsers();
        return false;
      }

      const u = allUsersData[idx];
      let isThisActive = false;
      if (u.is_office_gc) {
        isThisActive = (isGlobalChat && window.activeChannel === 'office' && !activeDM && !activeAdminConv);
      } else if (u.is_custom_gc) {
        isThisActive = (isGlobalChat && window.activeChannel === 'gc' && !activeDM && !activeAdminConv && window.activeCustomGc && (Number(window.activeCustomGc.gc_id) === Number(u.gc_id) || window.activeCustomGc.conv_id === u.conv_id));
      } else {
        isThisActive = ((activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) || (activeDM && u.username === activeDM));
      }

      if (isThisActive) {
        u.unreadCount = 0;
      } else if (opts.incrementUnread) {
        u.unreadCount = (u.unreadCount || 0) + 1;
      }
      if (opts.lastMessage !== undefined) {
        u.lastMessage = opts.lastMessage;
      }
      u.lastTimestamp = opts.lastTimestamp || Math.floor(Date.now() / 1000);

      if (idx > 0) {
        allUsersData.splice(idx, 1);
        allUsersData.unshift(u);
      }
      renderSidebarUsers();
      return true;
    }

    function renderSidebarUsers() {
      const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

      // Build a Set of GC IDs the user has already opened (persisted in localStorage)
      // so the NEW badge never reappears once clicked, even after fetchUsers() re-fetches.
      let _openedGcIdsSet = new Set();
      try {
        const _raw = localStorage.getItem('openedGcIds');
        if (_raw) _openedGcIdsSet = new Set(JSON.parse(_raw).map(Number));
      } catch (e) {}

      if (activeDM || activeDMAccountId) {
        const activeUser = (allUsersData || []).find(u =>
          (activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) ||
          (activeDM && u.username === activeDM)
        );
        if (activeUser) activeUser.unreadCount = 0;
      } else if (isGlobalChat) {
        if (window.activeChannel === 'office') {
          const activeOffice = (allUsersData || []).find(u => u.is_office_gc);
          if (activeOffice) activeOffice.unreadCount = 0;
        } else if (window.activeChannel === 'gc' && window.activeCustomGc) {
          const activeCustom = (allUsersData || []).find(u => u.is_custom_gc && (Number(u.gc_id) === Number(window.activeCustomGc.gc_id) || u.conv_id === window.activeCustomGc.conv_id));
          if (activeCustom) activeCustom.unreadCount = 0;
        }
      }

      latestTotalUnread = (allUsersData || []).reduce((sum, u) => {
        let isAct = false;
        if (u.is_office_gc) {
          isAct = (isGlobalChat && window.activeChannel === 'office' && !activeDM && !activeAdminConv);
        } else if (u.is_custom_gc) {
          isAct = (isGlobalChat && window.activeChannel === 'gc' && !activeDM && !activeAdminConv && window.activeCustomGc && (Number(window.activeCustomGc.gc_id) === Number(u.gc_id) || window.activeCustomGc.conv_id === u.conv_id));
        } else {
          isAct = (activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) || (activeDM && u.username === activeDM);
        }
        return sum + (isAct ? 0 : (u.unreadCount || 0));
      }, 0);
      updateTabTitle(latestTotalUnread);
      if (window.parent && window.parent !== window) {
        try {
          window.parent.postMessage({
            type: 'CHATIFY_REFRESH_BADGE',
            unread_count: latestTotalUnread
          }, '*');
        } catch (e) {}
      }

      if (query === '') {
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
        let isThisActive = false;
        if (u.is_office_gc) {
          isThisActive = (isGlobalChat && window.activeChannel === 'office' && !activeDM && !activeAdminConv);
        } else if (u.is_custom_gc) {
          isThisActive = (isGlobalChat && window.activeChannel === 'gc' && !activeDM && !activeAdminConv && window.activeCustomGc && (Number(window.activeCustomGc.gc_id) === Number(u.gc_id) || window.activeCustomGc.conv_id === u.conv_id));
        } else {
          isThisActive = (activeDMAccountId && Number(u.account_id) === Number(activeDMAccountId)) || (activeDM && u.username === activeDM);
        }

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
        }

        if (u.is_office_gc) {
          item.onclick = () => selectOfficeChat();
          item.onmouseenter = null;
          item.onpointerdown = null;
        } else if (u.is_custom_gc) {
          item.onclick = () => selectCustomGc(u);
          item.onmouseenter = null;
          item.onpointerdown = null;
        } else {
          item.onclick = () => selectDM(u);
          item.onmouseenter = () => speculateConversationCard(u, item);
          item.onpointerdown = () => speculateConversationCard(u, item);
        }

        const isNewlyAdded = u.is_custom_gc && !isThisActive && (u.is_newly_added || (window.newlyAddedGcIds && window.newlyAddedGcIds.has(Number(u.gc_id)))) && !_openedGcIdsSet.has(Number(u.gc_id));
        const newClassName = 'user-item' + (isThisActive ? ' active' : '') + (hasUnread ? ' has-unread' : '') + (isNewlyAdded ? ' newly-added-gc' : '') + (u.is_custom_gc ? ' custom-gc-item' : (u.is_office_gc ? ' office-gc-item' : ''));
        if (item.className !== newClassName) item.className = newClassName;

        if (u.is_office_gc || u.is_custom_gc) {
          if (dot) dot.style.display = 'none';
          if (avatar.dataset.avatarType !== 'gc') {
            avatar.innerHTML = `<img src="cspc.webp" width="48" height="48" alt="Group Chat" class="avatar-img" style="width:48px;height:48px;object-fit:cover;background:transparent;border-radius:50%;" draggable="false" ondragstart="return false;" oncontextmenu="return false;">`;
            avatar.dataset.avatarType = 'gc';
            avatar.dataset.initials = '';
            avatar.dataset.avatarUrl = '';
          }
        } else {
          if (dot) dot.style.display = '';
          if (avatar.dataset.avatarType === 'gc' || avatar.dataset.initials !== u.name || avatar.dataset.avatarUrl !== (u.avatar_url || '')) {
            avatar.dataset.avatarType = 'dm';
            const initials = getInitials(u.name);
            avatar.innerHTML = avatarInnerHtml(u.avatar_url, initials);
            if (dot && !avatar.contains(dot)) avatar.appendChild(dot);
            avatar.dataset.initials = u.name;
            avatar.dataset.avatarUrl = u.avatar_url || '';
          }
          if (hasReceivedPresenceSnapshot) {
            const isWsOnline = onlineAccountsSet.has(Number(u.account_id));
            u.status = isWsOnline ? 'online' : 'offline';
            u.is_currently_online = isWsOnline;
          }
          const effectiveStatus = hasReceivedPresenceSnapshot ? u.status : 'offline';
          const newDotClass = 'status-dot ' + (effectiveStatus || 'offline');
          if (dot && dot.className !== newDotClass) dot.className = newDotClass;
        }

        if (nameEl.textContent !== u.name) nameEl.textContent = u.name;

        if (activeDM === u.username && chatHeaderTitle.textContent !== u.name) {
          chatHeaderTitle.textContent = u.name;
          applyHeaderAdminBadge();
        }
        if (activeDM === u.username) {
          applyHeaderAvatar(u);
        }

        const vSet = (typeof verifiedAccountIds !== 'undefined' && verifiedAccountIds) ? verifiedAccountIds : window.verifiedAccountIdsSet;
        const targetIsVerified = u.is_office_gc || u.is_custom_gc || (vSet && vSet.has(Number(u.account_id))) || Boolean(u.is_chatify_verified);

        const sidebarBadge = nameRow.querySelector('.verified-badge');
        if (targetIsVerified) {
          if (!sidebarBadge) injectBadge(nameRow);
        } else if (sidebarBadge) {
          sidebarBadge.remove();
        }

        // "NEW" badge for newly added Custom GCs
        const existingNewBadge = nameRow.querySelector('.newly-added-badge');
        if (isNewlyAdded) {
          if (!existingNewBadge) {
            const newBadge = document.createElement('span');
            newBadge.className = 'newly-added-badge';
            newBadge.textContent = 'NEW';
            nameRow.appendChild(newBadge);
          }
        } else if (existingNewBadge) {
          existingNewBadge.remove();
        }

        if (officeEl) {
          let newOffice = '';
          if (u.is_custom_gc) {
            newOffice = 'Custom GC';
          } else if (u.is_office_gc) {
            newOffice = 'OFFICE GC';
          } else {
            newOffice = u.office_name ? u.office_name : 'No office assigned';
          }
          if (officeEl.textContent !== newOffice) {
            officeEl.textContent = newOffice;
          }
          if (u.is_custom_gc || u.is_office_gc || u.office_name) {
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

          if (u.is_custom_gc || u.is_office_gc) {
            lastMsgEl.textContent = '';
            lastMsgEl.style.display = 'none';
          } else {
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
          const _gcPats = ['added to the group', 'removed from the group', 'disbanded by the admin', 'has been disbanded', 'admin of the group'];
          list.forEach(function(n) {
            if (_gcPats.some(p => (n.message || '').toLowerCase().includes(p))) {
              n.is_gc_event = true;
            }
          });
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
        // Primary path: WebSocket pushes 'notify' events in real-time.
        // Only run HTTP catchup if WebSocket is disconnected.
        const wsAlive = ws && ws.readyState === WebSocket.OPEN;
        if (!wsAlive) {
          catchUpMissedNotifications();
        }
      }, 15000);
    }
    window.startNotificationPoll = startNotificationPoll;

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
      notifyContentTitle.textContent = n.is_gc_event ? 'Group Chat' : 'Mention';
      if (notifyContentSender) {
        notifyContentSender.textContent = n.sender ? (n.is_gc_event ? n.sender : (n.sender + ' mentioned you')) : '';
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
        notificationBellBadge.textContent = count > 99 ? '99+' : String(count);
        notificationBellBadge.style.display = 'flex';
      } else {
        notificationBellBadge.style.display = 'none';
      }
    }

    function renderBellList() {
      if (!notificationBellList) return;
      const markAllBtn = document.getElementById('markAllNotifReadBtn');
      const hasNotifications = bellNotifications && bellNotifications.length > 0;

      if (markAllBtn) {
        markAllBtn.disabled = !hasNotifications;
      }

      if (!hasNotifications) {
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
                 '<div class="bell-item-sender">' + escapeHtml(n.sender || 'A user') + (n.is_gc_event ? '' : ' mentioned you') + '</div>' +
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

    function markAllNotificationsRead() {
      if (bellNotifications.length === 0) {
        closeNotificationBellModal();
        return;
      }
      const btn = document.getElementById('markAllNotifReadBtn');
      if (btn) { btn.disabled = true; btn.textContent = 'Clearing…'; }

      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'mark_all_mentions_seen.php', true);
      xhr.onload = function() {
        // Clear local cache and update UI regardless of response
        bellNotifications = [];
        updateBellBadge();
        renderBellList();
        closeNotificationBellModal();
        if (btn) { btn.disabled = (bellNotifications.length === 0); btn.textContent = 'Mark All as Read'; }
      };
      xhr.onerror = function() {
        if (btn) { btn.disabled = (bellNotifications.length === 0); btn.textContent = 'Mark All as Read'; }
      };
      xhr.send();
    }
    window.markAllNotificationsRead = markAllNotificationsRead;

    if (notificationBellBtn) {
      notificationBellBtn.addEventListener('click', openNotificationBellModal);
    }
    if (notificationBellClose) {
      notificationBellClose.addEventListener('click', closeNotificationBellModal);
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

    // ── Auto-linkify URLs inside message text ──
    const URL_REGEX = /((?:https?:\/\/|www\.)[^\s<]+)/gi;

    function linkifyContent(contentEl) {
      if (!contentEl || contentEl.dataset.linkified === '1') return;
      contentEl.dataset.linkified = '1';

      const walker = document.createTreeWalker(contentEl, NodeFilter.SHOW_TEXT, null);
      const textNodes = [];
      let node;
      while ((node = walker.nextNode())) {
        if (node.parentElement && node.parentElement.closest('a')) continue;
        textNodes.push(node);
      }

      textNodes.forEach(function(textNode) {
        const text = textNode.nodeValue;
        URL_REGEX.lastIndex = 0;
        if (!URL_REGEX.test(text)) return;
        URL_REGEX.lastIndex = 0;

        const frag = document.createDocumentFragment();
        let lastIndex = 0;
        let match;
        while ((match = URL_REGEX.exec(text)) !== null) {
          let url = match[0];
          const trailingPunct = /[.,:;!?'")\]}]+$/;
          const trimmedMatch = trailingPunct.exec(url);
          let trailing = '';
          if (trimmedMatch) {
            trailing = trimmedMatch[0];
            url = url.slice(0, url.length - trailing.length);
          }
          if (!url) continue;

          const start = match.index;
          frag.appendChild(document.createTextNode(text.slice(lastIndex, start)));

          const a = document.createElement('a');
          a.href = /^https?:\/\//i.test(url) ? url : 'https://' + url;
          a.textContent = url;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.className = 'chat-link';
          frag.appendChild(a);

          if (trailing) {
            frag.appendChild(document.createTextNode(trailing));
          }
          lastIndex = start + match[0].length;
        }

        if (lastIndex < text.length) {
          frag.appendChild(document.createTextNode(text.slice(lastIndex)));
        }

        if (textNode.parentNode) {
          textNode.parentNode.replaceChild(frag, textNode);
        }
      });
    }
    window.linkifyContent = linkifyContent;

    // ── Modal shown when tapping "Read more..." on a long chat message ──
    // Renders the complete message (with clickable links) — used for both
    // Global Chat and Private (DM) chat, since both feed the same bubbles.
    function openReadMoreModal(fullText) {
      if (!readMoreModal || !readMoreModalBody) return;
      const prevST = chatBox ? chatBox.scrollTop : null;
      readMoreModalBody.textContent = fullText || '';
      delete readMoreModalBody.dataset.linkified; // allow re-linkifying on every open
      linkifyContent(readMoreModalBody);
      readMoreModal.classList.add('active');
      readMoreModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (chatBox && prevST !== null && Math.abs(chatBox.scrollTop - prevST) > 0) {
        chatBox.scrollTop = prevST;
      }
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
        e.preventDefault();
        e.stopPropagation();
        const savedST = chatBox ? chatBox.scrollTop : null;
        openReadMoreModal(contentEl.dataset.fullText || '');
        if (chatBox && savedST !== null && Math.abs(chatBox.scrollTop - savedST) > 0) {
          chatBox.scrollTop = savedST;
        }
      });
      link.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          e.stopPropagation();
          const savedST = chatBox ? chatBox.scrollTop : null;
          openReadMoreModal(contentEl.dataset.fullText || '');
          if (chatBox && savedST !== null && Math.abs(chatBox.scrollTop - savedST) > 0) {
            chatBox.scrollTop = savedST;
          }
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
        return `<img src="${escapeHtml(avatarUrl)}" class="avatar-img" alt="" loading="eager" referrerpolicy="no-referrer" draggable="false" ondragstart="return false;">`;
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
    let lastMarkedReadUser = null;
    let lastMarkedReadMsgId = null;

    function markRead(targetUsername, explicitMsgId = null) {
      if (!targetUsername) return;
      const isGc = typeof targetUsername === 'string' && (targetUsername.startsWith('gc_') || targetUsername.startsWith('office_'));
      const u = allUsersData.find(x =>
        x.username === targetUsername ||
        x.conv_id === targetUsername ||
        (isGc && x.is_custom_gc && ('gc_' + x.gc_id) === targetUsername) ||
        (isGc && x.is_office_gc && ('office_' + x.office_id) === targetUsername) ||
        (activeDMAccountId && Number(x.account_id) === activeDMAccountId)
      );
      if (u) u.unreadCount = 0;
      if (activeDM === targetUsername || (activeDMAccountId && u && Number(u.account_id) === activeDMAccountId)) {
        const activeU = allUsersData.find(x => x.username === activeDM || (activeDMAccountId && Number(x.account_id) === activeDMAccountId));
        if (activeU) activeU.unreadCount = 0;
      }
      renderSidebarUsers();

      // Handle GC read marking via conv_id
      if (isGc || (u && (u.is_custom_gc || u.is_office_gc))) {
        const gcConvId = (u && u.conv_id) ? u.conv_id : targetUsername;
        if (markReadHttpDebounceTimer) clearTimeout(markReadHttpDebounceTimer);
        markReadHttpDebounceTimer = setTimeout(() => {
          markReadHttpDebounceTimer = null;
          const xhr = new XMLHttpRequest();
          xhr.open('POST', 'mark_read.php', true);
          xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
          xhr.send('conv_id=' + encodeURIComponent(gcConvId));
        }, 50);
        return;
      }

      const targetId = activeDMAccountId || (u ? Number(u.account_id) : 0);
      if (!targetId) return;

      // Resolve newest message ID currently present in chatBox (last in DOM order)
      let newestMsgId = explicitMsgId || null;
      if (!newestMsgId && chatBox) {
        const containers = chatBox.querySelectorAll('.message-container[data-msg-id]');
        if (containers.length > 0) {
          newestMsgId = containers[containers.length - 1].getAttribute('data-msg-id');
        }
      }

      // If we already marked read up through this exact message for this user, suppress redundant network pings
      if (lastMarkedReadUser === targetUsername && lastMarkedReadMsgId === newestMsgId && newestMsgId !== null) {
        return;
      }
      lastMarkedReadUser = targetUsername;
      lastMarkedReadMsgId = newestMsgId;

      // Real-time WebSocket relay: fire immediately with last_msg_uuid
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
      }, 50);
    }

    // Messenger-style "Seen" indicator: shown under the newest message WE sent
    // that the other participant has actually read (dmReadUpTo).
    function updateSeenIndicator() {
      if (!chatBox) return;

      // Resolve the effective readUpTo: use dmReadUpTo if set, otherwise check
      // dmReadUpToMap directly.
      let effectiveReadUpTo = dmReadUpTo;
      if (!effectiveReadUpTo && activeDM && typeof dmReadUpToMap !== 'undefined' && dmReadUpToMap.has(activeDM)) {
        effectiveReadUpTo = dmReadUpToMap.get(activeDM);
      }

      const existing = chatBox.querySelector('.seen-indicator');

      if (!effectiveReadUpTo || !activeDM || isGlobalChat) {
        if (existing) {
          const prevST = chatBox.scrollTop;
          const prevSH = chatBox.scrollHeight;
          existing.remove();
          if (userScrolledUp) {
            const diff = chatBox.scrollHeight - prevSH;
            if (diff !== 0) chatBox.scrollTop = prevST + diff;
          }
        }
        return;
      }

      const allMessages = Array.from(chatBox.querySelectorAll('.message-container[data-msg-id]'));
      if (allMessages.length === 0) {
        if (existing) existing.remove();
        return;
      }

      const cleanReadUpTo = String(effectiveReadUpTo).trim().toLowerCase();

      // Find the index of the read marker message in DOM order (strictly chronological)
      let readMarkerIndex = -1;
      for (let i = 0; i < allMessages.length; i++) {
        const msgId = allMessages[i].getAttribute('data-msg-id');
        if (msgId && String(msgId).trim().toLowerCase() === cleanReadUpTo) {
          readMarkerIndex = i;
          break;
        }
      }

      let target = null;
      if (readMarkerIndex !== -1) {
        // Target is the last SENT message at or before readMarkerIndex
        for (let i = readMarkerIndex; i >= 0; i--) {
          if (allMessages[i].classList.contains('sent')) {
            target = allMessages[i];
            break;
          }
        }
      }

      if (!target) {
        const hasPendingSend = chatBox.querySelector('.sending-bubble, [data-sending-uid]') ||
          (document.getElementById('sending-overlay-container') && document.getElementById('sending-overlay-container').querySelector('.sending-bubble, [data-sending-uid]'));
        if (!hasPendingSend && existing) {
          const prevST = chatBox.scrollTop;
          const prevSH = chatBox.scrollHeight;
          existing.remove();
          if (userScrolledUp) {
            const diff = chatBox.scrollHeight - prevSH;
            if (diff !== 0) chatBox.scrollTop = prevST + diff;
          }
        }
        return;
      }

      // If existing indicator is already immediately after target, do nothing (no DOM mutation, no jump!)
      if (existing && target.nextElementSibling === existing) {
        return;
      }

      const preScrollTop    = chatBox.scrollTop;
      const preScrollHeight = chatBox.scrollHeight;
      const wasNearBottom   = !userScrolledUp && ((preScrollHeight - preScrollTop - chatBox.clientHeight) <= 40);

      const indicator = existing || document.createElement('div');
      indicator.className = 'seen-indicator';
      indicator.innerHTML = '<span class="seen-indicator-text">seen</span>';
      target.insertAdjacentElement('afterend', indicator);

      // Only adjust scroll if NOT on initial load (handleFirstLoadScroll handles initial load)
      if (!isFirstLoad) {
        if (wasNearBottom) {
          chatBox.scrollTop = chatBox.scrollHeight;
        } else if (userScrolledUp) {
          const heightDiff = chatBox.scrollHeight - preScrollHeight;
          if (heightDiff !== 0) {
            chatBox.scrollTop = preScrollTop + heightDiff;
          }
        }
      }
    }


    // State for global chat
    let isGlobalChat = false;
    let globalChatCache = null; // { html, hasMore, nextCursor, _raw }

    function cacheGlobalChatSnapshot(data) {
      if (!data) return;
      globalChatCache = {
        html: data.html || '',
        hasMore: data.hasMore || false,
        nextCursor: data.nextCursor || '',
        _raw: data
      };
    }
    // ── Infinite-scroll window constants ─────────────────────────────────────
    // INITIAL_LOAD   — messages fetched when first opening a conversation.
    // BACKREAD_BATCH — messages fetched per auto-triggered scroll-up fetch.
    // MAX_WINDOW     — maximum messages kept in the DOM at once (performance
    //                  guard, NOT a history limit). While backreading, older
    //                  pages are prepended and the same count is trimmed from
    //                  the bottom so the browser never holds more than this
    //                  many nodes simultaneously. "Go to bottom" snaps back
    //                  to a fresh INITIAL_LOAD-sized window from the DB.
    //                  Raised from 300 → 600 for deeper backread without a
    //                  DOM trip to the server.
    const INITIAL_LOAD   = 100;
    const BACKREAD_BATCH = 50;
    const MAX_WINDOW     = 600;  // ~100 initial + 10 backreads; balanced for mid-range Android
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
    const dmReadUpToMap = new Map(); // username -> msg_uuid
    window.dmReadUpToMap = dmReadUpToMap;

    // Spamming clicks across the conversation list used to make the chat
    // pane visibly jump: every single click synchronously swapped chatBox's
    // whole innerHTML (from the cache) and snapped/restored scroll position,
    // so a burst of clicks played back as a rapid series of scroll jumps
    // before finally landing on the last-clicked conversation. Instead, the
    // heavy part (painting messages + loadChat + scroll) is debounced to
    // only run once the clicks settle, while the row highlight updates
    // instantly on every click and the chat pane just gives a quick "blink"
    let selectDMDebounceTimer = null;
    let pendingSelectUser = null;

    function selectDM(u) {
      if (!u) return;
      if (!isGlobalChat && !activeAdminConv && activeDM === u.username) {
        if (isMobileViewport()) {
          sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
        return;
      }
      if (selectDMDebounceTimer) {
        clearTimeout(selectDMDebounceTimer);
        selectDMDebounceTimer = null;
      }
      performSelectDM(u);
    }

    function performSelectDM(u) {
      if (typeof cancelActiveScrollAnimation === 'function') cancelActiveScrollAnimation();
      if (typeof clearFirstLoadScrollTimers === 'function') clearFirstLoadScrollTimers();

      isGlobalChat = false;
      activeChannel = null;
      window.activeChannel = null;
      window.activeCustomGc = null;
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
      updateOfficeInfoButtonVisibility();
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
      
      const cached = dmMessageCache.get(u.username);
      chatBox.innerHTML = '';
      chatBox.scrollTop = 0;
      removePaginationBtn();
      window.replyJumpActive = false;
      hideScrollIndicator(true);
      const _htp = document.getElementById('headerTypingPreview');
      if (_htp) { _htp.textContent = ''; _htp.classList.remove('active'); }

      dmCursor = (cached && cached.nextCursor) ? cached.nextCursor : '';
      dmHasMore = (cached && cached.hasMore) ? cached.hasMore : false;
      dmReadUpTo = (typeof dmReadUpToMap !== 'undefined' && dmReadUpToMap.has(u.username) && dmReadUpToMap.get(u.username))
        ? dmReadUpToMap.get(u.username)
        : ((cached && typeof cached.readUpTo !== 'undefined' && cached.readUpTo) ? cached.readUpTo : null);
      if (dmReadUpTo && cached && cached._raw) {
        cached._raw.readUpTo = dmReadUpTo;
      }
      isFirstLoad = true; // snap straight to bottom once the new conversation's messages arrive
      chatFullyLoaded = false; // suppress scroll buttons until new chat finishes loading
      dmViewingOlder = false;
      u.unreadCount = 0;
      renderSidebarUsers();
      loadChat(false, false, true); // force: abort any in-flight request rather than drop this one
      // Channel items deactivate
      document.getElementById('globalChatItem')?.classList.remove('active');
      document.getElementById('officeChatItem')?.classList.remove('active');
      document.querySelectorAll('#customGcSection .user-item').forEach(el => el.classList.remove('active'));
      
      // Mobile/Tablet: hide sidebar when chat is selected
      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        if (backButton) backButton.style.display = 'inline-flex';
      } else {
        // Desktop only: auto-focus the message input so the cursor is ready
        // without an extra click. preventScroll prevents the browser from shifting scrollable parents.
        setTimeout(() => messageInput.focus({ preventScroll: true }), 0);
      }
    }

    let activeChannel = 'global'; // 'global' | 'office' | 'gc' | null
    window.activeChannel = activeChannel;
    window.activeCustomGc = null;

    function getActiveChannelConvId() {
      if (window.activeChannel === 'gc' && window.activeCustomGc && window.activeCustomGc.conv_id) {
        return window.activeCustomGc.conv_id;
      }
      if (window.activeChannel === 'office' && window.currentOfficeInfo && window.currentOfficeInfo.conv_id) {
        return window.currentOfficeInfo.conv_id;
      }
      return 'global';
    }
    window.getActiveChannelConvId = getActiveChannelConvId;

    function selectGlobalChat() {
      if (isGlobalChat && window.activeChannel === 'global' && !activeDM && !activeAdminConv) {
        if (isMobileViewport()) {
          sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
        return;
      }

      if (typeof cancelActiveScrollAnimation === 'function') cancelActiveScrollAnimation();
      if (typeof clearFirstLoadScrollTimers === 'function') clearFirstLoadScrollTimers();

      isGlobalChat = true;
      activeChannel = 'global';
      window.activeChannel = 'global';
      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.add('is-global-chat');
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();
      gcCursor = '';
      gcHasMore = false;
      gcViewingOlder = false;
      clearSendingOverlay();
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      updateHeaderActiveStatus();
      isFirstLoad = true;
      chatFullyLoaded = false;
      window.replyJumpActive = false;
      hideScrollIndicator(true);
      const _htpGc = document.getElementById('headerTypingPreview');
      if (_htpGc) { _htpGc.textContent = ''; _htpGc.classList.remove('active'); }
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
      chatHeaderTitle.innerHTML = 'Global Chat';
      applyHeaderAdminBadge();
      applyHeaderAvatar({ avatar_url: 'cspc.webp', name: 'Global Chat' });
      chatBox.innerHTML = '';

      removePaginationBtn();
      renderSidebarUsers();
      document.getElementById('globalChatItem')?.classList.add('active');
      document.getElementById('officeChatItem')?.classList.remove('active');
      window.activeCustomGc = null;
      document.querySelectorAll('#customGcSection .user-item').forEach(el => el.classList.remove('active'));

      loadGlobalChat();
      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        if (backButton) backButton.style.display = 'inline-flex';
      } else {
        setTimeout(() => messageInput.focus({ preventScroll: true }), 0);
      }
    }
    window.selectGlobalChat = selectGlobalChat;

    // ── Office GC unread badge helpers ────────────────────────────────────────
    let _officeGcUnread = 0;

    function updateOfficeGcBadge() {}
    window.updateOfficeGcBadge = updateOfficeGcBadge;

    function updateCustomGcBadge() {}
    window.updateCustomGcBadge = updateCustomGcBadge;
    // ── End Office GC unread badge helpers ────────────────────────────────────

    function selectOfficeChat() {
      const hasOffice = !!(window.currentOfficeInfo && window.currentOfficeInfo.office_id && Number(window.currentOfficeInfo.office_id) > 0);
      if (!hasOffice) {
        localStorage.removeItem('activeDM');
        const oc = document.getElementById('officeChatItem');
        if (oc) oc.style.display = 'none';
        if (typeof updateOfficeInfoButtonVisibility === 'function') updateOfficeInfoButtonVisibility();
        selectGlobalChat();
        return;
      }

      if (isGlobalChat && window.activeChannel === 'office' && !activeDM && !activeAdminConv) {
        if (isMobileViewport()) {
          sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
        return;
      }

      if (typeof cancelActiveScrollAnimation === 'function') cancelActiveScrollAnimation();
      if (typeof clearFirstLoadScrollTimers === 'function') clearFirstLoadScrollTimers();

      isGlobalChat = true;
      activeChannel = 'office';
      window.activeChannel = 'office';
      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.add('is-global-chat');
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();
      gcCursor = '';
      gcHasMore = false;
      gcViewingOlder = false;
      clearSendingOverlay();
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      updateHeaderActiveStatus();
      isFirstLoad = true;
      chatFullyLoaded = false;
      window.replyJumpActive = false;
      hideScrollIndicator(true);
      const _htpGc = document.getElementById('headerTypingPreview');
      if (_htpGc) { _htpGc.textContent = ''; _htpGc.classList.remove('active'); }
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

      localStorage.setItem('activeDM', '__office__');
      const officeTitle = (window.currentOfficeInfo && window.currentOfficeInfo.display_title)
        ? window.currentOfficeInfo.display_title
        : 'Office Group Chat';
      chatHeaderTitle.innerHTML = officeTitle;
      applyHeaderAdminBadge();
      applyHeaderAvatar({ avatar_url: 'cspc.webp', name: officeTitle });
      chatBox.innerHTML = '';

      removePaginationBtn();
      renderSidebarUsers();
      document.getElementById('officeChatItem')?.classList.add('active');
      document.getElementById('globalChatItem')?.classList.remove('active');
      window.activeCustomGc = null;
      document.querySelectorAll('#customGcSection .user-item').forEach(el => el.classList.remove('active'));

      loadGlobalChat();
      if (window.currentOfficeInfo && window.currentOfficeInfo.conv_id) {
        markRead(window.currentOfficeInfo.conv_id);
      }
      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        if (backButton) backButton.style.display = 'inline-flex';
      } else {
        setTimeout(() => messageInput.focus({ preventScroll: true }), 0);
      }
    }
    window.selectOfficeChat = selectOfficeChat;

    function selectCustomGc(group) {
      if (!group) return;

      if (isGlobalChat && window.activeChannel === 'gc' && window.activeCustomGc && window.activeCustomGc.gc_id === group.gc_id && !activeDM && !activeAdminConv) {
        if (isMobileViewport()) {
          sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
        return;
      }

      if (typeof cancelActiveScrollAnimation === 'function') cancelActiveScrollAnimation();
      if (typeof clearFirstLoadScrollTimers === 'function') clearFirstLoadScrollTimers();

      isGlobalChat = true;
      activeChannel = 'gc';
      window.activeChannel = 'gc';
      window.activeCustomGc = group;
      window.activeCustomGcGroupInfo = null;
      window.activeCustomGcMembers = null;

      // Clear the "newly added" highlight — persist to DB so it survives refreshes
      const _openedGcId = Number(group.gc_id);
      if (window.newlyAddedGcIds) {
        window.newlyAddedGcIds.delete(_openedGcId);
      }
      if (group.is_newly_added) {
        group.is_newly_added = false;
        // Fire-and-forget: tell the server this user has seen the NEW notification
        try {
          const _fd = new FormData();
          _fd.append('group_id', _openedGcId);
          fetch('mark_gc_seen.php', { method: 'POST', body: _fd }).catch(() => {});
        } catch (e) {}
      }
      // Also update localStorage immediately so the badge disappears before the
      // server round-trip completes (avoids any flash of the badge on next render)
      try {
        const _openedKey = 'openedGcIds';
        const _storedRaw = localStorage.getItem(_openedKey);
        const _openedSet = new Set(_storedRaw ? JSON.parse(_storedRaw).map(Number) : []);
        _openedSet.add(_openedGcId);
        localStorage.setItem(_openedKey, JSON.stringify([..._openedSet]));
      } catch (e) {}
      // Also clear it in allUsersData in-memory (or add if brand new)
      if (Array.isArray(allUsersData)) {
        const _gcIdx = allUsersData.findIndex(u => u.is_custom_gc && Number(u.gc_id) === _openedGcId);
        if (_gcIdx !== -1) {
          allUsersData[_gcIdx].is_newly_added = false;
        } else {
          allUsersData.unshift({
            is_custom_gc: true,
            gc_id: Number(group.gc_id),
            conv_id: group.conv_id || ('gc_' + group.gc_id),
            name: group.name,
            full_name: group.name,
            username: 'gc_' + group.gc_id,
            creator_id: Number(group.creator_id || 0),
            member_count: Number(group.member_count || 1),
            lastMessage: group.lastMessage || '',
            lastTimestamp: Number(group.lastTimestamp) || Math.floor(Date.now() / 1000),
            unreadCount: 0,
            is_newly_added: false,
            is_chatify_verified: true,
            avatar_url: 'cspc.webp'
          });
        }
      }

      const _hEl = document.querySelector('.header');
      if (_hEl) _hEl.classList.add('is-global-chat');

      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;

      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();

      gcCursor = '';
      gcHasMore = false;
      gcViewingOlder = false;
      clearSendingOverlay();
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      updateHeaderActiveStatus();
      isFirstLoad = true;
      chatFullyLoaded = false;
      window.replyJumpActive = false;
      hideScrollIndicator(true);

      const _htpGc = document.getElementById('headerTypingPreview');
      if (_htpGc) { _htpGc.textContent = ''; _htpGc.classList.remove('active'); }
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

      localStorage.setItem('activeDM', '__gc_' + group.gc_id + '__');
      chatHeaderTitle.innerHTML = escapeHtml(group.name);
      applyHeaderAdminBadge();
      applyHeaderAvatar({ avatar_url: 'cspc.webp', name: group.name });
      chatBox.innerHTML = '';

      removePaginationBtn();
      renderSidebarUsers();

      document.getElementById('globalChatItem')?.classList.remove('active');
      document.getElementById('officeChatItem')?.classList.remove('active');
      const allGcItems = document.querySelectorAll('#customGcSection .user-item');
      allGcItems.forEach(el => {
        if (el.dataset.gcId == group.gc_id) {
          el.classList.add('active');
        } else {
          el.classList.remove('active');
        }
      });

      loadGlobalChat();
      const gcConv = group.conv_id || ('gc_' + group.gc_id);
      markRead(gcConv);
      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        if (backButton) backButton.style.display = 'inline-flex';
      } else {
        setTimeout(() => messageInput.focus({ preventScroll: true }), 0);
      }
    }
    window.selectCustomGc = selectCustomGc;

    function loadCustomGcs(filterQuery) {
      if (typeof fetchUsers === 'function') {
        fetchUsers(filterQuery);
      }
    }
    window.loadCustomGcs = loadCustomGcs;

    function removePaginationBtn() {
      const existing = document.getElementById('loadOlderBtn');
      if (existing) existing.remove();
      const notice = document.getElementById('noMoreOlderNotice');
      if (notice) notice.remove();
    }

    function showNoMoreOlderNotice() {
      removePaginationBtn();
      if (document.getElementById('noMoreOlderNotice')) return; // already showing
      const notice = document.createElement('div');
      notice.id = 'noMoreOlderNotice';
      notice.style.cssText = `
        display:flex;align-items:center;justify-content:center;gap:8px;
        text-align:center;width:calc(100% - 32px);margin:10px 16px;
        padding:8px 16px;color:var(--text-secondary);font-size:12px;font-weight:500;
        opacity:0.7;
      `;
      // Thin horizontal rule on each side of the label — Messenger/Discord style
      notice.innerHTML =
        '<span style="flex:1;height:1px;background:var(--border-color,rgba(0,0,0,0.1));display:block;"></span>' +
        '<span>Beginning of conversation</span>' +
        '<span style="flex:1;height:1px;background:var(--border-color,rgba(0,0,0,0.1));display:block;"></span>';
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
      const scrollTop = chatBox.scrollTop;
      const prevScrollHeight = chatBox.scrollHeight;
      const messages = Array.from(chatBox.querySelectorAll('.message-container'));
      if (messages.length === 0) {
        return { el: null, msgId: null, prevOffsetTop: 0, offsetFromTop: 0, prevScrollTop: scrollTop, prevScrollHeight: prevScrollHeight };
      }

      // Find the first message whose bottom edge is at or below the current viewport top
      let anchorEl = null;
      for (let i = 0; i < messages.length; i++) {
        const el = messages[i];
        if ((el.offsetTop + el.offsetHeight) >= scrollTop + 2) {
          anchorEl = el;
          break;
        }
      }
      if (!anchorEl) anchorEl = messages[0];

      return {
        el: anchorEl,
        msgId: anchorEl.getAttribute('data-msg-id'),
        prevOffsetTop: anchorEl.offsetTop,
        offsetFromTop: anchorEl.offsetTop - scrollTop,
        prevScrollTop: scrollTop,
        prevScrollHeight: prevScrollHeight
      };
    }

    // Restores scroll position so anchorEl remains at the exact pixel offset.
    // Uses DOM offsetTop and scrollHeight in pure layout pixels (completely immune
    // to CSS zoom, DPI scaling, and transform differences), preventing viewport jumping.
    function restoreScrollAnchor(anchorInfo, prependedItems) {
      if (!chatBox || !anchorInfo) return;

      let restored = false;
      const anchorEl = (anchorInfo.el && anchorInfo.el.parentNode) ? anchorInfo.el : (
        anchorInfo.msgId ? chatBox.querySelector(`.message-container[data-msg-id="${anchorInfo.msgId}"]`) : null
      );

      // Preferred: Pin to the exact anchor element using DOM offsetTop
      if (anchorEl && typeof anchorInfo.offsetFromTop === 'number') {
        const targetScrollTop = anchorEl.offsetTop - anchorInfo.offsetFromTop;
        if (targetScrollTop >= 0) {
          chatBox.scrollTop = targetScrollTop;
          restored = true;
        }
      }

      // Fallback: Use exact scrollHeight difference
      if (!restored && typeof anchorInfo.prevScrollHeight === 'number') {
        const heightDiff = chatBox.scrollHeight - anchorInfo.prevScrollHeight;
        if (heightDiff > 0) {
          chatBox.scrollTop = Math.max(0, anchorInfo.prevScrollTop + heightDiff);
          restored = true;
        }
      }

      // Register prepended images with ResizeObserver so any late image load above
      // the visible viewport adjusts scrollTop smoothly without duplicate callbacks.
      // Guard: only observe images whose parent element is actually connected to the
      // DOM — filtered-out duplicate items are never inserted, so their images would
      // generate phantom delta adjustments (detached el.getBoundingClientRect().bottom
      // is 0, which always passes the "above viewport" test and falsely bumps scrollTop).
      if (prependedItems && prependedItems.length > 0 && typeof scrollAnchorObserver !== 'undefined' && scrollAnchorObserver) {
        prependedItems.forEach(item => {
          if (!item.querySelectorAll || !item.isConnected) return;
          item.querySelectorAll('img:not(.avatar-img)').forEach(img => {
            if (!img.dataset.scrollListener) {
              img.dataset.scrollListener = '1';
              scrollAnchorObserver.observe(img);
            }
          });
        });
      }
    }

    window.captureScrollAnchor = captureScrollAnchor;
    window.restoreScrollAnchor = restoreScrollAnchor;

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
    let adminSpyChannels = [];        // array of channel search result objects (Office GCs & Global Chat)
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
      messageInput.focus({ preventScroll: true });
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
      if (activeDM) {
        document.querySelectorAll('#customGcSection .user-item').forEach(el => el.classList.remove('active'));
      }

      if (query === '') {
        mentionSearchResults.innerHTML = '';
        return;
      }
      renderMentionResultState('searching');
      const seq = ++mentionSearchSeq;
      const targetConvId = (typeof getActiveChannelConvId === 'function')
        ? getActiveChannelConvId()
        : (window.activeChannel === 'office' ? (window.currentOfficeInfo && window.currentOfficeInfo.conv_id) : 'global');
      let url = 'mention_search.php?q=' + encodeURIComponent(query);
      if (targetConvId) {
        url += '&conv_id=' + encodeURIComponent(targetConvId);
      }
      if (window.activeChannel === 'office' && window.currentOfficeInfo && window.currentOfficeInfo.office_id) {
        url += '&office_id=' + encodeURIComponent(window.currentOfficeInfo.office_id);
      }
      const xhr = new XMLHttpRequest();
      xhr.open('GET', url, true);
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
        const descEl = document.getElementById('mentionModalDesc') || mentionModal.querySelector('.modal-body > p');
        if (descEl) {
          if (window.activeChannel === 'office') {
            const officeName = (window.currentOfficeInfo && (window.currentOfficeInfo.office_code || window.currentOfficeInfo.display_title)) || 'Office GC';
            descEl.textContent = 'Search for a member in ' + officeName + ' to mention.';
          } else {
            descEl.textContent = 'Search for a user to mention in Global Chat.';
          }
        }
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
        adminSpyChannels = [];
        adminSpyUsers = [];
        adminSpyConvs = [];
        adminSpyHasMore = false;
        adminSpyOffset = 0;
        adminSpyTargetUser = null;
        window.adminSpyTargetUser = null;
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
              adminSpyChannels = adminData.channels || [];
              adminSpyUsers = adminData.users || [];
            } else if (adminSpyType === 'conversations') {
              if (adminData.targetUser) {
                adminSpyTargetUser = adminData.targetUser;
                window.adminSpyTargetUser = adminSpyTargetUser;
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
    window.fetchAdminConvs = fetchAdminConvs;

    function selectAdminSpyTargetUser(user) {
      if (adminConvXhr) {
        try { adminConvXhr.abort(); } catch(e) {}
        adminConvXhr = null;
      }
      isLoadingAdminConv = false;
      adminSpyTargetUser = user;
      window.adminSpyTargetUser = adminSpyTargetUser;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();
      chatHeaderTitle.textContent = '';
      applyHeaderAdminBadge();
      applyHeaderAvatar(null);
      updateHeaderActiveStatus();
      if (chatBox) chatBox.innerHTML = '';
      adminSpyConvs = [];
      fetchAdminConvs('', 0, false, user.account_id);
    }

    function clearAdminSpyTargetUser() {
      if (adminConvXhr) {
        try { adminConvXhr.abort(); } catch(e) {}
        adminConvXhr = null;
      }
      isLoadingAdminConv = false;
      adminSpyTargetUser = null;
      window.adminSpyTargetUser = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();
      chatHeaderTitle.textContent = '';
      applyHeaderAdminBadge();
      applyHeaderAvatar(null);
      updateHeaderActiveStatus();
      if (chatBox) chatBox.innerHTML = '';
      adminSpyConvs = [];
      const query = adminSearchInput ? adminSearchInput.value.trim() : '';
      if (query !== '') {
        fetchAdminConvs(query, 0, false, 0);
      } else {
        adminSpyType = 'none';
        adminSpyChannels = [];
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

      // State B: Render User & Channel Search Results (Max 10)
      if (adminSpyType === 'users') {
        const hasChannels = Array.isArray(adminSpyChannels) && adminSpyChannels.length > 0;
        const hasUsers = Array.isArray(adminSpyUsers) && adminSpyUsers.length > 0;

        if (!hasChannels && !hasUsers) {
          const q = adminSearchInput ? adminSearchInput.value.trim() : '';
          list.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No users or office group chats found matching "${escapeHtml(q)}".</p></div>`;
          adminConvItems.clear();
          return;
        }

        const emptyEl = list.querySelector('.sidebar-empty-state, .empty-chat');
        if (emptyEl) emptyEl.remove();

        const existingNotice = list.querySelector('.search-limit-notice');
        if (existingNotice) existingNotice.remove();

        const seen = new Set();
        let renderedCount = 0;

        // 1. Render matching Group Chats (Office GCs and/or Global Chat)
        if (hasChannels) {
          adminSpyChannels.forEach(ch => {
            if (renderedCount >= 10) return;
            renderedCount++;
            const key = 'channel_' + ch.conv_id;
            seen.add(key);
            let item = adminConvItems.get(key);
            let nameEl, msgEl;

            if (!item) {
              item = document.createElement('div');
              item.className = 'user-item';

              const avatar = document.createElement('div');
              avatar.className = 'user-avatar';
              avatar.innerHTML = `<img src="cspc.webp" width="48" height="48" alt="Group Chat" class="avatar-img" style="width:48px;height:48px;object-fit:cover;background:transparent;border-radius:50%;" draggable="false" ondragstart="return false;" oncontextmenu="return false;">`;

              const info = document.createElement('div');
              info.className = 'user-info';

              const nameRow = document.createElement('div');
              nameRow.style.cssText = 'display:flex;align-items:center;gap:4px;min-width:0;';

              nameEl = document.createElement('div');
              nameEl.className = 'user-name';
              nameEl.style.fontSize = '13px';
              nameRow.appendChild(nameEl);

              const checkBadge = document.createElement('span');
              checkBadge.className = 'verified-badge';
              checkBadge.innerHTML = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="12" fill="#1b74e4"/><path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
              nameRow.appendChild(checkBadge);

              const badge = document.createElement('span');
              badge.style.cssText = 'font-size:10px;font-weight:700;background:rgba(27,116,228,0.12);color:#1b74e4;padding:1px 5px;border-radius:4px;text-transform:uppercase;flex-shrink:0;margin-left:4px;';
              badge.textContent = ch.channel_type === 'global' ? 'Global' : (ch.channel_type === 'custom_gc' ? 'Custom GC' : 'Office GC');
              nameRow.appendChild(badge);

              msgEl = document.createElement('div');
              msgEl.className = 'user-last-msg';
              info.appendChild(nameRow);
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

            item.onclick = () => openAdminChannel(ch);

            const newClassName = 'user-item' + (activeAdminConv === ch.conv_id ? ' active' : '');
            if (item.className !== newClassName) item.className = newClassName;

            if (nameEl.textContent !== ch.title) nameEl.textContent = ch.title;
            if (msgEl.textContent !== ch.subtitle) msgEl.textContent = ch.subtitle;

            list.appendChild(item);
          });
        }

        // 2. Render matching Users
        if (hasUsers) {
          adminSpyUsers.forEach(u => {
            if (renderedCount >= 10) return;
            renderedCount++;
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
        }

        for (const [key, item] of adminConvItems) {
          if (!seen.has(key)) {
            item.remove();
            adminConvItems.delete(key);
          }
        }

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
            item.className = 'user-item';

            const avatar = document.createElement('div');
            avatar.className = 'user-avatar';
            if (c.isCustomGc) {
              avatar.innerHTML = `<img src="cspc.webp" width="48" height="48" alt="Group Chat" class="avatar-img" style="width:48px;height:48px;object-fit:cover;background:transparent;border-radius:50%;" draggable="false">`;
            } else {
              avatar.innerHTML = EYE_ICON_SVG;
            }

            const info = document.createElement('div');
            info.className = 'user-info';

            const nameRow = document.createElement('div');
            nameRow.style.cssText = 'display:flex;align-items:center;gap:4px;min-width:0;';

            nameEl = document.createElement('div');
            nameEl.className = 'user-name';
            nameEl.style.fontSize = '13px';
            nameRow.appendChild(nameEl);

            if (c.isCustomGc) {
              const vBadge = document.createElement('span');
              vBadge.className = 'verified-badge';
              vBadge.innerHTML = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="12" fill="#1b74e4"/><path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
              nameRow.appendChild(vBadge);
            }

            const badge = document.createElement('span');
            badge.className = 'custom-gc-badge';
            badge.style.cssText = 'font-size:10px;font-weight:700;background:rgba(27,116,228,0.12);color:#1b74e4;padding:1px 5px;border-radius:4px;text-transform:uppercase;flex-shrink:0;margin-left:4px;';
            badge.textContent = 'Custom GC';
            badge.style.display = c.isCustomGc ? 'inline-block' : 'none';
            nameRow.appendChild(badge);

            msgEl = document.createElement('div');
            msgEl.className = 'user-last-msg';

            info.appendChild(nameRow);
            info.appendChild(msgEl);

            item.appendChild(avatar);
            item.appendChild(info);

            item._nameEl = nameEl;
            item._msgEl = msgEl;
            item._badgeEl = badge;
            item._avatarEl = avatar;

            adminConvItems.set(key, item);
          } else {
            nameEl = item._nameEl || item.querySelector('.user-name');
            msgEl = item._msgEl || item.querySelector('.user-last-msg');
            const badge = item._badgeEl || item.querySelector('.custom-gc-badge');
            if (badge) {
              badge.style.display = c.isCustomGc ? 'inline-block' : 'none';
            }
            if (item._avatarEl) {
              if (c.isCustomGc) {
                item._avatarEl.innerHTML = `<img src="cspc.webp" width="48" height="48" alt="Group Chat" class="avatar-img" style="width:48px;height:48px;object-fit:cover;background:transparent;border-radius:50%;" draggable="false">`;
              } else {
                item._avatarEl.innerHTML = EYE_ICON_SVG;
              }
            }
          }

          item.onclick = () => openAdminConv(c);

          const newClassName = 'user-item' + (activeAdminConv === c.convId ? ' active' : '');
          if (item.className !== newClassName) item.className = newClassName;

          const nameDisplay = c.isCustomGc
            ? (c.title || c.name1)
            : (c.name1 + ' ↔ ' + c.name2);
          if (nameEl.textContent !== nameDisplay) nameEl.textContent = nameDisplay;

          const count = Number(c.msgCount) || 0;
          let newMsg = 'No messages';
          if (count > 99) {
            newMsg = '99+ messages';
          } else if (count === 1) {
            newMsg = '1 message';
          } else if (count === 0) {
            newMsg = 'No messages';
          } else {
            newMsg = count + ' messages';
          }
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

    function updateOfficeInfoButtonVisibility() {
      const infoBtn = document.getElementById('officeInfoBtn');
      if (!infoBtn) return;

      // In Super Admin Spy Mode:
      // If in all-chats view (isAdminAllChatsView is true):
      // Only show if actively spying on a group chat (office GC or custom GC).
      // If browsing chats list (activeAdminConv is null) or spying on a private DM, HIDE.
      const isAdminOfficeGc = typeof activeAdminConv === 'string' && activeAdminConv.startsWith('office_');
      const isAdminCustomGc = typeof activeAdminConv === 'string' && activeAdminConv.startsWith('gc_');
      const isSpyGc = isAdminOfficeGc || isAdminCustomGc;

      if (typeof isAdminAllChatsView !== 'undefined' && isAdminAllChatsView) {
        infoBtn.style.display = isSpyGc ? 'inline-flex' : 'none';
        if (isAdminCustomGc) {
          infoBtn.title = 'View group chat members';
          infoBtn.setAttribute('aria-label', 'Group Information');
        } else if (isAdminOfficeGc) {
          infoBtn.title = 'View office group chat members';
          infoBtn.setAttribute('aria-label', 'Office Information');
        }
        return;
      }

      // In Normal Mode:
      // Visible ONLY when viewing an office GC or custom GC ("dapat sa gc lang mag a-appear yun")
      const currentChan = (window.activeChannel !== undefined)
        ? window.activeChannel
        : (typeof activeChannel !== 'undefined' ? activeChannel : null);
      const hasOffice = !!(window.currentOfficeInfo && window.currentOfficeInfo.office_id && Number(window.currentOfficeInfo.office_id) > 0);
      const isOfficeChannel = (currentChan === 'office') && !activeDM && !activeAdminConv && hasOffice;
      const isCustomGcChannel = (currentChan === 'gc') && !activeDM && !activeAdminConv && !!window.activeCustomGc;

      const isVisible = isOfficeChannel || isCustomGcChannel;
      infoBtn.style.display = isVisible ? 'inline-flex' : 'none';

      if (isCustomGcChannel) {
        infoBtn.title = 'View group chat members';
        infoBtn.setAttribute('aria-label', 'Group Information');
      } else if (isOfficeChannel) {
        infoBtn.title = 'View office group chat members';
        infoBtn.setAttribute('aria-label', 'Office Information');
      }
    }
    window.updateOfficeInfoButtonVisibility = updateOfficeInfoButtonVisibility;
    updateOfficeInfoButtonVisibility();

    let officeMembersPollTimer = null;

    function reloadOfficeMembersList(silent = true) {
      const modal = document.getElementById('officeMembersModal');
      if (!modal || !modal.classList.contains('active')) return;

      const titleEl = document.getElementById('officeMembersModalTitle');
      const subtitleEl = document.getElementById('officeMembersModalSubtitle');
      const countBadge = document.getElementById('officeMembersCountBadge');
      const listEl = document.getElementById('officeMembersList');
      const addBtn = document.getElementById('officeMembersAddUserBtn');

      // ── Custom GC branch (Normal or Super Admin Spy Mode) ───────────────────
      const isCustomGcAdminSpy = (typeof activeAdminConv === 'string' && activeAdminConv.startsWith('gc_'));
      if ((window.activeChannel === 'gc' && window.activeCustomGc) || isCustomGcAdminSpy) {
        const gcId = isCustomGcAdminSpy ? Number(activeAdminConv.replace('gc_', '')) : window.activeCustomGc.gc_id;
        const myIdForAddBtn = (typeof wsConfig !== 'undefined' && wsConfig.accountId) ? Number(wsConfig.accountId) : 0;
        const gcCreatorIdForAddBtn = (window.activeCustomGc && window.activeCustomGc.creator_id) ? Number(window.activeCustomGc.creator_id) : 0;
        const isCreatorForAddBtn = (!isCustomGcAdminSpy && myIdForAddBtn > 0 && gcCreatorIdForAddBtn > 0 && myIdForAddBtn === gcCreatorIdForAddBtn);
        // Also check in-memory groupInfo for promoted-admin status (avoids flash before fetch)
        const isCachedGcAdmin = isCreatorForAddBtn || (!isCustomGcAdminSpy && Boolean(window.activeCustomGcGroupInfo && Number(window.activeCustomGcGroupInfo.id) === gcId && window.activeCustomGcGroupInfo.is_gc_admin));
        if (addBtn) addBtn.style.display = isCachedGcAdmin ? 'flex' : 'none';

        if (!silent && listEl) {
          listEl.innerHTML = '<div style="padding:32px 16px;text-align:center;color:var(--text-secondary);font-size:13px;font-weight:500;">Loading members...</div>';
        }

        fetch('fetch_custom_gc_members.php?group_id=' + encodeURIComponent(gcId))
          .then(res => res.json())
          .then(data => {
            if (!modal.classList.contains('active')) return;
            if (!data.success) {
              if (!silent && listEl) {
                listEl.innerHTML = `<div style="padding:24px 16px;text-align:center;color:#e74c3c;font-size:13px;">${escapeHtml(data.error || 'Failed to load members.')}</div>`;
              }
              return;
            }

            window.activeCustomGcMembers = data.members || [];
            window.activeCustomGcGroupInfo = data.group || null;

            // Show/hide Disband button: strictly creator only (not in spy mode)
            const myId = (typeof wsConfig !== 'undefined' && wsConfig.accountId) ? Number(wsConfig.accountId) : 0;
            const creatorId = data.group ? Number(data.group.creator_id) : 0;
            const isCreator = (!isCustomGcAdminSpy && myId > 0 && creatorId > 0 && myId === creatorId);
            const isGcAdmin = (!isCustomGcAdminSpy && (isCreator || Boolean(data.group && data.group.is_gc_admin)));

            // Also update the + add button visibility — creator and promoted admins can add members
            const addBtnInModal = document.getElementById('officeMembersAddUserBtn');
            if (addBtnInModal) addBtnInModal.style.display = isGcAdmin ? 'flex' : 'none';

            const disbandBtn = document.getElementById('disbandGcBtn');
            const cancelBtn = document.getElementById('officeMembersCancelBtn') || document.querySelector('#officeMembersModal .cancel-button');
            if (disbandBtn) {
              disbandBtn.style.display = isCreator ? '' : 'none';
            }
            if (cancelBtn) {
              cancelBtn.textContent = isCreator ? 'Cancel' : 'Close';
              cancelBtn.style.borderRight = isCreator ? '' : 'none';
            }

            if (titleEl && data.group) {
              const rawGroupName = data.group.name || 'Group Chat';
              titleEl.textContent = rawGroupName.length > 20 ? (rawGroupName.slice(0, 20).trimEnd() + '...') : rawGroupName;
              if (isGcAdmin) {
                titleEl.style.cursor = 'pointer';
                titleEl.title = 'Click to rename group (' + rawGroupName + ')';
                titleEl.onclick = () => openRenameCustomGcModal();
              } else {
                titleEl.style.cursor = 'default';
                titleEl.title = rawGroupName;
                titleEl.onclick = null;
              }
            }
            if (subtitleEl) {
              subtitleEl.textContent = isCustomGcAdminSpy ? 'Custom Group Chat' : 'Group Chat Members';
            }
            if (countBadge) {
              countBadge.style.display = '';
              const mCount = (data.members && data.members.length) || (data.group && data.group.member_count) || 0;
              countBadge.textContent = String(mCount);
            }

            renderOfficeMembersList(data.members || [], silent, data.group);
          })
          .catch(err => {
            if (!silent && listEl) {
              listEl.innerHTML = '<div style="padding:24px 16px;text-align:center;color:#e74c3c;font-size:13px;">Error loading members. Please try again.</div>';
            }
          });
        return;
      }

      // ── Private DM branch (Super Admin Spy Mode) ────────────────────────────
      if (typeof activeAdminConv === 'string' && /^\d+_\d+$/.test(activeAdminConv)) {
        if (addBtn) addBtn.style.display = 'none';
        const _disbandBtn = document.getElementById('disbandGcBtn');
        if (_disbandBtn) _disbandBtn.style.display = 'none';
        const _cancelBtn = document.getElementById('officeMembersCancelBtn') || document.querySelector('#officeMembersModal .cancel-button');
        if (_cancelBtn) {
          _cancelBtn.textContent = 'Close';
          _cancelBtn.style.borderRight = 'none';
        }

        const conv = (Array.isArray(adminSpyConvs) ? adminSpyConvs.find(x => x.convId === activeAdminConv) : null);
        const parts = activeAdminConv.split('_').map(Number);
        const u1Id = parts[0];
        const u2Id = parts[1];

        if (titleEl) titleEl.textContent = conv ? (conv.name1 + ' & ' + conv.name2) : 'Direct Message';
        if (subtitleEl) subtitleEl.textContent = 'Conversation Participants';
        if (countBadge) countBadge.textContent = '2';

        const dmMbrs = [
          {
            account_id: u1Id,
            full_name: conv?.name1 || ('User #' + u1Id),
            role_name: conv?.u1_office || '',
            is_chatify_verified: true,
          },
          {
            account_id: u2Id,
            full_name: conv?.name2 || ('User #' + u2Id),
            role_name: conv?.u2_office || '',
            is_chatify_verified: true,
          }
        ];
        renderOfficeMembersList(dmMbrs, silent, null);
        return;
      }

      // ── Fixed Office GC branch ──────────────────────────────────────────────
      if (titleEl) { titleEl.onclick = null; titleEl.style.cursor = 'default'; }
      if (addBtn) addBtn.style.display = 'none';
      const _disbandBtn = document.getElementById('disbandGcBtn');
      if (_disbandBtn) _disbandBtn.style.display = 'none';
      const _cancelBtn = document.getElementById('officeMembersCancelBtn') || document.querySelector('#officeMembersModal .cancel-button');
      if (_cancelBtn) {
        _cancelBtn.textContent = 'Close';
        _cancelBtn.style.borderRight = 'none';
      }

      if (!silent && listEl) {
        listEl.innerHTML = '<div style="padding:32px 16px;text-align:center;color:var(--text-secondary);font-size:13px;font-weight:500;">Loading members...</div>';
      }

      let param = '';
      if (typeof activeAdminConv === 'string' && activeAdminConv.startsWith('office_')) {
        param = 'conv_id=' + encodeURIComponent(activeAdminConv);
      } else if (window.currentOfficeInfo && window.currentOfficeInfo.conv_id && window.currentOfficeInfo.conv_id.startsWith('office_')) {
        param = 'conv_id=' + encodeURIComponent(window.currentOfficeInfo.conv_id);
      } else if (window.currentOfficeInfo && window.currentOfficeInfo.office_id) {
        param = 'office_id=' + encodeURIComponent(window.currentOfficeInfo.office_id);
      }

      const url = param ? ('fetch_office_members.php?' + param) : 'fetch_office_members.php';

      fetch(url)
        .then(res => res.json())
        .then(data => {
          if (!modal.classList.contains('active')) return;
          if (!data.success) {
            if (!silent && listEl) {
              listEl.innerHTML = `<div style="padding:24px 16px;text-align:center;color:#e74c3c;font-size:13px;">${escapeHtml(data.error || 'Failed to load members.')}</div>`;
            }
            return;
          }

          if (titleEl && data.office) {
            titleEl.textContent = data.office.code ? (data.office.code + ' Members') : (data.office.name || 'Office Members');
          }
          if (subtitleEl && data.office && data.office.name) {
            subtitleEl.textContent = data.office.name;
          }
          if (countBadge) {
            countBadge.style.display = '';
            countBadge.textContent = data.member_count || (data.members ? data.members.length : 0);
          }

          renderOfficeMembersList(data.members || [], silent);
        })
        .catch(err => {
          if (!silent && listEl) {
            listEl.innerHTML = '<div style="padding:24px 16px;text-align:center;color:#e74c3c;font-size:13px;">Error loading members. Please try again.</div>';
          }
        });
    }
    window.reloadOfficeMembersList = reloadOfficeMembersList;

    function openOfficeMembersModal() {
      const modal = document.getElementById('officeMembersModal');
      if (!modal) return;
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');

      reloadOfficeMembersList(false);

      if (officeMembersPollTimer) clearInterval(officeMembersPollTimer);
      officeMembersPollTimer = setInterval(() => {
        const m = document.getElementById('officeMembersModal');
        if (m && m.classList.contains('active')) {
          reloadOfficeMembersList(true);
        } else {
          clearInterval(officeMembersPollTimer);
          officeMembersPollTimer = null;
        }
      }, 3000);
    }
    window.openOfficeMembersModal = openOfficeMembersModal;

    function closeOfficeMembersModal() {
      const modal = document.getElementById('officeMembersModal');
      if (!modal) return;
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
      const disbandBtn = document.getElementById('disbandGcBtn');
      if (disbandBtn) disbandBtn.style.display = 'none';
      const cancelBtn = document.getElementById('officeMembersCancelBtn') || document.querySelector('#officeMembersModal .cancel-button');
      if (cancelBtn) {
        cancelBtn.textContent = 'Close';
        cancelBtn.style.borderRight = 'none';
      }
      if (officeMembersPollTimer) {
        clearInterval(officeMembersPollTimer);
        officeMembersPollTimer = null;
      }
    }
    window.closeOfficeMembersModal = closeOfficeMembersModal;

    function syncOfficeMembersModalVerification(accountId, isVerified) {
      const targetId = Number(accountId);
      if (Array.isArray(window.currentOfficeMembers)) {
        const m = window.currentOfficeMembers.find(x => Number(x.account_id) === targetId);
        if (m) m.is_chatify_verified = !!isVerified;
      }
      if (Array.isArray(window.activeCustomGcMembers)) {
        const m = window.activeCustomGcMembers.find(x => Number(x.account_id) === targetId);
        if (m) m.is_chatify_verified = !!isVerified;
      }
      const row = document.querySelector(`#officeMembersList [data-account-id="${targetId}"]`);
      if (!row) return;

      const nameRow = row.querySelector('.mention-user-name')?.parentElement || row.querySelector('.mention-user-info > div');
      if (!nameRow) return;

      const existingBadge = nameRow.querySelector('.verified-badge');
      if (isVerified && !existingBadge) {
        const vBadge = document.createElement('span');
        vBadge.className = 'verified-badge';
        vBadge.style.cssText = 'flex-shrink:0;';
        vBadge.innerHTML = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="12" fill="#1b74e4"/><path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
        const nameEl = nameRow.querySelector('.mention-user-name');
        if (nameEl && nameEl.nextSibling) {
          nameRow.insertBefore(vBadge, nameEl.nextSibling);
        } else {
          nameRow.appendChild(vBadge);
        }
      } else if (!isVerified && existingBadge) {
        existingBadge.remove();
      }
    }
    window.syncOfficeMembersModalVerification = syncOfficeMembersModalVerification;

    // ── Generic danger confirmation modal ─────────────────────────────────────
    function showDangerConfirm(title, message, onConfirm) {
      const modal = document.getElementById('dangerConfirmModal');
      const titleEl = document.getElementById('dangerConfirmTitle');
      const msgEl = document.getElementById('dangerConfirmMessage');
      const okBtn = document.getElementById('dangerConfirmOkBtn');
      if (!modal || !titleEl || !msgEl || !okBtn) {
        // Fallback to native confirm if modal not found
        if (confirm(message)) onConfirm();
        return;
      }
      titleEl.textContent = title;
      msgEl.textContent = message;
      msgEl.style.wordBreak = 'break-word';
      msgEl.style.overflowWrap = 'anywhere';
      modal.style.display = 'flex';
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      // Remove previous listener and attach fresh one
      const newOkBtn = okBtn.cloneNode(true);
      okBtn.parentNode.replaceChild(newOkBtn, okBtn);
      newOkBtn.addEventListener('click', function() {
        closeDangerConfirmModal();
        onConfirm();
      });
    }
    function closeDangerConfirmModal() {
      const modal = document.getElementById('dangerConfirmModal');
      if (!modal) return;
      modal.style.display = 'none';
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }
    window.closeDangerConfirmModal = closeDangerConfirmModal;
    window.showDangerConfirm = showDangerConfirm;

    function markUserRemovedFromGcInChatBox(changedUserId) {
      if (!chatBox || !changedUserId) return;
      const noticeText = 'This user has been removed from the group';
      const changedMsgIds = new Set();
      const msgContainers = chatBox.querySelectorAll(`.message-container[data-sender-id="${changedUserId}"]`);
      msgContainers.forEach(container => {
        container.classList.add('user-moved');
        const mid = container.getAttribute('data-msg-id');
        if (mid) changedMsgIds.add(mid);
        let contentEl = container.querySelector('.message-content');
        if (!contentEl) {
          let bubble = container.querySelector('.message-bubble');
          if (!bubble) {
            bubble = document.createElement('div');
            bubble.className = 'message-bubble';
            const bubbleWrapper = container.querySelector('.bubble-wrapper');
            if (bubbleWrapper) bubbleWrapper.appendChild(bubble);
            else container.appendChild(bubble);
          }
          contentEl = document.createElement('div');
          contentEl.className = 'message-content';
          bubble.insertBefore(contentEl, bubble.firstChild);
          if (!bubble.querySelector('.message-info')) {
            const info = document.createElement('div');
            info.className = 'message-info';
            const sender = document.createElement('span');
            sender.className = 'message-sender';
            const userObj = allUsersData.find(u => Number(u.account_id) === changedUserId);
            sender.textContent = userObj ? (userObj.full_name || userObj.username || '').toLowerCase() : 'user';
            info.appendChild(sender);
            bubble.appendChild(info);
          }
        }
        if (contentEl) {
          contentEl.textContent = noticeText;
          contentEl.style.fontStyle = 'italic';
          contentEl.style.opacity = '0.85';
        }
        container.querySelectorAll('.chat-attachment-container, img.chat-image, video, audio, .message-media').forEach(el => el.remove());
      });

      if (changedMsgIds.size > 0) {
        chatBox.querySelectorAll('.reply-quote[data-reply-to]').forEach(rq => {
          const replyTo = rq.getAttribute('data-reply-to');
          if (replyTo && changedMsgIds.has(replyTo)) {
            rq.classList.remove('reply-quote-image-container');
            rq.innerHTML = `<div class="reply-quote-text" style="font-style:italic;opacity:0.8;">${escapeHtml(noticeText)}</div>`;
          }
        });
      }
    }
    window.markUserRemovedFromGcInChatBox = markUserRemovedFromGcInChatBox;

    function removeCustomGcMember(groupId, accountId, memberName) {
      const myId = (typeof wsConfig !== 'undefined' && wsConfig.accountId) ? Number(wsConfig.accountId) : 0;
      const creatorId = window.activeCustomGc ? Number(window.activeCustomGc.creator_id) : 0;
      if (myId <= 0 || creatorId <= 0 || myId !== creatorId) {
        alert('Only the group creator can remove members.');
        return;
      }

      const rawMemberName = memberName || 'this user';
      const truncatedMember = rawMemberName.length > 25 ? (rawMemberName.slice(0, 25).trimEnd() + '...') : rawMemberName;

      showDangerConfirm(
        'Remove Member',
        `Are you sure you want to remove "${truncatedMember}" from this group?`,
        function() {
          const bodyData = new FormData();
          bodyData.append('group_id', groupId);
          bodyData.append('account_id', accountId);

          fetch('remove_custom_gc_member.php', {
            method: 'POST',
            body: bodyData,
          })
            .then(r => r.json())
            .then(res => {
              if (res && res.success) {
                if (Array.isArray(window.activeCustomGcMembers)) {
                  window.activeCustomGcMembers = window.activeCustomGcMembers.filter(x => Number(x.account_id) !== Number(accountId));
                }
                if (window.activeChannel === 'gc' && window.activeCustomGc && Number(window.activeCustomGc.gc_id) === Number(groupId)) {
                  markUserRemovedFromGcInChatBox(Number(accountId));
                }
                if (typeof reloadOfficeMembersList === 'function') {
                  reloadOfficeMembersList(true);
                }
                if (typeof loadCustomGcs === 'function') {
                  loadCustomGcs();
                }
              } else {
                alert(res?.error || 'Failed to remove member.');
              }
            })
            .catch(() => {
              alert('Network error removing member.');
            });
        }
      );
    }
    window.removeCustomGcMember = removeCustomGcMember;

    function disbandCustomGc() {
      if (!window.activeCustomGc) return;
      const myId = (typeof wsConfig !== 'undefined' && wsConfig.accountId) ? Number(wsConfig.accountId) : 0;
      const creatorId = Number(window.activeCustomGc.creator_id);
      if (myId <= 0 || creatorId <= 0 || myId !== creatorId) {
        alert('Only the group creator can disband this group.');
        return;
      }

      const rawName = window.activeCustomGc.name || 'this group';
      const truncatedName = rawName.length > 25 ? (rawName.slice(0, 25).trimEnd() + '...') : rawName;
      const groupId = window.activeCustomGc.gc_id;

      showDangerConfirm(
        'Remove Group',
        `Are you sure you want to delete "${truncatedName}"? This will permanently delete the group and all its messages. This cannot be undone.`,
        function() {
          const bodyData = new FormData();
          bodyData.append('group_id', groupId);

          fetch('disband_custom_gc.php', {
            method: 'POST',
            body: bodyData,
          })
            .then(r => r.json())
            .then(res => {
              if (res && res.success) {
                if (typeof closeOfficeMembersModal === 'function') closeOfficeMembersModal();
                if (typeof resetToHome === 'function') resetToHome();
                if (typeof loadCustomGcs === 'function') loadCustomGcs();
              } else {
                alert(res?.error || 'Failed to disband group.');
              }
            })
            .catch(() => {
              alert('Network error disbanding group.');
            });
        }
      );
    }
    window.disbandCustomGc = disbandCustomGc;

    function toggleCustomGcAdmin(groupId, targetAccountId, makeAdmin, memberName) {
      const actionText = makeAdmin ? `promote "${memberName}" to Group Admin` : `dismiss "${memberName}" as Group Admin`;
      const title = makeAdmin ? 'Promote to Admin' : 'Dismiss Admin';
      const promptMsg = `Are you sure you want to ${actionText}?`;

      const doAction = () => {
        // Optimistic UI update immediately in-place
        if (Array.isArray(window.activeCustomGcMembers)) {
          const mem = window.activeCustomGcMembers.find(x => Number(x.account_id) === Number(targetAccountId));
          if (mem) mem.is_admin = makeAdmin;
        }
        if (typeof renderOfficeMembersList === 'function' && Array.isArray(window.activeCustomGcMembers)) {
          renderOfficeMembersList(window.activeCustomGcMembers, false, window.activeCustomGcGroupInfo);
        }

        const bodyData = new FormData();
        bodyData.append('group_id', groupId);
        bodyData.append('target_account_id', targetAccountId);
        bodyData.append('is_admin', makeAdmin ? '1' : '0');

        fetch('set_custom_gc_admin.php', {
          method: 'POST',
          body: bodyData,
        })
          .then(r => r.json())
          .then(res => {
            if (res && res.success) {
              if (Array.isArray(window.activeCustomGcMembers)) {
                const mem = window.activeCustomGcMembers.find(x => Number(x.account_id) === Number(targetAccountId));
                if (mem) mem.is_admin = makeAdmin;
              }
              if (typeof reloadOfficeMembersList === 'function') {
                reloadOfficeMembersList(false);
              }
            } else {
              alert(res?.error || 'Failed to update member role.');
              if (typeof reloadOfficeMembersList === 'function') {
                reloadOfficeMembersList(false);
              }
            }
          })
          .catch(() => {
            alert('Network error updating member role.');
            if (typeof reloadOfficeMembersList === 'function') {
              reloadOfficeMembersList(false);
            }
          });
      };

      if (typeof showDangerConfirm === 'function') {
        showDangerConfirm(title, promptMsg, doAction);
      } else if (confirm(promptMsg)) {
        doAction();
      }
    }
    window.toggleCustomGcAdmin = toggleCustomGcAdmin;

    function openRenameCustomGcModal() {
      const modal = document.getElementById('renameCustomGcModal');
      if (!modal) return;
      const input = document.getElementById('renameGcInput');
      const currentName = (window.activeCustomGcGroupInfo && window.activeCustomGcGroupInfo.name)
        ? window.activeCustomGcGroupInfo.name
        : (window.activeCustomGc ? window.activeCustomGc.name : '');
      if (input) {
        input.value = currentName || '';
        setTimeout(() => input.focus(), 80);
      }
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
    }
    window.openRenameCustomGcModal = openRenameCustomGcModal;

    function closeRenameCustomGcModal() {
      const modal = document.getElementById('renameCustomGcModal');
      if (!modal) return;
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
    }
    window.closeRenameCustomGcModal = closeRenameCustomGcModal;

    function submitRenameCustomGc() {
      const input = document.getElementById('renameGcInput');
      const newName = input ? input.value.trim() : '';
      if (!newName) {
        alert('Group chat name cannot be empty.');
        return;
      }
      if (newName.length > 40) {
        alert('Group chat name must not exceed 40 characters.');
        return;
      }
      const groupId = window.activeCustomGc ? window.activeCustomGc.gc_id : (window.activeCustomGcGroupInfo ? window.activeCustomGcGroupInfo.id : 0);
      if (!groupId) return;

      const submitBtn = document.getElementById('renameGcSubmitBtn');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
      }

      const bodyData = new FormData();
      bodyData.append('group_id', groupId);
      bodyData.append('name', newName);

      fetch('rename_custom_gc.php', {
        method: 'POST',
        body: bodyData,
      })
        .then(r => r.json())
        .then(res => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save';
          }
          if (res && res.success) {
            closeRenameCustomGcModal();
            // Update activeCustomGc in-memory
            if (window.activeCustomGc && Number(window.activeCustomGc.gc_id) === Number(groupId)) {
              window.activeCustomGc.name = res.name;
              if (chatHeaderTitle) chatHeaderTitle.innerHTML = escapeHtml(res.name);
            }
            if (window.activeCustomGcGroupInfo && Number(window.activeCustomGcGroupInfo.id) === Number(groupId)) {
              window.activeCustomGcGroupInfo.name = res.name;
            }
            const modalTitleEl = document.getElementById('officeMembersModalTitle');
            if (modalTitleEl) {
              modalTitleEl.textContent = res.name.length > 20 ? (res.name.slice(0, 20).trimEnd() + '...') : res.name;
              modalTitleEl.title = 'Click to rename group (' + res.name + ')';
            }
            // Update sidebar
            if (Array.isArray(allUsersData)) {
              const u = allUsersData.find(x => x.is_custom_gc && Number(x.gc_id) === Number(groupId));
              if (u) {
                u.name = res.name;
                u.full_name = res.name;
                renderSidebarUsers();
              }
            }
          } else {
            alert(res?.error || 'Failed to rename group chat.');
          }
        })
        .catch(() => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save';
          }
          alert('Network error renaming group chat.');
        });
    }
    window.submitRenameCustomGc = submitRenameCustomGc;

    document.getElementById('renameCustomGcModal')?.addEventListener('click', function(e) {
      if (e.target === this) closeRenameCustomGcModal();
    });
    document.getElementById('renameGcInput')?.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        submitRenameCustomGc();
      }
    });

    function renderOfficeMembersList(members, isSilent = false, groupInfo = null) {
      const listEl = document.getElementById('officeMembersList');
      if (!listEl) return;

      const myId = (typeof wsConfig !== 'undefined' && wsConfig.accountId) 
        ? Number(wsConfig.accountId) 
        : (typeof myAccountId !== 'undefined' ? Number(myAccountId) : (window.currentAccountDetails ? Number(window.currentAccountDetails.account_id || window.currentAccountDetails.id) : 0));

      const isCustomGcAdminSpy = (typeof activeAdminConv === 'string' && activeAdminConv.startsWith('gc_'));
      const isGc = (window.activeChannel === 'gc') || isCustomGcAdminSpy || !!(groupInfo && groupInfo.id);
      const creatorId = isGc ? Number(groupInfo?.creator_id || (window.activeCustomGc ? window.activeCustomGc.creator_id : 0)) : 0;
      const isGcAdmin = !isCustomGcAdminSpy && isGc && (myId > 0 && ((creatorId > 0 && myId === creatorId) || (groupInfo && Boolean(groupInfo.is_gc_admin)) || (Array.isArray(members) && members.some(x => Number(x.account_id) === myId && Boolean(x.is_admin || x.is_creator)))));
      const effectiveGroupId = isGc ? Number(groupInfo?.id || (window.activeCustomGc ? window.activeCustomGc.gc_id : (isCustomGcAdminSpy ? Number(activeAdminConv.replace('gc_', '')) : 0))) : 0;

      // Sync Add Member plus button visibility in real time
      if (isGc) {
        const addBtnInModal = document.getElementById('officeMembersAddUserBtn');
        if (addBtnInModal) addBtnInModal.style.display = isGcAdmin ? 'flex' : 'none';
      }

      if (!members || members.length === 0) {
        if (!isGc) {
          window.currentOfficeMembers = [];
        } else {
          window.activeCustomGcMembers = [];
        }
        listEl.innerHTML = '<div style="padding:32px 16px;text-align:center;color:var(--text-secondary);font-size:13px;">No members found.</div>';
        return;
      }

      const existingRows = listEl.querySelectorAll('.mention-user-row');
      const oldKeys = ((isGc ? window.activeCustomGcMembers : window.currentOfficeMembers) || []).map(m => `${m.account_id}_${m.is_chatify_verified ? 1 : 0}_${m.is_admin ? 1 : 0}_${m.role_name || ''}`).join('|');
      const newKeys = (members || []).map(m => `${m.account_id}_${m.is_chatify_verified ? 1 : 0}_${m.is_admin ? 1 : 0}_${m.role_name || ''}`).join('|');

      // Only skip re-render during background silent sync if the list already contains all rows and keys didn't change
      if (isSilent && existingRows.length === members.length && oldKeys === newKeys) {
        if (!isGc) {
          window.currentOfficeMembers = members;
        } else {
          window.activeCustomGcMembers = members;
        }
        return;
      }

      if (!isGc) {
        window.currentOfficeMembers = members;
      } else {
        window.activeCustomGcMembers = members;
      }
      listEl.innerHTML = '';

      members.forEach(m => {
        const item = document.createElement('div');
        item.className = 'mention-user-row';
        item.style.cssText = 'cursor:default;flex-shrink:0;';
        item.dataset.accountId = m.account_id;
        item.setAttribute('data-account-id', m.account_id);

        const avatar = document.createElement('div');
        avatar.className = 'mention-user-avatar';
        avatar.innerHTML = avatarInnerHtml(m.avatar_url, getInitialsFromFullName(m.full_name));

        const info = document.createElement('div');
        info.className = 'mention-user-info';

        const nameRow = document.createElement('div');
        nameRow.style.cssText = 'display:flex;align-items:center;gap:6px;min-width:0;';

        const nameEl = document.createElement('span');
        nameEl.className = 'mention-user-name';
        nameEl.textContent = m.full_name + (Number(m.account_id) === myId ? ' (You)' : '');
        nameRow.appendChild(nameEl);

        if (m.is_chatify_verified) {
          const vBadge = document.createElement('span');
          vBadge.className = 'verified-badge';
          vBadge.style.cssText = 'flex-shrink:0;';
          vBadge.innerHTML = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="12" fill="#1b74e4"/><path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
          nameRow.appendChild(vBadge);
        }

        const subEl = document.createElement('div');
        subEl.className = 'mention-user-sub';
        subEl.textContent = m.role_name || m.email || '';

        info.appendChild(nameRow);
        info.appendChild(subEl);

        item.appendChild(avatar);
        item.appendChild(info);

        // ── Custom GC: Admin badge, Admin promotion button, and Remove member button ──
        if (isGc) {
          const isThisUserCreator = (Number(m.account_id) === creatorId);
          const isThisUserAdmin = isThisUserCreator || Boolean(m.is_admin);

          const actionsContainer = document.createElement('div');
          actionsContainer.style.cssText = 'display:flex;align-items:center;gap:6px;margin-left:auto;flex-shrink:0;';

          if (isThisUserAdmin) {
            const adminBadge = document.createElement('span');
            adminBadge.style.cssText = 'font-size:10px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;background:rgba(27,116,228,0.12);color:#1b74e4;padding:2px 7px;border-radius:10px;flex-shrink:0;';
            adminBadge.textContent = 'Admin';
            actionsContainer.appendChild(adminBadge);
          }

          if (isGcAdmin && Number(m.account_id) !== Number(myId) && !isThisUserCreator) {
            // Admin promotion/demotion button
            const adminToggleBtn = document.createElement('button');
            adminToggleBtn.type = 'button';
            adminToggleBtn.className = 'toggle-gc-admin-btn';
            adminToggleBtn.title = m.is_admin ? 'Dismiss as group admin' : 'Make group admin';
            adminToggleBtn.setAttribute('aria-label', adminToggleBtn.title);
            adminToggleBtn.style.cssText = 'border:none;background:none;color:' + (m.is_admin ? '#e67e22' : '#1b74e4') + ';cursor:pointer;padding:6px;display:flex;align-items:center;justify-content:center;border-radius:6px;transition:background 0.15s;flex-shrink:0;';
            adminToggleBtn.innerHTML = m.is_admin
              ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="9" y1="12" x2="15" y2="12"/></svg>`
              : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>`;
            adminToggleBtn.onmouseover = () => { adminToggleBtn.style.background = m.is_admin ? 'rgba(230,126,34,0.12)' : 'rgba(27,116,228,0.12)'; };
            adminToggleBtn.onmouseout  = () => { adminToggleBtn.style.background = 'none'; };
            adminToggleBtn.onclick = (e) => {
              e.stopPropagation();
              toggleCustomGcAdmin(effectiveGroupId, m.account_id, !m.is_admin, m.full_name);
            };
            actionsContainer.appendChild(adminToggleBtn);

            // Remove member button
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-gc-member-btn';
            removeBtn.title = 'Remove from group';
            removeBtn.setAttribute('aria-label', `Remove ${m.full_name} from group`);
            removeBtn.style.cssText = 'border:none;background:none;color:#e74c3c;cursor:pointer;padding:6px;display:flex;align-items:center;justify-content:center;border-radius:6px;transition:background 0.15s;flex-shrink:0;';
            removeBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="18" y1="8" x2="23" y2="13"></line><line x1="23" y1="8" x2="18" y2="13"></line></svg>`;
            removeBtn.onmouseover = () => { removeBtn.style.background = 'rgba(231,76,60,0.12)'; };
            removeBtn.onmouseout  = () => { removeBtn.style.background = 'none'; };
            removeBtn.onclick = (e) => {
              e.stopPropagation();
              removeCustomGcMember(effectiveGroupId, m.account_id, m.full_name);
            };
            actionsContainer.appendChild(removeBtn);
          }

          item.appendChild(actionsContainer);
        }

        listEl.appendChild(item);
      });
    }


    let adminConvCursor = '';
    let adminConvHasMore = false;
    let adminConvViewingOlder = false; // true once the user has loaded an older window
    let isLoadingAdminConv = false;   // separate flag so admin spy never blocks DM loads
    let adminConvXhr = null;          // track in-flight XHR so stale responses can be discarded

    function loadAdminConv(convId, isAutoPoll = false, loadOlderMode = false) {
      if (isAutoPoll && !loadOlderMode && (adminConvViewingOlder || window.replyJumpActive)) return;
      if (isLoadingAdminConv) {
        // For non-poll (explicit open) calls, abort any in-flight request and proceed
        if (!isAutoPoll) {
          if (adminConvXhr) {
            try { adminConvXhr.abort(); } catch(e) {}
            adminConvXhr = null;
          }
          isLoadingAdminConv = false;
        } else {
          return;
        }
      }
      isLoadingAdminConv = true;

      const wasAtBottom = isAtBottom() && !window.replyJumpActive;
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
        if (this.status !== 200) {
          if (requestedConv === activeAdminConv && !loadOlderMode && chatBox) {
            chatBox.innerHTML = '<div class="empty-chat"><p>Failed to load conversation.</p></div>';
          }
          return;
        }
        if (requestedConv !== activeAdminConv) return; // stale response
        
        let data;
        try {
          data = JSON.parse(this.responseText);
        } catch(e) {
          if (!loadOlderMode && chatBox) {
            chatBox.innerHTML = '<div class="empty-chat"><p>Failed to load conversation.</p></div>';
          }
          return;
        }
        
        const newHtml = data.html || '';
        adminConvHasMore = data.hasMore || false;
        
        if (loadOlderMode) {
          shouldAutoScroll = false;
          userScrolledUp = true;
          adminConvCursor = data.nextCursor || '';
          adminConvViewingOlder = true;

          const anchor = (typeof captureScrollAnchor === 'function') ? captureScrollAnchor() : (window.captureScrollAnchor ? window.captureScrollAnchor() : null);
          const prevScrollHeight = chatBox.scrollHeight;
          const prevScrollTop = chatBox.scrollTop;

          const temp = document.createElement('div');
          temp.innerHTML = newHtml;
          const oldItems = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));

          const existingIds = new Set(
            Array.from(chatBox.querySelectorAll('.message-container[data-msg-id]'))
              .map(el => el.getAttribute('data-msg-id'))
          );

          const frag = document.createDocumentFragment();
          const insertedItems = []; // only items actually inserted into DOM
          oldItems.forEach(el => {
            const msgId = el.getAttribute('data-msg-id');
            if (!msgId || !existingIds.has(msgId)) {
              frag.appendChild(el);
              insertedItems.push(el);
            }
          });

          const insertRef = chatBox.querySelector('.message-container, .date-divider') || chatBox.firstChild;
          if (insertRef) {
            chatBox.insertBefore(frag, insertRef);
          } else {
            chatBox.appendChild(frag);
          }

          if (!adminConvHasMore) showNoMoreOlderNotice();
          applyAdminBadges();
          applyEmojiOnly();
          trimWindowFromBottom(MAX_WINDOW);

          const restoreFn = (typeof restoreScrollAnchor === 'function') ? restoreScrollAnchor : (window.restoreScrollAnchor ? window.restoreScrollAnchor : null);
          if (anchor && restoreFn) {
            restoreFn(anchor, insertedItems);
          } else {
            const safePrevScrollTop = Math.max(0, prevScrollTop);
            const heightDiff = chatBox.scrollHeight - prevScrollHeight;
            if (heightDiff > 0) {
              chatBox.scrollTop = safePrevScrollTop + heightDiff;
            }
          }

          if (adminConvHasMore && !document.getElementById('loadOlderBtn')) insertLoadOlderBtn();
          attachImageLoadListeners();
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
          if (isFirstLoad) {
            isFirstLoad = false;
            handleFirstLoadScroll();
          } else if (wasAtBottom && shouldAutoScroll) {
            scrollToBottom(true, true);
          }
          if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
          applyEmojiOnly();
          return;
        }

        if (rec.type === 'append') {
          let actuallyAddedCount = 0;
          rec.items.forEach(el => {
            if (el.classList.contains('message-container')) {
              const msgId = el.getAttribute('data-msg-id');
              if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
                return;
              }
              actuallyAddedCount++;
              if (chatFullyLoaded && !isFirstLoad) {
                const animClass = el.classList.contains('sent') ? 'msg-animate-sent' : 'msg-animate-received';
                el.classList.add(animClass);
                el.addEventListener('animationend', () => el.classList.remove(animClass), { once: true });
              }
            }
            chatBox.appendChild(el);
          });
          if (!adminConvViewingOlder) {
            if (isFirstLoad) {
              isFirstLoad = false;
              handleFirstLoadScroll();
            } else if ((wasAtBottom || !userScrolledUp) && !window.replyJumpActive) {
              scrollToBottom(true, true);
            } else if (userScrolledUp || window.replyJumpActive) {
              if (actuallyAddedCount > 0) {
                showScrollIndicator(actuallyAddedCount);
              } else if (window.replyJumpActive) {
                showScrollIndicator(0);
              }
            }
          }
          applyAdminBadges();
          applyEmojiOnly();
          attachImageLoadListeners();
          if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
          return;
        }

        // Full re-render
        const prevSTF = chatBox.scrollTop; const prevSHF = chatBox.scrollHeight;
        const curKeySetF = new Set(curKeys);
        const curMsgIdSetF = new Set(
          Array.from(chatBox.querySelectorAll('.message-container[data-msg-id]'))
            .map(el => el.getAttribute('data-msg-id'))
        );
        const genuinelyNewCountF = newMessages.filter(el => {
          if (!el.classList.contains('message-container')) return false;
          const msgId = el.getAttribute('data-msg-id');
          return msgId ? !curMsgIdSetF.has(msgId) : !curKeySetF.has(getMessageKey(el));
        }).length;
        chatBox.querySelectorAll('.message-container, .seen-indicator, .empty-chat, #loadOlderBtn, #noMoreOlderNotice').forEach(el => el.remove());

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

        if (isFirstLoad) {
          isFirstLoad = false;
          handleFirstLoadScroll();
        } else if (!adminConvViewingOlder) {
          if ((wasAtBottom || !userScrolledUp) && !window.replyJumpActive) {
            scrollToBottom(true, true);
          } else {
            chatBox.scrollTop = Math.max(0, prevSTF + chatBox.scrollHeight - prevSHF);
            if ((userScrolledUp || window.replyJumpActive) && genuinelyNewCountF > 0) {
              showScrollIndicator(genuinelyNewCountF);
            } else if (window.replyJumpActive) {
              showScrollIndicator(0);
            }
          }
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

    function openAdminChannel(ch) {
      if (!ch) return;
      if (activeAdminConv === ch.conv_id) {
        if (isMobileViewport()) {
          sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
        if (!isLoadingAdminConv) loadAdminConv(ch.conv_id, false);
        return;
      }

      if (typeof cancelActiveScrollAnimation === 'function') cancelActiveScrollAnimation();
      if (typeof clearFirstLoadScrollTimers === 'function') clearFirstLoadScrollTimers();

      if (adminConvXhr) {
        try { adminConvXhr.abort(); } catch(e) {}
        adminConvXhr = null;
      }
      isLoadingAdminConv = false;

      activeAdminConv = ch.conv_id;
      activeDM = null;
      activeDMAccountId = null;
      isGlobalChat = false;
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();

      const _hEl = document.querySelector('.header');
      if (_hEl) {
        _hEl.classList.remove('is-global-chat');
        _hEl.classList.add('is-single-title');
      }

      adminConvCursor = '';
      adminConvHasMore = false;
      adminConvViewingOlder = false;
      clearSendingOverlay();
      hideEditBanner();
      if (typeof hideReplyBanner === 'function') hideReplyBanner();
      isFirstLoad = true;

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

      localStorage.setItem('activeSpyConv', ch.conv_id);

      chatHeaderTitle.textContent = ch.title;
      updateHeaderActiveStatus();
      applyHeaderAdminBadge();
      applyHeaderAvatar({ avatar_url: 'cspc.webp', name: ch.office_code || ch.title || 'Group Chat' });

      chatBox.innerHTML = '<div class="empty-chat"><p>Loading...</p></div>';
      removePaginationBtn();
      window.replyJumpActive = false;
      hideScrollIndicator(true);
      renderAdminConvs();
      loadAdminConv(ch.conv_id, false);

      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        if (backButton) backButton.style.display = 'inline-flex';
      }
    }
    window.openAdminChannel = openAdminChannel;

    function openAdminConv(c) {
      if (!c) return;
      if (activeAdminConv === c.convId) {
        if (isMobileViewport()) {
          sidebar.classList.remove('open');
          if (backButton) backButton.style.display = 'inline-flex';
        }
        if (!isLoadingAdminConv) loadAdminConv(c.convId, false);
        return;
      }

      if (typeof cancelActiveScrollAnimation === 'function') cancelActiveScrollAnimation();
      if (typeof clearFirstLoadScrollTimers === 'function') clearFirstLoadScrollTimers();

      if (adminConvXhr) {
        try { adminConvXhr.abort(); } catch(e) {}
        adminConvXhr = null;
      }
      isLoadingAdminConv = false;

      activeAdminConv = c.convId;
      activeDM = null;
      activeDMAccountId = null;
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();
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

      // Display title: for custom GCs show the GC name, for DMs show both participants
      chatHeaderTitle.textContent = c.isCustomGc ? (c.title || c.name1) : (c.name1 + ' & ' + c.name2);
      updateHeaderActiveStatus();
      applyHeaderAdminBadge(); // activeDMAccountId is null for spied conversations — clears any leftover badge
      if (c.isCustomGc) {
        applyHeaderAvatar({ avatar_url: 'cspc.webp', name: c.title || c.name1 });
      } else {
        applyHeaderAvatar(null); // two participants, no single avatar to show
      }
      chatBox.innerHTML = '<div class="empty-chat"><p>Loading...</p></div>';
      
      removePaginationBtn();
      window.replyJumpActive = false;
      hideScrollIndicator(true);
      renderAdminConvs();
      loadAdminConv(c.convId, false);

      if (isMobileViewport()) {
        sidebar.classList.remove('open');
        if (backButton) backButton.style.display = 'inline-flex';
      }
    }
    
    let searchTimeout = null;
    searchInput.addEventListener('input', () => {
      if (searchTimeout) clearTimeout(searchTimeout);
      const query = searchInput.value.trim();

      if (typeof loadCustomGcs === 'function') {
        loadCustomGcs(query);
      }

      if (activeDM) {
        document.querySelectorAll('#customGcSection .user-item').forEach(el => el.classList.remove('active'));
      }

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
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      activeChannel = null;
      window.activeChannel = null;
      window.activeCustomGc = null;
      window.activeCustomGcMembers = null;
      window.activeCustomGcGroupInfo = null;
      isGlobalChat = false;
      const _hEl = document.querySelector('.header');
      if (_hEl) {
        _hEl.classList.remove('is-global-chat');
        _hEl.classList.remove('is-single-title');
      }
      
      updateClearChatButtonVisibility();
      updateOfficeInfoButtonVisibility();
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

      // Mobile/Tablet sidebar adjustments: return to frontpage
      if (isMobileViewport()) {
        if (sidebar) sidebar.classList.add('open');
        if (backButton) backButton.style.display = 'none';
      }

      if (chatBox) {
        chatBox.innerHTML = '<div class="empty-chat"></div>';
      }
      const gcItem = document.getElementById('globalChatItem');
      if (gcItem) gcItem.classList.remove('active');
      const ocItem = document.getElementById('officeChatItem');
      if (ocItem) ocItem.classList.remove('active');

      if (typeof sidebarUserItems !== 'undefined' && sidebarUserItems) {
        sidebarUserItems.forEach(item => item.classList.remove('active'));
      }
      if (typeof adminConvItems !== 'undefined' && adminConvItems) {
        adminConvItems.forEach(item => item.classList.remove('active'));
      }
      document.querySelectorAll('#customGcSection .user-item').forEach(item => item.classList.remove('active'));
      
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
