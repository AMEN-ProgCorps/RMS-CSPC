// ── All DOM element references first ──────────────────────────────────────

    const chatBox         = document.getElementById("chat-box");
    const nameInput       = document.getElementById("nameInput");
    const messageInput    = document.getElementById("messageInput");
    const sendButton      = document.getElementById("sendButton");
    const clearButton     = document.getElementById("clearButton"); // removed from header; now null, kept for legacy references
    const confirmModal    = document.getElementById("confirmModal");
    const cancelClear     = document.getElementById("cancelClear");
    const confirmClear    = document.getElementById("confirmClear");
    const scrollIndicator = document.getElementById("scrollIndicator");
    const loadOlderFloatingBtn = document.getElementById("loadOlderFloatingBtn");
    const secretInput     = document.getElementById("secretInput");
    const secretError     = document.getElementById("secretError");
    const darkModeToggle  = document.getElementById("darkModeToggle");
    const sidebarUsers    = document.getElementById('sidebarUsers');
    const searchInput     = document.getElementById('searchInput');
    const adminSearchInput = document.getElementById('adminSearchInput');

    const chatHeaderTitle = document.getElementById('chatHeaderTitle');
    const sidebar         = document.getElementById('sidebar');
    const backButton      = document.getElementById('backButton');
    const burgerButton    = document.getElementById('burgerButton');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');

    // The cancel-edit X button now lives inline in the message row itself
    // (next to Send), so no separate width-sync against #sendButton is
    // needed anymore — that was only for lining up the old two-row layout.
    let editingMsgId = null;

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
    const readMoreModal        = document.getElementById('readMoreModal');
    const readMoreModalBody    = document.getElementById('readMoreModalBody');
    const readMoreModalClose   = document.getElementById('readMoreModalClose');

    // Eye icon used to mark admin "spy" conversations (avoid emoji rendering
    // inconsistently across OS/browsers — use a proper inline SVG instead).
    const EYE_ICON_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';

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

        // Stop fallback polling on successful connection
        stopPollingFallback();

        // Authenticate connection
        ws.send(JSON.stringify({
          type: 'auth',
          account_id: wsConfig.accountId,
          name: wsConfig.name,
          expires: wsConfig.expires,
          token: wsConfig.token,
          comm_settings: window.currentUserCommSettings
        }));
      };
    function renderAndAppendWsMessage(msgData) {
      if (!chatBox) return;
      const msgId = msgData.msg_uuid || msgData.id;
      if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
        return; // Already rendered
      }

      const emptyNotice = chatBox.querySelector('.empty-chat');
      if (emptyNotice) emptyNotice.remove();

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
      const senderAvatarUrl = isSentByMe ? wsConfig.avatarUrl : (senderUser ? senderUser.avatar_url : null);

      let timeDisplay = '';
      if (msgData.created_at) {
        const d = new Date(msgData.created_at);
        const dateStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'long', day: 'numeric', year: 'numeric' });
        const timeStr = d.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
        timeDisplay = `${dateStr} at ${timeStr}`;
      } else {
        timeDisplay = getCurrentTime();
      }

      container.innerHTML = `
        <div class="message-avatar">${avatarInnerHtml(senderAvatarUrl, initials)}</div>
        <div class="bubble-wrapper">
          <div class="message-click-timestamp">${escapeHtml(timeDisplay)}</div>
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

      const atBottomNow = isAtBottom();

      // Cap the DOM at PAGE_SIZE (100) visible messages so real-time
      // WebSocket pushes never grow the chat window without bound.
      // Only trim while actively viewing the live/latest window —
      // never while the user has paged back into older history.
      const viewingOlderNow = isGlobalChat ? gcViewingOlder : dmViewingOlder;
      if (!viewingOlderNow) {
        trimChatMessages(PAGE_SIZE);
      }

      applyAdminBadges();
      if (atBottomNow || isSentByMe) {
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

        if (data.type === 'users_dm_response') {
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
        } else if (data.type === 'message') {
          console.log('Received WebSocket real-time update notice:', data);
          // Deduplication: if message is already rendered in chatBox, skip fetching!
          if (data.msg_uuid && chatBox.querySelector(`.message-container[data-msg-id="${data.msg_uuid}"]`)) {
            return;
          }
          if (data.chat_type === 'global') {
            if (isGlobalChat) {
              if (data.has_upload) {
                isLoadingGC = false;
                loadGlobalChat(false);
              } else {
                renderAndAppendWsMessage(data);
              }
            }
          } else if (data.chat_type === 'private') {
            if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              const s = Number(data.sender_id);
              const r = Number(data.recipient_id);
              if ((s === parts[0] && r === parts[1]) || (s === parts[1] && r === parts[0])) {
                scheduleAdminConvReload(activeAdminConv);
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
                    // loadChatForced() is an async network round-trip — the
                    // image/file HTML isn't actually in the DOM yet when we
                    // get here. loadChat()'s own completion handler (processChatData)
                    // calls markRead(activeDM) once the fetched HTML (with the real
                    // attachment) has been rendered, AND — same as the text path
                    // below — only does so if `!document.hidden`, so the attachment
                    // is never flagged "Seen" unless the recipient is genuinely
                    // looking at the chat. Skip calling markRead again here.
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
            showTypingIndicator(data.sender_name, data.is_typing);
          }
        } else if (data.type === 'typing_preview') {
          handleIncomingTypingPreview(data);
        } else if (data.type === 'typing_preview_cleared') {
          handleIncomingTypingPreviewCleared(data);
        } else if (data.type === 'typing_preview_sent') {
          handleIncomingTypingPreviewSent(data);
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
          if (data.chat_type === 'private') {
            const s = Number(data.sender_id);
            const r = Number(data.recipient_id);
            if (activeDM && activeDMAccountId && ((s === wsConfig.accountId && r === activeDMAccountId) || (s === activeDMAccountId && r === wsConfig.accountId))) {
              loadChat(false, false, true);
            }
            if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              if ((s === parts[0] && r === parts[1]) || (s === parts[1] && r === parts[0])) {
                loadAdminConv(activeAdminConv, true);
              }
            }
          } else if (data.chat_type === 'admin_conv') {
            const a = Number(data.user_a);
            const b = Number(data.user_b);
            if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              if ((parts[0] === a && parts[1] === b) || (parts[0] === b && parts[1] === a)) {
                loadAdminConv(activeAdminConv, true);
              }
            }
            if (activeDM && activeDMAccountId && ((a === wsConfig.accountId && b === activeDMAccountId) || (b === wsConfig.accountId && a === activeDMAccountId))) {
              loadChat(false, false, true);
            }
          }
          // Admin spy mode: keep the "X msgs · last message" counts in the
          // conversations list live when a conversation is cleared.
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
            activeDM = null; activeAdminConv = null; isGlobalChat = false;
            updateClearChatButtonVisibility();
            chatBox.innerHTML = '<div class="empty-chat"><p>All messages deleted.</p></div>';
          }
          allUsersData = [];
          renderSidebarUsers();
          if (serverIsAdmin) renderAdminConvs();
          fetchUsers();
        } else if (data.type === 'notify') {
          // Pushed directly by the server the instant someone notifies/mentions
          // us. This is the only delivery path now — no HTTP fallback poll.
          console.log('Received WebSocket real-time update notice:', data);
          showNotifyToast(data);
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
      return name.length > maxLen ? name.slice(0, maxLen).trim() + '…' : name;
    }

    function showTypingIndicator(senderName, isTyping) {
      const indicator = document.getElementById('typingIndicator');
      const textEl = document.getElementById('typingIndicatorText');

      if (typingTimer) {
        clearTimeout(typingTimer);
        typingTimer = null;
      }

      if (isTyping && activeDM) {
        textEl.textContent = `${truncateTypingName(senderName)} is typing`;
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
      if (document.hidden) return; // don't poll while tab is in background
      console.log('Starting backup message polling...');
      wsPollInterval = setInterval(function() {
        if (document.hidden) return; // skip each tick while hidden
        if (isGlobalChat) {
          loadGlobalChat(true);
        } else if (activeDM) {
          loadChat(true);
        } else if (activeAdminConv) {
          loadAdminConv(activeAdminConv, true);
        }
      }, 3000);
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
      if (window.innerWidth <= 991) {
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

    function processUsersDmPayload(data) {
      if (Array.isArray(data)) {
        allUsersData = data;
        userSearchHasMore = false;
      } else {
        allUsersData = data.users || [];
        userSearchHasMore = !!data.hasMore;
        serverIsAdmin = !!(data.currentUser && data.currentUser.is_admin);
      }
      renderSidebarUsers();

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
      if (opts.incrementUnread && activeDM !== username) {
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

      if (query === '') {
        latestTotalUnread = (allUsersData || []).reduce((sum, u) => sum + (u.unreadCount || 0), 0);
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
        const hasUnread = u.unreadCount > 0 && activeDM !== u.username;
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
          nameRow.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:4px;';
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

          item._avatar = avatar;
          item._dot = dot;
          item._info = info;
          item._nameEl = nameEl;
          item._officeEl = officeEl;
          item._actionsRight = actionsRight;

          sidebarUserItems.set(u.username, item);
        } else {
          avatar = item._avatar || item.querySelector('.user-avatar');
          dot = item._dot || item.querySelector('.status-dot');
          info = item._info || item.querySelector('.user-info');
          nameEl = item._nameEl || item.querySelector('.user-name');
          officeEl = item._officeEl || item.querySelector('.user-office');
          actionsRight = item._actionsRight || item.querySelector('.user-actions-right');
          item.onclick = () => selectDM(u);
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
        const newDotClass = 'status-dot ' + (u.status || 'offline');
        if (dot.className !== newDotClass) dot.className = newDotClass;

        if (nameEl.textContent !== u.name) nameEl.textContent = u.name;

        if (activeDM === u.username && chatHeaderTitle.textContent !== u.name) {
          chatHeaderTitle.textContent = u.name;
          applyHeaderAdminBadge();
        }

        const targetIsVerified = verifiedAccountIds && verifiedAccountIds.has(Number(u.account_id));

        // Verified badge next to verified users' names in the sidebar
        const sidebarBadge = nameEl.querySelector('.verified-badge');
        if (targetIsVerified) {
          if (!sidebarBadge) injectBadge(nameEl);
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

        // Preview line: only shows real-time typing preview, never past messages
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
            lastMsgEl.style.fontStyle = 'italic';
            lastMsgEl.style.color = 'var(--primary-color, #1b74e4)';
          } else {
            lastMsgEl.textContent = '';
            lastMsgEl.style.fontStyle = '';
            lastMsgEl.style.color = '';
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

    // Max characters to show in the toast preview before truncating with "..."
    const TOAST_PREVIEW_LIMIT = 80;

    function showNotifyToast(n) {
      const toast = document.createElement('div');
      toast.className = 'notify-toast';
      if (n.message) {
        const isLong = n.message.length > TOAST_PREVIEW_LIMIT;
        const preview = isLong ? n.message.slice(0, TOAST_PREVIEW_LIMIT).trim() + '...' : n.message;
        toast.innerHTML = '<strong>' + escapeHtml(n.sender) + '</strong> mentioned you: ' + escapeHtml(preview);
      } else {
        toast.innerHTML = '<strong>' + escapeHtml(n.sender) + '</strong> notified you';
      }
      const dismiss = () => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 200);
      };
      toast.onclick = () => {
        showNotifyContentModal(n);
        dismiss();
      };
      notifyToastContainer.appendChild(toast);
      setTimeout(dismiss, 6000);
    }

    // ── Modal shown when a notification toast is clicked ──
    function showNotifyContentModal(n) {
      if (!notifyContentModal) return;
      notifyContentTitle.textContent = n.sender ? (n.sender + ' notified you') : 'Notification';
      const content = (n.message || '').slice(0, 250);
      notifyContentBody.textContent = content || 'No message content.';
      notifyContentModal.classList.add('active');
      notifyContentModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeNotifyContentModal() {
      if (!notifyContentModal) return;
      notifyContentModal.classList.remove('active');
      notifyContentModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (notifyContentClose) {
      notifyContentClose.addEventListener('click', closeNotifyContentModal);
    }
    if (notifyContentModal) {
      notifyContentModal.addEventListener('click', function(e) {
        if (e.target === notifyContentModal) closeNotifyContentModal();
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
        return `<img src="${escapeHtml(avatarUrl)}" class="avatar-img" alt="" loading="lazy" referrerpolicy="no-referrer">`;
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

    function markRead(targetUsername) {
      // Immediately zero out in local data so badge disappears instantly
      const u = allUsersData.find(u => u.username === targetUsername);
      if (u) u.unreadCount = 0;
      renderSidebarUsers();

      const targetId = activeDMAccountId || 0;

      // Fast path: relay over the already-open WebSocket, same as typing
      // indicators — no new HTTP connection, no debounce, delivered to the
      // other participant's live socket the instant this fires. Deliberately
      // sends no last_msg_uuid: trying to compute one here from our own
      // chatBox is unreliable (e.g. right when a new message just arrived
      // via WS it hasn't been rendered into the DOM yet, and right after
      // selectDM() the previous conversation's messages are still on
      // screen). The receiving side fills in the correct id from its own
      // chatBox instead — see the 'message_read' handler below.
      if (ws && ws.readyState === WebSocket.OPEN && targetId) {
        ws.send(JSON.stringify({ type: 'mark_read', target_id: targetId }));
      }

      // Durable path: persist to Postgres via HTTP so the correct read
      // marker survives reloads / other tabs / the WS relay above being
      // missed during a brief reconnect gap. This also re-broadcasts
      // 'message_read' with the DB-confirmed last_msg_uuid shortly after,
      // which harmlessly reconciles the indicator if the optimistic
      // WS-only value above ever guessed wrong.
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'mark_read.php', true);
      xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
      xhr.send('target_id=' + encodeURIComponent(targetId) + '&target_user=' + encodeURIComponent(targetUsername));
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
      indicator.textContent = 'Seen';
      indicator.style.cssText = 'font-size:11px;color:var(--text-secondary);text-align:right;padding:2px 12px 6px 0;opacity:0.85;';
      target.insertAdjacentElement('afterend', indicator);
    }


    // State for global chat
    let isGlobalChat = false;
    // How many messages are fetched per page AND how many are kept on screen at
    // once. Loading an older page swaps the window rather than growing it
    // indefinitely — the newest messages get trimmed off the bottom to make
    // room, and clicking "Go to bottom" snaps back to the latest PAGE_SIZE.
    const PAGE_SIZE = 100;
    let gcCursor = '';
    let gcHasMore = false;
    let gcViewingOlder = false; // true once the user has loaded an older window
    let dmCursor  = '';
    let dmHasMore = false;
    let dmViewingOlder = false; // true once the user has loaded an older window
    // msg_uuid of the newest message the OTHER participant has read, or null.
    // Drives the Messenger-style "Seen" indicator under our own last-read sent message.
    let dmReadUpTo = null;

    function selectDM(u) {
      isGlobalChat = false;
      activeDM = u.username;
      activeDMAccountId = Number(u.account_id);
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      dmCursor = '';
      dmHasMore = false;
      dmViewingOlder = false;
      dmReadUpTo = null;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      
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

      isFirstLoad = true; // snap straight to bottom once the new conversation's messages arrive
      localStorage.setItem('activeDM', u.username);
      chatHeaderTitle.textContent = u.name;
      applyHeaderAdminBadge();
      // Note: we deliberately don't blank chatBox here. The previous chat's
      // messages stay on screen (harmlessly) until loadChat's diff logic swaps
      // them out the instant the new conversation's data arrives. Clearing it
      // immediately, combined with loadChat's old guard silently dropping the
      // request if one was already in flight, is what caused the chat pane to
      // flash blank repeatedly when clicking between conversations quickly.
      removePaginationBtn();
      markRead(u.username);
      renderSidebarUsers();
      loadChat(false, false, true); // force: abort any in-flight request rather than drop this one
      // Global Chat item deactivate
      document.getElementById('globalChatItem').classList.remove('active');
      
      // Mobile/Tablet: hide sidebar when chat is selected
      if (window.innerWidth <= 991) {
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
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      gcCursor = '';
      gcHasMore = false;
      gcViewingOlder = false;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
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

      localStorage.setItem('activeDM', '__global__');
      chatHeaderTitle.innerHTML = `Global Chat`;
      chatBox.innerHTML = '';

      removePaginationBtn();
      renderSidebarUsers();
      document.getElementById('globalChatItem').classList.add('active');
      loadGlobalChat();
      if (window.innerWidth <= 991) {
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

    // Track whether older messages are available for the current chat
    let hasOlderMessages = false;

    // Show/hide the floating load-older button based on scroll position + availability
    function syncLoadOlderBtn() {
      if (!loadOlderFloatingBtn) return;
      if (hasOlderMessages && userScrolledUp) {
        loadOlderFloatingBtn.classList.add('visible');
      } else {
        loadOlderFloatingBtn.classList.remove('visible');
      }
    }

    function removePaginationBtn() {
      hasOlderMessages = false;
      syncLoadOlderBtn();
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

    // Mark that older messages exist — visibility is driven by syncLoadOlderBtn()
    function insertLoadOlderBtn() {
      hasOlderMessages = true;
      syncLoadOlderBtn();
    }

    // Keeps the chat window capped at maxCount messages by trimming the
    // trailing (newest/bottom) ones — used right after prepending an older
    // page so loading history swaps the window instead of growing it forever.
    function trimWindowFromBottom(maxCount) {
      const items = Array.from(chatBox.querySelectorAll('.message-container, .empty-chat'));
      if (items.length <= maxCount) return;
      const excess = items.length - maxCount;
      for (let i = 0; i < excess; i++) {
        const el = items[items.length - 1 - i];
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
    }

    // Keeps the chat window capped at maxCount messages by trimming the
    // leading (oldest/top) ones — used during normal poll / initial load
    // so the message list doesn't grow forever.
    function trimWindowFromTop(maxCount) {
      const items = Array.from(chatBox.querySelectorAll('.message-container, .empty-chat'));
      if (items.length <= maxCount) return;
      const excess = items.length - maxCount;
      for (let i = 0; i < excess; i++) {
        const el = items[i];
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
    }

    // Reusable real-time trim helper: caps the number of visible
    // `.message-container` nodes in chatBox at `maxMessages`, always
    // dropping the OLDEST ones from the top first so the newest message
    // (the one that was just appended) stays visible.
    //
    // Call this right after appending a message that arrived via:
    //   • auto-poll (since_uuid) updates
    //   • WebSocket real-time pushes
    //   • locally sent ("optimistic") messages
    //
    // Do NOT call this for "Load Older" / prepending historical messages
    // or the initial conversation load — those flows intentionally grow
    // the window from the opposite end (see trimWindowFromBottom).
    //
    // Scroll position is preserved: removing nodes from the top shrinks
    // scrollHeight, so scrollTop is shifted by the exact delta, keeping
    // whatever the user was looking at visually stable (no jump).
    function trimChatMessages(maxMessages = 100) {
      if (!chatBox) return;
      const items = Array.from(chatBox.querySelectorAll('.message-container'));
      const excess = items.length - maxMessages;
      if (excess <= 0) return;

      const prevScrollTop = chatBox.scrollTop;
      const prevScrollHeight = chatBox.scrollHeight;

      for (let i = 0; i < excess; i++) {
        const el = items[i];
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }

      const scrollDelta = prevScrollHeight - chatBox.scrollHeight;
      if (scrollDelta !== 0) {
        chatBox.scrollTop = Math.max(0, prevScrollTop - scrollDelta);
      }
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
      adminSpyConvs = [];
      fetchAdminConvs('', 0, false, user.account_id);
    }

    function clearAdminSpyTargetUser() {
      adminSpyTargetUser = null;
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
            avatar.style.background = 'linear-gradient(135deg, #1b74e4, #00c3ff)';
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

          const newMsg = (c.msgCount || 1) + ' msg' + (c.msgCount !== 1 ? 's' : '') + (c.lastMessage ? ' · ' + c.lastMessage : '');
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
      const cursor = loadOlderMode ? adminConvCursor : '';
      const url = 'load_dm_admin.php?conv_id=' + encodeURIComponent(convId) + '&before_uuid=' + encodeURIComponent(cursor);

      const xhr = new XMLHttpRequest();
      adminConvXhr = xhr;
      xhr.open('GET', url, true);
      xhr.onload = function() {
        isLoadingAdminConv = false;
        if (adminConvXhr === xhr) adminConvXhr = null;
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
          adminConvCursor = data.nextCursor || '';
          adminConvViewingOlder = true;
          const prev = chatBox.scrollHeight;
          const temp = document.createElement('div');
          temp.innerHTML = newHtml;
          const oldItems = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
          const btn = document.getElementById('loadOlderBtn');
          const firstChild = chatBox.firstChild;
          oldItems.reverse().forEach(el => {
            if (btn) chatBox.insertBefore(el, btn.nextSibling);
            else chatBox.insertBefore(el, firstChild);
          });
          chatBox.scrollTop += chatBox.scrollHeight - prev;
          trimWindowFromBottom(PAGE_SIZE);
          if (!adminConvHasMore) showNoMoreOlderNotice(); else if (!document.getElementById('loadOlderBtn')) insertLoadOlderBtn();
          applyAdminBadges();
          applyEmojiOnly();
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
            trimWindowFromTop(PAGE_SIZE);
          }
          if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
          else if (wasAtBottom || shouldAutoScroll) requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
          else showScrollIndicator(rec.items.filter(el => el.classList.contains('message-container')).length);
          applyAdminBadges();
          applyEmojiOnly();
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
        if (mc > 0 && (wasAtBottom || shouldAutoScroll || isFirstLoad)) {
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
        adminConvCursor = data.nextCursor || '';
        adminConvViewingOlder = false;
        if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
      };
      xhr.onerror = function() { isLoadingAdminConv = false; adminConvXhr = null; };
      xhr.send();
    }

    function openAdminConv(c) {
      activeAdminConv = c.convId;
      activeDM = null;
      activeDMAccountId = null;
      updateClearChatButtonVisibility();
      isGlobalChat = false; // must reset — otherwise polling/visibilitychange keep re-loading Global Chat over the spy view
      
      adminConvCursor = '';
      adminConvHasMore = false;
      adminConvViewingOlder = false;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
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
      chatBox.innerHTML = '<div class="empty-chat"><p>Loading...</p></div>';
      
      removePaginationBtn();
      renderAdminConvs();
      loadAdminConv(c.convId, false);

      if (window.innerWidth <= 991) {
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

    backButton.addEventListener('click', () => {
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      isGlobalChat = false;
      
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

      removePaginationBtn();
      if (window.innerWidth <= 991) {
        sidebar.classList.add('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.add('visible');
        backButton.style.display = 'none';
        burgerButton.style.display = 'inline-flex';
      }
      chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
      document.getElementById('globalChatItem').classList.remove('active');
      renderSidebarUsers();
      if (serverIsAdmin) renderAdminConvs();
    });

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