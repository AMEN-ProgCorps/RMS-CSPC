<?php
require_once __DIR__ . '/bootstrap.php';
// IMPORTANT: this page is dynamic and session-gated (see the Auth::check()
// gate below) — it must NEVER be replayable from the browser's ordinary
// HTTP disk cache once the session that produced it is gone.
//
// A previous version of this file switched the session cache limiter to
// 'private_no_expire' to satisfy a Lighthouse bfcache warning about
// 'no-store' blocking back/forward-cache restoration. That "fix" was based
// on an outdated assumption: 'private_no_expire' sends
// "Cache-Control: private, max-age=<N>" with NO Expires/no-store, which
// makes this already-authenticated HTML a normal cacheable resource for
// up to session.cache_expire minutes (180 by default). Result: simply
// revisiting the URL (typing it again, a bookmark, a link) after logout/
// session expiry could be served straight from disk cache — no network
// request, no PHP execution, no Auth::check() — while the "bfcache guard"
// below (event.persisted) never fires, because that flag is only true for
// an actual back/forward-cache restore, not a plain disk-cache hit. AJAX
// endpoints (send.php, check_session.php, ...) always hit the network and
// correctly deny access, which is exactly the "UI still shows but can't
// send" symptom this produced.
//
// Back to explicit no-store: modern Chrome (96+), Firefox, and Safari all
// support bfcache for pages sent with Cache-Control: no-store, so the
// original Lighthouse concern no longer requires trading real cache safety
// away. The pageshow/event.persisted reload below is kept as defense in
// depth for the (rare) bfcache-restore path.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
session_cache_limiter('nocache');
session_start();

// ─────────────────────────────────────────────────────────────────────────
// SERVER-SIDE RMS SESSION GATE — must run before ANY output (HTML/JS/CSS).
// This is the ONLY authentication decision for this page. There is no
// client-side/JS fallback, and Chatify never mints or reuses a session of
// its own: Auth::check() must validate the request against the live RMS
// server-side session record (DB-backed), not merely the presence of a
// PHP $_SESSION array Chatify itself could have populated.
// ─────────────────────────────────────────────────────────────────────────
if (!Auth::check()) {
    // Defense-in-depth: if any Chatify-local session keys survived (e.g. a
    // stale session file from a previous request), wipe them so nothing
    // downstream can ever be reconstructed from them. Chatify must never
    // recreate or resurrect a session on its own once RMS says it's gone.
    $_SESSION = [];
    if (session_id() !== '') {
        session_unset();
        session_destroy();
    }
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    // Shared component — see core/AuthRedirect.php. Sets its own no-cache
    // headers, redirects to the RMS login page, and exits.
    AuthRedirect::toLogin();
}

// Get the user's name from session
// Collapse any repeated whitespace (e.g. accidental double spaces from how
// first/last name were combined at signup/login) and trim the ends, so the
// name field and every other place this value is echoed never shows things
// like "Test  User" with a double space.
$user_name = trim(preg_replace('/\s+/', ' ', (string) $_SESSION["name"]));
$username  = $_SESSION["username"] ?? '';

// Admin is strictly determined by account_id = 1
$_current_account_id = (int) ($_SESSION['account_id'] ?? 0);
$is_admin = ($_current_account_id === 1);
$_SESSION['is_admin'] = $is_admin;

// Admin display names: always just account_id 1's full_name
$admin_names = [];
if (!empty($_SESSION['full_name'])) {
    if ($is_admin) {
        $admin_names[] = strtolower(trim($_SESSION['full_name']));
        $admin_names[] = 'you'; // 'you' label when admin sees own messages
    } else {
        // We'll inject admin name when we know it via JS
    }
}
// Resolve the admin's full name from DB fresh on every load (not from
// session) so a name change made in the main system shows up immediately on
// the badge shown on the admin's messages to other users.
try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM account_details WHERE account_id = 1 LIMIT 1');
    $stmt->execute();
    $adminRow = $stmt->fetch();
    if ($adminRow) {
        $adminFullNameDisplay = trim(preg_replace('/\s+/', ' ', $adminRow['first_name'] . ' ' . $adminRow['last_name']));
        $adminFullName = strtolower($adminFullNameDisplay);
        if (!in_array($adminFullName, $admin_names)) {
            $admin_names[] = $adminFullName;
        }
    }
} catch (Throwable $e) {
    // Non-fatal
}

// Resolve the CURRENT user's own full name from DB fresh on every load, so
// a name change made in the main system shows up immediately in their own
// name field here — for every user, not just the Super Admin. Their session
// value can otherwise go stale until they log out and log back in.
try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM account_details WHERE account_id = ? LIMIT 1');
    $stmt->execute([$_current_account_id]);
    $ownRow = $stmt->fetch();
    if ($ownRow) {
        $ownFullNameDisplay = trim(preg_replace('/\s+/', ' ', $ownRow['first_name'] . ' ' . $ownRow['last_name']));
        if ($ownFullNameDisplay !== '') {
            $user_name = $ownFullNameDisplay;
            $_SESSION['name'] = $ownFullNameDisplay;
            $_SESSION['full_name'] = $ownFullNameDisplay;
            if ($is_admin) {
                $admin_names[0] = strtolower($ownFullNameDisplay); // keep 'you' badge match in sync too
            }
        }
    }
} catch (Throwable $e) {
    // Non-fatal
}


