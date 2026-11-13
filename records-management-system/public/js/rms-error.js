/**
 * Read the shared error payload (status, error_kind, title, message, reference).
 * DCS, DTS, RDP, and Admin can call rmsDiagnoseError(status, body) on any failed fetch.
 * Livewire failures also dispatch a document event named rms:error.
 */
(function () {
    function readBody(content) {
        if (content && typeof content === 'object') {
            return content;
        }
        if (typeof content === 'string' && content.trim().charAt(0) === '{') {
            try {
                return JSON.parse(content);
            } catch (e) {
                return null;
            }
        }
        return null;
    }

    window.rmsDiagnoseError = function (status, content) {
        var body = readBody(content);
        var code = Number(status) || (body && Number(body.status)) || 0;
        var offline = !code;
        var kind = body && body.error_kind
            ? body.error_kind
            : (offline || code >= 500 ? 'server' : 'client');
        var title = (body && body.title) || (kind === 'client' ? 'Client error' : 'Server error');
        var message = (body && body.message) || '';

        if (!message && (code === 408 || code === 504)) {
            title = 'Slow internet';
            message = 'Your internet connection is too slow. Check your connection and try again.';
        }
        if (!message && (offline || code === 503)) {
            title = offline ? 'Connection error' : 'Service unavailable';
            message = 'Cannot connect to the server. Please check your connection and try again.';
        }
        if (!message && code === 401) {
            message = 'Sign in again before continuing.';
        }
        if (!message && code === 403) {
            message = 'You do not have access to do that.';
        }
        if (!message && code === 404) {
            message = 'That record could not be found.';
        }
        if (!message && code === 419) {
            message = 'Your session expired. Refresh the page and try again.';
        }
        if (!message && code === 422) {
            message = 'Some of the information needs to be corrected before this can continue.';
        }
        if (!message && code === 429) {
            message = 'Too many attempts. Wait a moment and try again.';
        }
        if (!message) {
            message = kind === 'client'
                ? 'This request could not be completed.'
                : 'The server could not finish this request.';
        }

        var reference = body && body.reference ? body.reference : null;
        if (reference && message.indexOf(reference) === -1) {
            message += ' Reference ' + reference + '.';
        }
        var file = body && body.file ? String(body.file) : '';
        var line = body && body.line ? Number(body.line) : 0;
        if (file && message.indexOf(file) === -1) {
            message += ' File ' + file + (line ? ' on line ' + line : '') + '.';
        }

        return {
            status: code,
            error_kind: kind,
            title: title,
            message: message,
            reference: reference,
            file: file || null,
            line: line || null,
            return_url: pageHere(),
            redirect: body && body.redirect ? body.redirect : null,
            skipDefault: code !== 422
        };
    };

    function pageHere() {
        return location.pathname + location.search;
    }

    function rememberPage() {
        var here = pageHere();
        try {
            var last = sessionStorage.getItem('rmsLastGoodPage') || '';
            if (last && last !== here) {
                sessionStorage.setItem('rmsPreviousPage', last);
            }
            sessionStorage.setItem('rmsLastGoodPage', here);
        } catch (e) {}
    }

    function previousPage() {
        var here = pageHere();
        var saved = [];
        try {
            saved.push(sessionStorage.getItem('rmsLastGoodPage') || '');
            saved.push(sessionStorage.getItem('rmsPreviousPage') || '');
        } catch (e) {}
        try {
            if (document.referrer && document.referrer.indexOf(location.origin) === 0) {
                var from = new URL(document.referrer);
                saved.push(from.pathname + from.search);
            }
        } catch (e) {}
        for (var i = 0; i < saved.length; i++) {
            var url = saved[i];
            if (url && url.charAt(0) === '/' && url.indexOf('//') !== 0 && url !== '/' && url !== here) {
                return url;
            }
        }
        return '';
    }

    rememberPage();
    document.addEventListener('livewire:navigated', rememberPage);

    var slowTimer = null;
    var inflight = 0;
    var watchGeneration = 0;
    var userActionAt = Date.now();
    var SLOW_MS = 30000;
    var USER_WAIT_MS = 20000;

    ['click', 'keydown', 'submit', 'input'].forEach(function (name) {
        document.addEventListener(name, function () {
            userActionAt = Date.now();
        }, true);
    });

    function userIsWaiting() {
        return document.visibilityState !== 'hidden' && (Date.now() - userActionAt) < USER_WAIT_MS;
    }

    function returnToPage(target) {
        if (!target) {
            if (history.length > 1) {
                history.back();
            } else {
                location.reload();
            }
            return;
        }
        try {
            var url = new URL(target, location.origin);
            var sameOrigin = url.origin === location.origin;
            var safeProtocol = url.protocol === 'http:' || url.protocol === 'https:';
            if (sameOrigin && safeProtocol) {
                location.assign(url.href);
                return;
            }
        } catch (e) {}
        if (history.length > 1) {
            history.back();
        } else {
            location.reload();
        }
    }

    function errorColor(status) {
        var colors = {
            401: '#1d4ed8',
            403: '#be123c',
            404: '#0f766e',
            405: '#4338ca',
            408: '#c2410c',
            413: '#a16207',
            419: '#b45309',
            422: '#a16207',
            429: '#c2410c',
            500: '#dc2626',
            502: '#9f1239',
            503: '#b91c1c',
            504: '#9a3412'
        };
        if (colors[status]) return colors[status];
        return status >= 500 ? '#dc2626' : '#1d4ed8';
    }

    function reportErrorPopout(detail) {
        if (!detail) return;
        var status = Number(detail.status) || 0;
        // Skip routine client auth redirects, csrf renewals, or validation failures
        if (status === 401 || status === 419 || status === 422) return;

        var key = (status || '0') + '|' + (detail.title || '') + '|' + (detail.file || '') + '|' + (detail.message || '').substring(0, 50);
        var now = Date.now();
        window.__rmsReportedErrors = window.__rmsReportedErrors || {};
        if (window.__rmsReportedErrors[key] && (now - window.__rmsReportedErrors[key]) < 15000) {
            return;
        }
        window.__rmsReportedErrors[key] = now;

        if (!detail.reference) {
            detail.reference = 'POP-' + Math.random().toString(36).substring(2, 8).toUpperCase();
        }

        var token = document.querySelector('meta[name="csrf-token"]');
        var csrf = token ? token.getAttribute('content') : '';
        if (!csrf) {
            var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
            csrf = match ? decodeURIComponent(match[1]) : '';
        }

        var payload = {
            reference: detail.reference,
            title: detail.title || (status >= 500 ? 'Server error' : (status === 408 ? 'Slow internet' : 'Client error popout')),
            message: detail.message || '',
            kind: detail.error_kind || (status >= 500 ? 'server' : (status === 408 ? 'network' : 'popout')),
            http_status: status || (detail.error_kind === 'server' ? 500 : 0),
            url: detail.return_url || (location.pathname + location.search),
            file: detail.file || null,
            line: detail.line || null,
            context: {
                source: 'error_popout',
                screen: window.innerWidth + 'x' + window.innerHeight,
                online: navigator.onLine,
                time: new Date().toISOString()
            }
        };

        try {
            var nativeFetch = originalFetch || window.fetch;
            nativeFetch('/api/errors/auto-capture', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-XSRF-TOKEN': csrf
                },
                body: JSON.stringify(payload)
            }).then(function (r) {
                return r.json().catch(function () { return {}; });
            }).then(function (res) {
                if (res && res.ticket_number) {
                    detail.reference = res.ticket_number;
                    var el = document.getElementById('rmsSlowInternet');
                    if (el) el.dataset.reference = res.ticket_number;
                    var refEl = document.getElementById('rmsErrorRef');
                    if (refEl) {
                        refEl.textContent = 'Reference: ' + res.ticket_number;
                        refEl.style.display = 'block';
                    }
                }
            }).catch(function () {});
        } catch (e) {}
    }

    function showErrorScreen(detail, force) {
        if (!force && !userIsWaiting()) return;
        detail = detail || {};
        var backTarget = previousPage();
        var status = detail.status || 408;
        var title = detail.title || 'Slow internet';
        var message = detail.message || 'Your internet connection is too slow. Check your connection and try again.';
        var file = detail.file || '';
        var line = detail.line || 0;
        var color = errorColor(status);

        if (!detail.reference) {
            detail.reference = 'POP-' + Math.random().toString(36).substring(2, 8).toUpperCase();
        }

        reportErrorPopout(detail);

        var existing = document.getElementById('rmsSlowInternet');
        if (!existing) {
            existing = document.createElement('div');
            existing.id = 'rmsSlowInternet';
            existing.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:grid;place-items:center;font-family:Inter,system-ui,sans-serif;';
            existing.innerHTML =
                '<main id="rmsErrorCard" style="position:relative;width:min(520px,calc(100% - 32px));background:#fff;border-radius:12px;padding:24px;color:#0f172a;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1);">' +
                    '<button type="button" id="rmsErrorDismiss" title="Close" style="position:absolute;top:14px;right:16px;border:none;background:transparent;font-size:1.4rem;line-height:1;color:#94a3b8;cursor:pointer;padding:4px 8px;border-radius:6px;">&times;</button>' +
                    '<p id="rmsErrorStatus" style="margin:0;font-size:0.8rem;font-weight:700;letter-spacing:0.04em;"></p>' +
                    '<strong id="rmsErrorTitle" style="display:block;margin-top:6px;font-size:1.15rem;"></strong>' +
                    '<p id="rmsErrorMessage" style="margin:8px 0 0;color:#475569;line-height:1.5;"></p>' +
                    '<p id="rmsErrorFile" style="margin-top:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#334155;font-size:0.85rem;line-height:1.45;word-break:break-word;"></p>' +
                    '<p id="rmsErrorRef" style="display:none;margin-top:10px;font-size:0.8rem;font-weight:600;color:#64748b;font-family:monospace;"></p>' +
                    '<div id="rmsErrorActions" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:16px;">' +
                        '<a href="#" id="rmsSlowInternetBack" style="display:inline-block;font-weight:600;text-decoration:none;">Back to the system</a>' +
                        '<button type="button" id="rmsErrorReportBtn" style="display:inline-block;border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 12px;font:600 0.85rem Inter,system-ui,sans-serif;cursor:pointer;">Report this error</button>' +
                    '</div>' +
                '</main>';
            document.body.appendChild(existing);
            var dismissBtn = document.getElementById('rmsErrorDismiss');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function () {
                    existing.hidden = true;
                    existing.style.display = 'none';
                });
            }
            var back = document.getElementById('rmsSlowInternetBack');
            if (back) {
                back.addEventListener('click', function (event) {
                    event.preventDefault();
                    returnToPage(back.getAttribute('data-return') || '');
                });
            }
            var reportBtn = document.getElementById('rmsErrorReportBtn');
            if (reportBtn) {
                reportBtn.addEventListener('click', function () {
                    var payload = {
                        reference: existing.dataset.reference || detail.reference || null,
                        title: (document.getElementById('rmsErrorTitle') || {}).textContent || 'Error Encountered',
                        description: (document.getElementById('rmsErrorMessage') || {}).textContent || ''
                    };
                    window.dispatchEvent(new CustomEvent('rms-open-report-problem', { detail: payload }));
                });
            }
        }
        existing.hidden = false;
        existing.style.display = 'grid';
        existing.style.background = 'color-mix(in srgb, ' + color + ' 8%, #f8fafc)';
        var card = document.getElementById('rmsErrorCard');
        if (card) {
            card.style.border = '1px solid color-mix(in srgb, ' + color + ' 35%, #e2e8f0)';
            card.style.borderTop = '4px solid ' + color;
        }
        var statusEl = document.getElementById('rmsErrorStatus');
        var titleEl = document.getElementById('rmsErrorTitle');
        var messageEl = document.getElementById('rmsErrorMessage');
        var fileEl = document.getElementById('rmsErrorFile');
        var refEl = document.getElementById('rmsErrorRef');
        var backEl = document.getElementById('rmsSlowInternetBack');
        if (statusEl) {
            statusEl.textContent = status ? ('Error ' + status) : 'Error';
            statusEl.style.color = color;
        }
        if (backEl) backEl.style.color = color;
        if (titleEl) titleEl.textContent = title;
        if (messageEl) messageEl.textContent = message;
        if (fileEl) {
            fileEl.hidden = !file;
            fileEl.textContent = file ? ('File: ' + file + (line ? ' on line ' + line : '')) : '';
        }
        if (refEl) {
            if (detail.reference) {
                refEl.textContent = 'Reference: ' + detail.reference;
                refEl.style.display = 'block';
            } else {
                refEl.style.display = 'none';
            }
        }
        if (backEl) {
            backEl.setAttribute('href', backTarget || '#');
            backEl.setAttribute('data-return', backTarget);
        }
        existing.dataset.kind = (status === 408 || status === 504 || title === 'Slow internet') ? 'slow' : 'error';
        existing.dataset.reference = detail.reference || '';
        var reportBtn = document.getElementById('rmsErrorReportBtn');
        if (reportBtn) {
            reportBtn.style.display = 'inline-block';
            reportBtn.style.color = color;
            reportBtn.style.borderColor = color;
        }
    }

    function showSlowInternet(force) {
        showErrorScreen({
            status: 408,
            title: 'Slow internet',
            message: 'Your internet connection is too slow. Check your connection and try again.',
            return_url: pageHere()
        }, force);
    }

    function hideSlowInternet() {
        var existing = document.getElementById('rmsSlowInternet');
        if (existing && existing.dataset.kind === 'error') return;
        if (existing) {
            existing.hidden = true;
            existing.style.display = 'none';
        }
    }

    function armSlowRequest() {
        if (!userIsWaiting()) return null;
        return setTimeout(function () {
            if (userIsWaiting()) {
                showSlowInternet(false);
            }
        }, SLOW_MS);
    }

    function disarmSlowRequest(timerId, failed) {
        if (timerId) {
            clearTimeout(timerId);
        }
        if (!failed) {
            hideSlowInternet();
        }
    }

    if (typeof window.fetch === 'function' && !window.__rmsSlowFetch) {
        window.__rmsSlowFetch = true;
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            var body = init && init.body;
            var isUpload = typeof FormData !== 'undefined' && body instanceof FormData;
            var url = '';
            try {
                if (typeof input === 'string') url = input;
                else if (input && typeof input.url === 'string') url = input.url;
                else if (input && input.href) url = String(input.href);
            } catch (e) { url = ''; }

            // Background polling, chat heartbeats, notification counters, and Livewire polls should NEVER trigger Slow Internet popouts
            var isBackgroundPoll = /unread-count|fetch_notifications|fetch_users_dm|ping|heartbeat|chatify|messify|auto-capture|\/api\/notifications|\/livewire\//i.test(url);
            var skipSlow = isUpload || isBackgroundPoll
                || /\/dcs\/api\/documents\/(search|revisions)/.test(url)
                || /incoming-live|office-incoming|print-ready/.test(url);

            var timerId = !skipSlow ? armSlowRequest() : null;

            return originalFetch.apply(this, arguments).then(function (response) {
                var slowStatus = response && (response.status === 408 || response.status === 504);
                disarmSlowRequest(timerId, slowStatus);
                if (slowStatus && !isBackgroundPoll) showSlowInternet(false);
                return response;
            }, function (error) {
                var aborted = (error && (
                    error.name === 'AbortError' || 
                    /abort|cancel|cancelled/i.test(String(error.message || ''))
                )) || isBackgroundPoll;

                disarmSlowRequest(timerId, !aborted);
                if (!aborted && !isBackgroundPoll && userIsWaiting()) {
                    showSlowInternet(false);
                }
                throw error;
            });
        };
    }

    var offlineDebounceTimer = null;
    window.addEventListener('offline', function () {
        if (document.visibilityState === 'hidden') return;
        // Verify with 3.5s debounce to filter out virtual adapter blips or transient switches
        if (offlineDebounceTimer) clearTimeout(offlineDebounceTimer);
        offlineDebounceTimer = setTimeout(function () {
            if (!navigator.onLine) {
                showSlowInternet(true);
            }
        }, 3500);
    });
    window.addEventListener('online', function () {
        if (offlineDebounceTimer) {
            clearTimeout(offlineDebounceTimer);
            offlineDebounceTimer = null;
        }
        hideSlowInternet();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            hideSlowInternet();
        }
    });

    function hookLivewireErrors() {
        if (window.__rmsErrorHooked || typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }
        window.__rmsErrorHooked = true;
        Livewire.hook('request', function (hooks) {
            hooks.fail(function (payload) {
                // If status is 0 or canceled (user navigated away, refreshed, or morph race), skip!
                if (!payload || !payload.status || payload.status === 0) {
                    return;
                }
                // Prevent Livewire's default raw iframe debug box from taking over the screen
                if (typeof payload.preventDefault === 'function') {
                    payload.preventDefault();
                }
                var diagnosed = window.rmsDiagnoseError(payload.status, payload.content);
                document.dispatchEvent(new CustomEvent('rms:error', { detail: diagnosed }));
            });
        });
    }

    document.addEventListener('livewire:init', hookLivewireErrors);
    if (typeof Livewire !== 'undefined' && typeof Livewire.hook === 'function') {
        hookLivewireErrors();
    }

    document.addEventListener('rms:error', function (event) {
        var detail = event.detail || {};
        var status = Number(detail.status) || 0;
        if (status === 401 || status === 419 || status === 422) return;
        showErrorScreen(detail, true);
    });

    window.addEventListener('error', function (event) {
        var message = String((event && event.message) || '');
        // Livewire snapshot races during background polls / morphs — not user-facing outages.
        if (/ResizeObserver|undelivered notifications|script error|Snapshot missing on Livewire component/i.test(message)) return;
        // Optional widget helpers may be clicked before scripts finish loading.
        if (/toggleMessifyWidget|reloadMessifyIframe|hideMessifyLoader|updateMessifyUnreadBadge/i.test(message)) return;
        var filename = event && event.filename ? String(event.filename) : '';
        if (!filename || filename.indexOf(location.origin) !== 0) return;
        if (/\/vendor\/livewire\//i.test(filename) && /Snapshot missing/i.test(message)) return;
        showErrorScreen({
            status: 0,
            error_kind: 'client',
            title: 'Client error',
            message: (event.message || 'This page hit a script error.'),
            file: filename.replace(location.origin, ''),
            line: event.lineno || null,
            return_url: pageHere()
        }, true);
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event && event.reason;
        var message = '';
        if (reason && typeof reason === 'object' && reason.message) message = String(reason.message);
        else if (typeof reason === 'string') message = reason;
        if (/Snapshot missing on Livewire component/i.test(message)) {
            if (event && typeof event.preventDefault === 'function') event.preventDefault();
        }
    });

    /* =========================================================================
     * ACTIVE CIRCUIT BREAKER, MODAL GUARD & SIDE DUAL-SLOT STACKING
     * ========================================================================= */

    function normalizePath(url) {
        if (!url) return null;
        try {
            var parsed = new URL(url, location.origin);
            if (parsed.origin !== location.origin) return null;
            var path = parsed.pathname.toLowerCase();
            if (path.length > 1 && path.endsWith('/')) path = path.slice(0, -1);
            return path;
        } catch (e) {
            return null;
        }
    }

    window.__rmsGuardsReady = false;

    function syncActiveGuards() {
        try {
            fetch('/api/errors/active-guards', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && typeof data === 'object') {
                        window.__rmsActiveGuards = data;
                    }
                    window.__rmsGuardsReady = true;
                })
                .catch(function () {
                    // Keep blade snapshot usable if refresh fails.
                    window.__rmsGuardsReady = true;
                });
        } catch (e) {
            window.__rmsGuardsReady = true;
        }
    }

    // Always refresh — blade snapshot stays stale after tickets are resolved in another tab/session.
    syncActiveGuards();
    setInterval(syncActiveGuards, 15000);
    window.addEventListener('focus', syncActiveGuards);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) syncActiveGuards();
    });

    function getOrCreateSideStack() {
        var stack = document.getElementById('rms-side-alert-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'rms-side-alert-stack';
            stack.style.cssText = 'position:fixed;bottom:84px;right:24px;z-index:999999;display:flex;flex-direction:column;gap:10px;max-width:380px;width:calc(100vw - 48px);pointer-events:none;';
            document.body.appendChild(stack);
        }
        return stack;
    }

    window.rmsPromoteSideCard = function (type) {
        var errorCard = document.getElementById('rms-side-error-card');
        var notifCard = document.getElementById('rms-side-notif-container') || document.querySelector('.notif-overlay-container');
        if (!errorCard) return;
        if (type === 'notif') {
            if (notifCard) notifCard.style.order = '1';
            errorCard.style.order = '2';
        } else {
            errorCard.style.order = '1';
            if (notifCard) notifCard.style.order = '2';
        }
    };

    function rmsShowSideErrorCard(info) {
        var stack = getOrCreateSideStack();
        var card = document.getElementById('rms-side-error-card');
        if (!card) {
            card = document.createElement('div');
            card.id = 'rms-side-error-card';
            card.style.cssText = 'pointer-events:auto;background:#ffffff;border:1px solid #fecaca;border-left:4px solid #ef4444;border-radius:10px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.06);padding:14px 16px;font-family:Inter,system-ui,sans-serif;color:#1e293b;transition:all 0.25s ease;order:1;position:relative;';
            
            card.addEventListener('mouseenter', function () {
                window.rmsPromoteSideCard('error');
            });
            card.addEventListener('click', function () {
                window.rmsPromoteSideCard('error');
            });

            stack.appendChild(card);
        }

        // Error card is placed at the top (order: 1) by default when both coexist
        card.style.order = '1';
        var notifCard = document.getElementById('rms-side-notif-container') || document.querySelector('.notif-overlay-container');
        if (notifCard) notifCard.style.order = '2';

        var isRepeat = !!info.isRepeat;
        var headerLabel = isRepeat ? 'Access Blocked: Error Page' : 'Page Temporarily Unavailable';
        var badge = info.ticket ? ('Ticket ' + info.ticket) : (info.ref ? ('Ref ' + info.ref) : 'Active Incident');
        var message = isRepeat
            ? 'You are attempting to access an error page. Please wait for an administrator to fix it before trying again.'
            : (info.type === 'modal'
                ? 'This feature is temporarily unavailable due to an active system error. Please wait for an admin to resolve it.'
                : 'The page <code style="background:#f1f5f9;padding:2px 5px;border-radius:4px;font-size:11px;color:#dc2626;">' + (info.path || '') + '</code> encountered an automated crash. Reverted to current page to prevent interruption.');

        card.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">' +
                '<div style="display:flex;align-items:center;gap:7px;">' +
                    '<span style="width:8px;height:8px;border-radius:50%;background:#ef4444;box-shadow:0 0 6px #ef4444;display:inline-block;"></span>' +
                    '<strong style="font-size:12.5px;color:#b91c1c;">' + headerLabel + '</strong>' +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:6px;">' +
                    '<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 6px;border-radius:999px;">' + badge + '</span>' +
                    '<button type="button" id="rms-side-error-close" style="border:none;background:transparent;color:#94a3b8;font-size:16px;line-height:1;cursor:pointer;padding:0 2px;">&times;</button>' +
                '</div>' +
            '</div>' +
            '<p style="margin:6px 0 10px 0;font-size:12px;color:#475569;line-height:1.45;">' + message + '</p>' +
            '<div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;">' +
                '<button type="button" id="rms-side-error-report" style="border:1px solid #cbd5e1;background:#f8fafc;color:#334155;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;">Report / Info</button>' +
                '<button type="button" id="rms-side-error-dismiss" style="border:none;background:#ef4444;color:#ffffff;border-radius:6px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;">Dismiss</button>' +
            '</div>';

        card.style.display = 'block';

        var closeBtn = document.getElementById('rms-side-error-close');
        var dismissBtn = document.getElementById('rms-side-error-dismiss');
        var reportBtn = document.getElementById('rms-side-error-report');

        var closeCard = function (allowRetry) {
            if (allowRetry && info && info.path) {
                window.__rmsDismissedPageGuards = window.__rmsDismissedPageGuards || {};
                window.__rmsDismissedPageGuards[info.path] = true;
                if (window.__rmsActiveGuards && window.__rmsActiveGuards.pages) {
                    delete window.__rmsActiveGuards.pages[info.path];
                }
            }
            card.style.display = 'none';
        };

        if (closeBtn) closeBtn.onclick = function () { closeCard(true); };
        if (dismissBtn) dismissBtn.onclick = function () { closeCard(true); };
        if (reportBtn) {
            reportBtn.onclick = function () {
                window.dispatchEvent(new CustomEvent('rms-open-report-problem', {
                    detail: {
                        reference: info.ticket || info.ref || null,
                        title: 'Error on ' + (info.path || info.modalKey || 'feature'),
                        description: 'Encountered automated circuit breaker block for ticket ' + (info.ticket || '') + ' on ' + (info.path || '')
                    }
                }));
                closeCard(true);
            };
        }
    }

    // Expose Side Stacking & Modal Guard APIs
    window.rmsShowSideErrorCard = rmsShowSideErrorCard;

    window.rmsCheckPageGuard = function (targetUrl) {
        var path = normalizePath(targetUrl);
        if (!path) return false;

        var currentPath = normalizePath(location.pathname);
        if (path === currentPath) return false;

        window.__rmsDismissedPageGuards = window.__rmsDismissedPageGuards || {};
        if (window.__rmsDismissedPageGuards[path]) return false;

        // Do not hard-block from a possibly stale blade snapshot; wait for API refresh.
        if (!window.__rmsGuardsReady) {
            syncActiveGuards();
            return false;
        }

        var guards = (window.__rmsActiveGuards && window.__rmsActiveGuards.pages) || {};
        var error = guards[path];
        if (!error) return false;

        // Keep client guards aligned with ticket status for the next click.
        syncActiveGuards();

        window.__rmsAttemptedPages = window.__rmsAttemptedPages || {};
        var isRepeat = !!window.__rmsAttemptedPages[path];
        window.__rmsAttemptedPages[path] = true;

        rmsShowSideErrorCard({
            type: 'page',
            path: path,
            targetUrl: targetUrl,
            ticket: error.ticket,
            title: error.title,
            ref: error.ref,
            subsystem: error.subsystem,
            isRepeat: isRepeat
        });

        return true; // Navigation blocked
    };

    // Successful page render clears any circuit-breaker lock for this path.
    (function clearGuardForCurrentPage() {
        var path = normalizePath(location.pathname);
        if (!path || !window.__rmsActiveGuards || !window.__rmsActiveGuards.pages) return;
        if (window.__rmsActiveGuards.pages[path]) {
            delete window.__rmsActiveGuards.pages[path];
        }
        window.__rmsDismissedPageGuards = window.__rmsDismissedPageGuards || {};
        window.__rmsDismissedPageGuards[path] = true;
    })();

    window.rmsCheckModalGuard = function (modalKey) {
        var modals = (window.__rmsActiveGuards && window.__rmsActiveGuards.modals) || {};
        var cleanKey = String(modalKey || '').toLowerCase();
        var error = modals[cleanKey] || modals['modal:' + cleanKey] || modals[cleanKey.replace(/^modal[:_]/, '')];
        if (!error) return false;

        window.__rmsAttemptedModals = window.__rmsAttemptedModals || {};
        var isRepeat = !!window.__rmsAttemptedModals[cleanKey];
        window.__rmsAttemptedModals[cleanKey] = true;

        rmsShowSideErrorCard({
            type: 'modal',
            modalKey: modalKey,
            ticket: error.ticket,
            title: error.title,
            ref: error.ref,
            isRepeat: isRepeat
        });

        return true; // Modal open blocked
    };

    window.rmsHasActiveModalError = function (modalKey) {
        var modals = (window.__rmsActiveGuards && window.__rmsActiveGuards.modals) || {};
        var cleanKey = String(modalKey || '').toLowerCase();
        return !!(modals[cleanKey] || modals['modal:' + cleanKey] || modals[cleanKey.replace(/^modal[:_]/, '')]);
    };

    // Wrap navigation calls (proccedto)
    var prevProccedto = window.proccedto;
    window.proccedto = function (url) {
        if (window.rmsCheckPageGuard && window.rmsCheckPageGuard(url)) {
            return false;
        }
        if (typeof prevProccedto === 'function' && prevProccedto !== window.proccedto) {
            return prevProccedto(url);
        }
        window.location.href = url;
    };

    // Intercept standard internal anchor clicks
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href]');
        if (link) {
            var href = link.getAttribute('href');
            if (href && href.charAt(0) === '/' && !href.startsWith('//') && !href.startsWith('/#') && !href.startsWith('/api/')) {
                if (window.rmsCheckPageGuard && window.rmsCheckPageGuard(href)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }
        }
    }, true);

    // Intercept clicks on elements with modal guard attributes
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-modal-guard], [data-modal]');
        if (trigger) {
            var key = trigger.getAttribute('data-modal-guard') || trigger.getAttribute('data-modal');
            if (key && window.rmsCheckModalGuard && window.rmsCheckModalGuard(key)) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                return false;
            }
        }
    }, true);
})();
