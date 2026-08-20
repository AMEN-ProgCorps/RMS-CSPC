@auth
@unless(request()->routeIs('login', 'track-document', 'tracked') || request()->is('/', 'track-document', 'tracked'))
@php
    $user = auth()->user();
    $autoOpenChat = $user ? $user->autoOpenChat() : true;
@endphp

<script type="speculationrules">
{
  "prerender": [
    {
      "source": "list",
      "urls": ["{{ route('open-chat') }}"],
      "eagerness": "moderate"
    }
  ]
}
</script>

<div id="chatify-global-widget" style="position: fixed; bottom: 24px; right: 24px; z-index: 999999; font-family: 'Inter', system-ui, -apple-system, sans-serif;">
    <!-- Chat Window Container -->
    <div id="chatify-widget-card" 
         style="display: none; opacity: 0; transform: translateY(16px) scale(0.96); transition: opacity 0.25s ease, transform 0.25s ease; position: absolute; bottom: 72px; right: 0; width: 420px; height: 620px; max-width: calc(100vw - 32px); max-height: calc(100vh - 100px); border-radius: 16px; box-shadow: 0 20px 40px -8px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(148, 163, 184, 0.2); background: #ffffff; overflow: hidden; flex-direction: column;">
        
        <!-- Header Bar -->
        <div style="background: #0f172a; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255, 255, 255, 0.1); user-select: none;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: #10b981; box-shadow: 0 0 8px #10b981;"></div>
                <span style="color: #f8fafc; font-weight: 600; font-size: 14px; letter-spacing: 0.2px;">Chatify</span>
                <span style="background: rgba(255, 255, 255, 0.15); color: #94a3b8; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 999px;">Live</span>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
                <!-- Refresh Button -->
                <button type="button" onclick="reloadChatifyIframe()" title="Reload Chatify" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 4px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: color 0.2s;" onmouseover="this.style.color='#f8fafc'" onmouseout="this.style.color='#94a3b8'">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
                <!-- Minimize Button -->
                <button type="button" onclick="toggleChatifyWidget()" title="Minimize" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 4px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: color 0.2s;" onmouseover="this.style.color='#f8fafc'" onmouseout="this.style.color='#94a3b8'">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>
        </div>

        <!-- iFrame Container -->
        <div style="flex: 1; position: relative; background: #0f172a;">
            <div id="chatify-iframe-loader" style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #0f172a; color: #94a3b8; font-size: 13px; font-weight: 500; gap: 10px; z-index: 2;">
                <div style="width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.1); border-top-color: #3b82f6; border-radius: 50%; animation: chatify-spin 0.8s linear infinite;"></div>
                <span>Connecting to Chatify...</span>
            </div>
            <iframe id="chatify-iframe" data-src="{{ route('open-chat') }}" style="width: 100%; height: 100%; border: none; display: block;" onload="typeof hideChatifyLoader === 'function' && hideChatifyLoader()" allow="autoplay; clipboard-write"></iframe>
        </div>
    </div>

    <!-- Floating Trigger Button Container -->
    <div style="position: relative;">
        <!-- Unread Red Badge Pop-Up -->
        <span id="chatify-unread-badge" 
              style="display: none; position: absolute; top: -4px; right: -4px; background: #ef4444; color: #ffffff; font-size: 11px; font-weight: 700; min-width: 22px; height: 22px; border-radius: 11px; padding: 0 6px; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.5), 0 0 0 2px #ffffff; align-items: center; justify-content: center; z-index: 10; pointer-events: none; animation: chatify-badge-pop 0.3s ease-out;">
            0
        </span>
        <button id="chatify-widget-btn" 
                type="button" 
                onclick="toggleChatifyWidget()" 
                title="Chatify Messages"
                style="width: 56px; height: 56px; border-radius: 28px; background: linear-gradient(135deg, #1d4ed8, #2563eb); border: none; box-shadow: 0 8px 24px -4px rgba(37, 99, 235, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.2); cursor: pointer; display: flex; align-items: center; justify-content: center; color: #ffffff; transition: transform 0.2s ease, box-shadow 0.2s ease;"
                onmouseover="this.style.transform='scale(1.06)'" 
                onmouseout="this.style.transform='scale(1)'">
            
            <!-- Chat Icon -->
            <svg id="chatify-icon-chat" width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
            </svg>

            <!-- Close Icon -->
            <svg id="chatify-icon-close" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
</div>