// Check for dark mode preference in cookie
$dark_mode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Chatify - CSPC</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
  <meta charset="UTF-8">
  <meta name="description" content="Chatify - real-time messaging para sa CSPC.">
  <link rel="icon" href="cspc.webp" type="image/png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" onload="this.onload=null;this.rel='stylesheet'">
  <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap"></noscript>
  <!-- Apply dark mode BEFORE page renders to prevent flash -->
  <script>
    (function() {
      var saved = localStorage.getItem('darkMode');
      if (saved === 'enabled') {
        document.documentElement.setAttribute('data-theme', 'dark');
      } else if (saved === 'disabled') {
        document.documentElement.removeAttribute('data-theme');
      } else {
        // No localStorage value yet — check cookie as fallback
        var cookieMatch = document.cookie.match(/(?:^|;\s*)dark_mode=([^;]*)/);
        if (cookieMatch && cookieMatch[1] === 'enabled') {
          document.documentElement.setAttribute('data-theme', 'dark');
          localStorage.setItem('darkMode', 'enabled');
        }
      }
    })();
  </script>
  <style>

    /* Light mode variables (default) */
    :root {
      --bg-primary: #f0f2f5;
      --bg-secondary: #ffffff;
      --bg-chat: #f0f2f5;
      --bg-bubble-sent: #1b74e4;
      --bg-bubble-received: #ffffff;
      --bg-input: #f0f2f5;
      --bg-avatar-received: #e4e6eb;
      --bg-avatar-sent: #1b74e4;
      --bg-header: #ffffff;
      --bg-modal: #ffffff;
      --bg-drop-overlay: rgba(27, 116, 228, 0.05);
      
      --text-primary: #050505;
      --text-secondary: #65676b;
      --text-bubble-sent: #ffffff;
      --text-bubble-received: #050505;
      --text-sender-sent: #ffffff; /* was rgba(255,255,255,0.7) — 2.99:1 contrast on #1b74e4, failed WCAG AA (needs 4.5:1); solid white gives the max achievable ~4.5:1 on this background */
      --text-sender-received: #65676b;
      --text-time-sent: rgba(255, 255, 255, 0.5);
      --text-time-received: #65676b;
      --text-header: #1b74e4;
      
      --border-color: #e4e6eb;
      --border-input: #e4e6eb;
      
      --button-bg: transparent;
      --button-text: #050505;
      --button-border: #e4e6eb;
      --button-hover: #f0f2f5;
      
      --scrollbar-track: #f0f2f5;
      --scrollbar-thumb: #bcc0c4;
      --scrollbar-thumb-hover: #8e8e8e;
      
      --icon-color: #65676b;
      --icon-hover: #1b74e4;
      
      --shadow-color: rgba(0,0,0,0.1);
    }

    /* Dark mode variables */
    html[data-theme="dark"],
    html[data-theme="dark"] body,
    [data-theme="dark"] {
      --bg-primary: #1a1a1a;
      --bg-secondary: #2d2d2d;
      --bg-chat: #1a1a1a;
      --bg-bubble-sent: #1b74e4;
      --bg-bubble-received: #3a3b3c;
      --bg-input: #3a3b3c;
      --bg-avatar-received: #4a4b4c;
      --bg-avatar-sent: #1b74e4;
      --bg-header: #2d2d2d;
      --bg-modal: #2d2d2d;
      --bg-drop-overlay: rgba(27, 116, 228, 0.15);
      
      --text-primary: #e4e6eb;
      --text-secondary: #b0b3b8;
      --text-bubble-sent: #ffffff;
      --text-bubble-received: #e4e6eb;
      --text-sender-sent: #ffffff; /* see light-mode note above — same fix for dark mode */
      --text-sender-received: #b0b3b8;
      --text-time-sent: rgba(255, 255, 255, 0.5);
      --text-time-received: #b0b3b8;
      --text-header: #1b74e4;
      
      --border-color: #3a3b3c;
      --border-input: #3a3b3c;
      
      --button-bg: transparent;
      --button-text: #e4e6eb;
      --button-border: #3a3b3c;
      --button-hover: #3a3b3c;
      
      --scrollbar-track: #2d2d2d;
      --scrollbar-thumb: #4a4b4c;
      --scrollbar-thumb-hover: #5a5b5c;
      
      --icon-color: #b0b3b8;
      --icon-hover: #1b74e4;
      
      --shadow-color: rgba(0,0,0,0.3);
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
    }

    html {
      height: 100%;
      height: 100dvh;
      overflow: hidden;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background-color: var(--bg-primary);
      height: 100%;
      height: 100dvh;
      margin: 0;
      padding: 0;
      overflow: hidden;
      color: var(--text-primary);
      transition: background-color 0.3s ease, color 0.2s ease;
      user-select: none;
    }

    .app-wrapper {
      display: flex;
      height: 100vh;
      height: 100dvh;
      width: 100%;
      overflow: hidden;
      background-color: var(--bg-primary);
    }

    /* Sidebar Styles */
    .sidebar {
      width: 320px;
      min-width: 320px;
      background-color: var(--bg-secondary);
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      transition: background-color 0.3s ease, border-color 0.3s ease, transform 0.3s ease;
      z-index: 150;
      will-change: transform;
      backface-visibility: hidden;
      -webkit-backface-visibility: hidden;
    }

    /* Suppress the open/close slide transition on initial page load so the
       sidebar doesn't visibly slide/jump into place on refresh (mobile). */
    .sidebar.no-anim {
      transition: none !important;
    }
    
    .sidebar-header {
      padding: 12px 16px;
      height: 61px; /* Match header height */
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      font-weight: 600;
      font-size: 20px;
      color: var(--text-header);
      box-sizing: border-box;
      flex-shrink: 0;
    }

    .sidebar-header-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: auto;
    }

    .sidebar-action-btn {
      width: 36px !important;
      height: 36px !important;
      min-width: 36px !important;
      max-width: 36px !important;
      padding: 0 !important;
      margin: 0 !important;
      border-radius: 50% !important;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      background: #1b74e4;
      color: #ffffff;
      border: none;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }

    .sidebar-action-btn:hover {
      background: #1669c1;
    }

    .sidebar-action-btn:active {
      transform: scale(0.94);
    }

    @media (min-width: 992px) {
      #closeSidebarBtn {
        display: none !important;
      }
    }
    
    .sidebar-search {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-color);
      background-color: var(--bg-secondary);
      flex-shrink: 0;
    }
    
    .sidebar-search input, .admin-search input {
      width: 100%;
      padding: 8px 12px 8px 36px;
      border-radius: 20px;
      border: 1px solid var(--border-input);
      background-color: var(--bg-input);
      color: var(--text-primary);
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      outline: none;
      box-sizing: border-box;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2365676b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: 10px center;
    }
    
    .sidebar-search input:focus, .admin-search input:focus {
      border-color: #1b74e4;
    }
    
    .sidebar-users {
      min-height: 292px;
      flex: 1;
      overflow-y: auto;
      background-color: var(--bg-secondary);
      /* Hide scrollbar but keep scroll functionality */
      scrollbar-width: none; /* Firefox */
      -ms-overflow-style: none; /* IE/Edge */
      -webkit-overflow-scrolling: touch; /* Smooth momentum scrolling on mobile iOS/Android */
      contain: content; /* Isolate layout & repaint boundaries for max scrolling performance */
    }

    .sidebar-users::-webkit-scrollbar {
      display: none; /* Chrome/Safari/Opera */
    }

    /* Admin conversations panel: takes up the same remaining space as the
       main user list (.sidebar-users), instead of being capped to a fixed
       height. It has its own scrollbar so it doesn't share the main user
       list's scroll position. */
    #adminConvsSection {
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }

    #adminConvsList {
      flex: 1;
      min-height: 0;
      overflow-y: auto;
      /* Hide scrollbar but keep scroll functionality */
      scrollbar-width: none; /* Firefox */
      -ms-overflow-style: none; /* IE/Edge */
    }
    
    #adminConvsList::-webkit-scrollbar {
      display: none; /* Chrome/Safari/Opera */
    }
    
    .user-item {
      display: flex;
      align-items: center;
      padding: 12px 16px;
      cursor: pointer;
      border-bottom: 1px solid var(--border-color);
      transition: background-color 0.2s ease;
    }
    
    .user-item:hover {
      background-color: var(--bg-drop-overlay);
    }

    .user-item.active {
      background-color: rgba(27, 116, 228, 0.13) !important;
    }
    
    .user-avatar {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background-color: var(--bg-avatar-sent);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 18px;
      margin-right: 12px;
      text-transform: uppercase;
      flex-shrink: 0;   /* Never shrink */
      min-width: 48px;  /* Never shrink */
      position: relative;
    }
    
    .status-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      position: absolute;
      bottom: 0;
      right: 0;
      border: 2px solid var(--bg-secondary);
    }
    .status-dot.online { background-color: #31a24c; }
    .status-dot.offline { background-color: #8a8d91; }
    
    .user-info {
      flex: 1;
      min-width: 0;
    }
    
    .user-name {
      font-weight: 600;
      color: var(--text-primary);
      font-size: 15px;
      margin-bottom: 4px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .user-office {
      font-size: 11px;
      color: #1b74e4;
      font-weight: 500;
      margin-bottom: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .user-last-msg {
      font-size: 13px;
      color: var(--text-secondary);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Unread message badge in sidebar */
    .user-unread-badge {
      background: #1b74e4;
      color: #fff;
      border-radius: 12px;
      min-width: 20px;
      height: 20px;
      padding: 0 6px;
      font-size: 11px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      margin-left: 0;
      animation: badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1);
    }
    @keyframes badgePop {
      from { transform: scale(0); opacity: 0; }
      to   { transform: scale(1); opacity: 1; }
    }

    .user-actions-right {
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: flex-end;
      gap: 4px;
      flex-shrink: 0;
      margin-left: 6px;
    }

    /* Bold name/preview when there are unreads */
    .user-item.has-unread .user-name {
      color: var(--text-primary);
      font-weight: 700;
    }
    .user-item.has-unread .user-last-msg {
      color: var(--text-primary);
      font-weight: 500;
    }

    /* Notify button shown on each user-item (always visible/steady) */
    .notify-btn {
      flex-shrink: 0;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      border: none;
      background: transparent;
      color: var(--icon-color);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      margin-left: 4px;
      opacity: 0.75;
      transition: background-color 0.15s ease, color 0.15s ease, opacity 0.15s ease;
    }
    .user-item:hover .notify-btn {
      opacity: 1;
    }
    @media (hover: none) {
      /* Touch devices have no hover — keep the button reachable */
      .notify-btn { opacity: 0.75; }
    }
    .notify-btn:hover {
      background-color: var(--button-hover);
      color: #1b74e4;
    }
    .notify-btn svg { width: 17px; height: 17px; display: block; }

    /* Notify modal specifics */
    .notify-target {
      font-weight: 600;
      color: var(--text-primary);
    }
    .notify-textarea {
      width: 100%;
      margin-top: 10px;
      resize: none;
      border: 1px solid var(--border-input);
      background: var(--bg-input);
      color: var(--text-primary);
      border-radius: 8px;
      padding: 10px 12px;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      line-height: 1.4;
    }
    .notify-textarea:focus { outline: none; border-color: #1b74e4; }
    .notify-char-count {
      text-align: right;
      font-size: 11px;
      color: var(--text-secondary);
      margin-top: 4px;
    }
    .notify-char-count.limit-reached { color: #c0392b; }

    /* Incoming notification toasts */
    .notify-toast-container {
      position: fixed;
      top: 16px;
      right: 16px;
      left: auto;
      z-index: 3000;
      display: flex;
      flex-direction: column;
      gap: 10px;
      width: 320px;
      max-width: calc(100vw - 32px);
      pointer-events: none;
    }
    .notify-toast {
      background: var(--bg-modal);
      color: var(--text-primary);
      border-radius: 10px;
      box-shadow: 0 4px 16px var(--shadow-color);
      padding: 12px 14px;
      font-size: 13px;
      line-height: 1.4;
      animation: notifyToastIn 0.25s ease;
      cursor: pointer;
      pointer-events: auto;
      max-width: 100%;
      box-sizing: border-box;
      overflow-wrap: anywhere;
      word-break: break-word;
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
    }
    .notify-toast.hide {
      animation: notifyToastOut 0.2s ease forwards;
    }
    @keyframes notifyToastIn {
      from { transform: translateX(30px); opacity: 0; }
      to   { transform: translateX(0); opacity: 1; }
    }
    @keyframes notifyToastOut {
      from { transform: translateX(0); opacity: 1; }
      to   { transform: translateX(30px); opacity: 0; }
    }
    .notify-toast strong { color: var(--text-primary); }

    @media (max-width: 480px) {
      .notify-toast-container {
        top: 10px;
        right: 10px;
        left: 10px;
        width: auto;
        max-width: none;
      }
    }

    /* Modal shown when a notification toast is clicked */
    #notifyContentModal .modal-body {
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      word-break: break-word;
      max-height: 50vh;
      overflow-y: auto;
    }

    .app-container {
      display: flex;
      flex-direction: column;
      height: 100vh;
      height: 100dvh;
      flex: 1;
      min-width: 0; /* Important for flex child to not overflow */
      background-color: var(--bg-secondary);
      position: relative;
      transition: background-color 0.3s ease;
    }

    /* Header Styles */
    .header {
      padding: 12px 16px;
      background: var(--bg-header);
      user-select: none;    
      color: var(--text-primary);
      text-align: left;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      position: sticky;
      top: 0;
      z-index: 100;
      border-bottom: 1px solid var(--border-color);
      font-size: 15px;
      user-select: none;
      box-shadow: 0 1px 2px var(--shadow-color);
      transition: background-color 0.3s ease, border-color 0.3s ease;
    }

    .header h1 {
      font-size: 18px;
      font-weight: 400;
      margin: 0;
      display: inline-block;
      vertical-align: middle;
      color: var(--text-header);
      min-width: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }

    .header h1 svg {
      vertical-align: -2px;
      margin-right: 4px;
    }

    .header-left {
      display: flex;
      align-items: center;
      min-width: 0;
      flex: 1 1 auto;
      overflow: hidden;
    }

    .header-logo {
      height: 28px;
      width: 28px;
      margin-right: 10px;
      vertical-align: middle;
      display: inline-block;
      flex-shrink: 0;
      /* Prevent dragging the logo out (e.g. to open cspc.webp directly in a
         new tab) and prevent it from being selected like text/an image. */
      -webkit-user-drag: none;
      -khtml-user-drag: none;
      -moz-user-drag: none;
      -o-user-drag: none;
      user-drag: none;
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
      /* iOS Safari: block the long-press callout menu (Save Image / Open in
         New Tab / Copy) that would otherwise appear even with the above rules. */
      -webkit-touch-callout: none;
    }

    .header-buttons {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-shrink: 0;
    }

    .clear-button, .darkmode-button {
      background: #1b74e4;
      color: #ffffff;
      border: none;
      padding: 0 16px;
      height: 36px;
      border-radius: 24px;
      font-size: 13px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.2s ease;
      font-family: 'Inter', sans-serif;
      min-width: 70px;
      box-sizing: border-box;
      text-align: center;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      user-select: none;
      flex-shrink: 0;
      white-space: nowrap;
    }

    .darkmode-button {
      min-width: auto;
      padding: 0 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
    }

    .darkmode-button svg {
      width: 16px;
      height: 16px;
      fill: currentColor;
      transition: transform 0.3s ease;
    }

    [data-theme="dark"] .darkmode-button .sun-icon,
    html[data-theme="dark"] .darkmode-button .sun-icon {
      display: inline-block;
    }

    [data-theme="dark"] .darkmode-button .moon-icon,
    html[data-theme="dark"] .darkmode-button .moon-icon {
      display: none;
    }

    .darkmode-button .sun-icon {
      display: none;
    }

    .darkmode-button .moon-icon {
      display: inline-block;
    }

    .clear-button:hover, .darkmode-button:hover {
      background: #1669c1;
    }

    .clear-button:active, .darkmode-button:active {
      transform: scale(0.97);
    }

    /* Burger menu button: plain lines, no circle/pill container.
       Uses --text-primary so it flips to white automatically in dark mode
       (was hardcoded #000000 before, which never adapted). */
    #burgerButton {
      background: transparent;
      color: var(--text-primary);
      border-radius: 0;
      min-width: auto;
      padding: 0;
    }

    #burgerButton:hover {
      background: transparent;
    }

    #burgerButton:active {
      background: transparent;
      transform: scale(0.92);
    }

    #adminEyeToggleBtn.active {
      background: #ff0000;
    }

    /* Chat Container */
    #chat-box {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 16px 12px 20px 12px;
      background: var(--bg-chat);
      -webkit-overflow-scrolling: touch;
      overscroll-behavior-y: contain;
      position: relative;
      scroll-behavior: auto;
      transition: background-color 0.3s ease;
      will-change: scroll-position;
    }

    /* Desktop: always show scrollbar so users know there's more content */
    @media (min-width: 992px) {
      #chat-box {
        overflow-y: scroll;
      }
    }

    /* Message Container */
    .message-container {
      margin-bottom: 8px;
      display: flex;
      align-items: flex-end;
      flex-wrap: nowrap; /* Keep avatar + bubble on same row at all times */
      font-family: 'Inter', sans-serif;
      will-change: opacity, transform;
      overflow: hidden;  /* Clip bubble if viewport is narrower than avatar + bubble */
    }

    /* Floating sending/upload overlay — positioned above the input area */
    #sending-overlay-container {
      position: absolute;
      right: 12px;
      bottom: calc(100% + 8px);
      display: flex;
      flex-direction: column;
      gap: 8px;
      z-index: 180;
      pointer-events: none;
    }
    #sending-overlay-container .message-container {
      pointer-events: auto;
    }

    /* New message animation — only applied to freshly added messages */
    .message-container.msg-animate-sent {
      animation: msgPopSent 0.28s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    .message-container.msg-animate-received {
      animation: msgPopReceived 0.28s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    /* Global Chat staggered incoming — element is hidden until setTimeout fires */
    .message-container.gc-msg-pending {
      opacity: 0;
      pointer-events: none;
    }

    @keyframes msgPopSent {
      from {
        opacity: 0;
        transform: translateX(18px) scale(0.92);
      }
      to {
        opacity: 1;
        transform: translateX(0) scale(1);
      }
    }

    @keyframes msgPopReceived {
      from {
        opacity: 0;
        transform: translateX(-18px) scale(0.92);
      }
      to {
        opacity: 1;
        transform: translateX(0) scale(1);
      }
    }

    /* Optimistic "sending" bubble — snappy entrance, then a gentle
       breathing pulse for as long as it stays pending. */
    .message-bubble.sending-bubble {
      animation:
        sendingBubbleIn 0.16s cubic-bezier(0.34, 1.56, 0.64, 1) forwards,
        sendingPulse 1.3s ease-in-out 0.16s infinite;
    }

    @keyframes sendingBubbleIn {
      0%   { opacity: 0;   transform: scale(0.82) translateY(4px); }
      100% { opacity: 0.7; transform: scale(1) translateY(0); }
    }

    @keyframes sendingPulse {
      0%, 100% { opacity: 0.55; }
      50%      { opacity: 0.9; }
    }

    /* Three bouncing dots shown inside the bubble while a message is sending */
    .sending-dots {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 2px;
    }

    .sending-dots span {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background-color: rgba(255, 255, 255, 0.9);
      animation: sendingDotBounce 1.1s infinite ease-in-out both;
    }

    .sending-dots span:nth-child(1) { animation-delay: 0s; }
    .sending-dots span:nth-child(2) { animation-delay: .15s; }
    .sending-dots span:nth-child(3) { animation-delay: .3s; }

    @keyframes sendingDotBounce {
      0%, 80%, 100% { transform: translateY(0) scale(0.8); opacity: 0.5; }
      40%           { transform: translateY(-5px) scale(1); opacity: 1; }
    }

    .message-container.sent {
      justify-content: flex-end;
    }

    .message-container.received {
      justify-content: flex-start;
    }

    /* Avatar Styles */
    .message-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 13px;
      margin: 0 8px;
      color: white;
      min-width: 32px;  /* Never shrink */
      flex-shrink: 0;   /* Never shrink */
      text-transform: uppercase;
      transition: background-color 0.3s ease;
    }

    .received .message-avatar {
      background-color: var(--bg-avatar-received);
      color: var(--text-primary);
    }

    .sent .message-avatar {
      background-color: var(--bg-avatar-sent);
      color: white;
    }

    /* Message Bubble */
    .message-bubble {
      max-width: 100%; /* Bubble takes full space of its wrapper */
      padding: 8px 12px;
      border-radius: 18px;
      position: relative;
      word-wrap: break-word;
      box-shadow: 0 1px 2px var(--shadow-color);
      transition: background-color 0.3s ease, color 0.3s ease;
      will-change: transform;
      -webkit-user-select: none;
      -moz-user-select: none;
      user-select: none;
      -webkit-touch-callout: none;
    }

    .sent .message-bubble {
      background: var(--bg-bubble-sent);
      color: var(--text-bubble-sent);
      border-bottom-right-radius: 4px;
    }

    .received .message-bubble {
      background: var(--bg-bubble-received);
      color: var(--text-bubble-received);
      border-bottom-left-radius: 4px;
      order: 1;
    }

    /* Emoji-only messages — render as bare big emoji, no bubble background */
    .message-bubble.emoji-only {
      background: transparent !important;
      box-shadow: none !important;
      padding: 2px 0 !important;
      border-radius: 0 !important;
    }
    .message-bubble.emoji-only .message-content {
      font-size: 36px !important;
      line-height: 1.2 !important;
    }
    .message-bubble.emoji-only .message-info {
      display: none !important;
    }

    /* Media (image / audio) — no bubble background */
    .message-media {
      max-width: 100%;
      -webkit-user-select: none;
      -moz-user-select: none;
      user-select: none;
      -webkit-touch-callout: none;
    }

    .message-media audio {
      border-radius: 8px;
    }

    /* ── Media grid (multi-image send) ───────────────────────────
       No forced squares, no aggressive cropping: each image keeps
       its natural aspect ratio. Columns still come from data-count
       so the layout reads as a grid, but row height is driven by
       the image itself (capped by max-height) instead of a fixed
       1:1 box, so portrait stays portrait and landscape stays
       landscape. */
    .message-media-grid {
      display: grid;
      gap: 4px;
      border-radius: 12px;
      max-width: 320px;
      align-items: start;
    }
    .message-media-grid[data-count="2"]  { grid-template-columns: 1fr 1fr; }
    .message-media-grid[data-count="3"]  { grid-template-columns: 1fr 1fr 1fr; }
    .message-media-grid[data-count="4"]  { grid-template-columns: 1fr 1fr; }
    /* 5+ photos: first spans full width, rest fill 3-col row */
    .message-media-grid[data-count="5"]  { grid-template-columns: 1fr 1fr 1fr; }
    .message-media-grid[data-count="5"]  .media-grid-item:first-child { grid-column: span 3; }
    .message-media-grid:not([data-count]) { grid-template-columns: 1fr 1fr 1fr; }
    .media-grid-item {
      display: block;
      overflow: hidden;
      border-radius: 10px;
      background: var(--bg-secondary, #e4e6eb);
    }
    .media-grid-item img {
      display: block;
      width: 100%;
      height: auto;         /* let the image keep its own ratio, no stretching/cropping */
      max-height: 360px;     /* keeps a very tall portrait from dominating the bubble */
      object-fit: contain;   /* only matters if max-height caps it; never distorts */
      border-radius: 10px;
      transition: transform 0.2s ease;
    }
    .media-grid-item:hover img { transform: scale(1.02); }

    /* ── Attachment / paperclip button ───────────────────────── */
    .attachment-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: transparent;
      color: var(--text-secondary);
      cursor: pointer;
      flex-shrink: 0;
      transition: background 0.18s, color 0.18s, transform 0.15s;
    }
    .attachment-btn:hover {
      background: var(--bg-hover);
      color: #1b74e4;
      transform: rotate(-15deg) scale(1.08);
    }

    /* ── Drop overlay active state ─────────────────────────────── */
    .drop-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      font-weight: 600;
      letter-spacing: 0.02em;
      color: #1b74e4;
      background-color: var(--bg-drop-overlay);
      border: 2px dashed #1b74e4;
      border-radius: 16px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.18s;
      z-index: 200;
    }
    .drop-overlay.visible {
      opacity: 1;
      pointer-events: auto;
    }

    /* Message Content */
    .message-content {
      font-size: 14px;
      line-height: 1.4;
      margin-bottom: 2px;
      word-break: break-word;
    }

    .sent .message-content {
      color: var(--text-bubble-sent);
    }

    .received .message-content {
      color: var(--text-bubble-received);
    }

    /* Clickable links auto-detected inside message text */
    .message-content a.chat-link {
      text-decoration: underline;
      word-break: break-all;
    }

    .sent .message-content a.chat-link {
      color: var(--text-bubble-sent);
    }

    .received .message-content a.chat-link {
      color: var(--text-header);
    }

    .message-info {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 2px;
      font-size: 11px;
    }

    .message-sender {
      font-weight: 500;
    }

    .sent .message-sender {
      color: var(--text-sender-sent);
    }

    .received .message-sender {
      color: var(--text-sender-received);
    }

    .message-time {
      color: var(--text-time-received);
    }

    .sent .message-time {
      color: var(--text-time-sent);
    }

    .received .message-time {
      color: var(--text-time-received);
    }

    /* Image/audio messages (.message-media) have NO bubble background behind
       the sender/time row — unlike text bubbles, which are always blue for
       "sent" so white text always makes sense there. Without this override,
       a sent image's info row inherits the white-on-blue-bubble color and
       ends up white text sitting directly on the page background, which is
       invisible in light mode. Force theme-aware neutral color instead. */
    .message-media .message-sender,
    .message-media .message-time {
      color: var(--text-secondary);
    }

    /* Clickable Date/Time smoothly appearing above bubbles */
    .message-click-timestamp {
      max-height: 0;
      opacity: 0;
      overflow: hidden;
      font-size: 11px;
      color: var(--text-secondary);
      transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease, margin 0.3s ease;
      margin-bottom: 0;
      margin-top: 0;
      user-select: none;
      pointer-events: none;
      white-space: nowrap;
    }
    .sent .message-click-timestamp {
      align-self: flex-end;
      text-align: right;
      padding-right: 12px;
    }
    .received .message-click-timestamp {
      align-self: flex-start;
      text-align: left;
      padding-left: 12px;
    }
    .message-click-timestamp.show-timestamp {
      max-height: 25px;
      opacity: 0.85;
      margin-top: 4px;
      margin-bottom: 4px;
    }

    /* Empty Chat State */
    .empty-chat {
      text-align: center;
      color: var(--text-secondary);
      padding: 60px 20px;
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 80%;
    }

    .empty-chat .chat-icon {
      width: 60px;
      height: 60px;
      margin: 0 auto 20px;
      background: var(--bg-avatar-received);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .empty-chat svg {
      width: 30px;
      height: 30px;
      fill: var(--text-secondary);
    }

    .empty-chat h3 {
      font-size: 18px;
      font-weight: 600;
      margin-bottom: 8px;
      color: var(--text-primary);
    }

    .empty-chat p {
      font-size: 14px;
      line-height: 1.5;
      color: var(--text-secondary);
    }

    /* Message Input Area */
    .input-area {
      background-color: var(--bg-secondary);
      padding: 12px 16px;
      /* safe-area-inset-bottom for iPhone notch/home bar */
      padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
      border-top: 1px solid var(--border-color);
      /* Never sticky — flexbox keeps it at bottom naturally.
         sticky + virtual keyboard = input getting buried on Android. */
      position: relative;
      width: 100%;
      z-index: 100;
      transition: background-color 0.3s ease, border-color 0.3s ease;
      /* Critical: never shrink, never grow — fixed height at bottom of flex column */
      flex-shrink: 0;
      flex-grow: 0;
      min-height: 70px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .input-section {
      margin-bottom: 8px;
      position: relative;
    }

    .spy-mode-notice {
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      gap: 10px;
      width: 90%;
      max-width: 460px;
      min-width: 260px;
      min-height: 48px;
      flex-shrink: 0;
      padding: 12px 18px;
      background: var(--bg-modal, var(--bg-secondary));
      color: var(--text-secondary);
      font-size: 13px;
      font-weight: 600;
      border-radius: 12px;
      border: 1px dashed var(--border-color);
      box-shadow: 0 2px 8px var(--shadow-color, rgba(0, 0, 0, 0.08));
      margin: 6px auto;
      box-sizing: border-box;
      user-select: none;
      transition: all 0.3s ease;
    }

    @media (max-width: 480px) {
      .spy-mode-notice {
        width: 95%;
        min-width: 220px;
        padding: 10px 14px;
        font-size: 12px;
        gap: 6px;
      }
    }

    #cancelEditXBtn {
      display: none;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--text-secondary);
      border: none;
      cursor: pointer;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      opacity: 0.7;
      transition: opacity 0.15s, background 0.15s;
      padding: 0;
    }
    #cancelEditXBtn:hover {
      opacity: 1;
      background: #e53935;
    }
    #cancelEditXBtn svg {
      display: block;
    }

    .input-label {
      font-size: 12px;
      color: var(--text-secondary);
      margin-bottom: 4px;
      font-weight: 500;
      display: block;
    }

    .name-input-wrapper {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .name-field-inner {
      position: relative;
      display: flex;
      align-items: center;
      flex: 1;
      min-width: 0;
    }

    /* By default this just shrink-wraps the attach button (same footprint
       as before). Only while editing (.editing) do we pin its width to
       match #sendButton via JS, so the name field lines up with the
       message field specifically when the cancel-edit X button is showing. */
    .input-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
      flex-shrink: 0;
    }

    #nameInput {
      width: 100%;
      padding: 8px 12px 8px 36px;
      border-radius: 20px;
      border: 1px solid var(--border-input);
      background-color: var(--bg-input);
      color: var(--text-primary);
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      transition: all 0.2s ease;
      user-select: none;
    }

    .name-icon {
      position: absolute;
      left: 12px;
      width: 16px;
      height: 16px;
      fill: var(--icon-color);
      z-index: 10;
      transition: fill 0.3s ease;
    }

    .message-input-container {
      display: flex;
      align-items: center;
      gap: 8px;
      position: relative;
    }

    #messageInput {
      flex: 1;
      padding: 10px 16px;
      border-radius: 18px;
      border: 1px solid var(--border-input);
      background-color: var(--bg-input);
      color: var(--text-primary);
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      transition: border-color 0.2s ease, background-color 0.2s ease;
      resize: none;
      overflow-y: scroll;
      min-height: 40px;
      max-height: 120px;
      line-height: 1.4;
      display: block;
      /* Hide scrollbar — webkit */
      scrollbar-width: none;
    }

    #messageInput::-webkit-scrollbar {
      width: 0;
      background: transparent;
    }

    input:focus, textarea:focus {
      outline: none;
      border-color: #1b74e4;
      background-color: var(--bg-secondary);
    }

    input::placeholder, textarea::placeholder {
      color: var(--text-secondary);
    }

    #sendButton {
      width: auto;
      min-width: 70px;
      height: 40px;
      border-radius: 24px;
      background: #1b74e4;
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      border: none;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      font-weight: 600;
      box-shadow: none;
      margin-left: 0;
      padding: 0 16px;
      text-align: center;
      user-select: none;
    }

    #sendButton:disabled {
      background: var(--bg-avatar-received);
      color: var(--text-secondary);
      cursor: not-allowed;
    }

    #sendButton:hover:not(:disabled) {
      background: #1669c1;
    }

    #sendButton:active:not(:disabled) {
      transform: scale(0.97);
    }

    /* Modal Styles */
    .modal {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.3s ease;
      user-select: none;
    }

    .modal.active {
      opacity: 1;
      visibility: visible;
    }

    .modal-content {
      background-color: var(--bg-modal);
      width: 90%;
      max-width: 400px;
      min-width: 280px; /* Prevent shrinking past useful bounds */
      min-height: 180px; /* Maintain height on shrink test */
      flex-shrink: 0; /* Strict requirement: don't shrink during test */
      border-radius: 12px;
      overflow: hidden;
      animation: modalSlide 0.3s ease;
      box-shadow: 0 4px 12px var(--shadow-color);
      transition: background-color 0.3s ease;
    }

    @keyframes modalSlide {
      from { transform: translateY(-30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
      padding: 16px 20px;
      text-align: left;
      border-bottom: 1px solid var(--border-color);
    }

    .modal-header h3 {
      font-size: 18px;
      font-weight: 600;
      margin: 0;
      color: var(--text-primary);
    }

    .modal-body {
      padding: 16px 20px;
      text-align: left;
      color: var(--text-secondary);
      font-size: 14px;
      line-height: 1.5;
    }

    .modal-footer {
      display: flex;
      border-top: 1px solid var(--border-color);
    }

    .modal-button {
      flex: 1;
      padding: 12px;
      text-align: center;
      font-size: 14px;
      font-weight: 600;
      background: transparent;
      border: none;
      cursor: pointer;
      transition: background-color 0.2s ease;
      font-family: 'Inter', sans-serif;
      color: var(--text-primary);
    }

    .cancel-button {
      border-right: 1px solid var(--border-color);
    }

    .cancel-button:hover {
      background-color: var(--button-hover);
    }

    .confirm-button {
      color: #1b74e4;
    }

    .confirm-button:hover {
      background-color: rgba(27, 116, 228, 0.1);
    }

    .confirm-button:disabled {
      color: var(--text-secondary);
      cursor: not-allowed;
    }

    /* Secret key input inside modal — fully theme-aware */
    .secret-key-input {
      width: 100%;
      margin-top: 5px;
      padding: 8px 12px;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      border-radius: 8px;
      border: 1px solid var(--border-input);
      background-color: var(--bg-input);
      color: var(--text-primary);
      box-sizing: border-box;
      transition: border-color 0.2s ease, background-color 0.2s ease;
      -webkit-appearance: none;
      appearance: none;
    }

    .secret-key-input:focus {
      outline: none;
      border-color: #1b74e4;
      background-color: var(--bg-secondary);
    }

    .secret-key-input::placeholder {
      color: var(--text-secondary);
    }

    /* Scroll indicator */
    .scroll-indicator {
      position: absolute;
      bottom: calc(100% + 8px);
      right: 16px;
      background: #1b74e4;
      color: white;
      padding: 8px 14px;
      border-radius: 24px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.2s ease, background 0.2s ease;
      z-index: 200;
      box-shadow: 0 2px 10px rgba(0,0,0,0.25);
      display: flex;
      align-items: center;
      gap: 6px;
      user-select: none;
      white-space: nowrap;
      will-change: opacity, transform;
    }

    .scroll-indicator.visible {
      opacity: 1;
      visibility: visible;
    }

    .scroll-indicator:hover {
      background: #1669c1;
      transform: translateY(-2px);
      box-shadow: 0 4px 14px rgba(0,0,0,0.3);
    }

    .scroll-indicator:active {
      transform: scale(0.96);
    }

    /* Floating Load Older Messages button at top of chat container */
    .load-older-floating-btn {
      position: absolute;
      top: 80px; /* Floating below header with breathing room */
      left: 50%;
      transform: translateX(-50%);
      background: #1b74e4;
      color: white;
      padding: 8px 14px;
      border-radius: 24px;
      font-size: 13px;
      font-weight: 500;
      border: none;
      cursor: pointer;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.2s ease, background 0.2s ease;
      z-index: 200;
      box-shadow: 0 2px 10px rgba(0,0,0,0.25);
      display: flex;
      align-items: center;
      gap: 6px;
      user-select: none;
      white-space: nowrap;
      will-change: opacity, transform;
    }

    .load-older-floating-btn.visible {
      opacity: 1;
      visibility: visible;
    }

    .load-older-floating-btn:hover {
      background: #1669c1;
      transform: translateX(-50%) translateY(-2px);
      box-shadow: 0 4px 14px rgba(0,0,0,0.3);
    }

    .load-older-floating-btn:active {
      transform: translateX(-50%) scale(0.96);
    }

    /* Floating Load More button for Super Admin Spy Mode user cards */
    .admin-spy-floating-btn {
      position: absolute;
      bottom: 16px;
      left: 50%;
      transform: translateX(-50%);
      background: #1b74e4;
      color: white;
      padding: 8px 16px;
      border-radius: 24px;
      font-size: 13px;
      font-weight: 500;
      border: none;
      cursor: pointer;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.2s ease, background 0.2s ease;
      z-index: 50;
      box-shadow: 0 4px 12px rgba(0,0,0,0.25);
      display: flex;
      align-items: center;
      gap: 6px;
      user-select: none;
      white-space: nowrap;
    }

    .admin-spy-floating-btn.visible {
      opacity: 1;
      visibility: visible;
    }

    .admin-spy-floating-btn:hover {
      background: #1669c1;
      transform: translateX(-50%) translateY(-2px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.3);
    }

    .admin-spy-floating-btn:active {
      transform: translateX(-50%) scale(0.96);
    }

    .unread-badge {
      background: #ff3b30;
      color: white;
      border-radius: 12px;
      padding: 1px 7px;
      font-size: 11px;
      font-weight: 700;
      display: none;
    }

    .scroll-indicator.has-unread .unread-badge {
      display: inline-block;
    }

    /* Desktop specific styles */
    @media (min-width: 992px) {
      .app-wrapper {
        max-width: 1000px;
        height: 90vh;
        margin: 5vh auto;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px var(--shadow-color);
      }

      .app-container {
        height: 100%;
        margin: 0;
        border-radius: 0;
        box-shadow: none;
      }

      .header {
        border-radius: 0 12px 0 0;
      }


      .input-area {
        border-radius: 0 0 12px 12px;
      }

      #chat-box {
        overflow: hidden;
        overflow-y: auto;
      }
    }

    /* Mobile / Tablet specific styles */
    @media (max-width: 991px) {
      html, body {
        height: 100%;
        height: 100dvh;
        overflow: hidden;
        /* iOS Safari: prevent body scroll which can hide the header */
        position: fixed;
        width: 100%;
      }

      .app-wrapper {
        position: relative;
        width: 100vw;
        height: 100%;
        height: 100dvh;
      }

      .sidebar-backdrop {
        display: none;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.4);
        backdrop-filter: blur(2px);
        z-index: 190;
        opacity: 0;
        transition: opacity 0.25s ease;
      }

      .sidebar-backdrop.visible {
        display: block;
        opacity: 1;
      }

      .sidebar {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 320px;
        max-width: 100%;
        transform: translate3d(-100%, 0, 0);
        z-index: 200;
        box-shadow: 4px 0 24px rgba(0,0,0,0.15);
        will-change: transform;
      }

      @media (max-width: 480px) {
        .sidebar {
          width: 100%;
        }
      }

      .sidebar.open {
        transform: translate3d(0, 0, 0);
      }

      .app-container {
        height: 100%;
        height: 100dvh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        justify-content: flex-start;
        width: 100%;
      }


      /* Header must always stay at top, never pushed off-screen by keyboard.
         Size (padding/logo/title/buttons) intentionally stays the same as
         desktop — only the top offset gets the safe-area inset added. */
      .header {
        flex-shrink: 0;
        position: relative;
        top: auto;
        z-index: 100;
        padding-top: max(12px, env(safe-area-inset-top, 0px));
      }

      .header-buttons {
        flex-shrink: 0;
      }

      #chat-box {
        padding: 12px 8px 16px 8px;
        touch-action: pan-y;
        flex: 1 1 0;
        min-height: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-y: contain;
      }

      .message-bubble {
        max-width: 100%;
      }

      /* iOS safe area support (notch / home bar) */
      .input-area {
        flex-shrink: 0;
        padding-bottom: max(12px, env(safe-area-inset-bottom, 0px));
      }
    }

    /* Scrollbar Styling */
    /* ── Scrollbar — always visible, styled ── */
    #chat-box::-webkit-scrollbar {
      width: 6px;
    }

    #chat-box::-webkit-scrollbar-track {
      background: transparent;
      margin: 6px 0;
    }

    #chat-box::-webkit-scrollbar-thumb {
      background: var(--scrollbar-thumb);
      border-radius: 99px;
      min-height: 40px;
    }

    #chat-box::-webkit-scrollbar-thumb:hover {
      background: var(--scrollbar-thumb-hover);
    }

    #chat-box::-webkit-scrollbar-thumb:active {
      background: #1b74e4;
    }

    /* For Firefox */
    #chat-box {
      scrollbar-width: thin;
      scrollbar-color: var(--scrollbar-thumb) transparent;
    }

    /* Drop overlay */
    .drop-overlay {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.15s ease, background-color 0.15s ease;
      z-index: 40;
      font-family: 'Inter', sans-serif;
      font-size: 16px;
      font-weight: 500;
      color: #1b74e4;
      background-color: var(--bg-drop-overlay);
      border: 2px dashed #1b74e4;
      border-radius: 8px;
      margin: 8px;
    }

    .drop-overlay.visible {
      pointer-events: auto;
      opacity: 1;
    }

    /* ── Emoji Reaction Picker ── */
    .reaction-picker {
      position: fixed;
      z-index: 9999;
      display: flex;
      align-items: center;
      gap: 2px;
      background: var(--bg-secondary);
      border-radius: 999px;
      padding: 6px 10px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.22);
      border: 1px solid var(--border-color);
      opacity: 0;
      transform: scale(0.7) translateY(6px);
      pointer-events: none;
      transition: opacity 0.15s ease, transform 0.15s cubic-bezier(0.34,1.56,0.64,1);
      user-select: none;
      /* Mobile: never overflow the screen */
      max-width: calc(100vw - 16px);
      box-sizing: border-box;
      flex-wrap: nowrap;
    }
    .reaction-picker.visible {
      opacity: 1;
      transform: scale(1) translateY(0);
      pointer-events: auto;
    }
    .reaction-picker-btn {
      font-size: 24px;
      line-height: 1;
      cursor: pointer;
      transition: transform 0.15s cubic-bezier(0.34,1.56,0.64,1);
      border: none;
      background: transparent;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .reaction-picker-btn:hover,
    .reaction-picker-btn:active {
      transform: scale(1.35);
    }
    /* On very small screens, shrink emoji buttons a bit (header size stays constant) */
    @media (max-width: 360px) {
      .reaction-picker-btn {
        font-size: 20px;
        width: 32px;
        height: 32px;
      }
      .reaction-picker {
        padding: 4px 8px;
        gap: 0px;
      }
    }

    /* ── Reaction Badges on messages ── */
    /* Reactions sit BELOW the bubble, inside bubble-wrapper */
    .msg-reactions {
      display: flex;
      flex-direction: row;
      flex-wrap: wrap;
      gap: 2px;
      margin-top: 3px;
      padding: 0 2px;
      /* Reset any positioning from old layout */
      order: unset;
      align-self: unset;
      flex-shrink: unset;
      padding-bottom: unset;
    }
    /* sent bubbles: reactions align to the right */
    .sent .msg-reactions {
      justify-content: flex-end;
      margin-right: 0;
      order: unset;
    }
    /* received bubbles: reactions align to the left */
    .received .msg-reactions {
      justify-content: flex-start;
      margin-left: 0;
      order: unset;
    }
    /* If msg-reactions is a direct child of message-container (server-rendered),
       force it to break onto its own row below the bubble-wrapper */
    .message-container > .msg-reactions {
      width: 100%;
      order: 10;
    }
    .sent.message-container > .msg-reactions {
      justify-content: flex-end;
      padding-right: 44px; /* offset for avatar width */
    }
    .received.message-container > .msg-reactions {
      justify-content: flex-start;
      padding-left: 44px; /* offset for avatar width */
    }
    /* Pure emoji badge — no background, no border, no pill */
    .reaction-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: none;
      border: none;
      border-radius: 0;
      padding: 0;
      font-size: 18px;
      line-height: 1;
      cursor: pointer;
      transition: transform 0.15s cubic-bezier(0.34,1.56,0.64,1);
      box-shadow: none;
      user-select: none;
      /* hide the count number — emoji only */
    }
    .reaction-badge:hover {
      transform: scale(1.3);
    }
    .reaction-badge:active {
      transform: scale(0.85);
    }
    /* hide the count text node — show only emoji */
    .reaction-badge::after {
      display: none;
    }

    /* Long-press visual feedback — subtle highlight only, no scale/movement */
    .message-bubble.longpress-active,
    .message-media.longpress-active {
      filter: brightness(0.92);
      transition: filter 0.1s ease;
    }

    /* ── Hover Reaction Bar (desktop only) ── */
    /* Wrapper to hold bubble + hover bar together */
    .bubble-wrapper {
      position: relative;
      display: inline-flex;
      flex-direction: column;
      flex: 1;                       /* Fill all remaining space after avatar */
      min-width: 0;                  /* Allow shrinking below content size */
      max-width: min(75%, 480px);    /* Cap at 75% of container, max 480px on wide screens */
    }
    .sent .bubble-wrapper {
      align-items: flex-end;
      order: 0;
    }
    .received .bubble-wrapper {
      align-items: flex-start;
      order: 1;
    }

    /* Hover reaction bar is hidden. Reactions are strictly right-click. */
    .hover-reaction-bar {
      display: none !important;
    }

    .hover-reaction-bar .hrb-btn {
      font-size: 22px;
      line-height: 1;
      cursor: pointer;
      border: none;
      background: transparent;
      padding: 0;
      width: 34px;
      height: 34px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      transition: transform 0.13s cubic-bezier(0.34,1.56,0.64,1);
      flex-shrink: 0;
    }
    .hover-reaction-bar .hrb-btn:hover {
      transform: scale(1.35);
    }
    .hover-reaction-bar .hrb-btn:active {
      transform: scale(0.9);
    }

    /* Copy button inside hover bar */
    .hover-reaction-bar .hrb-copy {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-secondary);
      cursor: pointer;
      border: none;
      background: transparent;
      padding: 0 6px;
      height: 34px;
      display: flex;
      align-items: center;
      border-radius: 8px;
      transition: color 0.12s, background 0.12s;
      flex-shrink: 0;
      white-space: nowrap;
      font-family: 'Inter', sans-serif;
    }
    .hover-reaction-bar .hrb-copy:hover {
      color: var(--text-primary);
      background: var(--bg-input);
    }

    /* Divider between emojis and copy */
    .hover-reaction-bar .hrb-divider {
      width: 1px;
      height: 20px;
      background: var(--border-color);
      margin: 0 4px;
      flex-shrink: 0;
    }

    /* ── Mobile long-press: Copy button inside the fixed reaction picker ── */
    .reaction-picker .rp-divider {
      width: 1px;
      height: 24px;
      background: var(--border-color);
      margin: 0 4px;
      flex-shrink: 0;
      align-self: center;
    }
    .reaction-picker .rp-copy {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-secondary);
      cursor: pointer;
      border: none;
      background: transparent;
      padding: 0 8px;
      height: 40px;
      display: flex;
      align-items: center;
      border-radius: 8px;
      transition: color 0.12s, background 0.12s;
      white-space: nowrap;
      font-family: 'Inter', sans-serif;
      flex-shrink: 0;
    }
    @media (max-width: 360px) {
      .reaction-picker .rp-copy {
        padding: 0 6px;
        font-size: 11px;
        height: 34px;
      }
      .reaction-picker .rp-divider {
        margin: 0 2px;
      }
    }
    .reaction-picker .rp-copy:hover,
    .reaction-picker .rp-copy:active {
      color: var(--text-primary);
      background: var(--bg-input);
    }

    /* ── Admin POV: verified badge inside name input ── */
    .name-input-wrapper.is-admin-user #nameInput {
      padding-right: 36px;
    }
    .admin-input-badge {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      display: none;
      align-items: center;
      gap: 4px;
      pointer-events: none;
      z-index: 11;
    }
    .name-input-wrapper.is-admin-user .admin-input-badge {
      display: flex;
    }
    .admin-input-badge svg {
      width: 15px;
      height: 15px;
      flex-shrink: 0;
    }
    .admin-input-badge span {
      font-size: 10px;
      font-weight: 700;
      color: #1b74e4;
      letter-spacing: 0.4px;
      text-transform: uppercase;
    }
    /* Dark mode: keep badge text color consistent */
    html[data-theme="dark"] .admin-input-badge span {
      color: #5ea8ff;
    }

    /* ── Verified (admin) badge on sender name ── */
    .verified-badge {
      display: inline-flex;
      align-items: center;
      margin-left: 3px;
      vertical-align: middle;
      flex-shrink: 0;
    }
    .verified-badge svg {
      width: 13px;
      height: 13px;
      display: block;
    }
    /* Tooltip on hover */
    .verified-badge {
      position: relative;
      cursor: default;
    }
    .verified-badge::after {
      content: 'Super Admin';
      position: absolute;
      bottom: calc(100% + 4px);
      left: 50%;
      transform: translateX(-50%);
      background: rgba(27, 116, 228, 0.92);
      color: #fff;
      font-size: 10px;
      font-weight: 600;
      padding: 2px 6px;
      border-radius: 6px;
      white-space: nowrap;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.15s ease;
      letter-spacing: 0.3px;
    }
    .verified-badge:hover::after {
      opacity: 1;
    }

    /* ── Reaction count next to emoji badge ── */
    .reaction-badge {
      display: inline-flex;
      align-items: center;
      gap: 3px;
    }
    .reaction-count {
      font-size: 11px;
      font-weight: 600;
      line-height: 1;
      min-width: 12px;
    }

    /* ── @mention highlight in messages ── */
    .mention-tag {
      color: #1b74e4;
      font-weight: 600;
      background: rgba(27, 116, 228, 0.1);
      border-radius: 3px;
      padding: 0 2px;
    }
    [data-theme="dark"] .mention-tag {
      color: #5ea8ff;
      background: rgba(94, 168, 255, 0.15);
    }

    .sent .message-avatar {
      order: 1;
    }
    .received .message-avatar {
      order: 0;
    }

    /* ── bubble-wrapper sizing/order now handled in the single definition above ── */

    /* ── Global Chat pinned item active state ── */
    #globalChatItem.active {
      background: var(--active-chat, rgba(27,116,228,0.10));
    }
    #globalChatItem:hover {
      background: var(--hover-bg, rgba(0,0,0,0.04));
      cursor: pointer;
    }
    #globalChatItem.active:hover {
      background: var(--active-chat, rgba(27,116,228,0.14));
    }

    /* Load Older Messages button hover */
    #loadOlderBtn:hover {
      background: var(--hover-bg, rgba(0,0,0,0.06)) !important;
    }

    /* Chat message line styles for load.php output */
    .message-line {
      margin-bottom: 2px;
      line-height: 1.5;
    }

    .combined-chat {
      display: flex;
      flex-direction: column;
    }

    /* Typing Indicator Styles */
    /* Reserves its own space via a fixed height + visibility toggle
       (not display:none/flex), so it never shoves the input below it. */
    .typing-indicator-container {
      visibility: hidden;
      opacity: 0;
      height: 14px;
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      color: var(--text-secondary);
      margin-left: 8px;
      margin-bottom: 2px;
      transition: opacity 0.15s ease-in-out;
      
    }

    .typing-indicator-container.active {
      visibility: visible;
      opacity: 1;
    }

    #typingIndicatorText {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      min-width: 0;
      flex: 0 1 auto;
      margin-bottom: 8px;
    }

    .typing-dots {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      flex-shrink: 0;
    }
    
    .typing-dots span {
      width: 5px;
      height: 5px;
      background-color: var(--text-secondary);
      border-radius: 50%;
      animation: typingBounce 1.4s infinite both;
      opacity: 0.7;
      margin-bottom: 3px;
    }
    
    .typing-dots span:nth-child(2) {
      animation-delay: .2s;
    }
    
    .typing-dots span:nth-child(3) {
      animation-delay: .4s;
    }
    
    @keyframes typingBounce {
      0%, 80%, 100% { transform: translateY(0); }
      40% { transform: translateY(-4px); }
    }
  </style>

