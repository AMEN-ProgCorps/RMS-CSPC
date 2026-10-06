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
