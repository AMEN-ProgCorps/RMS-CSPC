@auth
@php
    // Hide the widget entirely while the Chatify subsystem is deactivated
    // (Admin > Subsystems > Activate/Deactivate) — same flag the portal uses.
    $messifySubsystemActive = \Illuminate\Support\Facades\DB::table(
        \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems'
    )->whereIn('subsystem_name', ['Chatify', 'Messify'])->where('is_active', true)->exists();
@endphp
@if($messifySubsystemActive)
@unless(request()->routeIs('login', 'track-document', 'tracked') || request()->is('/', 'track-document', 'tracked'))
@php
    $user = auth()->user();
    $autoOpenChat = $user ? $user->autoOpenChat() : true;
    $notificationSoundAlert = $user ? $user->notificationSoundAlert() : true;
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

<div id="messify-global-widget" class="messify-floating-widget messify-floating-widget" style="position: fixed; bottom: 24px; right: 24px; z-index: 999999; font-family: 'Inter', system-ui, -apple-system, sans-serif; transition: bottom 0.35s cubic-bezier(0.34, 1.25, 0.64, 1);">
    <!-- Chat Window Container -->
    <div id="messify-widget-card" 
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
                <button type="button" onclick="reloadMessifyIframe()" title="Reload Chatify" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 4px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: color 0.2s;" onmouseover="this.style.color='#f8fafc'" onmouseout="this.style.color='#94a3b8'">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
                <!-- Minimize Button -->
                <button type="button" onclick="toggleMessifyWidget()" title="Minimize" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; padding: 4px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: color 0.2s;" onmouseover="this.style.color='#f8fafc'" onmouseout="this.style.color='#94a3b8'">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </div>
        </div>

        <!-- iFrame Container -->
        <div style="flex: 1; position: relative; background: #0f172a;">
            <div id="messify-iframe-loader" style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #0f172a; color: #94a3b8; font-size: 13px; font-weight: 500; gap: 10px; z-index: 2;">
                <div style="width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.1); border-top-color: #3b82f6; border-radius: 50%; animation: messify-spin 0.8s linear infinite;"></div>
                <span>Connecting to Chatify...</span>
            </div>
            <iframe id="messify-iframe" data-src="{{ route('open-chat') }}" style="width: 100%; height: 100%; border: none; display: block;" onload="typeof hideMessifyLoader === 'function' && hideMessifyLoader()" allow="autoplay; clipboard-write"></iframe>
        </div>
    </div>

    <!-- Floating Trigger Button Container -->
    <div style="position: relative;">
        <!-- Unread Red Badge Pop-Up -->
        <span id="messify-unread-badge" 
              style="display: none; position: absolute; top: -4px; right: -4px; background: #ef4444; color: #ffffff; font-size: 11px; font-weight: 700; min-width: 22px; height: 22px; border-radius: 11px; padding: 0 6px; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.5), 0 0 0 2px #ffffff; align-items: center; justify-content: center; z-index: 10; pointer-events: none; animation: messify-badge-pop 0.3s ease-out;">
            0
        </span>
        <button id="messify-widget-btn" 
                type="button" 
                onclick="toggleMessifyWidget()" 
                title="Chatify Messages"
                class="rms-messify-fab">
            
            <span class="rms-messify-fab-icon">
                <!-- Chat Icon -->
                <svg id="messify-icon-chat" width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>

                <!-- Close Icon -->
                <svg id="messify-icon-close" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </span>

            <span id="messify-widget-btn-label" class="rms-messify-fab-label">Chatify</span>
        </button>
    </div>
</div>