</head>
<body tabindex="-1">
  <div class="app-wrapper">
    <!-- Sidebar Backdrop for Mobile/Tablet -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Sidebar -->
    <div class="sidebar no-anim" id="sidebar">
      <div class="sidebar-header">
        <span style="flex-grow:1;">Chatify</span>
        <div class="sidebar-header-actions">
          <button id="adminEyeToggleBtn" class="clear-button sidebar-action-btn" style="display:none;" title="View all user conversations">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
          </button>
          <button id="adminKeyToggleBtn" class="clear-button sidebar-action-btn" style="display:none;" title="Change Chat Secret Key">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"></circle><path d="m10.7 12.3 8.8-8.8"></path><path d="m15.5 4.5 3 3"></path><path d="m18 7 3 3"></path></svg>
          </button>
          <button id="closeSidebarBtn" class="clear-button sidebar-action-btn" style="display:none;" title="Close sidebar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
      </div>
      <div class="sidebar-search" id="ownSidebarSearch">
        <input type="text" id="searchInput" placeholder="Search for a user or office..." autocomplete="off">
      </div>
      <!-- Pinned Global Chat entry -->
      <div class="user-item" id="globalChatItem" onclick="selectGlobalChat()" style="border-bottom:1px solid var(--border-color);">
        <div class="user-avatar" style="background:linear-gradient(135deg,#1b74e4,#00c3ff);">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
        </div>
        <div class="user-info">
          <div class="user-name">Global Chat</div>
          <div class="user-last-msg" id="gcLastMsg">Everyone can chat here</div>
        </div>
        <span class="user-unread-badge" id="gcUnreadBadge" style="display:none;"></span>
      </div>
      <div class="sidebar-users" id="sidebarUsers">
        <!-- Users will be populated here via JS -->
      </div>
      <!-- Admin: View all users chats view -->
      <div id="adminConvsSection" style="display:none;position:relative;">
        <div id="adminConvsHeaderTitle" style="padding:8px 16px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-secondary);margin-top:4px;text-align:center;"></div>
        <div class="admin-search" style="padding: 6px 16px;">
          <input type="text" id="adminSearchInput" placeholder="Search for a user or office..." autocomplete="off">
        </div>
        <div class="sidebar-users" id="adminConvsList"></div>
      </div>
    </div>

    <!-- Main Chat Area -->
    <div class="app-container">
      <!-- Floating button for loading older messages -->
      <button class="load-older-floating-btn" id="loadOlderFloatingBtn">
        <span>Load Older Messages</span>
      </button>

      <div class="header">
        <div class="header-left">
          <button id="burgerButton" class="clear-button" style="display:none;margin-right:10px;min-width:auto;" aria-label="Open menu" title="Open menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
          </button>
          <button id="backButton" class="clear-button" style="display:none;margin-right:10px;padding:0 10px;min-width:auto;" aria-label="Go back" title="Go back">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
          </button>
          <img src="cspc.webp" width="28" height="28" alt="GhostLAN ghost logo" class="header-logo" draggable="false" ondragstart="return false;" oncontextmenu="return false;">
          <h1 id="chatHeaderTitle"></h1>
        </div>
      
      <div class="header-buttons">
          <!-- Dark mode button for all users -->
          <button class="darkmode-button" id="darkModeToggle" aria-label="Toggle dark mode" title="Toggle dark mode">
            <svg class="moon-icon" viewBox="0 0 24 24">
              <path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-3.03 0-5.5-2.47-5.5-5.5 0-1.82.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/>
            </svg>
            <svg class="sun-icon" viewBox="0 0 24 24">
              <path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0-.39.39-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0 .39-.39.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.36c.39-.39.39-1.03 0-1.41-.39-.39-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/>
            </svg>
          </button>

        <?php // Delete All button remains removed from header — "/delete all" command only.
              // Clear Chat button is admin-only and shown/hidden dynamically by JS:
              // visible only while Super Admin is spying on a specific conversation. ?>
        <?php if ($is_admin): ?>
        <button id="clearChatHeaderBtn" class="clear-button" style="display:none;" title="Clear this conversation" onclick="showModal()">Clear Chat</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Scroll to bottom indicator — lives outside chat-box so it doesn't scroll with content -->

    <div id="chat-box">
      <!-- Drop overlay element for drag & drop -->
      <div class="drop-overlay" id="dropOverlay">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:36px;height:36px;margin-bottom:8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="#1b74e4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="17 8 12 3 7 8" stroke="#1b74e4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="3" x2="12" y2="15" stroke="#1b74e4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Drop images or files here
      </div>
    </div>

    <div class="input-area">
      <!-- Scroll to bottom indicator anchored to top-right of input area -->
      <div class="scroll-indicator" id="scrollIndicator">
        <span>↓</span>
        <span id="scrollIndicatorText">Go to bottom</span>
        <span class="unread-badge" id="unreadBadge"></span>
      </div>

      <!-- Typing Indicator -->
      <div id="typingIndicator" class="typing-indicator-container">
        <span id="typingIndicatorText"></span>
        <div class="typing-dots">
          <span></span>
          <span></span>
          <span></span>
        </div>
      </div>


      <div class="input-section">
        <div class="name-input-wrapper<?php echo $is_admin ? ' is-admin-user' : ''; ?>">
          <div class="name-field-inner">
            <svg class="name-icon" viewBox="0 0 24 24">
              <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM21 9V7L15 4V6L21 9ZM15 10.26L21 7.26V9.26L15 12.26V10.26ZM12 7C16.42 7 20 8.79 20 11V13C20 15.21 16.42 17 12 17S4 15.21 4 13V11C4 8.79 7.58 7 12 7Z"/>
            </svg>
            <input type="text" id="nameInput" placeholder="" required readonly aria-label="Your display name" value="<?php echo htmlspecialchars($user_name); ?>">
            <?php if ($is_admin): ?>
            <span class="admin-input-badge" title="You are the Super Admin">
              <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="12" fill="#1b74e4"/>
                <path d="M7 12.5l3.5 3.5 6.5-7" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              <span></span>
            </span>
            <?php endif; ?>
          </div>
          <div class="input-actions">
            <label id="attachBtn" class="attachment-btn" title="Attach image or file" for="fileAttachmentInput">
              <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="20" height="20">
                <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </label>
            <input type="file" id="fileAttachmentInput" multiple accept="image/*,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.flac,.aac,.m4a,.mp4,.webm,.mov" style="display:none;">
            <button type="button" id="cancelEditXBtn" title="Cancel editing" aria-label="Cancel editing">
              <svg viewBox="0 0 24 24" width="12" height="12" fill="#fff">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

      <form id="chatForm" class="message-input-container">
        <textarea id="messageInput" placeholder="Type a message..." required autocomplete="off" rows="1"></textarea>
        <button type="submit" id="sendButton">Send</button>
      </form>

      <!-- Admin Spy Mode Notice -->
      <div id="spyModeNotice" class="spy-mode-notice" style="display:none;">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" style="flex-shrink:0;">
          <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
        <span>Super Admin Spy Mode</span>
      </div>
    </div>
  </div>



  <!-- Confirmation Modal - Only show if admin -->
  <?php if ($is_admin): ?>
  <div class="modal" id="confirmModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Clear All Messages</h3>
      </div>
      <div class="modal-body">
        <p>WARNING: You are about to permanently delete all message history. This operation cannot be undone.</p>
        <label for="secretInput" style="display:block;margin-top:10px;">Enter secret key to confirm:</label>
        <input type="password" id="secretInput" autocomplete="off" class="secret-key-input" />
        <div id="secretError" style="color:#b00;font-size:12px;display:none;margin-top:5px;text-align:center;">Invalid secret key.</div>
      </div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="cancelClear">[n] Cancel</button>
        <button class="modal-button confirm-button" id="confirmClear" disabled>[y] Delete</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Logout Confirmation Modal -->
  <div class="modal" id="logoutModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Confirm Logout</h3>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to logout?</p>
      </div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="logoutCancel">No</button>
        <button class="modal-button confirm-button" id="logoutConfirm">Yes</button>
      </div>
    </div>
  </div>

  <!-- Notify Modal - available to every logged in user, including the admin -->
  <div class="modal" id="notifyModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Notify User</h3>
      </div>
      <div class="modal-body">
        <p>This will mention <span class="notify-target" id="notifyTargetName"></span> and send them a notification.</p>
        <textarea id="notifyMessageInput" class="notify-textarea" rows="3" maxlength="250" placeholder="Add a message (optional)..."></textarea>
        <div class="notify-char-count" id="notifyCharCount">0/250</div>
      </div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="notifyCancel">Cancel</button>
        <button class="modal-button confirm-button" id="notifySend">Send</button>
      </div>
    </div>
  </div>

  <!-- Container where incoming "someone notified you" toasts appear -->
  <div class="notify-toast-container" id="notifyToastContainer"></div>

  <!-- Modal shown when a notification toast is clicked, shows full (up to 250 char) content -->
  <div class="modal" id="notifyContentModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="notifyContentTitle">Notification</h3>
      </div>
      <div class="modal-body">
        <p id="notifyContentBody"></p>
      </div>
      <div class="modal-footer">
        <button class="modal-button confirm-button" id="notifyContentClose" style="border-right:none;">Close</button>
      </div>
    </div>
  </div>

  <?php if ($is_admin): ?>
  <!-- Delete All Messages Modal (Admin only) -->
  <div class="modal" id="deleteAllModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3 style="color:#c0392b;">Delete all messages</h3>
      </div>
      <div class="modal-body">
        <p>This will <strong>permanently delete</strong> every message in the entire system — all DMs, and Global Chat will be remove.</p>
        <label for="deleteAllSecretInput" style="display:block;margin-top:10px;">Enter secret key to confirm:</label>
        <input type="password" id="deleteAllSecretInput" autocomplete="off" class="secret-key-input" />
        <div id="deleteAllSecretError" style="font-size:12px;display:none;margin-top:5px;text-align:center;"></div>
      </div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="cancelDeleteAll">Cancel</button>
        <button class="modal-button confirm-button" id="confirmDeleteAll">Delete Everything</button>
      </div>
    </div>
  </div>

  <!-- Change Secret Key Modal (Admin only) -->
  <div class="modal" id="adminKeyModal" aria-hidden="true">
    <div class="modal-content" style="max-width:400px;">
      <div class="modal-header">
        <h3 style="display:flex;align-items:center;gap:8px;margin:0;font-size:1.1rem;">
          Change Deletion Secret Key
        </h3>
      </div>
      <div class="modal-body" style="padding:16px;">
        <p style="font-size:12px;color:var(--subtext-color);margin-bottom:12px;text-align:left;line-height:1.4;">
          Update the secret key used for deleting conversations and wiping chat history in PostgreSQL.
        </p>
        <form id="adminKeyForm" onsubmit="handleSecretKeyUpdate(event)">
          <div style="margin-bottom:10px;text-align:left;">
            <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-color);">Current Secret Key</label>
            <input type="password" id="currentSecretInput" required class="secret-key-input" style="width:100%;box-sizing:border-box;padding:8px 12px;border-radius:6px;" placeholder="Enter current secret key" />
          </div>
          <div style="margin-bottom:10px;text-align:left;">
            <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-color);">New Secret Key</label>
            <input type="password" id="newSecretInput" required class="secret-key-input" style="width:100%;box-sizing:border-box;padding:8px 12px;border-radius:6px;" placeholder="Enter new secret key" />
          </div>
          <div style="margin-bottom:12px;text-align:left;">
            <label style="display:block;font-size:12px;margin-bottom:4px;color:var(--text-color);">Confirm New Secret Key</label>
            <input type="password" id="confirmNewSecretInput" required class="secret-key-input" style="width:100%;box-sizing:border-box;padding:8px 12px;border-radius:6px;" placeholder="Confirm new secret key" />
          </div>
          <div id="adminKeyError" style="font-size:12px;color:#e74c3c;display:none;margin-bottom:8px;text-align:center;font-weight:600;"></div>
          <div id="adminKeySuccess" style="font-size:12px;color:#2ecc71;display:none;margin-bottom:8px;text-align:center;font-weight:600;"></div>
          <button type="submit" style="display:none;" aria-hidden="true" tabindex="-1"></button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button cancel-button" id="adminKeyCancelBtn">Cancel</button>
        <button type="button" class="modal-button confirm-button" id="adminKeySubmitBtn" onclick="submitSecretKeyForm()">Update Key</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // Generate HMAC SHA256 token for WebSocket authentication.
  //
  // SECURITY NOTE: this token is only ever minted here, immediately after
  // the top-of-file Auth::check() gate has already confirmed a live RMS
  // session. Its lifetime is intentionally short (15 min, not 24h) because
  // the WS server has no direct access to the RMS session store — the
  // "expires" claim, plus the periodic reauth below, is the only thing
  // standing between "RMS session died" and "socket stays open anyway".
  // A 24h token meant a socket authenticated once would stay authenticated
  // for a full day even if the person logged out five minutes later.
  $ws_expires = time() + 900; // 15 minutes — must be re-authenticated via reauth below
  $ws_payload = $_current_account_id . '|' . $ws_expires;
  $ws_token = hash_hmac('sha256', $ws_payload, CHAT_SHARED_SECRET);
  ?>
  <script>
    const wsConfig = {
      accountId: <?php echo $_current_account_id; ?>,
      name: <?php echo json_encode($user_name); ?>,
      expires: <?php echo $ws_expires; ?>,
      token: <?php echo json_encode($ws_token); ?>
    };

    // ── Periodic WS reauth ───────────────────────────────────────────────
    // Every 5 minutes (well inside the 15-minute token lifetime), re-run
    // the *server-side* RMS check by calling refresh_ws_token.php, which
    // re-executes Auth::check() against the live RMS session before
    // minting anything. If the RMS session is gone, this call fails and we
    // treat it exactly like a forced session-kick: tear down the socket
    // and send the person to login. If it succeeds, we push the fresh
    // token to the already-open socket so the server extends its validity
    // — the socket is never trusted indefinitely off a single handshake.
    function reauthWebSocket() {
      if (!ws || ws.readyState !== WebSocket.OPEN) return;
      fetch('refresh_ws_token.php', { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error('refresh failed: ' + res.status);
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.token) throw new Error('malformed refresh response');
          wsConfig.expires = data.expires;
          wsConfig.token = data.token;
          if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
              type: 'reauth',
              account_id: wsConfig.accountId,
              expires: wsConfig.expires,
              token: wsConfig.token
            }));
          }
        })
        .catch(function () {
          // RMS session is gone (401/expired) or the request itself
          // failed — do not keep the socket alive on the old token.
          // Reuse the same overlay path as an explicit session kick.
          if (typeof showSessionKickedOverlay === 'function') {
            showSessionKickedOverlay('expired');
          } else {
            window.location.href = 'logout.php';
          }
        });
    }
    setInterval(reauthWebSocket, 5 * 60 * 1000);
  </script>

  <script>
    // ── All DOM element references first ──────────────────────────────────────

    const chatBox         = document.getElementById("chat-box");
    const nameInput       = document.getElementById("nameInput");
    const messageInput    = document.getElementById("messageInput");
    const sendButton      = document.getElementById("sendButton");
    const clearButton     = document.getElementById("clearButton"); // removed from header; now null, kept for legacy references
    const confirmModal    = document.getElementById("confirmModal");
    const cancelClear     = document.getElementById("cancelClear");
    const confirmClear    = document.getElementById("confirmClear");
    const scrollIndicator = document.getElementById("scrollIndicator");
    const loadOlderFloatingBtn = document.getElementById("loadOlderFloatingBtn");
    const secretInput     = document.getElementById("secretInput");
    const secretError     = document.getElementById("secretError");
    const darkModeToggle  = document.getElementById("darkModeToggle");
    const sidebarUsers    = document.getElementById('sidebarUsers');
    const searchInput     = document.getElementById('searchInput');
    const adminSearchInput = document.getElementById('adminSearchInput');

    // ── Persist search box contents across tab refreshes ─────────────────────
    // Previously the search text lived only in the input's live DOM value, so
    // refreshing the tab silently wiped it out. Restore whatever was typed
    // last (per-tab, via localStorage) before anything else on init reads
    // these inputs' .value (e.g. fetchUsers(), applyAdminAllChatsView()).
    if (searchInput) {
      const savedSearchQuery = localStorage.getItem('__searchQuery__');
      if (savedSearchQuery) searchInput.value = savedSearchQuery;
    }
    if (adminSearchInput) {
      const savedAdminSearchQuery = localStorage.getItem('__adminSearchQuery__');
      if (savedAdminSearchQuery) adminSearchInput.value = savedAdminSearchQuery;
    }
    const chatHeaderTitle = document.getElementById('chatHeaderTitle');
    const sidebar         = document.getElementById('sidebar');
    const backButton      = document.getElementById('backButton');
    const burgerButton    = document.getElementById('burgerButton');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');

    // Keep the name-row's action column (attach button + cancel-edit X
    // button) exactly as wide as the real rendered #sendButton, but ONLY
    // while editing — the rest of the time it stays its normal shrink-wrap
    // size (just the attach button), same as before.
    const inputActions = document.querySelector('.input-actions');
    function syncInputActionsWidth() {
      if (!inputActions || !sendButton) return;
      const w = sendButton.getBoundingClientRect().width;
      if (w > 0) inputActions.style.width = w + 'px';
    }
    window.addEventListener('resize', function () {
      if (editingMsgId !== null) syncInputActionsWidth();
    });

    let editingMsgId = null;

    function showEditBanner(msgId) {
      editingMsgId = msgId;
      const xBtn = document.getElementById('cancelEditXBtn');
      if (xBtn) xBtn.style.display = 'flex';
      syncInputActionsWidth();
    }

    function hideEditBanner() {
      editingMsgId = null;
      const xBtn = document.getElementById('cancelEditXBtn');
      if (xBtn) xBtn.style.display = 'none';
      if (inputActions) inputActions.style.width = ''; // back to auto (shrink-wrap)
    }
    const notifyModal        = document.getElementById('notifyModal');
    const notifyTargetName   = document.getElementById('notifyTargetName');
    const notifyMessageInput = document.getElementById('notifyMessageInput');
    const notifyCharCount    = document.getElementById('notifyCharCount');
    const notifyCancel       = document.getElementById('notifyCancel');
    const notifySend         = document.getElementById('notifySend');
    const notifyToastContainer = document.getElementById('notifyToastContainer');
    const notifyContentModal   = document.getElementById('notifyContentModal');
    const notifyContentTitle   = document.getElementById('notifyContentTitle');
    const notifyContentBody    = document.getElementById('notifyContentBody');
    const notifyContentClose   = document.getElementById('notifyContentClose');

    // Eye icon used to mark admin "spy" conversations (avoid emoji rendering
    // inconsistently across OS/browsers — use a proper inline SVG instead).
    const EYE_ICON_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';

    // DM Sidebar state
    let activeDM = null;
    let activeDMAccountId = null; // Track recipient's account ID

    // WebSocket state variables
    let ws = null;
    let wsReconnectTimer = null;
    let wsPollInterval = null;
    let localIsTyping = false;
    let localTypingTimeout = null;
    let localTypingHeartbeat = null;
    let typingTimer = null;

    // Debounce timers for coalescing bursts of incoming WS 'message' events
    // (e.g. someone sending several messages in quick succession) into a
    // single load_dm.php / loadAdminConv fetch instead of one HTTP round
    // trip per message. Without this, N messages arriving within a few
    // hundred ms triggers N full loadChat(true) calls (each pulling the
    // whole recent window AND firing mark_read.php) — needless load on the
    // server for something that only needs to happen once per burst.
    let dmLoadDebounceTimer = null;
    let adminConvLoadDebounceTimer = null;
    const WS_LOAD_DEBOUNCE_MS = 350;

    function scheduleDmReload() {
      if (dmLoadDebounceTimer) return; // a reload is already queued for this burst
      dmLoadDebounceTimer = setTimeout(function() {
        dmLoadDebounceTimer = null;
        loadChat(true);
      }, WS_LOAD_DEBOUNCE_MS);
    }

    function scheduleAdminConvReload(convKey) {
      if (adminConvLoadDebounceTimer) return;
      adminConvLoadDebounceTimer = setTimeout(function() {
        adminConvLoadDebounceTimer = null;
        // Guard against the admin having switched conversations while this
        // was queued.
        if (activeAdminConv === convKey) loadAdminConv(convKey, true);
      }, WS_LOAD_DEBOUNCE_MS);
    }


    // Exponential backoff state
    let wsAttempts = 0;
    const WS_BASE_DELAY = 1000;
    const WS_MAX_DELAY = 30000;

    function connectWebSocket() {
      if (ws) {
        // Prevent onclose trigger loop when closing manually
        ws.onclose = null;
        ws.onerror = null;
        try { ws.close(); } catch(e) {}
        ws = null;
      }

      if (wsReconnectTimer) {
        clearTimeout(wsReconnectTimer);
        wsReconnectTimer = null;
      }

      const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const wsUrl = `${wsProtocol}//${window.location.host}/ws`;

      console.log('Connecting to WebSocket server:', wsUrl);
      ws = new WebSocket(wsUrl);

      ws.onopen = function() {
        console.log('WebSocket connection established.');
        wsAttempts = 0; // Reset exponential backoff counter on success
        if (wsReconnectTimer) {
          clearTimeout(wsReconnectTimer);
          wsReconnectTimer = null;
        }

        // Stop fallback polling on successful connection
        stopPollingFallback();

        // Authenticate connection
        ws.send(JSON.stringify({
          type: 'auth',
          account_id: wsConfig.accountId,
          name: wsConfig.name,
          expires: wsConfig.expires,
          token: wsConfig.token
        }));
      };

      ws.onmessage = function(event) {
        let data;
        try {
          data = JSON.parse(event.data);
        } catch (e) {
          return;
        }

        if (data.type === 'message_edited') {
          // Another client edited a message — patch the bubble in-place immediately
          // without any server fetch so the update is instant for all viewers.
          const targetContainer = chatBox.querySelector(
            `.message-container[data-msg-id="${data.msg_uuid}"]`
          );
          if (targetContainer) {
            const contentEl = targetContainer.querySelector('.message-bubble .message-content');
            if (contentEl) contentEl.textContent = data.message;

            const bubbleWrapper = targetContainer.querySelector('.bubble-wrapper');
            if (bubbleWrapper && !bubbleWrapper.querySelector('.message-edited-label')) {
              const label = document.createElement('div');
              label.className = 'message-edited-label';
              label.style.cssText = 'font-size:10px;color:var(--text-secondary);opacity:0.8;margin-bottom:2px;font-style:italic;';
              label.textContent = 'edited';
              bubbleWrapper.insertBefore(label, bubbleWrapper.firstChild);
            }
          }
        } else if (data.type === 'message') {
          console.log('Received WebSocket real-time update notice:', data);
          // Deduplication: if message is already rendered in chatBox, skip fetching!
          if (data.msg_uuid && chatBox.querySelector(`.message-container[data-msg-id="${data.msg_uuid}"]`)) {
            return;
          }
          if (data.chat_type === 'global') {
            if (isGlobalChat) {
              loadGlobalChat(true);
            }
            // Global messages don't touch any per-user sidebar row, so no
            // fetch_users_dm.php round trip needed here.
          } else if (data.chat_type === 'private') {
            if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              const s = Number(data.sender_id);
              const r = Number(data.recipient_id);
              if ((s === parts[0] && r === parts[1]) || (s === parts[1] && r === parts[0])) {
                scheduleAdminConvReload(activeAdminConv);
              }
            } else if (activeDM && activeDMAccountId === Number(data.sender_id)) {
              scheduleDmReload();
              // Real-time "Seen": the recipient's chat with this sender is
              // already open and visible right now, so mark it read the
              // instant the message arrives instead of waiting for the
              // debounced reload + DOM reconcile to finish. That round trip
              // could take 350ms+ before mark_read.php ever fires; firing it
              // here means the sender sees "Seen" appear essentially
              // instantly, with no dependency on how loadChat() reconciles.
              if (!document.hidden) markRead(activeDM);
            }
            // Admin spy mode: keep the "X msgs · last message" counts in the
            // conversations list live instead of only updating on the next manual search.
            if (isAdminAllChatsView && adminSpyType === 'conversations' && adminSpyTargetUser) {
              const spyId = Number(adminSpyTargetUser.account_id);
              const s2 = Number(data.sender_id);
              const r2 = Number(data.recipient_id);
              if (s2 === spyId || r2 === spyId) {
                fetchAdminConvs('', 0, false, spyId);
              }
            }
            // Our own just-sent messages are already patched into the
            // sidebar directly by the send handler below — this branch only
            // needs to react when someone else's message just came in.
            if (Number(data.sender_id) !== wsConfig.accountId) {
              const otherUser = allUsersData.find(u => Number(u.account_id) === Number(data.sender_id));
              if (otherUser) {
                bumpSidebarUser(otherUser.username, { incrementUnread: true });
              } else {
                // Sender isn't in the currently loaded/filtered sidebar list
                // (e.g. a brand-new contact) — the one case that still needs
                // a real fetch.
                fetchUsers();
              }
            }
          }
        } else if (data.type === 'typing') {
          if (activeDM && activeDMAccountId === Number(data.sender_id)) {
            showTypingIndicator(data.sender_name, data.is_typing);
          }
        } else if (data.type === 'message_read') {
          // The other participant just read up through data.last_msg_uuid —
          // update the Messenger-style "Seen" indicator instantly, no poll needed.
          if (activeDM && activeDMAccountId === Number(data.reader_id)) {
            if (data.last_msg_uuid) {
              // DB-confirmed value (arrived via the HTTP-persisted path) —
              // always authoritative.
              dmReadUpTo = data.last_msg_uuid;
            } else {
              // Instant WS-only ping, sent with no id attached (see
              // markRead() above) — the other participant is actively here
              // right now, so flag whatever we've most recently sent as
              // seen using OUR OWN chatBox (accurate on this side), rather
              // than waiting for the slower DB round trip to confirm it.
              // Read state only ever moves forward, never backward.
              let newestSentId = null;
              chatBox.querySelectorAll('.message-container.sent[data-msg-id]').forEach(el => {
                const id = el.getAttribute('data-msg-id');
                if (id && (!newestSentId || id > newestSentId)) newestSentId = id;
              });
              if (newestSentId && (!dmReadUpTo || newestSentId > dmReadUpTo)) {
                dmReadUpTo = newestSentId;
              }
            }
            updateSeenIndicator();
          }
        } else if (data.type === 'chat_cleared') {
          console.log('Received WebSocket real-time update notice:', data);
          if (data.chat_type === 'private') {
            if (activeDM && activeDMAccountId === Number(data.sender_id)) {
              loadChat(true);
            } else if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              const s = Number(data.sender_id);
              const r = Number(data.recipient_id);
              if ((s === parts[0] && r === parts[1]) || (s === parts[1] && r === parts[0])) {
                loadAdminConv(activeAdminConv, true);
              }
            }
          } else if (data.chat_type === 'admin_conv') {
            const a = Number(data.user_a);
            const b = Number(data.user_b);
            if (activeAdminConv) {
              const parts = activeAdminConv.split('_').map(Number);
              if ((parts[0] === a && parts[1] === b) || (parts[0] === b && parts[1] === a)) {
                loadAdminConv(activeAdminConv, true);
              }
            }
            if (activeDM && (activeDMAccountId === a || activeDMAccountId === b)) {
              loadChat(true);
            }
          }
          // Admin spy mode: keep the "X msgs · last message" counts in the
          // conversations list live when a conversation is cleared.
          if (isAdminAllChatsView && adminSpyType === 'conversations' && adminSpyTargetUser) {
            const spyId = Number(adminSpyTargetUser.account_id);
            let touchesSpyTarget = false;
            if (data.chat_type === 'private') {
              touchesSpyTarget = (Number(data.sender_id) === spyId || Number(data.recipient_id) === spyId);
            } else if (data.chat_type === 'admin_conv') {
              touchesSpyTarget = (Number(data.user_a) === spyId || Number(data.user_b) === spyId);
            }
            if (touchesSpyTarget) {
              fetchAdminConvs('', 0, false, spyId);
            }
          }
          fetchUsers();
        } else if (data.type === 'all_cleared') {
          console.log('Received WebSocket real-time update notice:', data);
          activeDM = null; activeAdminConv = null; isGlobalChat = false;
          updateClearChatButtonVisibility();
          gcCursor = ''; dmCursor = '';
          gcViewingOlder = false; dmViewingOlder = false;
          allConvsData = [];
          localStorage.removeItem('activeSpyConv');
          localStorage.removeItem('activeDM');
          removePaginationBtn();
          const gcItem = document.getElementById('globalChatItem');
          if (gcItem) gcItem.classList.remove('active');
          chatBox.innerHTML = '<div class="empty-chat"><p>All messages deleted.</p></div>';
          renderSidebarUsers();
          if (serverIsAdmin) renderAdminConvs();
          fetchUsers();
        } else if (data.type === 'notify') {
          // Pushed directly by the server the instant someone notifies/mentions
          // us. This is the only delivery path now — no HTTP fallback poll.
          console.log('Received WebSocket real-time update notice:', data);
          showNotifyToast(data);
        } else if (data.type === 'session_kicked') {
          // Pushed by the server the instant another device logs into this
          // account — no more waiting on the 5s checkSession() poll.
          console.log('Received WebSocket real-time update notice:', data);
          showSessionKickedOverlay(data.reason || 'kicked');
        } else if (data.type === 'name_updated') {
          // Pushed by the server the instant this account's name changes
          // elsewhere — no more waiting on the 5s refreshOwnName() poll.
          console.log('Received WebSocket real-time update notice:', data);
          applyOwnNameUpdate(data.name);
        } else if (data.type === 'users_changed') {
          // Pushed by the server whenever something outside the chat itself
          // affects the sidebar list — account created/deleted, visibility
          // changed, etc. Message-driven sidebar refreshes already happen
          // via the 'message'/'chat_cleared'/'all_cleared' cases above; this
          // covers everything else without needing a blind poll for it.
          console.log('Received WebSocket real-time update notice:', data);
          fetchUsers();
        }
      };

      ws.onclose = function() {
        console.warn('WebSocket connection lost.');
        showTypingIndicator('', false);
        ws = null;
        
        // Start polling fallback immediately when connection is lost
        startPollingFallback();

        if (!wsReconnectTimer) {
          wsAttempts++;
          const delay = Math.min(WS_MAX_DELAY, WS_BASE_DELAY * Math.pow(2, wsAttempts - 1)) + Math.floor(Math.random() * 500);
          console.log(`Scheduling WebSocket reconnect in ${delay}ms (attempt ${wsAttempts})...`);
          wsReconnectTimer = setTimeout(connectWebSocket, delay);
        }
      };

      ws.onerror = function(err) {
        console.error('WebSocket connection error:', err);
      };
    }

    function sendTypingStatus(isTyping) {
      if (ws && ws.readyState === WebSocket.OPEN && activeDM && activeDMAccountId) {
        ws.send(JSON.stringify({
          type: 'typing',
          recipient_id: activeDMAccountId,
          is_typing: isTyping
        }));
      }
    }

    function showTypingIndicator(senderName, isTyping) {
      const indicator = document.getElementById('typingIndicator');
      const textEl = document.getElementById('typingIndicatorText');

      if (typingTimer) {
        clearTimeout(typingTimer);
        typingTimer = null;
      }

      if (isTyping && activeDM) {
        textEl.textContent = `${senderName} is typing`;
        indicator.classList.add('active');

        // Auto-expire after 4 seconds as a safety cleanup
        typingTimer = setTimeout(() => {
          indicator.classList.remove('active');
        }, 4000);
      } else {
        indicator.classList.remove('active');
      }
    }

    function startPollingFallback() {
      if (wsPollInterval) return;
      if (document.hidden) return; // don't poll while tab is in background
      console.log('Starting backup message polling...');
      wsPollInterval = setInterval(function() {
        if (document.hidden) return; // skip each tick while hidden
        if (isGlobalChat) {
          loadGlobalChat(true);
        } else if (activeDM) {
          loadChat(true);
        } else if (activeAdminConv) {
          loadAdminConv(activeAdminConv, true);
        }
      }, 3000);
    }

    function stopPollingFallback() {
      if (wsPollInterval) {
        console.log('Stopping backup message polling.');
        clearInterval(wsPollInterval);
        wsPollInterval = null;
      }
    }



    // Mobile layout setup - defined here but called AFTER chatBox is declared
    function setupMobileLayout() {
      if (window.innerWidth <= 991) {
        if (!activeDM && !activeAdminConv && !isGlobalChat) {
          sidebar.classList.add('open');
          const backdrop = document.getElementById('sidebarBackdrop');
          if (backdrop) backdrop.classList.add('visible');
          burgerButton.style.display = 'inline-flex';
          backButton.style.display = 'none';
        } else {
          burgerButton.style.display = 'none';
          backButton.style.display = 'inline-flex';
        }
        closeSidebarBtn.style.display = 'inline-flex';
      } else {
        burgerButton.style.display = 'none';
        backButton.style.display = 'none';
        closeSidebarBtn.style.display = 'none';
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
      }
    }
    window.addEventListener('resize', setupMobileLayout);

    burgerButton.addEventListener('click', () => {
      sidebar.classList.add('open');
      const backdrop = document.getElementById('sidebarBackdrop');
      if (backdrop) backdrop.classList.add('visible');
    });
    closeSidebarBtn.addEventListener('click', () => {
      sidebar.classList.remove('open');
      const backdrop = document.getElementById('sidebarBackdrop');
      if (backdrop) backdrop.classList.remove('visible');
    });

    // Backdrop click listener to close sidebar
    const backdropEl = document.getElementById('sidebarBackdrop');
    if (backdropEl) {
      backdropEl.addEventListener('click', () => {
        sidebar.classList.remove('open');
        backdropEl.classList.remove('visible');
      });
    }

    // Global data
    let allUsersData = [];
    let allConvsData = [];
    let serverIsAdmin = false;
    let hasAutoSelected = false;

    let userSearchHasMore = false;

    function fetchUsers(query = '') {
      const currentInput = searchInput ? searchInput.value.trim() : '';
      const q = query !== '' ? query : currentInput;

      const xhr = new XMLHttpRequest();
      let url = "fetch_users_dm.php";
      if (q !== '') {
        url += "?q=" + encodeURIComponent(q);
      }
      xhr.open("GET", url, true);
      xhr.onload = function() {
        if (this.status === 200) {
          try {
            const data = JSON.parse(this.responseText);
            if (Array.isArray(data)) {
              allUsersData = data;
              userSearchHasMore = false;
            } else {
              allUsersData = data.users || [];
              userSearchHasMore = !!data.hasMore;
              serverIsAdmin = !!(data.currentUser && data.currentUser.is_admin);
            }
            renderSidebarUsers();

            if (!hasAutoSelected) {
              hasAutoSelected = true;

              // Reopen whichever chat was active before the tab was
              // refreshed, instead of always dropping back to the
              // placeholder screen.
              const savedActiveDM = (!isAdminAllChatsView) ? localStorage.getItem('activeDM') : null;
              if (savedActiveDM) {
                restoreActiveConversation(savedActiveDM);
              } else {
                chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
              }
            }
          } catch(e){ console.error('fetchUsers parse error', e); }
        }
      };
      xhr.send();
    }

    // Reopens the conversation the person had open before a refresh. This is
    // called from inside fetchUsers()'s own onload, right after allUsersData
    // has just been populated from an unfiltered (q='') request — so the
    // match is looked up directly in that in-memory list instead of firing
    // a second, duplicate fetch_users_dm.php request for the same data.
    function restoreActiveConversation(savedActiveDM) {
      if (savedActiveDM === '__global__') {
        selectGlobalChat();
        return;
      }

      const matchedUser = allUsersData.find(u => u.username === savedActiveDM) || null;
      if (matchedUser) {
        selectDM(matchedUser);
      } else {
        // The saved conversation partner no longer exists / isn't reachable
        // anymore — don't keep pointing at a chat we can't reopen.
        localStorage.removeItem('activeDM');
        chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
      }
    }

    const sidebarUserItems = new Map(); // username -> item element
    let latestTotalUnread = 0;

    // Patches one sidebar row in-place (unread badge, move-to-top ordering)
    // using data we already have from a WS event or our own just-sent
    // message — no fetch_users_dm.php round trip needed.
    // Returns false if that user isn't in the currently loaded/filtered
    // list, so the caller can fall back to a real fetch in that one case.
    function bumpSidebarUser(username, opts) {
      opts = opts || {};
      const idx = allUsersData.findIndex(u => u.username === username);
      if (idx === -1) return false;

      const u = allUsersData[idx];
      if (opts.incrementUnread && activeDM !== username) {
        u.unreadCount = (u.unreadCount || 0) + 1;
      }
      if (idx > 0) {
        allUsersData.splice(idx, 1);
        allUsersData.unshift(u);
      }
      renderSidebarUsers();
      return true;
    }

    function renderSidebarUsers() {
      const query = searchInput ? searchInput.value.toLowerCase().trim() : '';

      if (query === '') {
        latestTotalUnread = (allUsersData || []).reduce((sum, u) => sum + (u.unreadCount || 0), 0);
        updateTabTitle(latestTotalUnread);

        if (!allUsersData || allUsersData.length === 0) {
          sidebarUsers.innerHTML = `<div class="sidebar-empty-state" style="padding:32px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin-bottom:8px;opacity:0.6;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <p style="margin:0;font-weight:500;">Search for a user or office.</p>
          </div>`;
          sidebarUserItems.clear();
          return;
        }
      } else {
        if (!allUsersData || allUsersData.length === 0) {
          sidebarUsers.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No users found matching "${escapeHtml(query)}".</p></div>`;
          sidebarUserItems.clear();
          return;
        }
      }

      const emptyEl = sidebarUsers.querySelector('.sidebar-empty-state');
      if (emptyEl) emptyEl.remove();

      const seen = new Set();

      allUsersData.forEach((u, index) => {
        const hasUnread = u.unreadCount > 0 && activeDM !== u.username;
        seen.add(u.username);

        let item = sidebarUserItems.get(u.username);
        let avatar, dot, info, nameRow, nameEl, officeEl, actionsRight;

        if (!item) {
          item = document.createElement('div');
          item.dataset.username = u.username;

          avatar = document.createElement('div');
          avatar.className = 'user-avatar';
          dot = document.createElement('div');
          avatar.appendChild(dot);

          info = document.createElement('div');
          info.className = 'user-info';

          nameRow = document.createElement('div');
          nameRow.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:4px;';
          nameEl = document.createElement('div');
          nameEl.className = 'user-name';
          nameRow.appendChild(nameEl);
          info.appendChild(nameRow);

          officeEl = document.createElement('div');
          officeEl.className = 'user-office';
          info.appendChild(officeEl);

          actionsRight = document.createElement('div');
          actionsRight.className = 'user-actions-right';

          item.appendChild(avatar);
          item.appendChild(info);
          item.appendChild(actionsRight);

          item.onclick = () => selectDM(u);

          item._avatar = avatar;
          item._dot = dot;
          item._nameEl = nameEl;
          item._officeEl = officeEl;
          item._actionsRight = actionsRight;

          sidebarUserItems.set(u.username, item);
        } else {
          avatar = item._avatar || item.querySelector('.user-avatar');
          dot = item._dot || item.querySelector('.status-dot');
          nameEl = item._nameEl || item.querySelector('.user-name');
          officeEl = item._officeEl || item.querySelector('.user-office');
          actionsRight = item._actionsRight || item.querySelector('.user-actions-right');
          item.onclick = () => selectDM(u);
        }

        const newClassName = 'user-item' + (activeDM === u.username ? ' active' : '') + (hasUnread ? ' has-unread' : '');
        if (item.className !== newClassName) item.className = newClassName;

        if (avatar.dataset.initials !== u.name) {
          const initials = getInitials(u.name);
          avatar.textContent = initials;
          avatar.appendChild(dot);
          avatar.dataset.initials = u.name;
        }
        const newDotClass = 'status-dot ' + (u.status || 'offline');
        if (dot.className !== newDotClass) dot.className = newDotClass;

        if (nameEl.textContent !== u.name) nameEl.textContent = u.name;

        if (activeDM === u.username && chatHeaderTitle.textContent !== u.name) {
          chatHeaderTitle.textContent = u.name;
        }

        const targetIsAdmin = Number(u.account_id) === 1;

        if (officeEl) {
          const newOffice = u.office_name ? u.office_name : 'No office assigned';
          if (officeEl.textContent !== newOffice) {
            officeEl.textContent = newOffice;
          }
          if (u.office_name) {
            officeEl.style.color = '#1b74e4';
            officeEl.style.fontStyle = 'normal';
          } else {
            officeEl.style.color = 'var(--text-secondary)';
            officeEl.style.fontStyle = 'italic';
          }
          officeEl.style.display = 'block';
        }

        // Unread badge and notify button rendering
        let badge = actionsRight.querySelector('.user-unread-badge');
        let adminBadgeWrapper = actionsRight.querySelector('.admin-badge-wrapper');
        let notifyBtn = actionsRight.querySelector('.notify-btn');

        if (targetIsAdmin) {
          if (notifyBtn) {
            notifyBtn.remove();
            notifyBtn = null;
          }

          if (hasUnread) {
            if (!adminBadgeWrapper) {
              adminBadgeWrapper = document.createElement('div');
              adminBadgeWrapper.className = 'admin-badge-wrapper';
              adminBadgeWrapper.style.cssText = 'width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-left: 4px;';
              actionsRight.appendChild(adminBadgeWrapper);
            }
            
            badge = adminBadgeWrapper.querySelector('.user-unread-badge');
            const badgeText = u.unreadCount > 99 ? '99+' : String(u.unreadCount);
            if (!badge) {
              badge = document.createElement('span');
              badge.className = 'user-unread-badge';
              badge.style.marginLeft = '0';
              adminBadgeWrapper.appendChild(badge);
              badge.textContent = badgeText;
            } else if (badge.textContent !== badgeText) {
              badge.textContent = badgeText;
            }
            
            let standaloneBadge = actionsRight.querySelector(':scope > .user-unread-badge');
            if (standaloneBadge) {
              standaloneBadge.remove();
            }
          } else {
            if (adminBadgeWrapper) {
              adminBadgeWrapper.remove();
              adminBadgeWrapper = null;
            }
          }
        } else {
          if (adminBadgeWrapper) {
            adminBadgeWrapper.remove();
            adminBadgeWrapper = null;
          }

          if (hasUnread) {
            const badgeText = u.unreadCount > 99 ? '99+' : String(u.unreadCount);
            if (!badge) {
              badge = document.createElement('span');
              badge.className = 'user-unread-badge';
              actionsRight.insertBefore(badge, actionsRight.firstChild);
              badge.textContent = badgeText;
            } else if (badge.textContent !== badgeText) {
              badge.textContent = badgeText;
            }
          } else if (badge) {
            badge.remove();
            badge = null;
          }

          if (!notifyBtn) {
            notifyBtn = document.createElement('button');
            notifyBtn.type = 'button';
            notifyBtn.className = 'notify-btn';
            notifyBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
            actionsRight.appendChild(notifyBtn);
          }
          notifyBtn.title = 'Notify ' + u.name;
          notifyBtn.setAttribute('aria-label', 'Notify ' + u.name);
          notifyBtn.onclick = function(e) {
            e.stopPropagation();
            openNotifyModal(u);
          };
        }

        if (sidebarUsers.children[index] !== item) {
          sidebarUsers.insertBefore(item, sidebarUsers.children[index] || null);
        }
      });

      for (const [username, item] of sidebarUserItems) {
        if (!seen.has(username)) {
          item.remove();
          sidebarUserItems.delete(username);
        }
      }

      const existingNotice = sidebarUsers.querySelector('.search-limit-notice');
      if (existingNotice) existingNotice.remove();

      if (userSearchHasMore) {
        const notice = document.createElement('div');
        notice.className = 'search-limit-notice';
        notice.style.cssText = 'padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center;font-weight:500;border-top:1px dashed var(--border-color);margin-top:4px;';
        notice.textContent = 'Showing the first 10 matches. Enter a more specific search term.';
        sidebarUsers.appendChild(notice);
      }
    }

    // ── Notify feature: mention + notify any user from the sidebar list ──
    let notifyTargetUser = null; // { account_id, username, name, ... } — same shape as fetch_users_dm.php's user objects

    function openNotifyModal(user) {
      // Defense in depth: never allow the admin (account_id === 1) to be
      // @mentioned/notified by a regular user, even if this gets called
      // some other way besides the sidebar's notify button.
      if (Number(user.account_id) === 1 && !serverIsAdmin) {
        console.warn('Blocked attempt to notify/mention the admin.');
        return;
      }
      notifyTargetUser = user;
      notifyTargetName.textContent = '@' + user.username;
      notifyMessageInput.value = '';
      notifyCharCount.textContent = '0/250';
      notifyCharCount.classList.remove('limit-reached');
      notifyModal.classList.add('active');
      notifyModal.setAttribute('aria-hidden', 'false');
      setTimeout(() => notifyMessageInput.focus(), 50);
    }

    function closeNotifyModal() {
      notifyModal.classList.remove('active');
      notifyModal.setAttribute('aria-hidden', 'true');
      notifyTargetUser = null;
    }

    notifyMessageInput.addEventListener('input', function() {
      const len = notifyMessageInput.value.length;
      notifyCharCount.textContent = len + '/250';
      notifyCharCount.classList.toggle('limit-reached', len >= 250);
    });

    notifyCancel.addEventListener('click', closeNotifyModal);
    notifyModal.addEventListener('click', function(e) {
      if (e.target === notifyModal) closeNotifyModal();
    });

    notifySend.addEventListener('click', function() {
      if (!notifyTargetUser) return;
      const message = notifyMessageInput.value.slice(0, 250).trim();

      notifySend.disabled = true;
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'notify.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        notifySend.disabled = false;
        // Whether it succeeds or fails, don't trap the user in the modal —
        // just close it. Errors are logged for debugging.
        if (this.status !== 200) {
          console.error('Notify failed', this.status, this.responseText);
        }
        closeNotifyModal();
      };
      xhr.onerror = function() {
        notifySend.disabled = false;
        console.error('Notify request error');
        closeNotifyModal();
      };
      xhr.send('recipient_id=' + encodeURIComponent(notifyTargetUser.account_id) + '&message=' + encodeURIComponent(message));
    });

    // Max characters to show in the toast preview before truncating with "..."
    const TOAST_PREVIEW_LIMIT = 80;

    function showNotifyToast(n) {
      const toast = document.createElement('div');
      toast.className = 'notify-toast';
      if (n.message) {
        const isLong = n.message.length > TOAST_PREVIEW_LIMIT;
        const preview = isLong ? n.message.slice(0, TOAST_PREVIEW_LIMIT).trim() + '...' : n.message;
        toast.innerHTML = '<strong>' + escapeHtml(n.sender) + '</strong> mentioned you: ' + escapeHtml(preview);
      } else {
        toast.innerHTML = '<strong>' + escapeHtml(n.sender) + '</strong> notified you';
      }
      const dismiss = () => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 200);
      };
      toast.onclick = () => {
        showNotifyContentModal(n);
        dismiss();
      };
      notifyToastContainer.appendChild(toast);
      setTimeout(dismiss, 6000);
    }

    // ── Modal shown when a notification toast is clicked ──
    function showNotifyContentModal(n) {
      if (!notifyContentModal) return;
      notifyContentTitle.textContent = n.sender ? (n.sender + ' notified you') : 'Notification';
      const content = (n.message || '').slice(0, 250);
      notifyContentBody.textContent = content || 'No message content.';
      notifyContentModal.classList.add('active');
      notifyContentModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }

    function closeNotifyContentModal() {
      if (!notifyContentModal) return;
      notifyContentModal.classList.remove('active');
      notifyContentModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (notifyContentClose) {
      notifyContentClose.addEventListener('click', closeNotifyContentModal);
    }
    if (notifyContentModal) {
      notifyContentModal.addEventListener('click', function(e) {
        if (e.target === notifyContentModal) closeNotifyContentModal();
      });
    }

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = String(str == null ? '' : str);
      return div.innerHTML;
    }

    // Tab title notification system
    let originalTitle = document.title;
    let titleFlashInterval = null;
    let currentUnreadCount = 0; // live count the flash interval reads, so it never goes stale

    function updateTabTitle(totalUnread) {
      currentUnreadCount = totalUnread;

      if (totalUnread > 0) {
        document.title = '(' + totalUnread + ') ' + originalTitle;
        // Flash title if tab is hidden
        if (document.hidden && !titleFlashInterval) {
          let toggled = false;
          titleFlashInterval = setInterval(() => {
            // Read currentUnreadCount live instead of the totalUnread closure
            // argument, so the count stays accurate as new messages arrive
            // while the tab remains hidden.
            document.title = toggled ? '(' + currentUnreadCount + ') ' + originalTitle : 'New message!';
            toggled = !toggled;
          }, 1200);
        }
      } else {
        document.title = originalTitle;
        if (titleFlashInterval) { clearInterval(titleFlashInterval); titleFlashInterval = null; }
      }
    }

    // Stop flashing when user returns to tab
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && titleFlashInterval) {
        clearInterval(titleFlashInterval);
        titleFlashInterval = null;
      }
    });

    function markRead(targetUsername) {
      // Immediately zero out in local data so badge disappears instantly
      const u = allUsersData.find(u => u.username === targetUsername);
      if (u) u.unreadCount = 0;
      renderSidebarUsers();

      const targetId = activeDMAccountId || 0;

      // Fast path: relay over the already-open WebSocket, same as typing
      // indicators — no new HTTP connection, no debounce, delivered to the
      // other participant's live socket the instant this fires. Deliberately
      // sends no last_msg_uuid: trying to compute one here from our own
      // chatBox is unreliable (e.g. right when a new message just arrived
      // via WS it hasn't been rendered into the DOM yet, and right after
      // selectDM() the previous conversation's messages are still on
      // screen). The receiving side fills in the correct id from its own
      // chatBox instead — see the 'message_read' handler below.
      if (ws && ws.readyState === WebSocket.OPEN && targetId) {
        ws.send(JSON.stringify({ type: 'mark_read', target_id: targetId }));
      }

      // Durable path: persist to Postgres via HTTP so the correct read
      // marker survives reloads / other tabs / the WS relay above being
      // missed during a brief reconnect gap. This also re-broadcasts
      // 'message_read' with the DB-confirmed last_msg_uuid shortly after,
      // which harmlessly reconciles the indicator if the optimistic
      // WS-only value above ever guessed wrong.
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'mark_read.php', true);
      xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
      xhr.send('target_id=' + encodeURIComponent(targetId) + '&target_user=' + encodeURIComponent(targetUsername));
    }

    // Messenger-style "Seen" indicator: shown under the newest message WE sent
    // that the other participant has actually read (dmReadUpTo). Re-run this
    // any time dmReadUpTo changes or the message list is re-rendered — it's
    // cheap (one DOM query over the currently-loaded page) and always fully
    // recomputes rather than trying to patch the previous position, so it can
    // never end up stuck under a stale message.
    function updateSeenIndicator() {
      const existing = chatBox.querySelector('.seen-indicator');
      if (existing) existing.remove();

      if (!dmReadUpTo || !activeDM || isGlobalChat) return;

      // msg_uuid values are fixed-width hex ("msg_" + 10 hex ms + 6 hex rnd),
      // so plain string comparison sorts them the same as chronological order.
      const sentMessages = chatBox.querySelectorAll('.message-container.sent[data-msg-id]');
      let target = null;
      sentMessages.forEach(el => {
        const id = el.getAttribute('data-msg-id');
        if (id && id <= dmReadUpTo) target = el; // keep the newest qualifying one
      });
      if (!target) return;

      const indicator = document.createElement('div');
      indicator.className = 'seen-indicator';
      indicator.textContent = 'Seen';
      indicator.style.cssText = 'font-size:11px;color:var(--text-secondary);text-align:right;padding:2px 12px 6px 0;opacity:0.85;';
      target.insertAdjacentElement('afterend', indicator);
    }

    // State for global chat
    let isGlobalChat = false;
    // How many messages are fetched per page AND how many are kept on screen at
    // once. Loading an older page swaps the window rather than growing it
    // indefinitely — the newest messages get trimmed off the bottom to make
    // room, and clicking "Go to bottom" snaps back to the latest PAGE_SIZE.
    const PAGE_SIZE = 100;
    let gcCursor = '';
    let gcHasMore = false;
    let gcViewingOlder = false; // true once the user has loaded an older window
    let dmCursor  = '';
    let dmHasMore = false;
    let dmViewingOlder = false; // true once the user has loaded an older window
    // msg_uuid of the newest message the OTHER participant has read, or null.
    // Drives the Messenger-style "Seen" indicator under our own last-read sent message.
    let dmReadUpTo = null;

    function selectDM(u) {
      isGlobalChat = false;
      activeDM = u.username;
      activeDMAccountId = Number(u.account_id);
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      dmCursor = '';
      dmHasMore = false;
      dmViewingOlder = false;
      dmReadUpTo = null;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      
      // Reset local typing indicator state
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
      showTypingIndicator('', false);

      isFirstLoad = true; // snap straight to bottom once the new conversation's messages arrive
      localStorage.setItem('activeDM', u.username);
      chatHeaderTitle.textContent = u.name;
      // Note: we deliberately don't blank chatBox here. The previous chat's
      // messages stay on screen (harmlessly) until loadChat's diff logic swaps
      // them out the instant the new conversation's data arrives. Clearing it
      // immediately, combined with loadChat's old guard silently dropping the
      // request if one was already in flight, is what caused the chat pane to
      // flash blank repeatedly when clicking between conversations quickly.
      removePaginationBtn();
      markRead(u.username);
      renderSidebarUsers();
      loadChat(false, false, true); // force: abort any in-flight request rather than drop this one
      // Global Chat item deactivate
      document.getElementById('globalChatItem').classList.remove('active');
      
      // Mobile/Tablet: hide sidebar when chat is selected
      if (window.innerWidth <= 991) {
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
        burgerButton.style.display = 'none';
        backButton.style.display = 'inline-flex';
      } else {
        // Desktop only: auto-focus the message input so the cursor is ready
        // without an extra click. Skipped on mobile so opening a conversation
        // doesn't immediately pop the on-screen keyboard over half the chat.
        setTimeout(() => messageInput.focus(), 0);
      }
    }

    function selectGlobalChat() {
      isGlobalChat = true;
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      gcCursor = '';
      gcHasMore = false;
      gcViewingOlder = false;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      isFirstLoad = true;
      
      // Reset local typing indicator state
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
      showTypingIndicator('', false);

      localStorage.setItem('activeDM', '__global__');
      chatHeaderTitle.innerHTML = `Global Chat`;
      chatBox.innerHTML = '';

      removePaginationBtn();
      renderSidebarUsers();
      document.getElementById('globalChatItem').classList.add('active');
      loadGlobalChat();
      if (window.innerWidth <= 991) {
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
        burgerButton.style.display = 'none';
        backButton.style.display = 'inline-flex';
      } else {
        // Desktop only: auto-focus the message input so the cursor is ready
        // without an extra click. Skipped on mobile so opening a conversation
        // doesn't immediately pop the on-screen keyboard over half the chat.
        setTimeout(() => messageInput.focus(), 0);
      }
    }

    // Track whether older messages are available for the current chat
    let hasOlderMessages = false;

    // Show/hide the floating load-older button based on scroll position + availability
    function syncLoadOlderBtn() {
      if (!loadOlderFloatingBtn) return;
      if (hasOlderMessages && userScrolledUp) {
        loadOlderFloatingBtn.classList.add('visible');
      } else {
        loadOlderFloatingBtn.classList.remove('visible');
      }
    }

    function removePaginationBtn() {
      hasOlderMessages = false;
      syncLoadOlderBtn();
      const existing = document.getElementById('loadOlderBtn');
      if (existing) existing.remove();
      const notice = document.getElementById('noMoreOlderNotice');
      if (notice) notice.remove();
    }

    function showNoMoreOlderNotice() {
      removePaginationBtn();
      const notice = document.createElement('div');
      notice.id = 'noMoreOlderNotice';
      notice.style.cssText = `
        display:block;text-align:center;width:calc(100% - 32px);margin:10px 16px;
        padding:8px 16px;color:var(--text-secondary);font-size:12.5px;font-weight:500;
      `;
      notice.textContent = 'No older messages';
      chatBox.insertBefore(notice, chatBox.firstChild);
    }

    // Compares a freshly-fetched "latest window" of messages (newMessages) against
    // everything currently rendered on screen (currentMessages, which may include
    // older messages the user pulled in via "Load Older Messages"). Rather than
    // blindly replacing the whole chat box on every poll — which used to wipe out
    // any older messages the user had already loaded — this finds the longest
    // overlap between the tail of what's on screen and the head of what's fresh,
    // and only appends the genuinely new trailing messages.
    function reconcilePoll(newMessages, currentMessages, newKeys, curKeys) {
      if (newKeys.join('~~') === curKeys.join('~~')) {
        return { type: 'nochange' };
      }
      const maxL = Math.min(curKeys.length, newKeys.length);
      for (let L = maxL; L > 0; L--) {
        const curTail = curKeys.slice(curKeys.length - L).join('~~');
        const newHead = newKeys.slice(0, L).join('~~');
        if (curTail === newHead) {
          return { type: 'append', overlap: L, items: newMessages.slice(L) };
        }
      }
      return { type: 'replace' };
    }

    // Mark that older messages exist — visibility is driven by syncLoadOlderBtn()
    function insertLoadOlderBtn() {
      hasOlderMessages = true;
      syncLoadOlderBtn();
    }

    // Keeps the chat window capped at maxCount messages by trimming the
    // trailing (newest/bottom) ones — used right after prepending an older
    // page so loading history swaps the window instead of growing it forever.
    function trimWindowFromBottom(maxCount) {
      const items = Array.from(chatBox.querySelectorAll('.message-container, .empty-chat'));
      if (items.length <= maxCount) return;
      const excess = items.length - maxCount;
      for (let i = 0; i < excess; i++) {
        const el = items[items.length - 1 - i];
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
    }

    // Keeps the chat window capped at maxCount messages by trimming the
    // leading (oldest/top) ones — used during normal poll / initial load
    // so the message list doesn't grow forever.
    function trimWindowFromTop(maxCount) {
      const items = Array.from(chatBox.querySelectorAll('.message-container, .empty-chat'));
      if (items.length <= maxCount) return;
      const excess = items.length - maxCount;
      for (let i = 0; i < excess; i++) {
        const el = items[i];
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
    }

    // ── Admin: render all conversations spy panel (Search-First Architecture) ──
    const adminConvItems = new Map(); // convId/userId -> item element
    let adminSpyType = 'none';        // 'none', 'users', or 'conversations'
    let adminSpyTargetUser = null;    // null or selected user object { account_id, full_name, email, ... }
    let adminSpyUsers = [];           // array of user search result objects
    let adminSpyConvs = [];           // array of conversation objects for target user
    let adminSpyHasMore = false;
    let adminSpyOffset = 0;
    let adminSpyIsLoading = false;
    let adminSearchTimeout = null;

    function getInitialsFromFullName(name) {
      if (!name) return '??';
      const parts = name.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fetchAdminConvs(query = '', offset = 0, isAppend = false, targetId = 0) {
      // Use isAdmin (available synchronously from PHP on page load) as a fallback,
      // since serverIsAdmin is only confirmed later via an async AJAX response —
      // without this fallback, a call made during the initial page load (e.g. when
      // restoring a persisted spy-mode view on refresh) would bail out too early
      // and never render the spy-mode search panel.
      if (!serverIsAdmin && !isAdmin) return;

      const trimmedQuery = query.trim();
      const currentTargetId = targetId || (adminSpyTargetUser ? adminSpyTargetUser.account_id : 0);

      // If no query and no target user selected: empty state, zero network calls
      if (trimmedQuery === '' && currentTargetId === 0) {
        adminSpyType = 'none';
        adminSpyUsers = [];
        adminSpyConvs = [];
        adminSpyHasMore = false;
        adminSpyOffset = 0;
        adminSpyTargetUser = null;
        renderAdminConvs();
        return;
      }

      adminSpyIsLoading = true;
      const xhr = new XMLHttpRequest();
      let url = "fetch_users_dm.php?spy_mode=1";

      if (currentTargetId > 0) {
        url += "&admin_target_id=" + currentTargetId;
      } else {
        url += "&admin_q=" + encodeURIComponent(trimmedQuery);
      }

      xhr.open("GET", url, true);
      xhr.onload = function() {
        adminSpyIsLoading = false;
        if (this.status === 200) {
          try {
            const data = JSON.parse(this.responseText);
            const adminData = data.adminConvs || {};
            adminSpyType = adminData.type || 'none';
            adminSpyHasMore = !!adminData.hasMore;

            if (adminSpyType === 'users') {
              adminSpyUsers = adminData.users || [];
            } else if (adminSpyType === 'conversations') {
              if (adminData.targetUser) {
                adminSpyTargetUser = adminData.targetUser;
              }
              adminSpyConvs = adminData.conversations || [];
            }

            renderAdminConvs();
          } catch(e) { console.error('fetchAdminConvs parse error', e); }
        }
      };
      xhr.onerror = function() { adminSpyIsLoading = false; };
      xhr.send();
    }

    function selectAdminSpyTargetUser(user) {
      adminSpyTargetUser = user;
      adminSpyConvs = [];
      fetchAdminConvs('', 0, false, user.account_id);
    }

    function clearAdminSpyTargetUser() {
      adminSpyTargetUser = null;
      adminSpyConvs = [];
      const query = adminSearchInput ? adminSearchInput.value.trim() : '';
      if (query !== '') {
        fetchAdminConvs(query, 0, false, 0);
      } else {
        adminSpyType = 'none';
        adminSpyUsers = [];
        renderAdminConvs();
      }
    }

    function renderAdminConvs() {
      const section = document.getElementById('adminConvsSection');
      const list    = document.getElementById('adminConvsList');
      const headerTitle = document.getElementById('adminConvsHeaderTitle');
      if (!section || !list) return;

      if (!isAdminAllChatsView) {
        section.style.display = 'none';
        list.innerHTML = '';
        adminConvItems.clear();
        return;
      }

      section.style.display = 'flex';

      // Update Section Header Title
      if (headerTitle) {
        if (adminSpyTargetUser) {
          headerTitle.innerHTML = `<div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:6px;">
            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Conversations for: ${escapeHtml(adminSpyTargetUser.full_name)}</span>
            <button onclick="clearAdminSpyTargetUser()" style="background:none;border:none;color:#1b74e4;cursor:pointer;font-size:11px;font-weight:600;padding:2px 6px;border-radius:4px;white-space:nowrap;">← Back</button>
          </div>`;
        } else {
          headerTitle.textContent = '';
        }
      }

      // State A: Empty initial state (No search query, no target user)
      if (adminSpyType === 'none') {
        list.innerHTML = `<div class="sidebar-empty-state" style="padding:32px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin-bottom:8px;opacity:0.6;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <p style="margin:0;font-weight:500;">Search for a user or office to spy on.</p>
        </div>`;
        adminConvItems.clear();
        return;
      }

      // State B: Render User Search Results (Max 10)
      if (adminSpyType === 'users') {
        if (!adminSpyUsers || adminSpyUsers.length === 0) {
          const q = adminSearchInput ? adminSearchInput.value.trim() : '';
          list.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No users found matching "${escapeHtml(q)}".</p></div>`;
          adminConvItems.clear();
          return;
        }

        const emptyEl = list.querySelector('.sidebar-empty-state, .empty-chat');
        if (emptyEl) emptyEl.remove();

        const seen = new Set();
        adminSpyUsers.forEach(u => {
          const key = 'user_' + u.account_id;
          seen.add(key);
          let item = adminConvItems.get(key);
          let nameEl, msgEl;

          if (!item) {
            item = document.createElement('div');
            item.className = 'user-item';

            const avatar = document.createElement('div');
            avatar.className = 'user-avatar';
            avatar.style.background = 'linear-gradient(135deg, #1b74e4, #00c3ff)';
            avatar.textContent = getInitialsFromFullName(u.full_name);

            const info = document.createElement('div');
            info.className = 'user-info';

            nameEl = document.createElement('div');
            nameEl.className = 'user-name';
            nameEl.style.fontSize = '13px';
            info.appendChild(nameEl);

            msgEl = document.createElement('div');
            msgEl.className = 'user-last-msg';
            info.appendChild(msgEl);

            item.appendChild(avatar);
            item.appendChild(info);

            item._nameEl = nameEl;
            item._msgEl = msgEl;

            adminConvItems.set(key, item);
          } else {
            nameEl = item._nameEl || item.querySelector('.user-name');
            msgEl = item._msgEl || item.querySelector('.user-last-msg');
          }

          item.onclick = () => selectAdminSpyTargetUser(u);

          if (nameEl.textContent !== u.full_name) nameEl.textContent = u.full_name;

          let subText = u.email || '';
          if (u.office_code) subText += ' • ' + u.office_code;
          else if (u.office_name) subText += ' • ' + u.office_name;

          if (msgEl.textContent !== subText) msgEl.textContent = subText;

          list.appendChild(item);
        });

        for (const [key, item] of adminConvItems) {
          if (!seen.has(key)) {
            item.remove();
            adminConvItems.delete(key);
          }
        }

        const existingNotice = list.querySelector('.search-limit-notice');
        if (existingNotice) existingNotice.remove();

        if (adminSpyHasMore) {
          const notice = document.createElement('div');
          notice.className = 'search-limit-notice';
          notice.style.cssText = 'padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center;font-weight:500;border-top:1px dashed var(--border-color);margin-top:4px;';
          notice.textContent = 'Showing the first 10 matches. Enter a more specific search term.';
          list.appendChild(notice);
        }
        return;
      }

      // State C: Render Selected User's Conversations (Max 50 latest)
      if (adminSpyType === 'conversations') {
        if (!adminSpyConvs || adminSpyConvs.length === 0) {
          const name = adminSpyTargetUser ? adminSpyTargetUser.full_name : 'selected user';
          list.innerHTML = `<div class="sidebar-empty-state" style="padding:24px 16px;text-align:center;font-size:13px;color:var(--text-secondary);opacity:0.85;font-weight:500;"><p style="margin:0;">No active conversations found for ${escapeHtml(name)}.</p></div>`;
          adminConvItems.clear();
          return;
        }

        const emptyEl = list.querySelector('.sidebar-empty-state, .empty-chat');
        if (emptyEl) emptyEl.remove();

        const seen = new Set();
        adminSpyConvs.forEach(c => {
          const key = 'conv_' + c.convId;
          seen.add(key);
          let item = adminConvItems.get(key);
          let nameEl, msgEl;

          if (!item) {
            item = document.createElement('div');

            const avatar = document.createElement('div');
            avatar.className = 'user-avatar';
            avatar.innerHTML = EYE_ICON_SVG;

            const info = document.createElement('div');
            info.className = 'user-info';

            nameEl = document.createElement('div');
            nameEl.className = 'user-name';
            nameEl.style.fontSize = '13px';
            info.appendChild(nameEl);

            msgEl = document.createElement('div');
            msgEl.className = 'user-last-msg';
            info.appendChild(msgEl);

            item.appendChild(avatar);
            item.appendChild(info);

            item._nameEl = nameEl;
            item._msgEl = msgEl;

            adminConvItems.set(key, item);
          } else {
            nameEl = item._nameEl || item.querySelector('.user-name');
            msgEl = item._msgEl || item.querySelector('.user-last-msg');
          }

          item.onclick = () => openAdminConv(c);

          const newClassName = 'user-item' + (activeAdminConv === c.convId ? ' active' : '');
          if (item.className !== newClassName) item.className = newClassName;

          const nameDisplay = c.name1 + ' ↔ ' + c.name2;
          if (nameEl.textContent !== nameDisplay) nameEl.textContent = nameDisplay;

          const newMsg = (c.msgCount || 1) + ' msg' + (c.msgCount !== 1 ? 's' : '') + (c.lastMessage ? ' · ' + c.lastMessage : '');
          if (msgEl.textContent !== newMsg) msgEl.textContent = newMsg;

          list.appendChild(item);
        });

        for (const [key, item] of adminConvItems) {
          if (!seen.has(key)) {
            item.remove();
            adminConvItems.delete(key);
          }
        }

        const existingNotice = list.querySelector('.search-limit-notice');
        if (existingNotice) existingNotice.remove();

        if (adminSpyHasMore) {
          const notice = document.createElement('div');
          notice.className = 'search-limit-notice';
          notice.style.cssText = 'padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center;font-weight:500;border-top:1px dashed var(--border-color);margin-top:4px;';
          notice.textContent = 'Showing the first 50 matches. Enter a more specific search term.';
          list.appendChild(notice);
        }
      }
    }

    if (adminSearchInput) {
      adminSearchInput.addEventListener('input', () => {
        if (adminSearchTimeout) clearTimeout(adminSearchTimeout);
        adminSpyTargetUser = null; // Reset user selection when typing new search
        const query = adminSearchInput.value.trim();

        // Keep the search box's contents across refreshes
        if (query === '') {
          localStorage.removeItem('__adminSearchQuery__');
        } else {
          localStorage.setItem('__adminSearchQuery__', query);
        }

        adminSearchTimeout = setTimeout(() => {
          fetchAdminConvs(query, 0, false, 0);
        }, 250);
      });
    }



    let activeAdminConv = null; // convId string when admin is spying

    // ── Contextual "Clear Chat" header button (Super Admin Spy Mode only) ──────
    // Visible only while isAdmin is true AND a specific spied conversation is
    // loaded (activeAdminConv set). Hidden for the all-conversations list view,
    // when no conversation is selected, and for non-admins. Call this any time
    // activeAdminConv changes so the button stays in sync without a refresh.
    function updateClearChatButtonVisibility() {
      const btn = document.getElementById('clearChatHeaderBtn');
      if (!btn) return;
      btn.style.display = (typeof isAdmin !== 'undefined' && isAdmin && !!activeAdminConv) ? 'inline-flex' : 'none';
    }
    let adminConvCursor = '';
    let adminConvHasMore = false;
    let adminConvViewingOlder = false; // true once the user has loaded an older window
    let isLoadingAdminConv = false;   // separate flag so admin spy never blocks DM loads
    let adminConvXhr = null;          // track in-flight XHR so stale responses can be discarded

    function loadAdminConv(convId, isAutoPoll = false, loadOlderMode = false) {
      if (isAutoPoll && !loadOlderMode && adminConvViewingOlder) return;
      if (isLoadingAdminConv) {
        // For non-poll (explicit open) calls, abort any in-flight request and proceed
        if (!isAutoPoll && adminConvXhr) { adminConvXhr.abort(); adminConvXhr = null; isLoadingAdminConv = false; }
        else return;
      }
      isLoadingAdminConv = true;

      const wasAtBottom = isAtBottom();
      const requestedConv = activeAdminConv;
      const cursor = loadOlderMode ? adminConvCursor : '';
      const url = 'load_dm_admin.php?conv_id=' + encodeURIComponent(convId) + '&before_uuid=' + encodeURIComponent(cursor);

      const xhr = new XMLHttpRequest();
      adminConvXhr = xhr;
      xhr.open('GET', url, true);
      xhr.onload = function() {
        isLoadingAdminConv = false;
        if (adminConvXhr === xhr) adminConvXhr = null;
        if (this.status !== 200) return;
        if (requestedConv !== activeAdminConv) return; // stale response
        
        let data;
        try {
          data = JSON.parse(this.responseText);
        } catch(e) {
          return;
        }
        
        const newHtml = data.html || '';
        adminConvHasMore = data.hasMore || false;
        
        if (loadOlderMode) {
          adminConvCursor = data.nextCursor || '';
          adminConvViewingOlder = true;
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
          if (!adminConvHasMore) showNoMoreOlderNotice(); else if (!document.getElementById('loadOlderBtn')) insertLoadOlderBtn();
          applyAdminBadges();
          applyEmojiOnly();
          return;
        }

        if (!newHtml.trim()) {
          chatBox.innerHTML = '';
          isFirstLoad = false;
          return;
        }

        if (adminConvCursor === '') adminConvCursor = data.nextCursor || ''; // establish cursor pointer
        const temp = document.createElement('div');
        temp.innerHTML = newHtml;
        const newMessages = Array.from(temp.querySelectorAll('.message-container, .empty-chat'));
        const currentMessages = Array.from(chatBox.querySelectorAll('.message-container:not([data-sending-uid]):not([data-upload-uid]), .empty-chat'));
        const newKeys = newMessages.map(getMessageKey);
        const curKeys = currentMessages.map(getMessageKey);

        const rec = reconcilePoll(newMessages, currentMessages, newKeys, curKeys);

        if (rec.type === 'nochange') {
          if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
          if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
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
          if (!adminConvViewingOlder) {
            trimWindowFromTop(PAGE_SIZE);
          }
          if (isFirstLoad) { isFirstLoad = false; handleFirstLoadScroll(); }
          else if (wasAtBottom || shouldAutoScroll) requestAnimationFrame(() => requestAnimationFrame(() => scrollToBottom(true, false)));
          else showScrollIndicator(rec.items.filter(el => el.classList.contains('message-container')).length);
          applyAdminBadges();
          applyEmojiOnly();
          if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
          return;
        }

        // Full re-render
        const prevSTF = chatBox.scrollTop; const prevSHF = chatBox.scrollHeight;
        const curKeySetF = new Set(curKeys);
        const genuinelyNewCountF = newMessages.filter(el =>
          el.classList.contains('message-container') && !curKeySetF.has(getMessageKey(el))
        ).length;
        currentMessages.forEach(el => el.remove());

        // Deduplicate newMessages during full re-render
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
        applyAdminBadges();
        applyEmojiOnly();
        adminConvCursor = data.nextCursor || '';
        adminConvViewingOlder = false;
        if (adminConvHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
      };
      xhr.onerror = function() { isLoadingAdminConv = false; adminConvXhr = null; };
      xhr.send();
    }

    function openAdminConv(c) {
      activeAdminConv = c.convId;
      updateClearChatButtonVisibility();
      isGlobalChat = false; // must reset — otherwise polling/visibilitychange keep re-loading Global Chat over the spy view
      
      adminConvCursor = '';
      adminConvHasMore = false;
      adminConvViewingOlder = false;
      clearSendingOverlay(); // drop any pending-send bubbles from the previous conversation
      hideEditBanner();
      isFirstLoad = true;

      // Reset local typing indicator state
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
      showTypingIndicator('', false);

      // Persist spied conversation separately so activeDM is never lost
      localStorage.setItem('activeSpyConv', c.convId);

      chatHeaderTitle.textContent = c.name1 + ' & ' + c.name2;
      chatBox.innerHTML = '<div class="empty-chat"><p>Loading...</p></div>';
      
      removePaginationBtn();
      renderAdminConvs();
      loadAdminConv(c.convId, false);

      if (window.innerWidth <= 991) {
        sidebar.classList.remove('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.remove('visible');
        burgerButton.style.display = 'none';
        backButton.style.display = 'inline-flex';
      }
    }
    
    let searchTimeout = null;
    searchInput.addEventListener('input', () => {
      if (searchTimeout) clearTimeout(searchTimeout);
      const query = searchInput.value.trim();

      // Keep the search box's contents across refreshes
      if (query === '') {
        localStorage.removeItem('__searchQuery__');
      } else {
        localStorage.setItem('__searchQuery__', query);
      }

      if (query === '') {
        fetchUsers();
      } else {
        searchTimeout = setTimeout(() => {
          fetchUsers(query);
        }, 250);
      }
    });

    backButton.addEventListener('click', () => {
      activeDM = null;
      activeDMAccountId = null;
      activeAdminConv = null;
      updateClearChatButtonVisibility();
      isGlobalChat = false;
      
      // Reset local typing indicator state
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
      showTypingIndicator('', false);

      localStorage.removeItem('activeDM');

      removePaginationBtn();
      if (window.innerWidth <= 991) {
        sidebar.classList.add('open');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (backdrop) backdrop.classList.add('visible');
        backButton.style.display = 'none';
        burgerButton.style.display = 'inline-flex';
      }
      chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
      document.getElementById('globalChatItem').classList.remove('active');
      renderSidebarUsers();
      if (serverIsAdmin) renderAdminConvs();
    });

    // Helper: get or create the floating sending overlay container
    function getSendingOverlay() {
      let overlay = document.getElementById('sending-overlay-container');
      if (!overlay) {
        const inputArea = document.querySelector('.input-area');
        if (!inputArea) return null;
        overlay = document.createElement('div');
        overlay.id = 'sending-overlay-container';
        inputArea.appendChild(overlay);
      }
      return overlay;
    }

    // Clear all pending send bubbles when switching conversations so they
    // don't bleed into the freshly-opened chat pane.
    function clearSendingOverlay() {
      const overlay = document.getElementById('sending-overlay-container');
      if (overlay) overlay.innerHTML = '';
      // Also sweep any stragglers that landed directly in chatBox
      document.querySelectorAll('[data-sending-uid]').forEach(el => el.remove());
    }

    // Get the user's name and admin status from PHP
    const userName = "<?php echo addslashes($user_name); ?>";
    const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;

    // ── Admin: eye-button toggle for "all users chatting" view ──────────────
    const adminEyeToggleBtn = document.getElementById('adminEyeToggleBtn');
    const adminKeyToggleBtn = document.getElementById('adminKeyToggleBtn');
    const ownSidebarSearch = document.getElementById('ownSidebarSearch');
    let isAdminAllChatsView = (localStorage.getItem('__adminAllChatsView__') === '1');
    let preSpyDM = localStorage.getItem('__preSpyDM__') || null;        // activeDM to restore when leaving the all-conversations spy view
    let preSpyIsGlobal = (localStorage.getItem('__preSpyIsGlobal__') === '1'); // isGlobalChat to restore when leaving the all-conversations spy view

    function applyAdminAllChatsView() {
      if (adminEyeToggleBtn) {
        adminEyeToggleBtn.classList.toggle('active', isAdminAllChatsView);
      }
      // Hide the admin's own conversation list/search while browsing all users' chats
      const globalChatItemEl = document.getElementById('globalChatItem');
      if (ownSidebarSearch) ownSidebarSearch.style.display = isAdminAllChatsView ? 'none' : '';
      if (globalChatItemEl) globalChatItemEl.style.display = isAdminAllChatsView ? 'none' : '';
      if (sidebarUsers) sidebarUsers.style.display = isAdminAllChatsView ? 'none' : '';

      const inputSection = document.querySelector('.input-section');
      const chatForm = document.getElementById('chatForm');
      const spyNotice = document.getElementById('spyModeNotice');
      if (inputSection) inputSection.style.display = isAdminAllChatsView ? 'none' : '';
      if (chatForm) chatForm.style.display = isAdminAllChatsView ? 'none' : '';
      if (spyNotice) spyNotice.style.display = isAdminAllChatsView ? 'flex' : 'none';

      if (isAdminAllChatsView) {
        fetchAdminConvs(adminSearchInput ? adminSearchInput.value.trim() : '', 0, false);
      } else {
        renderAdminConvs();
      }
    }

    if (adminEyeToggleBtn) {
      if (isAdmin) adminEyeToggleBtn.style.display = 'inline-flex';
      adminEyeToggleBtn.addEventListener('click', () => {
        const turningOn = !isAdminAllChatsView;

        if (turningOn) {
          // Entering spy view — remember activeDM and isGlobalChat to restore upon exit
          preSpyDM = activeDM;
          preSpyIsGlobal = isGlobalChat;
          localStorage.setItem('__preSpyDM__', activeDM || '');
          localStorage.setItem('__preSpyIsGlobal__', isGlobalChat ? '1' : '0');

          // Close/clear previously opened conversation
          activeDM = null;
          activeDMAccountId = null;
          isGlobalChat = false;
          activeAdminConv = null;
          updateClearChatButtonVisibility();
          localStorage.removeItem('activeSpyConv');
          
          chatHeaderTitle.textContent = '';
          removePaginationBtn();
          chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';

          isAdminAllChatsView = true;
          localStorage.setItem('__adminAllChatsView__', '1');
          applyAdminAllChatsView();
        } else {
          // Leaving spy view — restore the admin's conversation
          const currentSpyConv = activeAdminConv || localStorage.getItem('activeSpyConv');

          isAdminAllChatsView = false;
          localStorage.setItem('__adminAllChatsView__', '0');
          activeAdminConv = null;
          updateClearChatButtonVisibility();
          localStorage.removeItem('activeSpyConv');

          // Wipe chatBox innerHTML so reconcilePoll doesn't retain old spy-mode DOM nodes ("one liner")
          chatBox.innerHTML = '';
          isFirstLoad = true;

          applyAdminAllChatsView();

          let savedAdminDM = preSpyDM || localStorage.getItem('__preSpyDM__');
          let savedIsGlobal = preSpyIsGlobal || (localStorage.getItem('__preSpyIsGlobal__') === '1');

          // Fallback: If no preSpyDM was set, but admin was viewing a spied conversation in spy mode
          if (!savedAdminDM && !savedIsGlobal && currentSpyConv) {
            const parts = String(currentSpyConv).split('_').map(Number);
            if (parts.length === 2) {
              const spiedUser = (allUsersData || []).find(u => 
                (Number(u.account_id) === parts[0] || Number(u.account_id) === parts[1]) &&
                Number(u.account_id) !== Number(myAccountId || 0)
              );
              if (spiedUser) {
                savedAdminDM = spiedUser.username;
              }
            }
          }

          // Fallback: check activeDM in localStorage if not starting with __admin__
          if (!savedAdminDM && !savedIsGlobal) {
            let locDM = localStorage.getItem('activeDM');
            if (locDM === '__global__') savedIsGlobal = true;
            else if (locDM && !locDM.startsWith('__admin__')) savedAdminDM = locDM;
          }

          if (savedIsGlobal) {
            selectGlobalChat();
          } else if (savedAdminDM && savedAdminDM !== '' && !savedAdminDM.startsWith('__admin__')) {
            const matchedUser = (allUsersData || []).find(u => u.username === savedAdminDM);
            if (matchedUser) {
              selectDM(matchedUser);
            } else {
              activeDM = null; activeDMAccountId = null;
              localStorage.removeItem('activeDM');
              chatHeaderTitle.textContent = '';
              removePaginationBtn();
              chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
            }
          } else {
            activeDM = null; activeDMAccountId = null;
            localStorage.removeItem('activeDM');
            chatHeaderTitle.textContent = '';
            removePaginationBtn();
            chatBox.innerHTML = '<div class="empty-chat"><p>Camarines Sur Polytechnic Colleges</p></div>';
          }

          // Clean up pre-spy state
          preSpyDM = null;
          preSpyIsGlobal = false;
          localStorage.removeItem('__preSpyDM__');
          localStorage.removeItem('__preSpyIsGlobal__');
        }
      });
    }
    if (!isAdmin) isAdminAllChatsView = false;
    applyAdminAllChatsView();
    updateClearChatButtonVisibility(); // activeAdminConv is always null at this point (never persisted across refresh), so this hides the button by default

    // ── Admin: Secret Key Change Modal & Handler ──────────────────────────────
    const adminKeyModal = document.getElementById('adminKeyModal');
    const adminKeyCancelBtn = document.getElementById('adminKeyCancelBtn');

    if (adminKeyToggleBtn) {
      if (isAdmin) adminKeyToggleBtn.style.display = 'inline-flex';
      adminKeyToggleBtn.addEventListener('click', () => {
        openAdminKeyModal();
      });
    }

    function openAdminKeyModal() {
      if (!adminKeyModal) return;
      document.getElementById('currentSecretInput').value = '';
      document.getElementById('newSecretInput').value = '';
      document.getElementById('confirmNewSecretInput').value = '';
      const errDiv = document.getElementById('adminKeyError');
      const succDiv = document.getElementById('adminKeySuccess');
      if (errDiv) errDiv.style.display = 'none';
      if (succDiv) succDiv.style.display = 'none';
      adminKeyModal.classList.add('active');
      adminKeyModal.setAttribute('aria-hidden', 'false');
    }

    function closeAdminKeyModal() {
      if (adminKeyModal) {
        adminKeyModal.classList.remove('active');
        adminKeyModal.setAttribute('aria-hidden', 'true');
      }
    }

    if (adminKeyCancelBtn) adminKeyCancelBtn.addEventListener('click', closeAdminKeyModal);

    function submitSecretKeyForm() {
      const form = document.getElementById('adminKeyForm');
      if (form) {
        const event = new Event('submit', { cancelable: true });
        form.dispatchEvent(event);
      }
    }

    function handleSecretKeyUpdate(event) {
      event.preventDefault();
      const currentKey = document.getElementById('currentSecretInput').value.trim();
      const newKey = document.getElementById('newSecretInput').value.trim();
      const confirmKey = document.getElementById('confirmNewSecretInput').value.trim();
      const errDiv = document.getElementById('adminKeyError');
      const succDiv = document.getElementById('adminKeySuccess');
      const submitBtn = document.getElementById('adminKeySubmitBtn');

      if (errDiv) errDiv.style.display = 'none';
      if (succDiv) succDiv.style.display = 'none';

      if (!currentKey || !newKey || !confirmKey) {
        if (errDiv) { errDiv.textContent = 'All fields are required.'; errDiv.style.display = 'block'; }
        return;
      }

      if (newKey !== confirmKey) {
        if (errDiv) { errDiv.textContent = 'New secret key and confirmation do not match.'; errDiv.style.display = 'block'; }
        return;
      }

      if (newKey.length < 3) {
        if (errDiv) { errDiv.textContent = 'New secret key must be at least 3 characters long.'; errDiv.style.display = 'block'; }
        return;
      }

      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Updating...'; }

      const formData = new FormData();
      formData.append('current_secret', currentKey);
      formData.append('new_secret', newKey);

      fetch('update_secret.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Update Key'; }

        if (data.success) {
          if (succDiv) { succDiv.textContent = data.message || 'Secret key updated successfully!'; succDiv.style.display = 'block'; }
          setTimeout(() => {
            closeAdminKeyModal();
          }, 1500);
        } else {
          if (errDiv) { errDiv.textContent = data.message || 'Failed to update secret key.'; errDiv.style.display = 'block'; }
        }
      })
      .catch(err => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Update Key'; }
        if (errDiv) { errDiv.textContent = 'Network or server error while updating secret key.'; errDiv.style.display = 'block'; }
      });
    }

    // Admin display names for verified badge (lowercased)
    const adminNames = <?php echo json_encode($admin_names); ?>;

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
      
      const text = contentEl.textContent.trim();
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

    function loadGlobalChat(isAutoPoll = false, loadOlderMode = false) {
      if (!isGlobalChat) return;
      if (isLoadingGC) return;
      // While the user is browsing an older window they loaded manually, silent
      // background polls must not touch the chat box — otherwise the very next
      // poll would immediately clobber the older messages they just pulled in.
      // The view only resyncs to the latest window when the user explicitly
      // asks for it (clicking "Go to bottom").
      if (isAutoPoll && !loadOlderMode && gcViewingOlder) return;
      isLoadingGC = true;

      const wasAtBottom = isAtBottom();
      const cursor = loadOlderMode ? gcCursor : '';

      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'load.php?before_uuid=' + encodeURIComponent(cursor), true);
      xhr.onload = function() {
        isLoadingGC = false;
        if (this.status !== 200) return;
        let data;
        try { data = JSON.parse(this.responseText); } catch(e) { return; }
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

        // Normal poll / initial load
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
          return;
        }

        if (rec.type === 'append') {
          // --- YouTube Live Chat–style staggered reveal ---
          // Filter out dupes first, collecting only genuinely new elements.
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

          // If this is the very first load OR there's only 1 new message, skip
          // staggering and just render immediately for a snappy experience.
          const STAGGER_MS = 90;      // gap between each message appearing
          const MAX_STAGGER = 8;      // cap: beyond this many messages, no extra delay
          const useStagger = !isFirstLoad && toInsert.length > 1;

          // Append all elements to DOM immediately but keep them invisible
          // (gc-msg-pending) so they don't cause layout jank while waiting.
          toInsert.forEach(el => {
            if (useStagger && el.classList.contains('message-container')) {
              el.classList.add('gc-msg-pending');
            }
            chatBox.appendChild(el);
          });
          document.querySelectorAll('[data-sending-uid]').forEach(el => el.remove());
          if (!gcViewingOlder) trimWindowFromTop(PAGE_SIZE);

          // Reveal each message one-by-one with a staggered delay.
          // Finalization (scroll-to-bottom, badges, load-older button) fires once
          // every timer has completed, tracked via a counter — NOT via "is this
          // the last array index". Once MAX_STAGGER caps the delay, several
          // trailing messages share the exact same timeout value, so we can't
          // assume the highest-index element's timer is the one that resolves
          // last. A completion counter is correct regardless of firing order.
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
                applyAdminBadges(); applyEmojiOnly();
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
      };
      xhr.onerror = function() { isLoadingGC = false; };
      xhr.send();
    }

    // ── loadChat: fetches from load_dm.php with pagination ────────────────────
    // Tracks the in-flight request so a manual conversation switch (force=true)
    // can abort it instead of being silently dropped by the isLoadingChat guard
    // — that drop is what used to leave the chat pane blank (a visible "blink")
    // when the user clicked between conversations quickly, since nothing would
    // fill it back in until the next 2s auto-poll.
    let chatXhr = null;

    function loadChat(isAutoPoll = false, loadOlderMode = false, force = false) {
      if (isAdminAllChatsView || activeAdminConv) return;
      if (!activeDM) return;
      // While the user is browsing an older window they loaded manually, silent
      // background polls must not touch the chat box — otherwise the very next
      // poll would immediately clobber the older messages they just pulled in.
      // The view only resyncs to the latest window when the user explicitly
      // asks for it (clicking "Go to bottom").
      if (isAutoPoll && !loadOlderMode && dmViewingOlder) return;
      if (isLoadingChat) {
        if (!force) return;
        if (chatXhr) chatXhr.abort();
        isLoadingChat = false;
      }
      isLoadingChat = true;

      const wasAtBottom = isAtBottom();
      // Capture which conversation this request is for. If the user clicks to
      // a different conversation before this response comes back, we discard
      // the (now stale) result instead of rendering it into the wrong chat.
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
        if (requestedUser !== activeDM) return; // stale response for a conversation we've since left
        let data;
        try { data = JSON.parse(this.responseText); } catch(e) { return; }
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
          // Swap the window: drop the newest messages off the bottom so the
          // total on screen stays capped at PAGE_SIZE instead of growing forever.
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

        if (dmCursor === '') dmCursor = data.nextCursor || ''; // establish cursor pointer
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
          // Safety net: even when nothing new rendered (e.g. this reload was
          // triggered by the tab regaining visibility rather than a genuinely
          // new message), still sync the read marker while the chat is open
          // and visible — otherwise a read state bump can be missed whenever
          // the WS-triggered markRead() above didn't fire for some reason
          // (e.g. reconnect gap) and this poll finds no DOM diff to react to.
          if (!document.hidden && activeDM) markRead(activeDM);
          updateSeenIndicator();
          return;
        }

        if (rec.type === 'append') {
          rec.items.forEach(el => {
            if (el.classList.contains('message-container')) {
              const msgId = el.getAttribute('data-msg-id');
              if (msgId && chatBox.querySelector(`.message-container[data-msg-id="${msgId}"]`)) {
                // Deduplicate check: message ID already exists, do not render again
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
        
        // Deduplicate newMessages during full re-render
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
        applyAdminBadges(); applyEmojiOnly();
        if (!document.hidden && activeDM) markRead(activeDM);
        updateSeenIndicator();
        // Chat was rebuilt from scratch (e.g. cleared), so pagination state no longer applies
        dmCursor = data.nextCursor || '';
        dmViewingOlder = false;
        if (dmHasMore && !document.getElementById('loadOlderBtn') && !document.getElementById('noMoreOlderNotice')) insertLoadOlderBtn();
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

      const xhr = new XMLHttpRequest();
      xhr.open("POST", "delete_dm.php", true);
      xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      xhr.onload = function () {
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
          closeModal();

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
        } else {
          secretError.style.display = 'block';
          secretError.textContent = 'Error: ' + this.responseText;
          secretError.style.color = 'red';
        }
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

      // ── Super Admin chat commands: "/clear" and "/delete all" ──────────────
      // These are intercepted before anything else (including the admin-spy-mode
      // early return below) so that "/clear" works while Spy Mode is open on a
      // conversation. Commands are never sent as messages, never appended to
      // history, and never broadcast — they just open the existing confirmation
      // modals. All real permission checks still happen server-side exactly as
      // before (validate_secret.php / clear_all_dm.php / delete_dm.php via
      // Auth::isAdmin()); this is purely an alternate way to trigger the modals.
      if (isAdmin) {
        const cmd = messageInput.value.trim().toLowerCase();
        if (cmd === '/clear' || cmd === '/delete all') {
          messageInput.value = '';
          messageInput.style.height = 'auto';
          messageInput.style.color = '';
          if (cmd === '/clear') {
            showModal();
          } else {
            openDeleteAllModal();
          }
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
        // Only scroll if user was at bottom to avoid jarring jumps when overlay is used
        if (isAtBottom()) scrollToBottom(true, true);
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
              if (contentEl) contentEl.textContent = message;
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
                      <div class="message-info"><span class="message-sender">${senderLabel}</span></div>
                    </div>
                  </div>
                `;
                  applyAdminBadges();
                  if (isAtBottom()) scrollToBottom(true, true);
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
                msg_uuid: confirmedMsg ? confirmedMsg.id : null
              }));
            }
          }

          // Fallback only if confirmedMsg missing
          if (!confirmedMsg) {
            if (isGlobalChat) { isLoadingGC = false; loadGlobalChat(false); }
            else loadChatForced();
          }

          // Patch the sidebar locally instead of re-fetching the whole user
          // list — we already know exactly what changed: this conversation
          // moves to the top and shows this message as its preview. No
          // fetch_users_dm.php round trip needed for our own sent messages.
          if (!isGlobalChat && activeDM) {
            const sentText = (confirmedMsg && confirmedMsg.plaintext) ? confirmedMsg.plaintext : message;
            if (!bumpSidebarUser(activeDM, { lastMessage: sentText })) {
              // Conversation partner isn't in the currently loaded/filtered
              // sidebar list (e.g. still under a search filter) — the one
              // case that still needs a real fetch.
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

    // Super Admin command visual indicator: while typing exactly "/clear" or
    // "/delete all" (case-insensitive), color the whole input red so it's clear
    // it will be treated as a command. Purely cosmetic — has no bearing on
    // whether the command actually executes (that's still gated by isAdmin
    // above and by server-side Auth::isAdmin() checks on every endpoint).
    if (isAdmin) {
      messageInput.addEventListener('input', function() {
        const cmd = this.value.trim().toLowerCase();
        if (cmd === '/clear' || cmd === '/delete all') {
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
    });


    // Enter to send, Shift+Enter for new line
    messageInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
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

    // ── Admin: Delete ALL button ──────────────────────────────────────────────
    const deleteAllButton    = document.getElementById('deleteAllButton');
    const deleteAllModal     = document.getElementById('deleteAllModal');
    const cancelDeleteAll    = document.getElementById('cancelDeleteAll');
    const confirmDeleteAll   = document.getElementById('confirmDeleteAll');
    const deleteAllSecretIn  = document.getElementById('deleteAllSecretInput');
    const deleteAllSecretErr = document.getElementById('deleteAllSecretError');

    function openDeleteAllModal() {
      if (!deleteAllModal) return;
      deleteAllModal.classList.add('active');
      deleteAllModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (deleteAllSecretIn)  { deleteAllSecretIn.value = ''; }
      if (confirmDeleteAll)   { confirmDeleteAll.disabled = true; }
      if (deleteAllSecretErr) { deleteAllSecretErr.style.display = 'none'; }
      setTimeout(() => deleteAllSecretIn && deleteAllSecretIn.focus(), 200);
    }
    function closeDeleteAllModal() {
      if (!deleteAllModal) return;
      deleteAllModal.classList.remove('active');
      deleteAllModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (isAdmin && deleteAllModal) {
      cancelDeleteAll && cancelDeleteAll.addEventListener('click', closeDeleteAllModal);
      deleteAllModal.addEventListener('click', e => { if (e.target === deleteAllModal) closeDeleteAllModal(); });

      // Validate secret on input
      deleteAllSecretIn && deleteAllSecretIn.addEventListener('input', function() {
        if (!this.value) {
          if (confirmDeleteAll) confirmDeleteAll.disabled = true;
          if (deleteAllSecretErr) deleteAllSecretErr.style.display = 'none';
          return;
        }
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'validate_secret.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          try {
            const res = JSON.parse(this.responseText);
            if (confirmDeleteAll) confirmDeleteAll.disabled = !res.valid;
            if (deleteAllSecretErr) {
              deleteAllSecretErr.style.display = 'block';
              deleteAllSecretErr.textContent   = res.valid ? 'Correct secret key' : 'Invalid secret key';
              deleteAllSecretErr.style.color   = res.valid ? 'green' : 'red';
            }
          } catch(e) {
            if (confirmDeleteAll) confirmDeleteAll.disabled = true;
          }
        };
        xhr.send('secretKey=' + encodeURIComponent(this.value));
      });

      // Allow Enter key to trigger delete all if secret is correct
      deleteAllSecretIn && deleteAllSecretIn.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (confirmDeleteAll && !confirmDeleteAll.disabled) {
            confirmDeleteAll.click();
          } else if (deleteAllSecretErr) {
            deleteAllSecretErr.style.display = 'block';
            deleteAllSecretErr.textContent = 'Invalid secret key';
            deleteAllSecretErr.style.color = 'red';
            deleteAllSecretIn.focus();
          }
        }
      });

      // Confirm Delete All
      confirmDeleteAll && confirmDeleteAll.addEventListener('click', function() {
        if (this.disabled) return;
        // Re-validate before executing
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'validate_secret.php', true);
        xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
          try {
            const res = JSON.parse(this.responseText);
            if (!res.valid) {
              if (deleteAllSecretErr) { deleteAllSecretErr.style.display = 'block'; deleteAllSecretErr.textContent = 'Invalid secret key'; deleteAllSecretErr.style.color = 'red'; }
              return;
            }
            // Execute delete all
            const dx = new XMLHttpRequest();
            dx.open('POST', 'clear_all_dm.php', true);
            dx.onload = function() {
              closeDeleteAllModal();
              // Reset all chat state
              activeDM = null; activeAdminConv = null; isGlobalChat = false;
              updateClearChatButtonVisibility();
              gcCursor = ''; dmCursor = '';
              gcViewingOlder = false; dmViewingOlder = false;
              allConvsData = [];
              localStorage.removeItem('activeSpyConv');
              localStorage.removeItem('activeDM');
              removePaginationBtn();
              document.getElementById('globalChatItem').classList.remove('active');
              chatBox.innerHTML = '<div class="empty-chat"><p>All messages deleted.</p></div>';
              renderSidebarUsers();
              if (serverIsAdmin) renderAdminConvs();

              // Broadcast so every other connected client wipes its view in
              // realtime too, instead of only the admin who triggered this.
              if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'all_cleared' }));
              }
            };
            dx.onerror = function() { alert('Delete failed. Try again.'); };
            dx.send();
          } catch(e) {}
        };
        xhr.onerror = function() {};
        xhr.send('secretKey=' + encodeURIComponent(deleteAllSecretIn ? deleteAllSecretIn.value : ''));
      });
    }

    // User Mention Autocomplete System Completely Removed

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        if (deleteAllModal && deleteAllModal.classList.contains('active')) closeDeleteAllModal();
        if (confirmModal && confirmModal.classList.contains('active') && isAdmin) {
          closeModal();
        }
        if (logoutModal && logoutModal.classList.contains('active')) {
          closeLogoutModal();
        }
        if (notifyContentModal && notifyContentModal.classList.contains('active')) {
          closeNotifyContentModal();
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
      if (files.length > 0) handleFileUploads(files);
    }, false);

    // ── File input (attachment button) ─────────────────────────────────────────
    if (fileAttachInput) {
      fileAttachInput.addEventListener('change', function() {
        const files = Array.from(this.files || []);
        if (files.length > 0) handleFileUploads(files);
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


    // ── Core upload handler ───────────────────────────────────────────────────
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

      // silently ignore rejected files

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
      for (const file of fileList) {
        fd.append('files[]', file);
      }

      // POST to upload.php
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php', true);
      xhr.onload = function() {
        if (this.status !== 200) {
          return;
        }

        let result;
        try { result = JSON.parse(this.responseText); } catch(e) {
          return;
        }

        if (!result.success || !result.uploaded || result.uploaded.length === 0) return;

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
          if (this.status === 200) {
            // Trigger WS broadcast so the other party refreshes
            if (ws && ws.readyState === WebSocket.OPEN) {
              if (isGlobalChat) {
                ws.send(JSON.stringify({ type: 'message', chat_type: 'global' }));
              } else {
                ws.send(JSON.stringify({ type: 'message', chat_type: 'private', recipient_id: activeDMAccountId }));
              }
            }
            // Force a fresh chat load so the grid renders
            isLoadingChat = false;
            if (isGlobalChat) loadGlobalChat();
            else if (activeDM) loadChat();
          }
        };
        sendXhr.onerror = function() {};
        sendXhr.send(params);
      };
      xhr.onerror = function() {};
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

  </script>
</body>
</html>