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

        return {
            status: code,
            error_kind: kind,
            title: title,
            message: message,
            reference: reference,
            redirect: body && body.redirect ? body.redirect : null,
            skipDefault: code !== 422
        };
    };

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

    function subsystemHome() {
        var path = location.pathname;
        if (path.indexOf('/dcs') === 0) return '/dcs';
        if (path.indexOf('/dts') === 0) return '/dts';
        if (path.indexOf('/rdp') === 0) return '/rdp';
        if (path.indexOf('/admin') === 0) return '/admin/console';
        if (path.indexOf('/profile') === 0) return '/profile';
        return '';
    }

    function showSlowInternet(force) {
        if (!force && !userIsWaiting()) return;
        var existing = document.getElementById('rmsSlowInternet');
        if (existing) {
            existing.hidden = false;
            existing.style.display = 'grid';
            return;
        }
        var wrap = document.createElement('div');
        wrap.id = 'rmsSlowInternet';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:100000;display:grid;place-items:center;background:rgba(154,52,18,0.08);font-family:Inter,system-ui,sans-serif;';
        wrap.innerHTML =
            '<main style="width:min(520px,calc(100% - 32px));background:#fff;border:1px solid #fdba74;border-top:4px solid #c2410c;border-radius:12px;padding:24px;color:#0f172a;">' +
                '<p style="margin:0;color:#c2410c;font-size:0.8rem;font-weight:700;letter-spacing:0.04em;">Error 408</p>' +
                '<strong style="display:block;margin-top:6px;font-size:1.15rem;">Slow internet</strong>' +
                '<p style="margin:8px 0 0;color:#475569;line-height:1.5;">Your internet connection is too slow. Check your connection and try again.</p>' +
                '<a href="#" id="rmsSlowInternetBack" style="display:inline-block;margin-top:16px;color:#c2410c;font-weight:600;text-decoration:none;">Back to the system</a>' +
            '</main>';
        document.body.appendChild(wrap);
        var back = document.getElementById('rmsSlowInternetBack');
        if (!back) return;
        back.addEventListener('click', function (event) {
            event.preventDefault();
            var referrer = document.referrer || '';
            var referrerPath = '';
            try {
                referrerPath = referrer ? new URL(referrer).pathname.replace(/\/$/, '') || '/' : '';
            } catch (e) {
                referrerPath = '';
            }
            var sameSite = referrer.indexOf(location.origin) === 0;
            var here = location.pathname.replace(/\/$/, '') || '/';
            if (sameSite && referrerPath !== '/' && referrerPath !== here) {
                history.back();
                return;
            }
            var home = subsystemHome();
            if (home && here !== home.replace(/\/$/, '')) {
                location.href = home;
                return;
            }
            wrap.hidden = true;
        });
    }

    function hideSlowInternet() {
        var existing = document.getElementById('rmsSlowInternet');
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
            var armed = !isUpload && armSlowWatch();
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
})();
