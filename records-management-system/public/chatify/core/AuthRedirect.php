<?php
// =============================================================================
// core/AuthRedirect.php — Shared "not authenticated" redirect
// =============================================================================
// Single source of truth for what happens when Auth::check() fails on a
// full-page (non-AJAX) Chatify entry point. Every such entry point
// (index.php, auth_entry.php, ...) calls AuthRedirect::toLogin() instead of
// keeping its own copy of this logic — one file to edit, every entry point
// stays behaviorally identical automatically.
//
// This replaces the old core/AccessDeniedView.php, which rendered a static
// "Access Denied" page. Per product decision, Chatify no longer shows its
// own denied/error page for authentication failures of any kind (no
// session, expired session, destroyed session, invalid/expired SSO token,
// or a direct hit on a Chatify URL with no RMS session at all) — it sends
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

        // Server-side redirect, before any HTML/JS/CSS output. RMS_LOGIN_URL
        // is defined once in config/db.php (default '/', same target
        // logout.php already redirects to).
        header('Location: ' . RMS_LOGIN_URL);
        exit();
    }
}
