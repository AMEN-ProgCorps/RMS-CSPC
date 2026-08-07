// ── Verified badge: inject checkmark next to admin sender names ──
    function applyAdminBadges() {
      if (!adminNames || adminNames.length === 0) return;
      document.querySelectorAll('.message-sender').forEach(function(el) {
        // Skip if badge already added
        if (el.querySelector('.verified-badge')) return;
        const senderText = el.textContent.trim().toLowerCase();
        if (adminNames.includes(senderText)) {
          injectBadge(el);
        }
      });
    }
    function injectBadge(el) {
      const badge = document.createElement('span');
      badge.className = 'verified-badge';
      badge.title = '';
      badge.innerHTML = `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="12" cy="12" r="12" fill="#1b74e4"/>
        <path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>`;
      el.appendChild(badge);
    }

    // ── Verified badge on the chat header (1-on-1 DM title) ──
    // Whenever the header title is set to the name of the person being
    // chatted with, show the blue checkmark next to it if that person is
    // the super admin. Re-checks every time so switching between an admin
    // DM and a regular-user DM correctly adds/removes the badge.
    function applyHeaderAdminBadge() {
      const existing = chatHeaderTitle.querySelector('.verified-badge');
      if (existing) existing.remove();
      if (!adminNames || adminNames.length === 0) return;
      const headerText = chatHeaderTitle.textContent.trim().toLowerCase();
      if (headerText && adminNames.includes(headerText)) {
        injectBadge(chatHeaderTitle);
      }
    }
    
    // Logout modal DOM refs
    const logoutModal = document.getElementById("logoutModal");
    const logoutConfirmBtn = document.getElementById("logoutConfirm");
    const logoutCancelBtn = document.getElementById("logoutCancel");
    
    // Track if user is at bottom of chat
    let userScrolledUp = false;
    let shouldAutoScroll = true;
    let isSending = false;
    let unreadCount = 0;

    // Dark mode functionality — applies to all users (admin and non-admin)
    if (darkModeToggle) {
      // Sync body attribute from html element (set by inline script in <head>)
      if (document.documentElement.hasAttribute('data-theme')) {
        document.body.setAttribute('data-theme', 'dark');
      }

      darkModeToggle.addEventListener('click', function() {
        const isDark = document.documentElement.hasAttribute('data-theme');
        if (isDark) {
          document.documentElement.removeAttribute('data-theme');
          document.body.removeAttribute('data-theme');
          localStorage.setItem('darkMode', 'disabled');
          document.cookie = "dark_mode=disabled; path=/; max-age=31536000";
        } else {
          document.documentElement.setAttribute('data-theme', 'dark');
          document.body.setAttribute('data-theme', 'dark');
          localStorage.setItem('darkMode', 'enabled');
          document.cookie = "dark_mode=enabled; path=/; max-age=31536000";
        }
      });
    }

    // Enhanced scroll management
    function isAtBottom() {
      return (chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight) <= 100;
    }

    function scrollToBottom(force = false, instant = false) {
      if (force || shouldAutoScroll) {
        // Instant scroll — no animation (used on page load / refresh)
        if (instant) {
          chatBox.scrollTop = chatBox.scrollHeight;
          // Double-tap for mobile where layout may not be settled
          requestAnimationFrame(function() {
            chatBox.scrollTop = chatBox.scrollHeight;
            hideScrollIndicator();
          });
          return;
        }

        const start = chatBox.scrollTop;
        const target = chatBox.scrollHeight;
        const distance = target - start;
        if (distance <= 0) { hideScrollIndicator(); return; }

        const duration = Math.min(300, Math.max(120, distance * 0.3));
        const startTime = performance.now();

        function animateScroll(currentTime) {
          const elapsed = currentTime - startTime;
          const progress = Math.min(elapsed / duration, 1);
          // Ease out cubic
          const ease = 1 - Math.pow(1 - progress, 3);
          chatBox.scrollTop = start + distance * ease;

          if (progress < 1) {
            requestAnimationFrame(animateScroll);
          } else {
            chatBox.scrollTop = chatBox.scrollHeight;
            hideScrollIndicator();
          }
        }

        requestAnimationFrame(animateScroll);
      }
    }

    function handleFirstLoadScroll() {
      const activeKey = isGlobalChat ? '__global__' : (activeDM || (activeAdminConv ? '__admin__' + activeAdminConv.convId : null));
      let restored = false;
      if (activeKey) {
        const savedScrollTop = sessionStorage.getItem('chatScroll_' + activeKey);
        const savedScrollHeight = sessionStorage.getItem('chatScrollHeight_' + activeKey);
        const savedAtBottom = sessionStorage.getItem('chatScrollAtBottom_' + activeKey);
        
        if (savedAtBottom === 'true') {
          scrollToBottom(true, true);
          restored = true;
        } else if (savedScrollTop !== null && savedScrollHeight !== null) {
          const scrollTop = parseFloat(savedScrollTop);
          const scrollHeight = parseFloat(savedScrollHeight);
          const diff = chatBox.scrollHeight - scrollHeight;
          chatBox.scrollTop = scrollTop + diff;
          restored = true;
        }
      }
      if (!restored) {
        scrollToBottom(true, true);
      }
    }

    const scrollIndicatorText = document.getElementById('scrollIndicatorText');
    const unreadBadge = document.getElementById('unreadBadge');

    function showScrollIndicator(newCount = 0) {
      if (newCount > 0) {
        unreadCount += newCount;
        unreadBadge.textContent = unreadCount;
        scrollIndicatorText.textContent = unreadCount === 1 ? 'new message' : 'new messages';
        scrollIndicator.classList.add('has-unread');
      } else if (!scrollIndicator.classList.contains('visible')) {
        // Only set "Go to bottom" label if not already showing unread
        if (!scrollIndicator.classList.contains('has-unread')) {
          scrollIndicatorText.textContent = 'Go to bottom';
        }
      }
      scrollIndicator.classList.add('visible');
    }

    function hideScrollIndicator() {
      scrollIndicator.classList.remove('visible', 'has-unread');
      unreadCount = 0;
      unreadBadge.textContent = '';
      scrollIndicatorText.textContent = 'Go to bottom';
    }
    
    function logout() {
      showLogoutModal();
    }

    function showLogoutModal() {
      if (!logoutModal) return;
      logoutModal.classList.add('active');
      logoutModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(() => logoutConfirmBtn && logoutConfirmBtn.focus(), 150);
    }

    function closeLogoutModal() {
      if (!logoutModal) return;
      logoutModal.classList.remove('active');
      logoutModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    // Close logout modal when clicking outside modal-content
    if (logoutModal) {
      logoutModal.addEventListener('click', function(e) {
        if (e.target === logoutModal) {
          closeLogoutModal();
        }
      });
    }

    // Logout modal buttons
    if (logoutConfirmBtn) {
      logoutConfirmBtn.addEventListener('click', function () {
        window.location.href = 'logout.php';
      });
    }
    if (logoutCancelBtn) {
      logoutCancelBtn.addEventListener('click', function () {
        closeLogoutModal();
      });
    }

    let clickTimeout = null;
    // Event delegation to smoothly toggle timestamp on click
    chatBox.addEventListener('click', function (e) {
      if (e.target.closest('a') && !e.target.closest('a').querySelector('img') && !e.target.closest('.message-media')) {
        return;
      }
      const bubble = e.target.closest('.message-bubble, .message-media');
      if (!bubble) return;

      // Image/audio messages always show their timestamp permanently —
      // clicking the image should never hide it, so skip the toggle entirely.
      if (bubble.classList.contains('message-media')) return;

      if (clickTimeout) {
        clearTimeout(clickTimeout);
        clickTimeout = null;
        return;
      }

      clickTimeout = setTimeout(function () {
        clickTimeout = null;
        const wrapper = bubble.closest('.bubble-wrapper');
        if (!wrapper) return;
        const timestamp = wrapper.querySelector('.message-click-timestamp');
        if (timestamp) {
          timestamp.classList.toggle('show-timestamp');
        }
      }, 250);
    });

    // Event delegation for double click to edit chat message
    chatBox.addEventListener('dblclick', function (e) {
      const container = e.target.closest('.message-container.sent');
      if (!container) return; // only edit messages sent by you
      
      const msgId = container.getAttribute('data-msg-id');
      if (!msgId) return;

      // Ensure it is a text message, not an upload
      const contentEl = container.querySelector('.message-bubble .message-content');
      if (!contentEl) return;
      
      // If it contains an attachment (like an anchor link or image or audio), do not edit
      if (contentEl.querySelector('a') || container.querySelector('img') || container.querySelector('audio')) {
        return;
      }
      
      // Use the original full message when this bubble was truncated by the
      // "Read More..." feature — contentEl.textContent at this point would
      // otherwise include the truncated preview plus the "Read More..." label
      // itself, which must never end up inside the edit box.
      const text = (contentEl.dataset.fullText || contentEl.textContent).trim();
      messageInput.value = text;
      editingMsgId = msgId;

      // Show X cancel button
      showEditBanner(msgId);

      // Auto-grow textarea to fit the text
      messageInput.style.height = 'auto';
      messageInput.style.height = messageInput.scrollHeight + 'px';

      // Scroll to bottom so the input is always visible
      shouldAutoScroll = true;
      userScrolledUp = false;
      scrollToBottom(true, true);

      messageInput.focus();
    });

    // X cancel-edit button
    const cancelEditXBtn = document.getElementById('cancelEditXBtn');
    if (cancelEditXBtn) {
      cancelEditXBtn.addEventListener('click', () => {
        hideEditBanner();
        messageInput.value = '';
        messageInput.style.height = 'auto';
      });
    }

    // Monitor scroll position
    chatBox.addEventListener('scroll', function() {
      const atBottom = isAtBottom();
      
      if (atBottom) {
        shouldAutoScroll = true;
        userScrolledUp = false;
        hideScrollIndicator();
        // Hide load-older button when user returns to bottom
        syncLoadOlderBtn();
      } else {
        shouldAutoScroll = false;
        userScrolledUp = true;
        const hasMessages = chatBox.querySelectorAll('.message-container').length > 0;
        if (hasMessages && !scrollIndicator.classList.contains('visible')) {
          showScrollIndicator(0);
        }
        // Show load-older button when user scrolls up and older messages exist
        syncLoadOlderBtn();
      }

      // Save scroll position for active chat
      const activeKey = isGlobalChat ? '__global__' : (activeDM || (activeAdminConv ? '__admin__' + activeAdminConv.convId : null));
      if (activeKey) {
        sessionStorage.setItem('chatScroll_' + activeKey, chatBox.scrollTop);
        sessionStorage.setItem('chatScrollHeight_' + activeKey, chatBox.scrollHeight);
        sessionStorage.setItem('chatScrollAtBottom_' + activeKey, atBottom ? 'true' : 'false');
      }
    });

    // Ensure scroll position is maintained when images finish loading
    chatBox.addEventListener('load', function(event) {
      if (event.target.tagName === 'IMG') {
        const activeKey = isGlobalChat ? '__global__' : (activeDM || (activeAdminConv ? '__admin__' + activeAdminConv.convId : null));
        if (activeKey) {
          const savedAtBottom = sessionStorage.getItem('chatScrollAtBottom_' + activeKey);
          if (savedAtBottom === 'true' || shouldAutoScroll || isAtBottom()) {
            scrollToBottom(true, true);
          }
        }
      }
    }, true);

    // Click scroll indicator to go to bottom
    scrollIndicator.addEventListener('click', function() {
      shouldAutoScroll = true;
      userScrolledUp = false;

      // If the user has loaded an older window, "Go to bottom" snaps back to
      // the latest PAGE_SIZE messages instead of just scrolling within the
      // (now stale) older batch that's on screen.
      if (isGlobalChat && gcViewingOlder) {
        gcViewingOlder = false;
        gcCursor = '';
        removePaginationBtn();
        chatBox.innerHTML = '';
        isFirstLoad = true;
        loadGlobalChat(false, false);
        return;
      }
      if (!isGlobalChat && activeAdminConv && adminConvViewingOlder) {
        adminConvViewingOlder = false;
        adminConvCursor = '';
        removePaginationBtn();
        chatBox.innerHTML = '';
        isFirstLoad = true;
        loadAdminConv(activeAdminConv, false, false);
        return;
      }
      if (!isGlobalChat && !activeAdminConv && activeDM && dmViewingOlder) {
        dmViewingOlder = false;
        dmCursor = '';
        removePaginationBtn();
        chatBox.innerHTML = '';
        isFirstLoad = true;
        loadChat(false, false, true);
        return;
      }

      scrollToBottom(true);
    });

    // Click floating load older button to load older messages
    if (loadOlderFloatingBtn) {
      loadOlderFloatingBtn.addEventListener('click', loadOlderMessages);
    }

    // Generate initials from name
    function getInitials(name) {
      return name
        .split(' ')
        .map(word => word[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
    }

    // ── Emoji-only detection ──
    // Returns true when the entire trimmed string is composed only of emoji characters.
    function isEmojiOnly(str) {
      if (!str || !str.trim()) return false;
      const stripped = str.replace(/\s/g, '');
      if (!stripped) return false;
      const emojiRegex = /^(?:\p{Emoji_Presentation}|\p{Extended_Pictographic}|\p{Emoji}\uFE0F|\uD83C[\uDDE0-\uDDFF][\uD83C][\uDDE0-\uDDFF]|[\u0023\u002A\u0030-\u0039]\uFE0F?\u20E3|\u200D)+$/u;
      return emojiRegex.test(stripped);
    }

    // ── Auto-linkify URLs inside message text ──
    // Turns any plain-text http(s):// or www. URL found in a message bubble
    // into a real, clickable <a> that opens in a new tab. Only touches raw
    // text nodes (never other markup already inside the bubble, e.g. images)
    // and marks each content element once it's been processed so re-running
    // this on every poll/reconcile never double-wraps an already-linkified
    // message.
    const URL_REGEX = /((?:https?:\/\/|www\.)[^\s<]+)/gi;

    function linkifyContent(contentEl) {
      if (!contentEl || contentEl.dataset.linkified === '1') return;
      contentEl.dataset.linkified = '1';

      const walker = document.createTreeWalker(contentEl, NodeFilter.SHOW_TEXT, null);
      const textNodes = [];
      let node;
      while ((node = walker.nextNode())) {
        // Skip text that's already inside a link (e.g. from a future
        // server-rendered link) to avoid nesting anchors.
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
          // Trim common trailing punctuation that's likely part of the
          // sentence rather than the URL itself (e.g. "check this out: https://x.com/foo.")
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

          lastIndex = start + url.length;
          if (trailing) {
            frag.appendChild(document.createTextNode(trailing));
            lastIndex += trailing.length;
          }
        }
        frag.appendChild(document.createTextNode(text.slice(lastIndex)));
        textNode.parentNode.replaceChild(frag, textNode);
      });
    }

    // Walk all rendered bubbles and apply / remove the emoji-only class.
    // Also force image/audio (.message-media) timestamps to stay permanently
    // visible — unlike text bubbles, their date/time should never be hidden
    // by the click-to-toggle behavior below.
    function applyEmojiOnly() {
      chatBox.querySelectorAll('.message-bubble').forEach(function(bubble) {
        const contentEl = bubble.querySelector('.message-content');
        if (!contentEl) return;
        linkifyContent(contentEl);
        const text = contentEl.textContent || '';
        if (isEmojiOnly(text)) {
          bubble.classList.add('emoji-only');
        } else {
          bubble.classList.remove('emoji-only');
        }
        applyReadMoreToElement(contentEl);
      });

      chatBox.querySelectorAll('.message-media').forEach(function(media) {
        const wrapper = media.closest('.bubble-wrapper');
        const timestamp = wrapper && wrapper.querySelector('.message-click-timestamp');
        if (timestamp) timestamp.classList.add('show-timestamp');
      });
    }

    // Get current time formatted
    function getCurrentTime() {
      const now = new Date();
      return now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // Helper: extract a stable key from a message element
    function getMessageKey(el) {
      if (el && typeof el.getAttribute === 'function') {
        const msgId = el.getAttribute('data-msg-id');
        if (msgId) return msgId;
      }
      if (el && el.classList && el.classList.contains('empty-chat')) {
        return 'empty-chat|' + (el.textContent || '').trim().replace(/\s+/g, ' ');
      }
      const sender  = (el.querySelector('.message-sender')?.textContent?.trim() || '').toLowerCase();
      const time    = (el.querySelector('.message-time') || el.querySelector('.message-click-timestamp'))?.textContent?.trim() || '';
      const content = el.querySelector('.message-content')?.textContent?.trim() || '';
      return sender + '|' + time + '|' + content;
    }

    // Track if this is the first load (page refresh/initial visit)
    let isFirstLoad = true;

    // Guard against overlapping load.php requests — never block for isSending/isUploading
    let isLoadingChat = false;

    // ── loadGlobalChat: fetches from load.php with pagination ────────────────
    let isLoadingGC = false;

    function processGlobalChatData(data, loadOlderMode = false) {
      const newHtml    = data.html || '';
      gcHasMore        = data.hasMore || false;

      if (loadOlderMode) {
        gcCursor = data.nextCursor || '';
        gcViewingOlder = true;
        // Prepend older messages
        const prev = chatBox.scrollHeight;
        const temp = document.createElement('div');
        temp.innerHTML = newHtml;
        const oldItems = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
        const firstChild = chatBox.firstChild;
        const btn = document.getElementById('loadOlderBtn');
        oldItems.reverse().forEach(el => {
          if (btn) chatBox.insertBefore(el, btn.nextSibling);
          else chatBox.insertBefore(el, firstChild);
        });
        // Maintain scroll position
        chatBox.scrollTop += chatBox.scrollHeight - prev;
        // Swap the window: drop the newest messages off the bottom so the
        // total on screen stays capped at PAGE_SIZE instead of growing forever.
        trimWindowFromBottom(PAGE_SIZE);
        if (!gcHasMore) showNoMoreOlderNotice(); else if (!document.getElementById('loadOlderBtn')) insertLoadOlderBtn();
        applyAdminBadges();
        applyEmojiOnly();
        return;
      }

      const wasAtBottom = isAtBottom();
      if (gcCursor === '') gcCursor = data.nextCursor || ''; // establish the cursor pointer
      const temp = document.createElement('div');
      temp.innerHTML = newHtml;
      const newMessages     = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
      const currentMessages = Array.from(chatBox.querySelectorAll('.message-container:not([data-sending-uid]):not([data-upload-uid]), .empty-chat'));
      const newKeys = newMessages.map(getMessageKey);
      const curKeys = currentMessages.map(getMessageKey);

      const rec = reconcilePoll(newMessages, currentMessages, newKeys, curKeys);

      if (rec.type === 'nochange') {
        if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
        if (gcHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
        applyEmojiOnly();
        return;
      }

      if (rec.type === 'append') {
        const toInsert = [];
        rec.items.forEach(el => {
          if (el.classList.contains('message-container')) {
            const msgId = el.getAttribute('data-msg-id');
            if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
              return; // Deduplicate: already in DOM
            }
          }
          toInsert.push(el);
        });

        if (toInsert.length === 0) {
          document.querySelectorAll('[data-sending-uid]').forEach(el => el.remove());
          if (!gcViewingOlder) trimWindowFromTop(PAGE_SIZE);
          applyAdminBadges(); applyEmojiOnly();
          if (gcHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
          return;
        }

        const STAGGER_MS = 90;      // gap between each message appearing
        const MAX_STAGGER = 8;      // cap: beyond this many messages, no extra delay
        const useStagger = !isFirstLoad && toInsert.length > 1;

        toInsert.forEach(el => {
          if (useStagger && el.classList.contains('message-container')) {
            el.classList.add('gc-msg-pending');
          }
          chatBox.appendChild(el);
        });
        document.querySelectorAll('[data-sending-uid]').forEach(el => el.remove());
        if (!gcViewingOlder) trimWindowFromTop(PAGE_SIZE);

        let revealedCount = 0;
        toInsert.forEach((el, i) => {
          const delay = useStagger ? Math.min(i, MAX_STAGGER) * STAGGER_MS : 0;
          setTimeout(() => {
            revealedCount++;
            if (el.isConnected) {
              el.classList.remove('gc-msg-pending');
              if (el.classList.contains('message-container')) {
                const animClass = el.classList.contains('sent') ? 'msg-animate-sent' : 'msg-animate-received';
                el.classList.add(animClass);
                el.addEventListener('animationend', () => el.classList.remove(animClass), { once: true });
              }
            }
            if (revealedCount === toInsert.length) {
              if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
              else if (wasAtBottom || shouldAutoScroll) requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
              else showScrollIndicator(toInsert.filter(el => el.classList.contains('message-container')).length);
              applyAdminBadges(); applyEmojiOnly(); attachImageLoadListeners();
              if (gcHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
            }
          }, delay);
        });
        return;
      }

      // Full re-render (only when we truly can't reconcile, e.g. chat was cleared)
      const prevST = chatBox.scrollTop; const prevSH = chatBox.scrollHeight;
      const curKeySet = new Set(curKeys);
      const genuinelyNewCount = newMessages.filter(el =>
        el.classList.contains('message-container') && !curKeySet.has(getMessageKey(el))
      ).length;
      currentMessages.forEach(el => el.remove());
      
      // Deduplicate newMessages during full re-render
      const renderedIds = new Set();
      newMessages.forEach(el => {
        if (el.classList.contains('message-container')) {
          const msgId = el.getAttribute('data-msg-id');
          if (msgId) {
            if (renderedIds.has(msgId)) return;
            renderedIds.add(msgId);
          }
        }
        chatBox.appendChild(el);
      });
      
      document.querySelectorAll('[data-sending-uid]').forEach(el => el.remove());
      chatBox.scrollTop = Math.max(0, prevST + chatBox.scrollHeight - prevSH);
      if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
      else if (wasAtBottom || shouldAutoScroll || isFirstLoad) requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
      else if (genuinelyNewCount > 0) showScrollIndicator(genuinelyNewCount);
      applyAdminBadges(); applyEmojiOnly();
      // Chat was rebuilt from scratch (e.g. cleared), so pagination state no longer applies
      gcCursor = data.nextCursor || '';
      gcViewingOlder = false;
      if (gcHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
    }

    function loadGlobalChat(isAutoPoll = false, loadOlderMode = false) {
      if (!isGlobalChat) return;
      if (isLoadingGC) return;
      if (isAutoPoll && !loadOlderMode && gcViewingOlder) return;
      isLoadingGC = true;

      const cursor = loadOlderMode ? gcCursor : '';

      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'load.php?before_uuid=' + encodeURIComponent(cursor), true);
      xhr.onload = function() {
        isLoadingGC = false;
        if (this.status !== 200) return;
        let data;
        try { data = JSON.parse(this.responseText); } catch(e) { return; }
        processGlobalChatData(data, loadOlderMode);
      };
      xhr.onerror = function() { isLoadingGC = false; };
      xhr.send();
    }

    // ── loadChat: fetches from load_dm.php with pagination ────────────────────
    let chatXhr = null;

    function processChatData(data, requestedUser, loadOlderMode = false) {
      if (requestedUser !== activeDM) return;
      const newHtml = data.html || '';
      dmHasMore = data.hasMore || false;
      if (typeof data.readUpTo !== 'undefined') dmReadUpTo = data.readUpTo;

      if (loadOlderMode) {
        dmCursor = data.nextCursor || '';
        dmViewingOlder = true;
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
        if (!dmHasMore) showNoMoreOlderNotice(); else if (!document.getElementById('loadOlderBtn')) insertLoadOlderBtn();
        applyAdminBadges(); applyEmojiOnly();
        updateSeenIndicator();
        return;
      }

      if (!newHtml.trim()) {
        chatBox.innerHTML = '';
        isFirstLoad = false;
        return;
      }

      const wasAtBottom = isAtBottom();
      if (dmCursor === '') dmCursor = data.nextCursor || '';
      const temp = document.createElement('div');
      temp.innerHTML = newHtml;
      const newMessages     = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
      const currentMessages = Array.from(chatBox.querySelectorAll('.message-container:not([data-sending-uid]):not([data-upload-uid]), .empty-chat'));
      const newKeys = newMessages.map(getMessageKey);
      const curKeys = currentMessages.map(getMessageKey);

      const rec = reconcilePoll(newMessages, currentMessages, newKeys, curKeys);

      if (rec.type === 'nochange') {
        if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
        if (dmHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
        if (!document.hidden && activeDM) markRead(activeDM);
        updateSeenIndicator();
        applyEmojiOnly();
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
        if (!dmViewingOlder) {
          trimWindowFromTop(PAGE_SIZE);
        }
        if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
        else if (wasAtBottom || shouldAutoScroll) requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
        else showScrollIndicator(rec.items.filter(el => el.classList.contains('message-container')).length);
        applyAdminBadges(); applyEmojiOnly();
        if (!document.hidden && activeDM) markRead(activeDM);
        updateSeenIndicator();
        if (dmHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
        return;
      }

      // Full re-render (only when we truly can't reconcile, e.g. chat was cleared)
      const prevSTF = chatBox.scrollTop; const prevSHF = chatBox.scrollHeight;
      const curKeySetF = new Set(curKeys);
      const genuinelyNewCountF = newMessages.filter(el =>
        el.classList.contains('message-container') && !curKeySetF.has(getMessageKey(el))
      ).length;
      currentMessages.forEach(el => el.remove());
      
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
      applyAdminBadges(); applyEmojiOnly(); attachImageLoadListeners();
      if (!document.hidden && activeDM) markRead(activeDM);
      updateSeenIndicator();
      dmCursor = data.nextCursor || '';
      dmViewingOlder = false;
      if (dmHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
    }

    function loadChat(isAutoPoll = false, loadOlderMode = false, force = false) {
      if (isAdminAllChatsView || activeAdminConv) return;
      if (!activeDM) return;
      if (isAutoPoll && !loadOlderMode && dmViewingOlder) return;
      if (isLoadingChat) {
        if (!force) return;
        if (chatXhr) chatXhr.abort();
        isLoadingChat = false;
      }
      isLoadingChat = true;

      const requestedUser = activeDM;
      const cursor = loadOlderMode ? dmCursor : '';
      const url = 'load_dm.php?target_id=' + encodeURIComponent(activeDMAccountId || 0) + '&target_user=' + encodeURIComponent(activeDM) + '&before_uuid=' + encodeURIComponent(cursor);

      const xhr = new XMLHttpRequest();
      chatXhr = xhr;
      xhr.open('GET', url, true);
      xhr.onload = function () {
        isLoadingChat = false;
        if (chatXhr === xhr) chatXhr = null;
        if (this.status !== 200) return;
        if (requestedUser !== activeDM) return;
        let data;
        try { data = JSON.parse(this.responseText); } catch(e) { return; }
        processChatData(data, requestedUser, loadOlderMode);
      };
      xhr.onerror = function() { isLoadingChat = false; if (chatXhr === xhr) chatXhr = null; };
      xhr.send();
    }

    function loadOlderMessages() {
      if (activeAdminConv || isAdminAllChatsView) {
        if (activeAdminConv) loadAdminConv(activeAdminConv, false, true);
      } else if (isGlobalChat) {
        loadGlobalChat(false, true);
      } else if (activeDM) {
        loadChat(false, true);
      }
    }

    function deleteChat() {
      const secret = secretInput.value.trim();
      
      if (!secret) {
        secretError.style.display = 'block';
        secretError.textContent = 'Please enter secret key';
        secretError.style.color = 'red';
        secretInput.focus();
        return;
      }

      // Close the confirmation modal immediately and swap in the clearing
      // progress modal, matching the file-upload progress flow.
      closeModal();
      showClearingModal('Clearing conversation...');

      // Force the browser to paint the 0% frame before we start mutating
      // the bar. Without this, on a very fast (e.g. local) response the
      // "show modal" + "animate" + "close modal" calls can all happen
      // inside the same tick and the browser never actually renders a
      // frame in between — the modal opens and closes in one paint cycle,
      // which looks indistinguishable from "never showed up" even though
      // the DOM technically went through the motions.
      const clearingModalEl = document.getElementById('clearingChatModal');
      if (clearingModalEl) void clearingModalEl.offsetHeight;

      const clearingStartedAt = Date.now();
      const CLEARING_MIN_VISIBLE_MS = 900; // guarantee the modal is on screen long enough to register

      // There's no real byte-progress for this request (it's a tiny POST),
      // so fake a smooth climb toward 90% while it's in flight, the same
      // way upload.php's request is padded to feel alive. It snaps to 100%
      // only once the server actually confirms success AND the minimum
      // visible duration above has elapsed.
      let clearingProgress = 0;
      const clearingInterval = setInterval(function() {
        clearingProgress += (90 - clearingProgress) * 0.15;
        if (clearingProgress > 89) clearingProgress = 89;
        updateClearingProgress(clearingProgress);
      }, 100);

      function finishClearingSuccess() {
        const elapsed = Date.now() - clearingStartedAt;
        const remaining = Math.max(0, CLEARING_MIN_VISIBLE_MS - elapsed);
        setTimeout(function() {
          updateClearingProgress(100);
          setTimeout(closeClearingModal, 300);
        }, remaining);
      }

      function finishClearingError(message) {
        const elapsed = Date.now() - clearingStartedAt;
        const remaining = Math.max(0, CLEARING_MIN_VISIBLE_MS - elapsed);
        setTimeout(function() {
          closeClearingModal();
          if (confirmModal) {
            confirmModal.classList.add('active');
            document.body.style.overflow = 'hidden';
          }
          secretError.style.display = 'block';
          secretError.textContent = message;
          secretError.style.color = 'red';
        }, remaining);
      }

      const xhr = new XMLHttpRequest();
      xhr.open("POST", "delete_dm.php", true);
      xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      xhr.onload = function () {
        clearInterval(clearingInterval);

        if (this.status === 200) {
          shouldAutoScroll = true;
          userScrolledUp = false;
          hideScrollIndicator();
          if (activeDM) {
            loadChat();
          } else if (activeAdminConv) {
            loadAdminConv(activeAdminConv, false);
            fetchUsers();
          }

          // Broadcast the clear so every other connected client (the other
          // party in the DM, or any other admin viewing the same admin
          // conversation) refreshes immediately instead of showing stale
          // messages until their next poll/reload.
          if (ws && ws.readyState === WebSocket.OPEN) {
            if (activeDM) {
              ws.send(JSON.stringify({
                type: 'chat_cleared',
                chat_type: 'private',
                recipient_id: activeDMAccountId
              }));
            } else if (activeAdminConv) {
              const clearedParts = activeAdminConv.split('_').map(Number);
              ws.send(JSON.stringify({
                type: 'chat_cleared',
                chat_type: 'admin_conv',
                user_a: clearedParts[0],
                user_b: clearedParts[1]
              }));
            }
          }

          // Reset secret input
          secretInput.value = '';
          secretError.style.display = 'none';

          finishClearingSuccess();
        } else {
          finishClearingError('Error: ' + this.responseText);
        }
      };
      xhr.onerror = function() {
        clearInterval(clearingInterval);
        finishClearingError('Network error — please try again');
      };

      let params = "secret=" + encodeURIComponent(secret);
      if (activeDMAccountId) {
        params += "&target_id=" + encodeURIComponent(activeDMAccountId) + "&target_user=" + encodeURIComponent(activeDM);
      } else if (activeDM) {
        params += "&target_user=" + encodeURIComponent(activeDM);
      } else if (activeAdminConv) {
        params += "&conv_id=" + encodeURIComponent(activeAdminConv);
      }
      xhr.send(params);
    }

    // ── Clearing Chat Progress Modal Controls (mirrors the upload progress modal) ──
    function showClearingModal(label) {
      const modal = document.getElementById('clearingChatModal');
      const labelEl = document.getElementById('clearingChatLabel');
      const bar = document.getElementById('clearingChatProgressBar');
      const text = document.getElementById('clearingChatProgressText');

      if (!modal) {
        // If this fires, #clearingChatModal isn't in the DOM — almost always
        // means index.php wasn't redeployed/refreshed with the updated markup.
        console.error('[clearChat] #clearingChatModal not found in DOM — is index.php up to date?');
        return;
      }

      if (labelEl) labelEl.textContent = label || 'Clearing conversation...';
      if (bar) bar.style.width = '0%';
      if (text) text.textContent = '0%';

      modal.style.display = 'flex';
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function updateClearingProgress(percent) {
      const bar = document.getElementById('clearingChatProgressBar');
      const text = document.getElementById('clearingChatProgressText');
      const p = Math.min(100, Math.max(0, Math.round(percent)));
      if (bar) bar.style.width = p + '%';
      if (text) text.textContent = p + '%';
    }

    function closeClearingModal() {
      const modal = document.getElementById('clearingChatModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
      }
      document.body.style.overflow = '';
    }

    function showModal() {
      // Check if user is admin before showing modal
      if (!isAdmin) {
        alert("Only administrators can clear the chat.");
        return;
      }
      
      if (!activeDM && !activeAdminConv) {
        alert("Please select a conversation to clear first.");
        return;
      }
      
      if (confirmModal) {
        confirmModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        secretInput.value = '';
        secretError.style.display = 'none';
        confirmClear.disabled = true;
        setTimeout(() => secretInput.focus(), 200);
      }
    }

    function closeModal() {
      if (confirmModal) {
        confirmModal.classList.remove('active');
        document.body.style.overflow = '';
      }
    }

    // =========================================================================
    // "/backup" — explicit, job-tracked full backup (separate from /clear)
    // =========================================================================

    // Module-level state so polling survives the progress modal being closed
    // or "backgrounded" — the whole point is the backup keeps going and the
    // admin can check back in on it.
    let backupJobId = null;
    let backupPollTimer = null;
    let backupFakeProgress = 0;
    let backupFakeProgressTimer = null;

    function showBackupConfirmModal() {
      if (!isAdmin) {
        alert("Only administrators can run a backup.");
        return;
      }
      const modal = document.getElementById('backupConfirmModal');
      const secretInputEl = document.getElementById('backupSecretInput');
      const secretErrorEl = document.getElementById('backupSecretError');
      const confirmBtn = document.getElementById('confirmBackup');
      if (!modal) return;

      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (secretInputEl) secretInputEl.value = '';
      if (secretErrorEl) secretErrorEl.style.display = 'none';
      if (confirmBtn) confirmBtn.disabled = true;
      setTimeout(() => secretInputEl && secretInputEl.focus(), 200);
    }

    function closeBackupConfirmModal() {
      const modal = document.getElementById('backupConfirmModal');
      if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
      }
      document.body.style.overflow = '';
    }

    function showBackupProgressModal() {
      const modal = document.getElementById('backupChatModal');
      const bar = document.getElementById('backupChatProgressBar');
      const text = document.getElementById('backupChatProgressText');
      const label = document.getElementById('backupChatLabel');
      if (!modal) return;

      if (label) label.textContent = 'Backup in progress...';
      if (bar) bar.style.width = '0%';
      if (text) text.textContent = '0%';

      modal.style.display = 'flex';
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function updateBackupProgress(percent) {
      const bar = document.getElementById('backupChatProgressBar');
      const text = document.getElementById('backupChatProgressText');
      const p = Math.min(100, Math.max(0, Math.round(percent)));
      if (bar) bar.style.width = p + '%';
      if (text) text.textContent = p + '%';
    }

    function closeBackupProgressModal() {
      const modal = document.getElementById('backupChatModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
      }
      document.body.style.overflow = '';
    }

    function showBackupAlreadyDoneModal() {
      const modal = document.getElementById('backupAlreadyDoneModal');
      if (!modal) return;
      modal.style.display = 'flex';
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeBackupAlreadyDoneModal() {
      const modal = document.getElementById('backupAlreadyDoneModal');
      if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        modal.style.display = 'none';
      }
      document.body.style.overflow = '';
    }

    function stopBackupPolling() {
      if (backupPollTimer) { clearInterval(backupPollTimer); backupPollTimer = null; }
      if (backupFakeProgressTimer) { clearInterval(backupFakeProgressTimer); backupFakeProgressTimer = null; }
    }

    // There's no per-row progress from the server (it's a single INSERT...SELECT),
    // so — EXACT same trick as the clear-chat modal — fake a smooth climb toward
    // 90% while the job is "running" (same 0.15 easing factor, same 100ms tick,
    // same 89% cap), and only snap to 100% once backup_status.php actually
    // reports status=completed, respecting the same minimum-visible-time before
    // closing so it never looks like it flashed and vanished.
    const BACKUP_MIN_VISIBLE_MS = 900;
    let backupStartedAt = 0;

    function finishBackupSuccess(rowsBackedUp) {
      const elapsed = Date.now() - backupStartedAt;
      const remaining = Math.max(0, BACKUP_MIN_VISIBLE_MS - elapsed);
      setTimeout(function() {
        updateBackupProgress(100);
        const label = document.getElementById('backupChatLabel');
        if (label) label.textContent = 'Backup complete (' + rowsBackedUp + ' messages archived)';
        setTimeout(closeBackupProgressModal, 300);
      }, remaining);
    }

    // Mirrors finishClearingError()'s pattern: close the progress modal and
    // reopen the confirm modal with the error shown inline, instead of a
    // separate background/error indicator.
    function finishBackupError(message) {
      const elapsed = Date.now() - backupStartedAt;
      const remaining = Math.max(0, BACKUP_MIN_VISIBLE_MS - elapsed);
      setTimeout(function() {
        closeBackupProgressModal();
        const modal = document.getElementById('backupConfirmModal');
        const secretErrorEl = document.getElementById('backupSecretError');
        if (modal) {
          modal.classList.add('active');
          modal.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
        }
        if (secretErrorEl) {
          secretErrorEl.style.display = 'block';
          secretErrorEl.textContent = message;
          secretErrorEl.style.color = 'red';
        }
      }, remaining);
    }

    function startBackupPolling(jobId) {
      backupJobId = jobId;
      backupFakeProgress = 0;
      backupStartedAt = Date.now();
      stopBackupPolling();

      backupFakeProgressTimer = setInterval(function() {
        backupFakeProgress += (90 - backupFakeProgress) * 0.15;
        if (backupFakeProgress > 89) backupFakeProgress = 89;
        updateBackupProgress(backupFakeProgress);
      }, 100);

      backupPollTimer = setInterval(function() {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', 'backup_status.php?job_id=' + encodeURIComponent(jobId), true);
        xhr.onload = function() {
          if (this.status !== 200) return;
          let res;
          try { res = JSON.parse(this.responseText); } catch (e) { return; }
          if (!res || !res.ok) return;

          if (res.status === 'completed') {
            stopBackupPolling();
            finishBackupSuccess(res.rows_backed_up);
          } else if (res.status === 'failed') {
            stopBackupPolling();
            finishBackupError('Backup failed: ' + (res.error || 'unknown error'));
          }
          // status === 'running' → keep polling, keep faking progress
        };
        xhr.send();
      }, 1500);
    }

    function runBackup() {
      const secretInputEl = document.getElementById('backupSecretInput');
      const secretErrorEl = document.getElementById('backupSecretError');
      const secret = secretInputEl ? secretInputEl.value.trim() : '';

      if (!secret) {
        if (secretErrorEl) {
          secretErrorEl.style.display = 'block';
          secretErrorEl.textContent = 'Please enter secret key';
          secretErrorEl.style.color = 'red';
        }
        if (secretInputEl) secretInputEl.focus();
        return;
      }

      closeBackupConfirmModal();
      showBackupProgressModal();
      backupStartedAt = Date.now(); // so an immediate failure still respects BACKUP_MIN_VISIBLE_MS

      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'backup_dm.php', true);
      xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        if (this.status !== 200) {
          finishBackupError('Could not start backup: ' + this.responseText);
          return;
        }
        let res;
        try { res = JSON.parse(this.responseText); } catch (e) { res = null; }
        if (!res || !res.ok) {
          finishBackupError('Could not start backup: ' + (res && res.error ? res.error : 'unknown error'));
          return;
        }
        if (res.already_backed_up) {
          closeBackupProgressModal();
          showBackupAlreadyDoneModal();
          return;
        }
        if (!res.job_id) {
          finishBackupError('Could not start backup: ' + (res.error || 'unknown error'));
          return;
        }
        startBackupPolling(res.job_id);
      };
      xhr.onerror = function() {
        finishBackupError('Network error — please try again');
      };
      xhr.send('secret=' + encodeURIComponent(secret));

      if (secretInputEl) secretInputEl.value = '';
      if (secretErrorEl) secretErrorEl.style.display = 'none';
    }

    // Counter for unique sending-indicator IDs (supports rapid-fire sends)
    let sendingUidCounter = 0;

    // loadChat wrapper that forces a fresh load even if one is in-flight.
    // Used by send confirmations so we never miss a message after rapid sends.
    function loadChatForced() {
      isLoadingChat = false; // reset guard so the next call goes through
      loadChat();
    }

    // Event Letters
    document.getElementById("chatForm").addEventListener("submit", function (e) {
      e.preventDefault();

      // ── Super Admin chat command: "/clear" ──────────────────────────────────
      // Intercepted before anything else (including the admin-spy-mode early
      // return below) so that "/clear" works while Spy Mode is open on a
      // conversation. The command is never sent as a message, never appended to
      // history, and never broadcast — it just opens the existing confirmation
      // modal. All real permission checks still happen server-side exactly as
      // before (validate_secret.php / delete_dm.php via Auth::isAdmin()); this
      // is purely an alternate way to trigger the modal.
      if (isAdmin) {
        const cmd = messageInput.value.trim().toLowerCase();
        if (cmd === '/clear') {
          messageInput.value = '';
          messageInput.style.height = 'auto';
          messageInput.style.color = '';
          showModal();
          return;
        }

        // ── Super Admin chat command: "/backup" ──────────────────────────
        // Opens the backup confirmation modal. Unlike "/clear" this isn't
        // tied to a specific conversation — it always backs up everything,
        // so it deliberately has NO activeDM/activeAdminConv/isGlobalChat
        // requirement anywhere in its path. Works from a totally empty
        // "no conversation selected" screen as long as the admin can type
        // into the message box and hit send.
        if (cmd === '/backup') {
          messageInput.value = '';
          messageInput.style.height = 'auto';
          messageInput.style.color = '';
          showBackupConfirmModal();
          return;
        }
      }

      if (isAdminAllChatsView || activeAdminConv) {
        return;
      }

      const name = nameInput.value.trim();
      const message = messageInput.value.trim();

      if (!activeDM && !isGlobalChat) {
        alert("Please select a chat first.");
        return;
      }

      if (!name || !message) {
        if (!message) messageInput.focus();
        return;
      }

      // Disable send button momentarily to prevent double-tap
      isSending = true;
      sendButton.textContent = "...";
      sendButton.disabled = true;

      // Clear input immediately and reset textarea to single-line height.
      // Note: overflow-y is intentionally left alone here — it's controlled
      // entirely by CSS (overflow-y: scroll, scrollbar visually hidden via
      // scrollbar-width/::-webkit-scrollbar). Setting it inline to 'hidden'
      // used to permanently override that CSS rule after the first send,
      // silently breaking scroll on every long message typed afterward.
      messageInput.value = "";
      messageInput.style.height = 'auto';

      // iOS: snap footer back to its default (single-line) position right away.
      // Double-rAF ensures the browser has reflowed the collapsed textarea before
      // we read its new offsetHeight — prevents the white-gap flash.
      if (isIOS && window.visualViewport) {
        requestAnimationFrame(function() {
          requestAnimationFrame(applyIOSViewport);
        });
      }

      // Keep keyboard open on mobile by immediately refocusing.
      // Suppress the blur→resetIOSViewport that fires when .focus() briefly
      // blurs the element — the keyboard never actually closes here.
      if (isIOS) iosBlurSuppressed = true;
      messageInput.focus();
      if (isIOS) iosBlurSuppressed = false;

      // Show optimistic "Sending..." bubble immediately (only if NOT editing).
      let sendIndId = null;
      if (!editingMsgId) {
        const emptyChat = chatBox.querySelector('.empty-chat');
        if (emptyChat) emptyChat.remove();

        const sendUid = ++sendingUidCounter;
        sendIndId = 'sending-indicator-' + sendUid;

        const sendingBubble = document.createElement('div');
        sendingBubble.id = sendIndId;
        sendingBubble.setAttribute('data-sending-uid', sendUid);
        sendingBubble.className = 'message-container sent msg-animate-sent';
        sendingBubble.innerHTML = `
          <div class="message-bubble sending-bubble">
            <div class="message-content sending-dots"><span></span><span></span><span></span></div>
          </div>
          <div class="message-avatar">${getInitials(name)}</div>
        `;
        sendingBubble.addEventListener('animationend', () => sendingBubble.classList.remove('msg-animate-sent'), { once: true });
        // Append optimistic sending bubble into floating overlay so it doesn't reflow chat
        const overlay3 = getSendingOverlay();
        if (overlay3) overlay3.appendChild(sendingBubble);
        else chatBox.appendChild(sendingBubble);
        shouldAutoScroll = true;
        userScrolledUp = false;
        // Always scroll for the user's own outgoing message — isAtBottom() can read
        // stale while the virtual keyboard is open (viewport/chatBox height is still
        // reflowing), which silently blocked this scroll and left the new message
        // hidden below the fold when the keyboard was up.
        scrollToBottom(true, true);
      }

      const xhr = new XMLHttpRequest();
      let payload = '';

      if (editingMsgId) {
        xhr.open('POST', 'edit_message.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        payload = 'msg_uuid=' + encodeURIComponent(editingMsgId) + '&message=' + encodeURIComponent(message);
      } else {
        // Route to correct send endpoint
        if (isGlobalChat) {
          xhr.open('POST', 'send.php', true);
        } else {
          xhr.open('POST', 'send_dm.php', true);
        }
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        payload = isGlobalChat
          ? 'message=' + encodeURIComponent(message)
          : 'target_id=' + encodeURIComponent(activeDMAccountId || 0) + '&target_user=' + encodeURIComponent(activeDM) + '&message=' + encodeURIComponent(message);
      }

      // Fire the XHR immediately — no artificial delay. The "Sending..." bubble
      // animation above already gives instant visual feedback that the send
      // was registered, so re-enable send controls right after dispatching.
      try { xhr.send(payload); } catch (e) { /* ignore send errors here */ }
      isSending = false;
      sendButton.textContent = "Send";
      sendButton.disabled = false;

      xhr.onload = function () {
        if (this.status === 200) {
          // Stop typing indicator immediately on successful send
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

          // Optimistically patch the edited bubble in-place so it updates
          // instantly without waiting for loadChatForced() to re-render.
          let capturedEditingMsgId = null;
          if (editingMsgId) {
            capturedEditingMsgId = editingMsgId;
            const editedContainer = chatBox.querySelector(
              `.message-container[data-msg-id="${editingMsgId}"]`
            );
            if (editedContainer) {
              const editedBubble = editedContainer.querySelector('.message-bubble');
              const contentEl = editedContainer.querySelector('.message-bubble .message-content');
              if (contentEl) {
                contentEl.textContent = message;
                delete contentEl.dataset.linkified;
                linkifyContent(contentEl);
                reapplyReadMore(contentEl);
              }
              // Re-evaluate emoji-only styling since editing can change whether
              // the message is now (or is no longer) emoji-only.
              if (editedBubble) {
                editedBubble.classList.toggle('emoji-only', isEmojiOnly(message));
              }

              // Inject "edited" label if not already present
              const bubbleWrapper = editedContainer.querySelector('.bubble-wrapper');
              if (bubbleWrapper && !bubbleWrapper.querySelector('.message-edited-label')) {
                const label = document.createElement('div');
                label.className = 'message-edited-label';
                label.style.cssText = 'font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;';
                label.textContent = 'edited';
                bubbleWrapper.insertBefore(label, bubbleWrapper.firstChild);
              }
            }
            hideEditBanner();
          }

          let resData = null;
          try { resData = JSON.parse(this.responseText); } catch(e) {}
          const confirmedMsg = (resData && resData.message) ? resData.message : null;

          // Convert optimistic sending bubble in-place immediately without full chat reload
          if (!editingMsgId && sendIndId) {
            const sendingBubble = document.getElementById(sendIndId);
            if (sendingBubble) {
              if (confirmedMsg && confirmedMsg.id) {
                const existingInChatBox = chatBox.querySelector(`.message-container[data-msg-id="${confirmedMsg.id}"]`);
                if (existingInChatBox) {
                  if (sendingBubble.parentNode) sendingBubble.parentNode.removeChild(sendingBubble);
                } else {
                  sendingBubble.setAttribute('data-msg-id', confirmedMsg.id);
                  sendingBubble.removeAttribute('id');
                  sendingBubble.removeAttribute('data-sending-uid');
                
                // Move from floating sending overlay to main chatBox if needed
                if (sendingBubble.parentNode && sendingBubble.parentNode !== chatBox) {
                  sendingBubble.parentNode.removeChild(sendingBubble);
                  chatBox.appendChild(sendingBubble);
                }

                const msgContent = confirmedMsg.plaintext || message;
                const d = new Date();
                const dateStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'long', day: 'numeric', year: 'numeric' });
                const timeStr = d.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
                const fullTimeDisplay = `${dateStr} at ${timeStr}`;
                const senderLabel = (name || 'you').toLowerCase();

                sendingBubble.className = 'message-container sent';
                const emojiOnlyClass = isEmojiOnly(msgContent) ? ' emoji-only' : '';
                sendingBubble.innerHTML = `
                  <div class="message-avatar">${getInitials(name)}</div>
                  <div class="bubble-wrapper">
                    <div class="message-click-timestamp">${fullTimeDisplay}</div>
                    <div class="message-bubble${emojiOnlyClass}">
                      <div class="message-content">${escapeHtml(msgContent)}</div>
                    </div>
                  </div>
                `;
                  const contentEl = sendingBubble.querySelector('.message-content');
                  if (contentEl) {
                    linkifyContent(contentEl);
                    applyReadMoreToElement(contentEl);
                  }
                  applyAdminBadges();
                  // The optimistic bubble just became a real, permanent
                  // .message-container inside chatBox — cap the window at
                  // PAGE_SIZE just like any other real-time append.
                  if (!gcViewingOlder && !dmViewingOlder) {
                    trimChatMessages(PAGE_SIZE);
                  }
                  // Always scroll for the user's own confirmed message — see note
                  // above the optimistic-bubble scroll for why isAtBottom() is
                  // unreliable while the virtual keyboard is open.
                  scrollToBottom(true, true);
                }
              }
            }
          }

          // Broadcast notification via WebSocket so other clients patch DOM
          if (ws && ws.readyState === WebSocket.OPEN) {
            const wasEditing = !!capturedEditingMsgId;
            if (wasEditing) {
              ws.send(JSON.stringify({
                type: 'message_edited',
                msg_uuid: capturedEditingMsgId,
                message: message,
                chat_type: isGlobalChat ? 'global' : 'private',
                recipient_id: activeDMAccountId || null
              }));
            } else {
              ws.send(JSON.stringify({
                type: 'message',
                chat_type: isGlobalChat ? 'global' : 'private',
                recipient_id: activeDMAccountId || null,
                msg_uuid: confirmedMsg ? confirmedMsg.id : null,
                message: confirmedMsg && confirmedMsg.plaintext ? confirmedMsg.plaintext : message,
                created_at: new Date().toISOString()
              }));
            }
          }

          // Fallback only if confirmedMsg missing
          if (!confirmedMsg) {
            if (isGlobalChat) { isLoadingGC = false; loadGlobalChat(false); }
            else loadChatForced();
          }

          // Bump the conversation to the top of the sidebar after a successful send.
          // No fetch needed — we just move the user row up in-place.
          if (!isGlobalChat && activeDM) {
            if (!bumpSidebarUser(activeDM, {})) {
              // Conversation partner isn't in the currently loaded/filtered
              // sidebar list (e.g. still under a search filter).
              fetchUsers();
            }
          }
        } else {
          // Server error — remove only THIS send's optimistic bubble
          if (sendIndId) {
            const indicator = document.getElementById(sendIndId);
            if (indicator) indicator.remove();
          }
        }
      };

      xhr.onerror = function() {
        const indicator = document.getElementById(sendIndId);
        if (indicator) indicator.remove();
      };
    });

    // Prevent send button from stealing focus (keeps keyboard open on mobile)
    sendButton.addEventListener('mousedown', function(e) {
      e.preventDefault();
    });

    // Mobile: use touchend (not touchstart) to avoid double-fire with the
    // browser's synthetic click event. bubbles:true ensures the form listener catches it.
    let touchFired = false;
    sendButton.addEventListener('touchend', function(e) {
      e.preventDefault(); // block the synthetic mouse click that follows
      if (touchFired) return;
      touchFired = true;
      document.getElementById('chatForm').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      setTimeout(() => { touchFired = false; }, 500);
    }, {passive: false});

    // Super Admin command visual indicator: while typing exactly "/clear"
    // (case-insensitive), color the whole input red so it's clear it will be
    // treated as a command. Purely cosmetic — has no bearing on whether the
    // command actually executes (that's still gated by isAdmin above and by
    // server-side Auth::isAdmin() checks on every endpoint).
    if (isAdmin) {
      messageInput.addEventListener('input', function() {
        const cmd = this.value.trim().toLowerCase();
        if (cmd === '/clear' || cmd === '/backup') {
          this.style.color = '#e74c3c';
        } else {
          this.style.color = '';
        }
      });
    }

    // Auto-expand textarea + typing indicator dispatch
    messageInput.addEventListener('input', function() {
      this.style.height = 'auto';
      const newHeight = Math.min(this.scrollHeight, 120);
      this.style.height = newHeight + 'px';
      // Keep overflow-y:scroll always (scrollbar hidden via CSS, not JS toggle)
      // iOS: recalculate layout whenever textarea height changes.
      // Double-rAF ensures we read offsetHeight AFTER the browser has fully
      // reflowed the textarea — otherwise footerH is stale → white gap appears.
      if (isIOS && window.visualViewport) {
        requestAnimationFrame(function() {
          requestAnimationFrame(applyIOSViewport);
        });
      }

      // Typing indicator: only fire for private DMs (not global, not admin spy)
      if (activeDM && activeDMAccountId && !isGlobalChat && !activeAdminConv) {
        if (!localIsTyping) {
          localIsTyping = true;
          sendTypingStatus(true);
          // Heartbeat: keep re-sending "true" every 2s while user keeps typing,
          // so the receiver's 4s auto-expire timer keeps getting refreshed.
          if (localTypingHeartbeat) clearInterval(localTypingHeartbeat);
          localTypingHeartbeat = setInterval(function() {
            if (localIsTyping) {
              sendTypingStatus(true);
            }
          }, 2000);
        }
        // Restart the idle timeout – if user stops typing for 3s, cancel indicator
        if (localTypingTimeout) clearTimeout(localTypingTimeout);
        localTypingTimeout = setTimeout(function() {
          localIsTyping = false;
          localTypingTimeout = null;
          if (localTypingHeartbeat) {
            clearInterval(localTypingHeartbeat);
            localTypingHeartbeat = null;
          }
          sendTypingStatus(false);
        }, 3000);
      }

      // Real-Time Typing Preview Keystroke Dispatch (300ms debounced)
      handleChatInputKeystroke();
    });

    // ── Real-Time Typing Preview & Communication Settings ────────────────
    const clientActivePreviews = new Map(); // senderId -> { preview }
    let typingPreviewDebounceTimer = null;

    function sendTypingPreview() {
      if (!activeDM || !activeDMAccountId || isGlobalChat || activeAdminConv) return;
      if (!ws || ws.readyState !== WebSocket.OPEN) return;

      const textInput = document.getElementById('messageInput');
      if (!textInput) return;

      const rawText = textInput.value || '';
      const cleanText = rawText.substring(0, 1000);

      // Default to true when settings object is missing (e.g. DB unavailable on load)
      const _senderAllowTyping = window.currentUserCommSettings
        ? window.currentUserCommSettings.allow_typing_preview !== false
        : true;
      const _senderAllowSee = window.currentUserCommSettings
        ? window.currentUserCommSettings.allow_see_typing_preview !== false
        : true;

      const payload = {
        type: 'typing_preview',
        recipient_id: activeDMAccountId,
        preview: cleanText,
        allow_typing_preview: _senderAllowTyping,
        allow_see_typing_preview: _senderAllowSee
      };

      ws.send(JSON.stringify(payload));
    }

    function handleChatInputKeystroke() {
      // Send immediately over WebSocket with ZERO DELAY for instant keystroke preview
      sendTypingPreview();
    }

    function handleIncomingTypingPreview(data) {
      const senderId = Number(data.sender_id);
      if (!senderId || senderId === wsConfig.accountId) return;

      // Only block if explicitly set to false; missing settings defaults to ON
      const allowSeePreview = window.currentUserCommSettings
        ? window.currentUserCommSettings.allow_see_typing_preview !== false
        : true;
      if (!allowSeePreview) {
        clientActivePreviews.delete(senderId);
        updateSidebarPreviewState(senderId, null);
        return;
      }

      const previewText = (data.preview || '').trim();

      if (!previewText) {
        clientActivePreviews.delete(senderId);
        updateSidebarPreviewState(senderId, null);
      } else {
        clientActivePreviews.set(senderId, {
          preview: previewText,
          isSent: false
        });

        const isChatOpenWithSender = (activeDM && activeDMAccountId === senderId);
        if (isChatOpenWithSender) {
          const senderUser = allUsersData.find(u => Number(u.account_id) === senderId);
          const senderName = senderUser ? senderUser.full_name : `User ${senderId}`;
          showTypingIndicator(senderName, true);
        }
        updateSidebarPreviewState(senderId, previewText);
      }
    }

    function handleIncomingTypingPreviewCleared(data) {
      const senderId = Number(data.sender_id);
      if (!senderId) return;

      clientActivePreviews.delete(senderId);
      updateSidebarPreviewState(senderId, null);
    }

    function handleIncomingTypingPreviewSent(data) {
      const senderId = Number(data.sender_id);
      if (!senderId) return;

      clientActivePreviews.set(senderId, {
        preview: '',
        isSent: true
      });
      updateSidebarPreviewState(senderId, null);
    }

    function updateSidebarPreviewState(senderId, previewText) {
      const user = allUsersData.find(u => Number(u.account_id) === Number(senderId));
      if (!user) return;
      const username = user.username;
      const rowItem = sidebarUserItems.get(username);
      if (!rowItem) return;

      const infoEl = rowItem.querySelector('.user-info');
      if (infoEl) {
        let lastMsgEl = infoEl.querySelector('.user-last-msg');
        if (!lastMsgEl) {
          lastMsgEl = document.createElement('div');
          lastMsgEl.className = 'user-last-msg';
          lastMsgEl.style.cssText = 'font-size:12px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;height:18px;';
          infoEl.appendChild(lastMsgEl);
        }

        if (previewText !== null && previewText !== '') {
          if (!lastMsgEl.dataset.originalText && lastMsgEl.style.fontStyle !== 'italic') {
            lastMsgEl.dataset.originalText = lastMsgEl.textContent;
          }
          lastMsgEl.textContent = previewText;
          lastMsgEl.style.fontStyle = 'italic';
          lastMsgEl.style.color = 'var(--primary-color, #1b74e4)';
        } else {
          const restoredText = '';
          lastMsgEl.textContent = restoredText;
          delete lastMsgEl.dataset.originalText;
          lastMsgEl.style.fontStyle = '';
          lastMsgEl.style.color = '';
        }
      }
    }

    // Immediately apply a comm setting change: update memory, broadcast via WS,
    // and persist to DB in the background (fire-and-forget).
    function applyCommSettingsChange(settings) {
      window.currentUserCommSettings = Object.assign(
        {}, window.currentUserCommSettings || {}, settings
      );
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({
          type: 'update_comm_settings',
          account_id: wsConfig.accountId,
          ...window.currentUserCommSettings
        }));
      }
      // Persist in background — failures are non-fatal; in-memory + WS state is already correct
      fetch('save_comm_settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(window.currentUserCommSettings)
      }).catch(() => {});
    }

    window.openCommSettingsModal = function() {
      const modal = document.getElementById('commSettingsModal');
      if (!modal) return;
      
      const chk1 = document.getElementById('chkAllowTypingPreview');
      const chk2 = document.getElementById('chkAllowSeeTypingPreview');

      const s = window.currentUserCommSettings || {};
      if (chk1) chk1.checked = s.allow_typing_preview !== false;
      if (chk2) chk2.checked = s.allow_see_typing_preview !== false;

      // Live toggle: apply change immediately on every checkbox click
      if (chk1 && !chk1._commListener) {
        chk1._commListener = true;
        chk1.addEventListener('change', () => {
          applyCommSettingsChange({ allow_typing_preview: chk1.checked });
        });
      }
      if (chk2 && !chk2._commListener) {
        chk2._commListener = true;
        chk2.addEventListener('change', () => {
          applyCommSettingsChange({ allow_see_typing_preview: chk2.checked });
        });
      }

      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
    };

    window.closeCommSettingsModal = function() {
      const modal = document.getElementById('commSettingsModal');
      if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
      }
    };

    window.saveCommSettings = function() {
      // Settings are already applied in real-time via checkbox change listeners.
      // "Save Settings" just closes the modal.
      closeCommSettingsModal();
    };

    // Backdrop click listener for closing comm settings modal
    document.getElementById('commSettingsModal')?.addEventListener('click', function(e) {
      if (e.target === this) closeCommSettingsModal();
    });

    // Attach click listener directly to settings button
    const commBtn = document.getElementById('commSettingsBtn');
    if (commBtn) {
      commBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        openCommSettingsModal();
      });
    }


    // Enter behavior differs by device:
    //  - Desktop / physical keyboard: Enter sends the message (Shift+Enter
    //    still makes a new line) — so a mouse click on Send isn't required.
    //  - Mobile / touch virtual keyboard: Enter always inserts a new line;
    //    sending only happens via a tap on the Send button. This avoids the
    //    on-screen keyboard's "send"/"go" key accidentally firing sends, and
    //    matches enterkeyhint="enter" set on the textarea above.
    // Detected independently here (not reused from isIOS below) so it's
    // available at the time this listener is registered, regardless of
    // script order. Deliberately UA-based only (no pointer:coarse check) —
    // many Windows laptops/2-in-1s report a coarse pointer even when used
    // with a physical keyboard, which was wrongly disabling Enter-to-send
    // on PCs.
    var isMobileInputDevice =
      /Android|iPhone|iPad|iPod|Mobile|Windows Phone/i.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPadOS

    messageInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey && !isMobileInputDevice) {
        e.preventDefault();
        document.getElementById('sendButton').click();
      }
    });

    // Only setup clear functionality if user is admin and modal exists.
    // (No longer gated on a header button — /clear command calls showModal() directly.)
    if (isAdmin && confirmModal && cancelClear && confirmClear && secretInput) {
      // Secret key validation (AJAX to validate_secret.php)
      secretInput.addEventListener('input', function() {
        if (secretInput.value.length === 0) {
          confirmClear.disabled = true;
          secretError.style.display = 'none';
          secretError.textContent = '';
          return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'validate_secret.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          if (this.status === 200) {
            try {
              const res = JSON.parse(this.responseText);
              confirmClear.disabled = !res.valid;
              if (res.valid) {
                secretError.style.display = 'block';
                secretError.textContent = 'Correct secret key';
                secretError.style.color = 'green';
              } else {
                secretError.style.display = 'block';
                secretError.textContent = 'Invalid secret key';
                secretError.style.color = 'red';
              }
            } catch (e) {
              confirmClear.disabled = true;
              secretError.style.display = 'block';
              secretError.textContent = 'Invalid secret key';
              secretError.style.color = 'red';
            }
          } else {
            confirmClear.disabled = true;
            secretError.style.display = 'block';
            secretError.textContent = 'Invalid secret key';
            secretError.style.color = 'red';
          }
        };
        xhr.send('secretKey=' + encodeURIComponent(secretInput.value));
      });

      // Allow Enter key to trigger delete if secret is correct
      secretInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (confirmClear.disabled) {
            secretError.style.display = 'block';
            secretInput.focus();
            return;
          }
          deleteChat();
        }
      });

      cancelClear.addEventListener("click", closeModal);
      confirmClear.addEventListener("click", function() {
        if (confirmClear.disabled) {
          secretError.style.display = 'block';
          secretInput.focus();
          return;
        }

        // Revalidate the secret key via AJAX before proceeding
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'validate_secret.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          if (this.status === 200) {
            try {
              const res = JSON.parse(this.responseText);
              if (!res.valid) {
                secretError.style.display = 'block';
                secretInput.focus();
                return;
              }
              // Proceed with deletion if valid
              deleteChat();
            } catch (e) {
              secretError.style.display = 'block';
              secretInput.focus();
            }
          } else {
            secretError.style.display = 'block';
            secretInput.focus();
          }
        };
        xhr.onerror = function() {
          // Handle network errors
          secretError.style.display = 'block';
          secretInput.focus();
        };
        xhr.send('secretKey=' + encodeURIComponent(secretInput.value));
      });
      
      // Close modal when clicking outside
      confirmModal.addEventListener("click", function(e) {
        if (e.target === confirmModal) {
          closeModal();
        }
      });
    }

    // ── "/backup" modal wiring (mirrors the /clear wiring above) ──────────────
    const backupConfirmModalEl = document.getElementById('backupConfirmModal');
    const backupSecretInputEl  = document.getElementById('backupSecretInput');
    const backupSecretErrorEl  = document.getElementById('backupSecretError');
    const confirmBackupBtn     = document.getElementById('confirmBackup');
    const cancelBackupBtn      = document.getElementById('cancelBackup');

    if (isAdmin && backupConfirmModalEl && cancelBackupBtn && confirmBackupBtn && backupSecretInputEl) {
      backupSecretInputEl.addEventListener('input', function() {
        if (backupSecretInputEl.value.length === 0) {
          confirmBackupBtn.disabled = true;
          backupSecretErrorEl.style.display = 'none';
          backupSecretErrorEl.textContent = '';
          return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'validate_secret.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          if (this.status === 200) {
            try {
              const res = JSON.parse(this.responseText);
              confirmBackupBtn.disabled = !res.valid;
              backupSecretErrorEl.style.display = 'block';
              backupSecretErrorEl.textContent = res.valid ? 'Correct secret key' : 'Invalid secret key';
              backupSecretErrorEl.style.color = res.valid ? 'green' : 'red';
            } catch (e) {
              confirmBackupBtn.disabled = true;
              backupSecretErrorEl.style.display = 'block';
              backupSecretErrorEl.textContent = 'Invalid secret key';
              backupSecretErrorEl.style.color = 'red';
            }
          } else {
            confirmBackupBtn.disabled = true;
            backupSecretErrorEl.style.display = 'block';
            backupSecretErrorEl.textContent = 'Invalid secret key';
            backupSecretErrorEl.style.color = 'red';
          }
        };
        xhr.send('secretKey=' + encodeURIComponent(backupSecretInputEl.value));
      });

      backupSecretInputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (confirmBackupBtn.disabled) {
            backupSecretErrorEl.style.display = 'block';
            backupSecretInputEl.focus();
            return;
          }
          runBackup();
        }
      });

      cancelBackupBtn.addEventListener('click', closeBackupConfirmModal);
      confirmBackupBtn.addEventListener('click', function() {
        if (confirmBackupBtn.disabled) {
          backupSecretErrorEl.style.display = 'block';
          backupSecretInputEl.focus();
          return;
        }
        runBackup();
      });

      backupConfirmModalEl.addEventListener('click', function(e) {
        if (e.target === backupConfirmModalEl) closeBackupConfirmModal();
      });
    }

    const backupAlreadyDoneModalEl = document.getElementById('backupAlreadyDoneModal');
    if (backupAlreadyDoneModalEl) {
      backupAlreadyDoneModalEl.addEventListener('click', function(e) {
        if (e.target === backupAlreadyDoneModalEl) closeBackupAlreadyDoneModal();
      });
    }

    // User Mention Autocomplete System Completely Removed

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        if (confirmModal && confirmModal.classList.contains('active') && isAdmin) {
          closeModal();
        }
        if (backupConfirmModalEl && backupConfirmModalEl.classList.contains('active') && isAdmin) {
          closeBackupConfirmModal();
        }
        if (backupAlreadyDoneModalEl && backupAlreadyDoneModalEl.classList.contains('active') && isAdmin) {
          closeBackupAlreadyDoneModal();
        }
        if (logoutModal && logoutModal.classList.contains('active')) {
          closeLogoutModal();
        }
        if (notifyContentModal && notifyContentModal.classList.contains('active')) {
          closeNotifyContentModal();
        }
        if (readMoreModal && readMoreModal.classList.contains('active')) {
          closeReadMoreModal();
        }
      }
      
      // Press Space or Enter when scroll indicator is visible to scroll to bottom
      if ((e.key === ' ' || e.key === 'Enter') && scrollIndicator.classList.contains('visible')) {
        e.preventDefault();
        shouldAutoScroll = true;
        userScrolledUp = false;
        scrollToBottom(true);
      }
      
      // Prevent clear chat shortcuts for non-admins
      if (!isAdmin && (e.ctrlKey && e.key === 'Delete')) {
        e.preventDefault();
        alert("Only administrators can clear the chat.");
      }
    });
    
    // ==========================================================================
    // FILE UPLOAD — Drag-and-Drop + Attachment Button
    // ==========================================================================

    // ── Rejected executable/script extensions ──────────────────────────────────
    const REJECTED_EXTS = new Set([
      'exe','bat','cmd','sh','bash','zsh',
      'php','php3','php4','php5','phtml','phar',
      'pl','py','rb','go','swift',
      'js','ts','jsx','tsx',
      'jar','class',
      'msi','vbs','vbe','wsf','ws','wsc',
      'scr','com','pif','gadget',
      'ps1','ps2','psm1','psd1',
      'msc','hta','cpl','inf','reg',
      'lnk','url',
      'asp','aspx','jsp','jspx',
      'dll','so','ko','sys','drv',
      'cgi','fcgi',
    ]);

    // ── Image extensions (same list as PHP) ────────────────────────────────────
    const IMAGE_EXTS = new Set(['jpg','jpeg','png','gif','webp','bmp','svg','ico']);

    function isImageFile(file) {
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      return IMAGE_EXTS.has(ext);
    }

    const dropOverlay       = document.getElementById('dropOverlay');
    const fileAttachInput   = document.getElementById('fileAttachmentInput');

    // ── Drag counter (prevents overlay flicker on child-enter/leave) ───────────
    let dragCount = 0;

    chatBox.addEventListener('dragenter', function(e) {
      e.preventDefault();
      dragCount++;
      if (dropOverlay) dropOverlay.classList.add('visible');
    }, false);

    chatBox.addEventListener('dragleave', function(e) {
      e.preventDefault();
      dragCount--;
      if (dragCount <= 0) {
        dragCount = 0;
        if (dropOverlay) dropOverlay.classList.remove('visible');
      }
    }, false);

    chatBox.addEventListener('dragover', function(e) {
      e.preventDefault();
    }, false);

    chatBox.addEventListener('drop', function(e) {
      e.preventDefault();
      dragCount = 0;
      if (dropOverlay) dropOverlay.classList.remove('visible');
      const files = e.dataTransfer ? Array.from(e.dataTransfer.files) : [];
      if (files.length === 0) return;

      // All dropped files (images and any other type) go into the staging
      // modal so the user can review them — and drag & drop in a few more —
      // before anything is actually sent.
      openImageStagingModal(files);
    }, false);

    // ── File input (attachment button) ─────────────────────────────────────────
    // Selecting file(s) via the attach button no longer sends immediately —
    // they're staged in the modal first so the user can review/remove them
    // and confirm with "Send". Same behavior on mobile, since it's the same
    // input/modal, just triggered via the mobile-friendly tap listener below.
    if (fileAttachInput) {
      fileAttachInput.addEventListener('change', function() {
        const files = Array.from(this.files || []);
        if (files.length > 0) openImageStagingModal(files);
        this.value = ''; // reset so same file can be re-picked
      });
    }

    // ── Mobile-friendly tap listener for the attach button ───────────────────
    const attachBtn = document.getElementById('attachBtn');
    if (attachBtn && fileAttachInput) {
      attachBtn.addEventListener('click', function(e) {
        e.preventDefault();
        fileAttachInput.click();
      });
    }

    // ==========================================================================
    // IMAGE STAGING MODAL — drop an image, then drop in more before sending
    // ==========================================================================
    const imageStagingModal     = document.getElementById('imageStagingModal');
    const imageStagingDropzone  = document.getElementById('imageStagingDropzone');
    const imageStagingGrid      = document.getElementById('imageStagingGrid');
    const imageStagingFileInput = document.getElementById('imageStagingFileInput');
    const imageStagingCancelBtn = document.getElementById('imageStagingCancelBtn');
    const imageStagingSendBtn   = document.getElementById('imageStagingSendBtn');

    let stagedImages     = []; // { id, file, url }
    let stagedImageSeq   = 0;
    let stagingDragCount = 0;  // prevents dropzone flicker on child enter/leave

    function renderImageStagingGrid() {
      if (!imageStagingGrid) return;

      if (stagedImages.length === 0) {
        imageStagingGrid.innerHTML = '<div class="image-staging-empty">No files added yet.</div>';
        if (imageStagingSendBtn) imageStagingSendBtn.disabled = true;
        return;
      }

      if (imageStagingSendBtn) imageStagingSendBtn.disabled = false;
      imageStagingGrid.innerHTML = stagedImages.map(function(item) {
        if (item.isImage) {
          return '<div class="image-staging-thumb" data-id="' + item.id + '">' +
                   '<img src="' + item.url + '" alt="">' +
                   '<button type="button" class="image-staging-remove" data-id="' + item.id + '" title="Remove" aria-label="Remove file">&times;</button>' +
                 '</div>';
        }

        // Non-image files get a generic file card: icon + extension badge + name.
        const fullName = item.file.name || 'file';
        const shortName = fullName.length > 22 ? fullName.substring(0, 19).trim() + '...' : fullName;
        const extLabel = (item.ext || 'file').toUpperCase().substring(0, 5);
        return '<div class="image-staging-thumb image-staging-file" data-id="' + item.id + '">' +
                 '<div class="image-staging-file-inner">' +
                   '<div class="image-staging-file-icon">' +
                     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                     '<span class="image-staging-file-ext">' + escapeHtml(extLabel) + '</span>' +
                   '</div>' +
                   '<span class="image-staging-file-name" title="' + escapeHtml(fullName) + '">' + escapeHtml(shortName) + '</span>' +
                 '</div>' +
                 '<button type="button" class="image-staging-remove" data-id="' + item.id + '" title="Remove" aria-label="Remove file">&times;</button>' +
               '</div>';
      }).join('');

      imageStagingGrid.querySelectorAll('.image-staging-remove').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          removeStagedImage(this.getAttribute('data-id'));
        });
      });
    }

    function addImagesToStaging(files) {
      const rejected = [];
      for (const file of files) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (REJECTED_EXTS.has(ext)) { rejected.push(file.name); continue; }
        stagedImageSeq++;
        const isImg = isImageFile(file);
        stagedImages.push({
          id: 'simg_' + stagedImageSeq,
          file: file,
          url: isImg ? URL.createObjectURL(file) : null, // only images get a preview thumbnail
          isImage: isImg,
          ext: ext
        });
      }
      if (rejected.length > 0) {
        showUploadErrorModal(rejected.map(f => `'${f}' was rejected: executable or script files are not allowed.`));
      }
      renderImageStagingGrid();
    }

    function removeStagedImage(id) {
      const idx = stagedImages.findIndex(i => i.id === id);
      if (idx === -1) return;
      if (stagedImages[idx].url) URL.revokeObjectURL(stagedImages[idx].url);
      stagedImages.splice(idx, 1);
      renderImageStagingGrid();
    }

    function openImageStagingModal(files) {
      if (isAdminAllChatsView || activeAdminConv) return;
      if (!activeDM && !isGlobalChat) return;

      addImagesToStaging(files);
      if (imageStagingModal) {
        imageStagingModal.style.display = 'flex';
        imageStagingModal.classList.add('active');
        imageStagingModal.setAttribute('aria-hidden', 'false');
      }
    }

    function closeImageStagingModal(clearImages) {
      if (imageStagingModal) {
        imageStagingModal.classList.remove('active');
        imageStagingModal.setAttribute('aria-hidden', 'true');
        setTimeout(function() {
          if (!imageStagingModal.classList.contains('active')) imageStagingModal.style.display = 'none';
        }, 300); // matches .modal { transition: all 0.3s ease; }
      }
      if (clearImages) {
        stagedImages.forEach(function(item) { if (item.url) URL.revokeObjectURL(item.url); });
        stagedImages = [];
        renderImageStagingGrid();
      }
      if (imageStagingDropzone) imageStagingDropzone.classList.remove('drag-active');
      stagingDragCount = 0;
    }

    if (imageStagingCancelBtn) {
      imageStagingCancelBtn.addEventListener('click', function() {
        closeImageStagingModal(true);
      });
    }

    if (imageStagingSendBtn) {
      imageStagingSendBtn.addEventListener('click', function() {
        if (stagedImages.length === 0) return;

        // Images are sent together as a single grid message; every other
        // file type is sent individually — same grouping handleFileUploads
        // used to do before files went through staging.
        const imageBatch = stagedImages.filter(function(item) { return item.isImage; })
                                        .map(function(item) { return item.file; });
        const otherFiles = stagedImages.filter(function(item) { return !item.isImage; })
                                        .map(function(item) { return item.file; });

        if (imageBatch.length > 0) uploadAndSend(imageBatch, true);
        for (const file of otherFiles) uploadAndSend([file], false);

        closeImageStagingModal(true);
      });
    }

    if (imageStagingFileInput) {
      imageStagingFileInput.addEventListener('change', function() {
        const files = Array.from(this.files || []);
        if (files.length > 0) addImagesToStaging(files);
        this.value = ''; // allow re-picking the same file
      });
    }

    if (imageStagingDropzone) {
      // The dropzone doubles as a <label> for the hidden file input (click
      // = browse); these listeners only add the drag & drop behavior.
      imageStagingDropzone.addEventListener('dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        stagingDragCount++;
        imageStagingDropzone.classList.add('drag-active');
      }, false);

      imageStagingDropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        stagingDragCount--;
        if (stagingDragCount <= 0) {
          stagingDragCount = 0;
          imageStagingDropzone.classList.remove('drag-active');
        }
      }, false);

      imageStagingDropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
      }, false);

      imageStagingDropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        stagingDragCount = 0;
        imageStagingDropzone.classList.remove('drag-active');
        const files = e.dataTransfer ? Array.from(e.dataTransfer.files) : [];
        if (files.length > 0) addImagesToStaging(files);
      }, false);
    }

    // Also let the whole modal accept a drop anywhere over it, not just the
    // dropzone box itself, without triggering the underlying chat drop handler.
    if (imageStagingModal) {
      imageStagingModal.addEventListener('dragover', function(e) { e.preventDefault(); }, false);
      imageStagingModal.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const files = e.dataTransfer ? Array.from(e.dataTransfer.files) : [];
        if (files.length > 0) addImagesToStaging(files);
      }, false);
    }

    // ── Upload Progress & Error Modal Controls ────────────────────────────────
    function showUploadingModal(names) {
      const modal = document.getElementById('uploadingModal');
      const nameEl = document.getElementById('uploadingFileName');
      const bar = document.getElementById('uploadProgressBar');
      const text = document.getElementById('uploadProgressText');

      // Keep this label short and fixed-size no matter how many files were
      // selected or how long their names are — never join/list every name,
      // that's what was making the modal grow.
      let label = 'Uploading file...';
      if (Array.isArray(names) && names.length > 0) {
        if (names.length === 1) {
          const n = names[0] || '';
          label = 'Uploading ' + (n.length > 60 ? n.substring(0, 60).trim() + '...' : n);
        } else {
          label = 'Uploading ' + names.length + ' files...';
        }
      } else if (typeof names === 'string' && names.trim() !== '') {
        label = names.length > 60 ? names.substring(0, 60).trim() + '...' : names;
      }
      if (nameEl) nameEl.textContent = label;
      if (bar) bar.style.width = '0%';
      if (text) text.textContent = '0%';
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
      }
    }

    function updateUploadProgress(percent) {
      const bar = document.getElementById('uploadProgressBar');
      const text = document.getElementById('uploadProgressText');
      const p = Math.min(100, Math.max(0, Math.round(percent)));
      if (bar) bar.style.width = p + '%';
      if (text) text.textContent = p + '%';
    }

    function closeUploadingModal() {
      const modal = document.getElementById('uploadingModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
      }
    }

    function formatErrorMessage(str, maxLen = 160) {
      str = (str || '').trim();
      if (str.length > maxLen) {
        return str.substring(0, maxLen).trim() + '.......';
      }
      return str;
    }

    // Keeps the modal a fixed, predictable size no matter how many errors
    // come back or how long a filename is. If the error list would be too
    // long/verbose to show cleanly, we collapse it to one short generic
    // line instead of letting the modal grow or silently clipping items.
    const UPLOAD_ERROR_MAX_ITEMS = 3;       // max individual lines to list
    const UPLOAD_ERROR_MAX_CHARS = 200;     // combined chars before we collapse

    function showUploadErrorModal(errors) {
      closeUploadingModal();
      const modal = document.getElementById('uploadErrorModal');
      const listEl = document.getElementById('uploadErrorList');
      if (listEl) {
        let items = [];
        if (Array.isArray(errors)) {
          items = errors.map(e => (e || '').toString().trim()).filter(Boolean);
        } else if (typeof errors === 'string' && errors.trim() !== '') {
          items = [errors.trim()];
        }

        const combinedLength = items.reduce((sum, e) => sum + e.length, 0);
        const tooMuch = items.length > UPLOAD_ERROR_MAX_ITEMS || combinedLength > UPLOAD_ERROR_MAX_CHARS;

        if (items.length === 0) {
          listEl.textContent = 'Failed to upload file(s). Please try again.';
        } else if (tooMuch) {
          listEl.innerHTML = `<div class="upload-error-item">File is too large.</div>`;
        } else {
          listEl.innerHTML = items.map(e => `<div class="upload-error-item">${escapeHtml(formatErrorMessage(e))}</div>`).join('');
        }
      }
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
      }
    }

    function closeUploadErrorModal() {
      const modal = document.getElementById('uploadErrorModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
      }
    }

    function attachImageLoadListeners() {
      if (!chatBox) return;
      chatBox.querySelectorAll('img').forEach(img => {
        if (img.dataset.scrollListener) return;
        img.dataset.scrollListener = '1';
        img.addEventListener('load', () => {
          if (isAtBottom() || shouldAutoScroll) {
            scrollToBottom(true, false);
          }
        });
      });
    }

    // ── Core upload handler ───────────────────────────────────────────────────
    // NOTE: no longer called directly from the attach button or from
    // drag-and-drop — both now route through openImageStagingModal() so
    // every file (image or not) is staged/previewed before it's sent.
    // Left in place since uploadAndSend() (which it calls) is still used
    // by the staging modal's Send button.
    function handleFileUploads(files) {
      if (isAdminAllChatsView || activeAdminConv) {
        return;
      }

      if (!activeDM && !isGlobalChat) {
        return;
      }

      // Separate rejected and accepted files
      const rejected = [];
      const accepted = [];
      for (const file of files) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (REJECTED_EXTS.has(ext)) {
          rejected.push(file.name);
        } else {
          accepted.push(file);
        }
      }

      // If there are rejected files, inform the user via Error Modal
      if (rejected.length > 0) {
        showUploadErrorModal(rejected.map(f => `'${f}' was rejected: executable or script files are not allowed.`));
        if (accepted.length === 0) return;
      }

      // Split accepted files: images in one batch, other files individually
      const imageBatch = accepted.filter(f => IMAGE_EXTS.has((f.name.split('.').pop()||'').toLowerCase()));
      const otherFiles = accepted.filter(f => !IMAGE_EXTS.has((f.name.split('.').pop()||'').toLowerCase()));

      // Upload image batch as a single grid message (if there are images)
      if (imageBatch.length > 0) {
        uploadAndSend(imageBatch, true);
      }

      // Upload each non-image file individually
      for (const file of otherFiles) {
        uploadAndSend([file], false);
      }
    }

    // ── Upload files → save message ───────────────────────────────────────────
    function uploadAndSend(fileList, isImageBatch) {
      if (isAdminAllChatsView || activeAdminConv) {
        return;
      }

      shouldAutoScroll = true;
      userScrolledUp   = false;

      // Build FormData
      const fd = new FormData();
      fd.append('chat_type', isGlobalChat ? 'global' : 'dm');
      if (!isGlobalChat && typeof activeDMAccountId !== 'undefined' && activeDMAccountId) {
        fd.append('target_id', activeDMAccountId);
      }
      const names = [];
      for (const file of fileList) {
        fd.append('files[]', file);
        names.push(file.name);
      }

      showUploadingModal(names);

      // POST to upload.php
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php', true);

      if (xhr.upload) {
        xhr.upload.onprogress = function(e) {
          if (e.lengthComputable) {
            const percent = (e.loaded / e.total) * 85;
            updateUploadProgress(percent);
          }
        };
      }

      xhr.onload = function() {
        if (this.status !== 200) {
          showUploadErrorModal('Server returned error status: ' + this.status);
          return;
        }

        let result;
        try { result = JSON.parse(this.responseText); } catch(e) {
          showUploadErrorModal('Failed to parse server response.');
          return;
        }

        if (!result.success || !result.uploaded || result.uploaded.length === 0) {
          showUploadErrorModal(result.errors || 'File upload failed or was rejected by server.');
          return;
        }

        updateUploadProgress(92);

        // Send the uploaded filenames as a message
        const uploadedFiles = result.uploaded;
        const filesPayload  = JSON.stringify(uploadedFiles);

        const sendXhr = new XMLHttpRequest();
        const sendUrl = isGlobalChat ? 'send.php' : 'send_dm.php';
        sendXhr.open('POST', sendUrl, true);
        sendXhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

        let params = 'uploaded_files=' + encodeURIComponent(filesPayload);
        if (!isGlobalChat && activeDM) {
          params += '&target_id=' + encodeURIComponent(activeDMAccountId || 0) + '&target_user=' + encodeURIComponent(activeDM);
        }

        sendXhr.onload = function() {
          updateUploadProgress(100);
          setTimeout(closeUploadingModal, 300);

          if (this.status === 200) {
            let msgUuid = null;
            try {
              const resData = JSON.parse(this.responseText);
              if (resData && resData.message && resData.message.id) {
                msgUuid = resData.message.id;
              }
            } catch(e) {}

            // Trigger WS broadcast so recipient & other tabs refresh immediately with full image
            if (ws && ws.readyState === WebSocket.OPEN) {
              if (isGlobalChat) {
                ws.send(JSON.stringify({ type: 'message', chat_type: 'global', sender_id: wsConfig.accountId, msg_uuid: msgUuid, has_upload: true }));
              } else {
                ws.send(JSON.stringify({ type: 'message', chat_type: 'private', sender_id: wsConfig.accountId, recipient_id: activeDMAccountId, msg_uuid: msgUuid, has_upload: true }));
              }
            }
            // Force a fresh chat load so the grid/image renders immediately
            if (isGlobalChat) {
              isLoadingGC = false;
              loadGlobalChat(false);
            } else if (activeDM) {
              loadChatForced();
            }
          } else {
            showUploadErrorModal('Failed to save uploaded file message.');
          }
        };

        sendXhr.onerror = function() {
          closeUploadingModal();
          showUploadErrorModal('Network error while saving message.');
        };

        sendXhr.send(params);
      };

      xhr.onerror = function() {
        closeUploadingModal();
        showUploadErrorModal('Network error while uploading file(s).');
      };

      xhr.send(fd);
    }

    // Prevent zoom on input focus (iOS)
    document.addEventListener('touchstart', function() {}, {passive: true});


    // ── iOS Safari keyboard fix ──
    // On iOS, the virtual keyboard does NOT resize the viewport (unlike Android).
    // Strategy:
    //   - header    → position:fixed, pinned to top of visualViewport
    //   - input-area (footer) → position:fixed, pinned just above the keyboard
    //   - chat-box  → position:fixed between header and footer, stays scrollable
    var isIOS = (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream)
             || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var appContainer       = document.querySelector('.app-container');
    var iosHeader          = document.querySelector('.header');
    var iosFooter          = document.querySelector('.input-area');
    var iosBlurSuppressed  = false; // true while we're about to refocus after send

    function applyIOSViewport() {
      if (!window.visualViewport) return;
      var vv            = window.visualViewport;
      var visibleTop    = vv.offsetTop;
      var visibleLeft   = vv.offsetLeft;
      var visibleWidth  = vv.width;
      var visibleHeight = vv.height;

      // Get safe-area-inset-bottom (home bar on notched iPhones).
      // Only apply it when the keyboard is NOT open (i.e. visibleHeight is close to full screen).
      // When the keyboard is open, visibleHeight already excludes the keyboard area,
      // so we don't need to add extra bottom inset.
      var safeBottom = 0;
      var fullHeight = window.screen.height / window.devicePixelRatio;
      var keyboardOpen = visibleHeight < (fullHeight * 0.75);
      if (!keyboardOpen) {
        // Try to read CSS env() via a temporary element
        try {
          var tmp = document.createElement('div');
          tmp.style.cssText = 'position:fixed;bottom:0;height:env(safe-area-inset-bottom,0px);pointer-events:none;visibility:hidden;';
          document.body.appendChild(tmp);
          safeBottom = tmp.offsetHeight || 0;
          document.body.removeChild(tmp);
        } catch(e) { safeBottom = 0; }
      }

      var headerH = iosHeader.offsetHeight;
      var footerH = iosFooter.offsetHeight;

      // Pin header at top of visible area
      iosHeader.style.position = 'fixed';
      iosHeader.style.top      = visibleTop + 'px';
      iosHeader.style.left     = visibleLeft + 'px';
      iosHeader.style.width    = visibleWidth + 'px';
      iosHeader.style.zIndex   = '200';

      // Pin footer just above the keyboard (or home bar when keyboard is closed)
      var footerTop = visibleTop + visibleHeight - footerH - safeBottom;
      iosFooter.style.position    = 'fixed';
      iosFooter.style.top         = footerTop + 'px';
      iosFooter.style.left        = visibleLeft + 'px';
      iosFooter.style.width       = visibleWidth + 'px';
      iosFooter.style.zIndex      = '200';
      // Remove the CSS padding-bottom safe-area rule while fixed — we handle it manually above
      iosFooter.style.paddingBottom = (keyboardOpen ? '12px' : (12 + safeBottom) + 'px');

      // chat-box fills the space between header and footer — still scrollable
      chatBox.style.position  = 'fixed';
      chatBox.style.top       = (visibleTop + headerH) + 'px';
      chatBox.style.left      = visibleLeft + 'px';
      chatBox.style.width     = visibleWidth + 'px';
      chatBox.style.height    = (visibleHeight - headerH - footerH - safeBottom) + 'px';
      chatBox.style.overflowY = 'auto';

      // Scroll chat to bottom whenever keyboard changes size
      setTimeout(function() {
        chatBox.scrollTop = chatBox.scrollHeight;
      }, 50);
    }

    function resetIOSViewport() {
      if (!window.visualViewport) return;

      iosHeader.style.position = '';
      iosHeader.style.top      = '';
      iosHeader.style.left     = '';
      iosHeader.style.width    = '';
      iosHeader.style.zIndex   = '';

      iosFooter.style.position     = '';
      iosFooter.style.top          = '';
      iosFooter.style.left         = '';
      iosFooter.style.width        = '';
      iosFooter.style.zIndex       = '';
      iosFooter.style.paddingBottom = '';

      chatBox.style.position  = '';
      chatBox.style.top       = '';
      chatBox.style.left      = '';
      chatBox.style.width     = '';
      chatBox.style.height    = '';
      chatBox.style.overflowY = '';
    }

    if (isIOS && window.visualViewport) {
      // Use requestAnimationFrame so the layout update runs on the very next
      // paint frame — eliminates the white flash seen before the footer snaps up.
      var rafPending = false;
      function scheduleIOSViewport() {
        if (rafPending) return;
        rafPending = true;
        requestAnimationFrame(function() {
          rafPending = false;
          applyIOSViewport();
        });
      }
      window.visualViewport.addEventListener('resize', scheduleIOSViewport);
      window.visualViewport.addEventListener('scroll', scheduleIOSViewport);
    }

    // Scroll chat to latest message when the keyboard opens.
    // iOS: do NOT call applyIOSViewport() here — the keyboard hasn't opened yet,
    // so visibleHeight is still full-screen and would place the footer wrongly.
    // The visualViewport 'resize' event (above) fires as soon as the keyboard
    // appears and will correctly reposition everything via scheduleIOSViewport.
    messageInput.addEventListener('focus', function () {
      setTimeout(function () {
        chatBox.scrollTop = chatBox.scrollHeight;
      }, isIOS ? 400 : 100);
    });

    messageInput.addEventListener('blur', function () {
      if (isIOS) {
        // Skip reset when we're immediately refocusing after send — the keyboard
        // never actually closed so resetting would cause a white-gap flash.
        if (iosBlurSuppressed) return;
        setTimeout(resetIOSViewport, 300);
      }
    });

    // ── Mobile keyboard: keep input-area always above the virtual keyboard ──
    // Android Chrome: handled automatically via interactive-widget=resizes-content in meta tag.
    //   The viewport shrinks, so the flex layout pushes input-area up naturally.
    // iOS Safari: handled above via visualViewport API.
    //

    // Set up mobile layout immediately (synchronously), instead of waiting for
    // window 'load' — 'load' only fires after ALL images/resources finish
    // downloading, which can take a noticeable moment on mobile. Waiting that
    // long meant the sidebar sat in its default (closed) state and then
    // visibly slid open once 'load' finally fired. The sidebar already starts
    // with the "no-anim" class in its HTML markup, so this initial pass never
    // animates, no matter how early or late it runs.
    setupMobileLayout();
    // Re-enable the slide transition for subsequent user-triggered toggles
    // (burger button, back button, etc.) once this initial state is committed.
    void sidebar.offsetHeight;
    requestAnimationFrame(function() {
      requestAnimationFrame(function() {
        sidebar.classList.remove('no-anim');
      });
    });

    // Initialize app 
    window.addEventListener('load', function() {
      // Set the name input as readonly
      nameInput.readOnly = true;
      
      // Focus on message input only if a chat is already selected
      if (activeDM) {
        setTimeout(() => {
          messageInput.focus();
        }, 300);
      }
      
      // Start WebSocket client connection
      connectWebSocket();

      // ── Adaptive sidebar polling ──────────────────────────────────────────────
      // • Poll every 3 s  when WebSocket is disconnected (fallback mode).
      // • Poll every 60 s when WebSocket is live — real-time refreshes now
      //   come from the 'message'/'chat_cleared'/'all_cleared'/'users_changed'
      //   WS pushes; this tick is just a rarely-needed safety net, not the
      //   primary path, so it doesn't need to run every few seconds anymore.
      // • Skip the tick entirely while the tab is hidden to save CPU/battery.
      let sidebarPollInterval = null;
      function startSidebarPoll() {
        if (sidebarPollInterval) clearInterval(sidebarPollInterval);
        const wsAlive = ws && ws.readyState === WebSocket.OPEN;
        const delay   = wsAlive ? 60000 : 3000;
        sidebarPollInterval = setInterval(function() {
          if (document.hidden) return;
          fetchUsers();
          // Fallback safety net: keep admin spy-mode message counts fresh even
          // if a WebSocket push was missed (e.g. brief reconnect gap).
          if (isAdminAllChatsView && adminSpyType === 'conversations' && adminSpyTargetUser) {
            fetchAdminConvs('', 0, false, adminSpyTargetUser.account_id);
          }
          // Re-evaluate interval length each tick so it adapts when WS state changes
          const nowAlive = ws && ws.readyState === WebSocket.OPEN;
          if (nowAlive !== wsAlive) startSidebarPoll(); // restart with new delay
        }, delay);
      }
      // Expose so connectWebSocket / visibilitychange can re-trigger the interval
      window._startSidebarPoll = startSidebarPoll;
      startSidebarPoll();
      fetchUsers();

      // One-shot catch-up for any notify/mention toasts that arrived while we
      // were offline (e.g. a missed WS push before this tab connected). Live
      // delivery from here on is the server's 'notify' WS push (see
      // ws.onmessage above) — no more blind 4s polling.
    });
    
    // Handle page visibility change
    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) {
        // ── Tab became visible ────────────────────────────────────────────────
        // 1. Reconnect WebSocket if it dropped while we were away
        if (!ws || ws.readyState === WebSocket.CLOSED || ws.readyState === WebSocket.CLOSING) {
          if (wsReconnectTimer) {
            clearTimeout(wsReconnectTimer);
            wsReconnectTimer = null;
          }
          connectWebSocket();
        }

        // 2. Immediately catch up on any messages missed while hidden
        if (isGlobalChat) {
          loadGlobalChat(false);
        } else if (activeDM) {
          loadChat(false);
          // Don't wait on that fetch to resolve before syncing the read
          // marker — the chat is visibly open again right now, so mark it
          // read immediately (loadChat's own markRead calls further down
          // will simply no-op/repeat harmlessly once it resolves).
          markRead(activeDM);
        } else if (activeAdminConv) {
          loadAdminConv(activeAdminConv, false);
        }

        // 3. Also refresh sidebar so unread badges are current
        fetchUsers();

        // 3b. Catch up immediately on session validity + own-name changes,
        // since checkSession()/refreshOwnName() now skip ticks while hidden.
        checkSession();
        refreshOwnName();

        // 4. Resume the fallback poll if WebSocket is still down
        if (!ws || ws.readyState !== WebSocket.OPEN) {
          startPollingFallback();
        }

        // 5. Re-evaluate sidebar poll frequency now that we're visible again
        if (typeof window._startSidebarPoll === 'function') window._startSidebarPoll();
      } else {
        // ── Tab became hidden ─────────────────────────────────────────────────
        // Fallback HTTP poll is suspended (each tick guards against document.hidden)
        // — no explicit stop needed since the guard inside each tick is enough.
        // We do nothing here so the interval handle stays valid for when we return.
      }
    });

    // Clean up WebSocket on page unload.
    // NOTE: 'pagehide' is used instead of 'beforeunload' — an unload/beforeunload
    // listener disqualifies the page from the back/forward cache (bfcache) in
    // Chrome/Firefox/Safari, which was flagged by Lighthouse ("Page prevented
    // back/forward cache restoration"). 'pagehide' fires at the same point for
    // this cleanup purpose but does not block bfcache.
    window.addEventListener('pagehide', function() {
      if (localIsTyping && activeDMAccountId) {
        sendTypingStatus(false);
      }
      if (ws) {
        try { ws.close(1000, 'Page unload'); } catch(e) {}
      }
    });

    // ── bfcache re-validation guard ────────────────────────────────────────
    // This page deliberately uses a cache-control limiter that allows
    // back/forward-cache restoration (see the comment at the top of this
    // file). bfcache restores the DOM exactly as it was WITHOUT re-running
    // any PHP on the server — so navigating back to this tab after logging
    // out of RMS in another tab/step could otherwise redisplay the last
    // authenticated view straight from the browser's cache.
    // event.persisted === true means this load came from bfcache rather
    // than a real network request, so we force a real reload, which goes
    // through the server-side Auth::check() gate at the very top of this
    // file again. The reload — not this listener — is what makes the
    // authentication decision; JS here only forces the real check to run.
    window.addEventListener('pageshow', function(event) {
      if (event.persisted) {
        window.location.reload();
      }
    });


    // ── Session kick / expiry overlay ─────────────────────────────────────
    // Primary path: the server pushes a 'session_kicked' WS event the instant
    // another device logs into this account (see ws.onmessage above) — this
    // fires immediately, no polling needed. checkSession() below is kept only
    // as an HTTP fallback for the window where the WS itself is down.
    let sessionKicked = false;
    function showSessionKickedOverlay(reason) {
      if (sessionKicked) return;
      sessionKicked = true;
      const isKicked = reason === 'kicked';
      const title = isKicked ? 'Logged in on another device' : 'Session expired';
      const body  = isKicked
      ? 'Your account has been logged in on another device or browser. You will be automatically redirected to the login page.'
      : 'Your session has expired. Please log in again.';

      // Dismiss virtual keyboard on Android & iOS before showing overlay.
      // Blurring the active element forces the keyboard to retract immediately.
      if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
      }
      // iOS Safari extra: move focus to body so keyboard fully collapses
      document.body.focus();

      // Show overlay
      const overlay = document.createElement('div');
      overlay.style.cssText = `
        position:fixed;inset:0;z-index:99999;
        background:rgba(0,0,0,0.72);
        display:flex;align-items:center;justify-content:center;
        font-family:'Inter',sans-serif;
      `;
      overlay.innerHTML = `
        <div style="
          background:var(--bg-secondary,#fff);
          border-radius:14px;padding:32px 28px;
          max-width:320px;width:90%;
          text-align:center;
          box-shadow:0 8px 32px rgba(0,0,0,0.28);
        ">
          <div style="font-size:38px;margin-bottom:12px;">⚠️</div>
          <div style="font-size:16px;font-weight:700;color:var(--text-primary,#050505);margin-bottom:8px;">${title}</div>
          <div style="font-size:13px;color:var(--text-secondary,#65676b);margin-bottom:22px;line-height:1.5;">${body}</div>
          <button onclick="window.location.href='logout.php'" style="
            background:#1b74e4;color:#fff;border:none;border-radius:8px;
            padding:10px 28px;font-size:14px;font-weight:600;cursor:pointer;
            font-family:'Inter',sans-serif;width:100%;
          ">OK</button>
        </div>
      `;
      document.body.appendChild(overlay);

      // Auto-redirect after 8 seconds — no need to wait for user to tap OK
      setTimeout(() => { window.location.href = 'logout.php'; }, 8000);
    }

    function checkSession() {
      if (sessionKicked) return;
      if (document.hidden) return; // don't poll while tab is in background
      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'check_session.php', true);
      xhr.onload = function() {
        if (this.status === 200) {
          try {
            const response = JSON.parse(this.responseText);
            if (!response.valid) {
              showSessionKickedOverlay(response.reason);
            }
          } catch (e) {
            console.error('Error checking session:', e);
          }
        }
      };
      xhr.send();
    }

    // Fallback-only poll: the server pushes 'session_kicked' over WS in real
    // time now, so this just catches the rare case where the WS connection
    // itself is down. Slow interval since it's a safety net, not the primary path.
    setInterval(function() {
      const wsAlive = ws && ws.readyState === WebSocket.OPEN;
      if (!wsAlive) checkSession();
    }, 15000);

    // ── Live-refresh the logged-in user's own name (no page reload needed) ───
    // Primary path: the server pushes a 'name_updated' WS event the instant
    // this account's name changes elsewhere (see ws.onmessage above).
    // refreshOwnName() below is kept only as an HTTP fallback for when the WS
    // connection is down. Applies to every user, not just the Super Admin.
    function applyOwnNameUpdate(newName) {
      if (!newName || newName === wsConfig.name) return; // unchanged

      wsConfig.name = newName;
      if (nameInput) nameInput.value = newName;

      // Push the updated name to the WS server so the typing indicator
      // (which reads from the server-side cached name) stays current.
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'update_name', name: newName }));
      }
    }

    function refreshOwnName() {
      if (document.hidden) return; // don't poll while tab is in background
      fetch('get_current_name.php', { credentials: 'same-origin' })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (!data || !data.name) return;
          applyOwnNameUpdate(data.name);
        })
        .catch(function () { /* silent — keep last known name */ });
    }

    // Fallback-only poll: same reasoning as checkSession above — the WS push
    // is the primary path, this just covers the WS-down window.
    setInterval(function() {
      const wsAlive = ws && ws.readyState === WebSocket.OPEN;
      if (!wsAlive) refreshOwnName();
    }, 15000);