<style>
.rms-messify-fab {
    height: 56px;
    min-width: 56px;
    width: 56px;
    border-radius: 999px;
    background: linear-gradient(135deg, #1d4ed8, #2563eb);
    border: none;
    box-shadow: 0 8px 24px -4px rgba(37, 99, 235, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.2);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    padding: 0 0 0 15px;
    color: #ffffff;
    overflow: hidden;
    white-space: nowrap;
    text-decoration: none;
    user-select: none;
    box-sizing: border-box;
    transition: width 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                padding 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                transform 0.2s ease,
                box-shadow 0.2s ease;
}

.rms-messify-fab:hover {
    width: 134px;
    padding: 0 16px 0 15px;
    transform: translateY(-1px);
    box-shadow: 0 12px 28px -4px rgba(37, 99, 235, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.3);
}

.rms-messify-fab-icon {
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.rms-messify-fab-label {
    max-width: 0;
    opacity: 0;
    overflow: hidden;
    margin-left: 0;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.2px;
    color: #ffffff;
    transition: max-width 0.28s cubic-bezier(0.4, 0, 0.2, 1),
                opacity 0.2s ease,
                margin-left 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}

.rms-messify-fab:hover .rms-messify-fab-label {
    max-width: 75px;
    opacity: 1;
    margin-left: 8px;
}

/* When Messify is open, close hover shows "Close" cleanly */
body.messify-widget-open .rms-messify-fab:hover {
    width: 120px;
}

/* When Report Problem widget is opened, Messify moves UP above the Report Card */
body.report-widget-open #messify-global-widget,
body.report-widget-open #messify-global-widget {
    bottom: calc(min(580px, calc(100vh - 100px)) + 104px) !important;
    z-index: 1000000 !important;
}

@media print {
    #messify-global-widget,
    #messify-global-widget,
    #messify-widget-card,
    #messify-widget-btn,
    .messify-widget,
    .messify-widget,
    [id^="messify"],
    [id^="messify"] {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
    }
}
@media (max-width: 767px) {
    #messify-global-widget,
    #messify-global-widget {
        display: none !important;
    }
}
@keyframes messify-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@keyframes messify-badge-pop {
    0% { transform: scale(0); opacity: 0; }
    80% { transform: scale(1.15); }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<script>
(function() {
    const autoOpenSetting = @json($autoOpenChat);
    let soundAlertsEnabled = @json($notificationSoundAlert);
    let isOpen = false;
    let lastChatUnread = null;
    let lastSystemUnread = null;

    // --- Audio Notification System & Fallback Protocol ---
    let pingAudio = null;
    let audioCtx = null;

    function initAudioContext() {
        if (!audioCtx) {
            try {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) {
                    audioCtx = new AudioContextClass();
                }
            } catch (e) {}
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume().catch(() => {});
        }
        if (!pingAudio) {
            try {
                pingAudio = new Audio("{{ asset('sfx/ping-message.mp3') }}");
                pingAudio.preload = 'auto';
                pingAudio.volume = 0.8;
            } catch (e) {}
        }
    }

    // Unlock browser audio on first real gesture so later poll/WS notification
    // sounds are allowed (HTML5 Audio needs a successful play() under a gesture).
    const unlockEvents = ['click', 'touchstart', 'keydown', 'pointerdown'];
    function unlockAudioHandler() {
        initAudioContext();
        try {
            if (pingAudio) {
                const prevVolume = pingAudio.volume;
                pingAudio.volume = 0.001;
                const p = pingAudio.play();
                if (p && typeof p.then === 'function') {
                    p.then(() => {
                        pingAudio.pause();
                        pingAudio.currentTime = 0;
                        pingAudio.volume = prevVolume || 0.8;
                    }).catch(() => {
                        try { pingAudio.volume = prevVolume || 0.8; } catch (e) {}
                    });
                }
            }
        } catch (e) {}
        unlockEvents.forEach(evt => document.removeEventListener(evt, unlockAudioHandler));
    }
    unlockEvents.forEach(evt => document.addEventListener(evt, unlockAudioHandler, { passive: true }));

    // Fallback Catch Protocol: Web Audio API synthesized 2-tone melodic chime
    function playSynthesizerChime() {
        if (!soundAlertsEnabled) return;
        try {
            if (!audioCtx) {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (AudioContextClass) {
                    audioCtx = new AudioContextClass();
                }
            }
            if (!audioCtx) return;

            const emitChime = () => {
                try {
                    const now = audioCtx.currentTime;

                    // Tone 1: 587.33 Hz (D5)
                    const osc1 = audioCtx.createOscillator();
                    const gain1 = audioCtx.createGain();
                    osc1.type = 'sine';
                    osc1.frequency.setValueAtTime(587.33, now);
                    gain1.gain.setValueAtTime(0.2, now);
                    gain1.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
                    osc1.connect(gain1);
                    gain1.connect(audioCtx.destination);
                    osc1.start(now);
                    osc1.stop(now + 0.18);

                    // Tone 2: 880 Hz (A5)
                    const osc2 = audioCtx.createOscillator();
                    const gain2 = audioCtx.createGain();
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(880, now + 0.10);
                    gain2.gain.setValueAtTime(0.25, now + 0.10);
                    gain2.gain.exponentialRampToValueAtTime(0.0001, now + 0.38);
                    osc2.connect(gain2);
                    gain2.connect(audioCtx.destination);
                    osc2.start(now + 0.10);
                    osc2.stop(now + 0.38);
                } catch (err) {}
            };

            if (audioCtx.state === 'suspended') {
                audioCtx.resume().then(emitChime).catch(() => {});
            } else {
                emitChime();
            }
        } catch (e) {}
    }

    // Main Audio Player: Plays MP3, falls back smoothly to Web Audio synthesizer on error
    function playNotificationSound() {
        if (!soundAlertsEnabled) return;
        try {
            initAudioContext();
            if (!pingAudio) {
                pingAudio = new Audio("{{ asset('sfx/ping-message.mp3') }}");
                pingAudio.preload = 'auto';
                pingAudio.volume = 0.8;
            }
            pingAudio.currentTime = 0;
            const playPromise = pingAudio.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    // Audio played successfully
                }).catch(function() {
                    // Catch protocol: fallback to synthesizer chime
                    playSynthesizerChime();
                });
            }
        } catch (e) {
            playSynthesizerChime();
        }
    }

    window.playRMSNotificationSound = playNotificationSound;

    window.addEventListener('rms-sound-setting-changed', function(event) {
        if (event && event.detail !== undefined) {
            soundAlertsEnabled = typeof event.detail === 'object' && event.detail.enabled !== undefined
                ? !!event.detail.enabled 
                : !!event.detail;
        }
    });

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

    let messifyLoadedOnce = false;

    function speculatePreloadMessifyIframe() {
        if (!isTabletOrDesktop()) return;
        const iframe = document.getElementById('messify-iframe');
        if (iframe && (!iframe.src || iframe.src.includes('about:blank') || iframe.src === '')) {
            const loader = document.getElementById('messify-iframe-loader');
            if (loader && !messifyLoadedOnce) loader.style.display = 'flex';
            iframe.src = iframe.getAttribute('data-src');
        }
    }

    function attachSpeculativeListeners() {
        // Feature detection check for Speculation Rules API
        if (typeof HTMLScriptElement !== 'undefined' && HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
            if (!document.getElementById('messify-speculation-rules-dynamic')) {
                try {
                    const specScript = document.createElement('script');
                    specScript.id = 'messify-speculation-rules-dynamic';
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

        const btn = document.getElementById('messify-widget-btn');
        if (btn) {
            btn.addEventListener('mouseenter', speculatePreloadMessifyIframe, { passive: true });
            btn.addEventListener('pointerdown', speculatePreloadMessifyIframe, { passive: true });
        }
        const dropdownBadge = document.getElementById('messify-dropdown-unread-badge');
        if (dropdownBadge && dropdownBadge.parentElement) {
            dropdownBadge.parentElement.addEventListener('mouseenter', speculatePreloadMessifyIframe, { passive: true });
            dropdownBadge.parentElement.addEventListener('pointerdown', speculatePreloadMessifyIframe, { passive: true });
        }
    }

    function initWidgetState() {
        attachSpeculativeListeners();
        if (!isTabletOrDesktop()) {
            isOpen = false;
            setWidgetVisibility(false, false);
            // Still poll unread so system/distribution popouts work on mobile.
            updateUnreadBadge();
            return;
        }

        const storedState = sessionStorage.getItem('messify_widget_open');
        if (storedState !== null) {
            isOpen = storedState === 'true';
        } else {
            isOpen = !!autoOpenSetting && isTabletOrDesktop();
        }

        if (isOpen) {
            document.body.classList.add('messify-widget-open');
            setWidgetVisibility(true, false);
        } else {
            document.body.classList.remove('messify-widget-open');
        }

        updateUnreadBadge();
        // No manual setInterval needed — updateUnreadBadge reschedules itself via .finally()
    }

    // --- Inflight guard: only one unread-count request at a time ---
    let _unreadAbortController = null;
    let _unreadPollTimeout = null;

    function scheduleUnreadPoll(delayMs) {
        // Default: 5 seconds. Pass shorter value for event-triggered refreshes.
        clearTimeout(_unreadPollTimeout);
        _unreadPollTimeout = setTimeout(updateUnreadBadge, delayMs ?? 5000);
    }

    function updateUnreadBadge() {
        // Cancel any in-flight request before starting a new one.
        if (_unreadAbortController) {
            _unreadAbortController.abort();
        }
        _unreadAbortController = new AbortController();
        const signal = _unreadAbortController.signal;

        fetch('{{ route("chat.unread-count") }}', {
            signal,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            _unreadAbortController = null;
            const chatCount = parseInt(data.chat_unread !== undefined ? data.chat_unread : (data.unread || 0), 10);
            const systemCount = parseInt(data.system_unread || 0, 10);
            const totalSystemCount = parseInt(data.total_system_unread !== undefined ? data.total_system_unread : systemCount, 10);
            const totalCount = parseInt(data.total_unread !== undefined ? data.total_unread : (chatCount + systemCount), 10);

            // Offline catch-up popout on initial login / session start
            if (lastSystemUnread === null && totalSystemCount > 0) {
                if (!sessionStorage.getItem('rms_offline_catchup_shown')) {
                    sessionStorage.setItem('rms_offline_catchup_shown', 'true');
                    window.dispatchEvent(new CustomEvent('rms-notification-overlay-show', {
                        detail: {
                            id: null,
                            is_catchup: true,
                            subsystem_name: 'Notification Center',
                            content: `Welcome back! You have ${totalSystemCount} unread notification${totalSystemCount > 1 ? 's' : ''} waiting in your inbox.`,
                            time: 'Offline catch-up'
                        }
                    }));
                }
            }

            // Play chat sound here; system overlay Alpine plays its own sound.
            const hasNewChat = (lastChatUnread !== null && chatCount > lastChatUnread);
            const hasNewSystem = (lastSystemUnread !== null && systemCount > lastSystemUnread);

            if (hasNewChat) {
                playNotificationSound();
            }

            if (hasNewSystem && data.latest_system_notification) {
                window.dispatchEvent(new CustomEvent('rms-notification-overlay-show', {
                    detail: data.latest_system_notification
                }));
            } else if (hasNewSystem) {
                playNotificationSound();
            }

            applyUnreadCounts(chatCount, systemCount, totalSystemCount);
        })
        .catch(err => {
            _unreadAbortController = null;
            // Ignore aborts (we triggered them intentionally); still reschedule.
        })
        .finally(() => {
            // Only reschedule when this call was the poll cycle (not event-triggered).
            // Event-triggered calls explicitly pass their own reschedule.
            scheduleUnreadPoll(5000);
        });
    }

    function applyUnreadCounts(chatCount, systemCount, totalSystemCount) {
        chatCount = Math.max(0, parseInt(chatCount !== undefined ? chatCount : (lastChatUnread || 0), 10));
        systemCount = Math.max(0, parseInt(systemCount !== undefined ? systemCount : (lastSystemUnread || 0), 10));
        totalSystemCount = Math.max(0, parseInt(totalSystemCount !== undefined ? totalSystemCount : systemCount, 10));

        lastChatUnread = chatCount;
        lastSystemUnread = systemCount;
        const totalCount = chatCount + systemCount;

        // 1. Update Messify Floating Widget Button Badge
        const badge = document.getElementById('messify-unread-badge');
        if (badge) {
            if (chatCount > 0) {
                badge.textContent = chatCount > 99 ? '99+' : chatCount;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }

        // 2. Update Messify Dropdown Badge
        const dropdownBadge = document.getElementById('messify-dropdown-unread-badge');
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
            bellBadge.style.display = totalSystemCount > 0 ? 'block' : 'none';
        }

        // 4. Update Tab Title & Favicon Red Dot
        updateTabTitle(totalCount);
        updateFaviconBadge(totalCount);
    }

    // Expose via scheduleUnreadPoll so external callers also go through the inflight guard.
    window.updateMessifyUnreadBadge = () => scheduleUnreadPoll(0);

    window.addEventListener('message', function(event) {
        if (!event.data) return;

        if (event.data.type === 'MESSIFY_NEW_MESSAGE' || event.data.type === 'MESSIFY_NEW_MESSAGE') {
            if (typeof event.data.unread_count === 'number') {
                applyUnreadCounts(event.data.unread_count, lastSystemUnread);
            }
            playNotificationSound();
            // Single debounced refresh — AbortController cancels any in-flight request.
            scheduleUnreadPoll(300);
        } else if (event.data.type === 'MESSIFY_SYSTEM_NOTIFICATION') {
            // Instant DCS/system bell notice from Messify WebSocket (e.g. document distribution).
            // Overlay Alpine plays the sound; just show the popout + refresh counts.
            const notif = event.data.notification || null;
            if (notif && notif.content) {
                window.dispatchEvent(new CustomEvent('rms-notification-overlay-show', {
                    detail: notif
                }));
                if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                    window.Livewire.dispatch('rms-notification-ping');
                }
            }
            scheduleUnreadPoll(200);
        } else if (event.data.type === 'MESSIFY_PLAY_SOUND' || event.data.type === 'MESSIFY_PLAY_SOUND') {
            playNotificationSound();
        } else if (event.data.type === 'MESSIFY_USER_INTERACTION' || event.data.type === 'MESSIFY_USER_INTERACTION') {
            initAudioContext();
        } else if (event.data.type === 'MESSIFY_MARK_READ' || event.data.type === 'MESSIFY_MARK_READ' || event.data.type === 'MESSIFY_REFRESH_BADGE' || event.data.type === 'MESSIFY_REFRESH_BADGE') {
            if (typeof event.data.unread_count === 'number') {
                applyUnreadCounts(event.data.unread_count, lastSystemUnread);
            }
            scheduleUnreadPoll(300);
        }
    });

    window.addEventListener('rms-notification-updated', function() {
        scheduleUnreadPoll(300);
    });

    document.addEventListener('livewire:init', function() {
        if (window.Livewire && typeof window.Livewire.on === 'function') {
            window.Livewire.on('rms-notification-updated', function() {
                updateUnreadBadge();
                setTimeout(updateUnreadBadge, 300);
            });
        }
    });

    window.addEventListener('rms-play-sound', function() {
        playNotificationSound();
    });

    window.addEventListener('rms-widget-opened', function(e) {
        if (e.detail && e.detail.widget !== 'messify' && isOpen) {
            isOpen = false;
            sessionStorage.setItem('messify_widget_open', false);
            setWidgetVisibility(false, true);
        }
    });

    window.toggleMessifyWidget = function() {
        if (!isTabletOrDesktop()) {
            return;
        }
        isOpen = !isOpen;
        sessionStorage.setItem('messify_widget_open', isOpen);
        setWidgetVisibility(isOpen, true);
        // Single refresh — no need for multiple setTimeouts.
        scheduleUnreadPoll(200);
    };

    window.reloadMessifyIframe = function() {
        const iframe = document.getElementById('messify-iframe');
        const loader = document.getElementById('messify-iframe-loader');
        if (iframe) {
            messifyLoadedOnce = false;
            if (loader) loader.style.display = 'flex';
            iframe.src = iframe.getAttribute('data-src') + '?t=' + new Date().getTime();
        }
        // Single debounced refresh after reload — no stacking.
        scheduleUnreadPoll(500);
    };

    function disableMessifySearchAutoInput() {
        const iframe = document.getElementById('messify-iframe');
        if (!iframe) return;

        try {
            const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
            if (!iframeDoc) return;

            const searchInputs = iframeDoc.querySelectorAll('#searchInput, #adminSearchInput, .sidebar-search input, .admin-search input');

            searchInputs.forEach(input => {
                // 1. Universal Anti-Autofill & Password Manager Ignore Attributes
                input.setAttribute('autocomplete', 'new-password');
                input.setAttribute('autocorrect', 'off');
                input.setAttribute('autocapitalize', 'off');
                input.setAttribute('spellcheck', 'false');
                input.setAttribute('data-lpignore', 'true');
                input.setAttribute('data-1p-ignore', 'true');
                input.setAttribute('data-form-type', 'other');

                // 2. Readonly Protection on Load (blocks browser autofill engine across all browsers)
                input.setAttribute('readonly', 'readonly');

                // Seamlessly remove readonly the instant the user interacts
                const unlockInput = () => {
                    input.removeAttribute('readonly');
                };
                input.addEventListener('focus', unlockInput, { once: true });
                input.addEventListener('pointerdown', unlockInput, { once: true });
                input.addEventListener('mousedown', unlockInput, { once: true });
                input.addEventListener('touchstart', unlockInput, { once: true });
                input.addEventListener('keydown', unlockInput, { once: true });

                // 3. Clear any value auto-injected by the browser on load
                if (input.value) {
                    input.value = '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }

                // Delayed purge for browsers / password managers injecting 50ms - 500ms after load
                [50, 150, 300, 600].forEach(delay => {
                    setTimeout(() => {
                        if (input.hasAttribute('readonly') && input.value) {
                            input.value = '';
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }, delay);
                });

                // 4. Prevent unwanted auto-focus on page load
                if (iframeDoc.activeElement === input) {
                    input.blur();
                }
            });
        } catch (e) {
            // Failsafe in case of cross-origin boundaries
        }
    }

    window.hideMessifyLoader = function() {
        messifyLoadedOnce = true;
        const loader = document.getElementById('messify-iframe-loader');
        if (loader) {
            loader.style.display = 'none';
        }
        disableMessifySearchAutoInput();
        updateUnreadBadge();
        setTimeout(updateUnreadBadge, 600);
    };

    function setWidgetVisibility(show, animate) {
        const card = document.getElementById('messify-widget-card');
        const btn = document.getElementById('messify-widget-btn');
        const iconChat = document.getElementById('messify-icon-chat');
        const iconClose = document.getElementById('messify-icon-close');
        const iframe = document.getElementById('messify-iframe');

        if (!card || !btn) return;

        if (!isTabletOrDesktop()) {
            card.style.display = 'none';
            return;
        }

        if (show) {
            document.body.classList.add('messify-widget-open');
            window.dispatchEvent(new CustomEvent('rms-widget-opened', { detail: { widget: 'messify' } }));
            const labelEl = document.getElementById('messify-widget-btn-label');
            if (labelEl) labelEl.textContent = 'Close';

            if (iframe && (!iframe.src || iframe.src.includes('about:blank') || iframe.src === '')) {
                const loader = document.getElementById('messify-iframe-loader');
                if (loader && !messifyLoadedOnce) loader.style.display = 'flex';
                iframe.src = iframe.getAttribute('data-src');
            } else if (messifyLoadedOnce) {
                // Already loaded and cached in DOM — keep loader hidden
                const loader = document.getElementById('messify-iframe-loader');
                if (loader) loader.style.display = 'none';
                try {
                    if (iframe && iframe.contentWindow && typeof iframe.contentWindow.resetToHome === 'function') {
                        iframe.contentWindow.resetToHome();
                    }
                } catch(e) {}
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
            document.body.classList.remove('messify-widget-open');
            window.dispatchEvent(new CustomEvent('rms-widget-closed', { detail: { widget: 'messify' } }));
            const labelEl = document.getElementById('messify-widget-btn-label');
            if (labelEl) labelEl.textContent = 'Chatify';

            try {
                if (iframe && iframe.contentWindow && typeof iframe.contentWindow.resetToHome === 'function') {
                    iframe.contentWindow.resetToHome();
                }
            } catch(e) {}

            if (animate) {
                card.style.opacity = '0';
                card.style.transform = 'translateY(16px) scale(0.96)';
                setTimeout(() => {
                    if (!isOpen) {
                        card.style.display = 'none';
                        // Keep iframe intact in DOM (cached) instead of resetting to about:blank
                    }
                }, 250);
            } else {
                card.style.display = 'none';
                card.style.opacity = '0';
                // Keep iframe intact in DOM (cached) instead of resetting to about:blank
            }

            if (iconChat && iconClose) {
                iconChat.style.display = 'block';
                iconClose.style.display = 'none';
            }
            btn.setAttribute('title', 'Messify Messages');
        }
    }

    let lastWidgetWindowWidth = window.innerWidth;
    window.addEventListener('resize', function() {
        if (window.innerWidth !== lastWidgetWindowWidth) {
            lastWidgetWindowWidth = window.innerWidth;
            if (!isTabletOrDesktop()) {
                setWidgetVisibility(false, false);
            }
        }
    });

    function attachIframeLoadListeners() {
        const iframe = document.getElementById('messify-iframe');
        if (iframe) {
            iframe.addEventListener('load', disableMessifySearchAutoInput);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initWidgetState();
            attachIframeLoadListeners();
        });
    } else {
        initWidgetState();
        attachIframeLoadListeners();
    }
})();
</script>



@endunless
@endif
@endauth
