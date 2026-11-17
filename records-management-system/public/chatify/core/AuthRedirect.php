<?php
// =============================================================================
// core/AuthRedirect.php — Shared "not authenticated" redirect
// =============================================================================
// Single source of truth for what happens when Auth::check() fails on a
// full-page (non-AJAX) Messify entry point. Every such entry point
// (index.php, auth_entry.php, ...) calls AuthRedirect::toLogin() instead of
// keeping its own copy of this logic — one file to edit, every entry point
// stays behaviorally identical automatically.
//
// This replaces the old core/AccessDeniedView.php, which rendered a static
// "Access Denied" page. Per product decision, Messify no longer shows its
// own denied/error page for authentication failures of any kind (no
// session, expired session, destroyed session, invalid/expired SSO token,
// or a direct hit on a Messify URL with no RMS session at all) — it sends
// the user straight to the main RMS login page instead, exactly as
// logout.php already does.
//
// This function ALWAYS ends the request (exit) — nothing after a call to
// toLogin() ever executes.
// =============================================================================

class AuthRedirect
{
    public static function toLogin(): void
    {
        // A redirect driven by an auth failure must never be cached — a
        // shared/browser cache serving this response for a *different*
        // later request (or serving a stale authenticated page instead of
        // re-running this check) would be exactly the kind of stale-auth
        // bug this guards against.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // Embedded Chatify iframe: never send the login page into the iframe.
        // The RMS login view used to break out to window.top and kick the
        // entire authenticated portal session right after load.
        $fetchDest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
        if ($fetchDest === 'iframe' || $fetchDest === 'empty') {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><script>'
                . 'try{parent.postMessage({type:"rms-chatify-auth-failed"},window.location.origin);}catch(e){}'
                . 'location.replace("about:blank");'
                . '</script></head><body></body></html>';
            exit();
        }

        // Full-page Chatify entry: redirect to RMS login.
        header('Location: ' . RMS_LOGIN_URL);
        exit();
    }
}
