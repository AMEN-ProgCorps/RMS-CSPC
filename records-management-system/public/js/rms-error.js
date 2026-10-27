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

        if (!message && (code === 408 || code === 504 || (offline && navigator.onLine))) {
            title = 'Slow internet';
            message = 'Your internet connection is too slow. Check your connection and try again.';
        }
        if (!message && (offline || code === 503)) {
            message = 'Cannot connect to the server.';
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
    var SLOW_MS = 15000;
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
            if (history.length > 1) history.back();
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
        if (history.length > 1) history.back();
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
        var existing = document.getElementById('rmsSlowInternet');
        if (!existing) {
            existing = document.createElement('div');
            existing.id = 'rmsSlowInternet';
            existing.style.cssText = 'position:fixed;inset:0;z-index:100000;display:grid;place-items:center;font-family:Inter,system-ui,sans-serif;';
            existing.innerHTML =
                '<main id="rmsErrorCard" style="width:min(520px,calc(100% - 32px));background:#fff;border-radius:12px;padding:24px;color:#0f172a;">' +
                    '<p id="rmsErrorStatus" style="margin:0;font-size:0.8rem;font-weight:700;letter-spacing:0.04em;"></p>' +
                    '<strong id="rmsErrorTitle" style="display:block;margin-top:6px;font-size:1.15rem;"></strong>' +
                    '<p id="rmsErrorMessage" style="margin:8px 0 0;color:#475569;line-height:1.5;"></p>' +
                    '<p id="rmsErrorFile" style="margin-top:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#334155;font-size:0.85rem;line-height:1.45;word-break:break-word;"></p>' +
                    '<div id="rmsErrorActions" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:16px;">' +
                        '<a href="#" id="rmsSlowInternetBack" style="display:inline-block;font-weight:600;text-decoration:none;">Back to the system</a>' +
                        '<button type="button" id="rmsErrorReportBtn" style="display:none;border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 12px;font:600 0.85rem Inter,system-ui,sans-serif;cursor:pointer;">Report this error</button>' +
                    '</div>' +
                '</main>';
            document.body.appendChild(existing);
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
                        reference: existing.dataset.reference || null,
                        title: (document.getElementById('rmsErrorTitle') || {}).textContent || 'Server error',
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
        if (backEl) {
            backEl.setAttribute('href', backTarget || '#');
            backEl.setAttribute('data-return', backTarget);
        }
        existing.dataset.kind = (status === 408 || status === 504 || title === 'Slow internet') ? 'slow' : 'error';
        existing.dataset.reference = detail.reference || '';
        var reportBtn = document.getElementById('rmsErrorReportBtn');
        if (reportBtn) {
            var canReport = status >= 500 || !!detail.reference;
            reportBtn.style.display = canReport ? 'inline-block' : 'none';
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

    function armSlowWatch() {
        if (!userIsWaiting()) return false;
        var generation = watchGeneration;
        inflight += 1;
        if (!slowTimer) {
            slowTimer = setTimeout(function () {
                slowTimer = null;
                if (generation !== watchGeneration || inflight < 1) return;
                showSlowInternet(false);
            }, SLOW_MS);
        }
        return true;
    }

    function disarmSlowWatch(failed) {
        inflight = Math.max(0, inflight - 1);
        if (inflight > 0) return;
        if (slowTimer) {
            clearTimeout(slowTimer);
            slowTimer = null;
        }
        if (!failed) hideSlowInternet();
    }

    function pauseSlowWatch() {
        watchGeneration += 1;
        inflight = 0;
        if (slowTimer) {
            clearTimeout(slowTimer);
            slowTimer = null;
        }
        hideSlowInternet();
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
            var skipSlow = /\/dcs\/api\/documents\/(search|revisions)/.test(url)
                || /\/livewire\/(update|upload-file)/.test(url)
                || /incoming-live|office-incoming|print-ready/.test(url);
            var armed = !isUpload && !skipSlow && armSlowWatch();
            return originalFetch.apply(this, arguments).then(function (response) {
                var slowStatus = response && (response.status === 408 || response.status === 504);
                if (armed) disarmSlowWatch(slowStatus);
                if (slowStatus) showSlowInternet(false);
                return response;
            }, function (error) {
                var aborted = error && (error.name === 'AbortError' || /abort/i.test(String(error.message || '')));
                if (armed) disarmSlowWatch(!aborted);
                if (!aborted) showSlowInternet(false);
                throw error;
            });
        };
    }

    window.addEventListener('offline', function () {
        if (document.visibilityState === 'hidden') return;
        showSlowInternet(true);
    });
    window.addEventListener('online', hideSlowInternet);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') pauseSlowWatch();
    });

    document.addEventListener('livewire:init', function () {
        if (window.__rmsErrorHooked || typeof Livewire === 'undefined' || typeof Livewire.hook !== 'function') {
            return;
        }
        window.__rmsErrorHooked = true;
        Livewire.hook('request', function (hooks) {
            hooks.fail(function (payload) {
                var diagnosed = window.rmsDiagnoseError(payload.status, payload.content);
                document.dispatchEvent(new CustomEvent('rms:error', { detail: diagnosed }));
            });
        });
    });

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
})();