<style>
@media print {
    #chatify-global-widget,
    #chatify-widget-card,
    #chatify-widget-btn,
    .chatify-widget,
    [id^="chatify"] {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
    }
}
@media (max-width: 767px) {
    #chatify-global-widget {
        display: none !important;
    }
}
@keyframes chatify-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@keyframes chatify-badge-pop {
    0% { transform: scale(0); opacity: 0; }
    80% { transform: scale(1.15); }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
(function() {
    const autoOpenSetting = @json($autoOpenChat);
    let isOpen = false;
    let lastChatUnread = null;
    let lastSystemUnread = null;

    // --- Audio Notification System & Fallback Protocol ---
    let pingAudio = null;
    let audioCtx = null;

    function initAudioContext() {
        if (!audioCtx) {
            try {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) {}
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume().catch(() => {});
        }
        if (!pingAudio) {
            try {
                pingAudio = new Audio("{{ asset('sfx/ping-message.mp3') }}");
                pingAudio.preload = 'auto';
            } catch (e) {}
        }
    }

    // Unlock browser audio context on user's first interaction anywhere on page
    const unlockEvents = ['click', 'touchstart', 'keydown'];
    function unlockAudioHandler() {
        initAudioContext();
        unlockEvents.forEach(evt => document.removeEventListener(evt, unlockAudioHandler));
    }
    unlockEvents.forEach(evt => document.addEventListener(evt, unlockAudioHandler, { passive: true }));

    // Fallback Catch Protocol: Web Audio API synthesized 2-tone chime
    function playSynthesizerChime() {
        try {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume().catch(() => {});
            }
            const now = audioCtx.currentTime;

            // Tone 1: 587.33 Hz (D5)
            const osc1 = audioCtx.createOscillator();
            const gain1 = audioCtx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, now);
            gain1.gain.setValueAtTime(0.14, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
            osc1.connect(gain1);
            gain1.connect(audioCtx.destination);
            osc1.start(now);
            osc1.stop(now + 0.18);

            // Tone 2: 880 Hz (A5)
            const osc2 = audioCtx.createOscillator();
            const gain2 = audioCtx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(880, now + 0.10);
            gain2.gain.setValueAtTime(0.16, now + 0.10);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
            osc2.connect(gain2);
            gain2.connect(audioCtx.destination);
            osc2.start(now + 0.10);
            osc2.stop(now + 0.35);
        } catch (e) {}
    }

    // Main Audio Player: Plays MP3, falls back smoothly to Web Audio synthesizer on error
    function playNotificationSound() {
        try {
            if (!pingAudio) {
                pingAudio = new Audio("{{ asset('sfx/ping-message.mp3') }}");
            }
            pingAudio.currentTime = 0;
            const playPromise = pingAudio.play();
            if (playPromise !== undefined) {
                playPromise.catch(function() {
                    // Catch protocol: fallback to synthesizer chime
                    playSynthesizerChime();
                });
            }
        } catch (e) {
            playSynthesizerChime();
        }
    }

    // --- Tab Title & Favicon Red Dot Indicator ---
    let originalDocumentTitle = null;
    let originalFaviconHref = null;
    let badgedFaviconDataUrl = null;
    let isBadgedFaviconActive = false;

    function getBaseDocumentTitle() {
        if (originalDocumentTitle === null) {
            originalDocumentTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
        }
        return originalDocumentTitle;
    }

    function updateTabTitle(totalCount) {
        const baseTitle = getBaseDocumentTitle();
        if (totalCount > 0) {
            const countText = totalCount > 99 ? '99+' : totalCount;
            document.title = `(${countText}) ${baseTitle}`;
        } else {
            document.title = baseTitle;
        }
    }

    function getFaviconElement() {
        let link = document.querySelector("link[rel*='icon']");
        if (!link) {
            link = document.createElement('link');
            link.rel = 'icon';
            document.head.appendChild(link);
        }
        return link;
    }

    function updateFaviconBadge(totalCount) {
        const link = getFaviconElement();
        if (!link) return;

        if (originalFaviconHref === null) {
            originalFaviconHref = link.href || "{{ asset('images/cspc.webp') }}";
        }

        if (totalCount > 0) {
            if (isBadgedFaviconActive) return;

            if (badgedFaviconDataUrl) {
                link.type = 'image/png';
                link.href = badgedFaviconDataUrl;
                isBadgedFaviconActive = true;
            } else {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.src = originalFaviconHref;
                img.onload = function() {
                    try {
                        const canvas = document.createElement('canvas');
                        canvas.width = 64;
                        canvas.height = 64;
                        const ctx = canvas.getContext('2d');
                        if (!ctx) return;

                        ctx.drawImage(img, 0, 0, 64, 64);

                        // Messenger-style Red Notification Dot on Top-Right Corner
                        const centerX = 49;
                        const centerY = 15;
                        const radius = 13;

                        // Outer White Ring
                        ctx.beginPath();
                        ctx.arc(centerX, centerY, radius + 3, 0, 2 * Math.PI);
                        ctx.fillStyle = '#ffffff';
                        ctx.fill();

                        // Inner Red Circle
                        ctx.beginPath();
                        ctx.arc(centerX, centerY, radius, 0, 2 * Math.PI);
                        ctx.fillStyle = '#ef4444';
                        ctx.fill();

                        badgedFaviconDataUrl = canvas.toDataURL('image/png');
                        link.type = 'image/png';
                        link.href = badgedFaviconDataUrl;
                        isBadgedFaviconActive = true;
                    } catch (e) {}
                };
                img.onerror = function() {
                    try {
                        const canvas = document.createElement('canvas');
                        canvas.width = 32;
                        canvas.height = 32;
                        const ctx = canvas.getContext('2d');
                        if (ctx) {
                            ctx.beginPath();
                            ctx.arc(16, 16, 14, 0, 2 * Math.PI);
                            ctx.fillStyle = '#ef4444';
                            ctx.fill();
                            badgedFaviconDataUrl = canvas.toDataURL('image/png');
                            link.type = 'image/png';
                            link.href = badgedFaviconDataUrl;
                            isBadgedFaviconActive = true;
                        }
                    } catch (err) {}
                };
            }
        } else {
            if (isBadgedFaviconActive) {
                if (originalFaviconHref) {
                    link.href = originalFaviconHref;
                }
                isBadgedFaviconActive = false;
            }
        }
    }

    // --- Widget Logic & Polling ---
    function isTabletOrDesktop() {
        return window.innerWidth >= 768;
    }

    function speculatePreloadChatifyIframe() {
        if (!isTabletOrDesktop()) return;
        const iframe = document.getElementById('chatify-iframe');
        if (iframe && (!iframe.src || iframe.src.includes('about:blank'))) {
            const loader = document.getElementById('chatify-iframe-loader');
            if (loader) loader.style.display = 'flex';
            iframe.src = iframe.getAttribute('data-src');
        }
    }

    function attachSpeculativeListeners() {
        // Feature detection check for Speculation Rules API
        if (typeof HTMLScriptElement !== 'undefined' && HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
            if (!document.getElementById('chatify-speculation-rules-dynamic')) {
                try {
                    const specScript = document.createElement('script');
                    specScript.id = 'chatify-speculation-rules-dynamic';
                    specScript.type = 'speculationrules';
                    specScript.textContent = JSON.stringify({
                        prerender: [{
                            source: "list",
                            urls: ["{{ route('open-chat') }}"],
                            eagerness: "moderate"
                        }]
                    });
                    document.head.appendChild(specScript);
                } catch (e) {}
            }
        }

        const btn = document.getElementById('chatify-widget-btn');
        if (btn) {
            btn.addEventListener('mouseenter', speculatePreloadChatifyIframe, { passive: true });
            btn.addEventListener('pointerdown', speculatePreloadChatifyIframe, { passive: true });
        }
        const dropdownBadge = document.getElementById('chatify-dropdown-unread-badge');
        if (dropdownBadge && dropdownBadge.parentElement) {
            dropdownBadge.parentElement.addEventListener('mouseenter', speculatePreloadChatifyIframe, { passive: true });
            dropdownBadge.parentElement.addEventListener('pointerdown', speculatePreloadChatifyIframe, { passive: true });
        }
    }

    function initWidgetState() {
        attachSpeculativeListeners();
        if (!isTabletOrDesktop()) {
            isOpen = false;
            setWidgetVisibility(false, false);
            return;
        }

        const storedState = sessionStorage.getItem('chatify_widget_open');
        if (storedState !== null) {
            isOpen = storedState === 'true';
        } else {
            isOpen = !!autoOpenSetting && isTabletOrDesktop();
        }

        if (isOpen) {
            setWidgetVisibility(true, false);
        }

        updateUnreadBadge();
        setInterval(updateUnreadBadge, 5000);
    }

    function updateUnreadBadge() {
        fetch('{{ route("chat.unread-count") }}', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            const chatCount = parseInt(data.chat_unread !== undefined ? data.chat_unread : (data.unread || 0), 10);
            const systemCount = parseInt(data.system_unread || 0, 10);
            const totalCount = parseInt(data.total_unread !== undefined ? data.total_unread : (chatCount + systemCount), 10);

            // Play notification sound if unread count increased (and not initial page load)
            const hasNewChat = (lastChatUnread !== null && chatCount > lastChatUnread);
            const hasNewSystem = (lastSystemUnread !== null && systemCount > lastSystemUnread);

            if (hasNewChat || hasNewSystem) {
                playNotificationSound();
            }

            lastChatUnread = chatCount;
            lastSystemUnread = systemCount;

            // 1. Update Chatify Floating Widget Button Badge
            const badge = document.getElementById('chatify-unread-badge');
            if (badge) {
                if (chatCount > 0) {
                    badge.textContent = chatCount > 99 ? '99+' : chatCount;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }

            // 2. Update Chatify Dropdown Badge
            const dropdownBadge = document.getElementById('chatify-dropdown-unread-badge');
            if (dropdownBadge) {
                if (chatCount > 0) {
                    dropdownBadge.textContent = chatCount > 99 ? '99+' : chatCount;
                    dropdownBadge.style.display = 'inline-flex';
                } else {
                    dropdownBadge.style.display = 'none';
                }
            }

            // 3. Update Header Bell Notification Badge
            const bellBadge = document.getElementById('header-notif-badge') || document.querySelector('.notif-badge');
            if (bellBadge) {
                bellBadge.style.display = systemCount > 0 ? 'block' : 'none';
            }

            // 4. Update Tab Title & Favicon Red Dot
            updateTabTitle(totalCount);
            updateFaviconBadge(totalCount);
        })
        .catch(() => {});
    }

    window.updateChatifyUnreadBadge = updateUnreadBadge;

    window.addEventListener('message', function(event) {
        if (event.data && (event.data.type === 'CHATIFY_MARK_READ' || event.data.type === 'CHATIFY_REFRESH_BADGE')) {
            updateUnreadBadge();
            setTimeout(updateUnreadBadge, 500);
        }
    });

    window.addEventListener('rms-notification-updated', function() {
        updateUnreadBadge();
        setTimeout(updateUnreadBadge, 500);
    });

    window.toggleChatifyWidget = function() {
        if (!isTabletOrDesktop()) {
            return;
        }
        isOpen = !isOpen;
        sessionStorage.setItem('chatify_widget_open', isOpen);
        setWidgetVisibility(isOpen, true);
        updateUnreadBadge();
        setTimeout(updateUnreadBadge, 500);
        setTimeout(updateUnreadBadge, 1500);
    };

    window.reloadChatifyIframe = function() {
        const iframe = document.getElementById('chatify-iframe');
        const loader = document.getElementById('chatify-iframe-loader');
        if (iframe) {
            if (loader) loader.style.display = 'flex';
            iframe.src = iframe.getAttribute('data-src') + '?t=' + new Date().getTime();
        }
        updateUnreadBadge();
        setTimeout(updateUnreadBadge, 400);
        setTimeout(updateUnreadBadge, 1000);
        setTimeout(updateUnreadBadge, 2000);
    };

    window.hideChatifyLoader = function() {
        const loader = document.getElementById('chatify-iframe-loader');
        if (loader) {
            loader.style.display = 'none';
        }
        updateUnreadBadge();
        setTimeout(updateUnreadBadge, 600);
    };

    function setWidgetVisibility(show, animate) {
        const card = document.getElementById('chatify-widget-card');
        const btn = document.getElementById('chatify-widget-btn');
        const iconChat = document.getElementById('chatify-icon-chat');
        const iconClose = document.getElementById('chatify-icon-close');
        const iframe = document.getElementById('chatify-iframe');

        if (!card || !btn) return;

        if (!isTabletOrDesktop()) {
            card.style.display = 'none';
            if (iframe) iframe.src = 'about:blank';
            return;
        }

        if (show) {
            if (iframe && (!iframe.src || iframe.src.includes('about:blank'))) {
                const loader = document.getElementById('chatify-iframe-loader');
                if (loader) loader.style.display = 'flex';
                iframe.src = iframe.getAttribute('data-src');
            }

            card.style.display = 'flex';
            requestAnimationFrame(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0) scale(1)';
            });

            if (iconChat && iconClose) {
                iconChat.style.display = 'none';
                iconClose.style.display = 'block';
            }
            btn.setAttribute('title', 'Minimize Chatify');
        } else {
            if (animate) {
                card.style.opacity = '0';
                card.style.transform = 'translateY(16px) scale(0.96)';
                setTimeout(() => {
                    if (!isOpen) {
                        card.style.display = 'none';
                        if (iframe) iframe.src = 'about:blank';
                    }
                }, 250);
            } else {
                card.style.display = 'none';
                card.style.opacity = '0';
                if (iframe) iframe.src = 'about:blank';
            }

            if (iconChat && iconClose) {
                iconChat.style.display = 'block';
                iconClose.style.display = 'none';
            }
            btn.setAttribute('title', 'Chatify Messages');
        }
    }

    window.addEventListener('resize', function() {
        if (!isTabletOrDesktop()) {
            setWidgetVisibility(false, false);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidgetState);
    } else {
        initWidgetState();
    }
})();
</script>



@endunless
@endauth
