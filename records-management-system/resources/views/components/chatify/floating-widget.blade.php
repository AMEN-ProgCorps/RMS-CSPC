@auth
@unless(request()->routeIs('login', 'track-document', 'tracked') || request()->is('/', 'track-document', 'tracked'))
@php
    $user = auth()->user();
    $autoOpenChat = $user ? $user->autoOpenChat() : true;
@endphp

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

    function isTabletOrDesktop() {
        return window.innerWidth >= 768; // Screen width >= 768px (Tablet & PC)
    }

    function initWidgetState() {
        if (!isTabletOrDesktop()) {
            isOpen = false;
            setWidgetVisibility(false, false);
            return;
        }

        const storedState = sessionStorage.getItem('chatify_widget_open');
        if (storedState !== null) {
            isOpen = storedState === 'true';
        } else {
            // Auto-open on login ONLY if enabled AND on PC/Tablet layout (>= 768px)
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
            const count = parseInt(data.unread || 0, 10);

            const badge = document.getElementById('chatify-unread-badge');
            if (badge) {
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }

            const dropdownBadge = document.getElementById('chatify-dropdown-unread-badge');
            if (dropdownBadge) {
                if (count > 0) {
                    dropdownBadge.textContent = count > 99 ? '99+' : count;
                    dropdownBadge.style.display = 'inline-flex';
                } else {
                    dropdownBadge.style.display = 'none';
                }
            }
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
            return;
        }

        if (show) {
            if (iframe && !iframe.src) {
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
                    if (!isOpen) card.style.display = 'none';
                }, 250);
            } else {
                card.style.display = 'none';
                card.style.opacity = '0';
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
