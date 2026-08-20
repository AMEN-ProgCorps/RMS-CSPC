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

// Load the list of account_ids with is_chatify_verified = TRUE for real-time badge injection.
// The Super Admin manages this list via the User Verification modal.
$verified_account_ids = [];
try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->query('SELECT account_id FROM account_details WHERE is_chatify_verified = TRUE');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $verified_account_ids = array_map('intval', $rows);
} catch (Throwable $e) {
    // Non-fatal — verified badges simply won't show if DB is unavailable
}

// Resolve the CURRENT user's own full name from DB fresh on every load, so
// a name change made in the main system shows up immediately in their own
// name field here — for every user, not just the Super Admin. Their session
// value can otherwise go stale until they log out and log back in.
try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare('SELECT first_name, last_name, avatar_url FROM account_details WHERE account_id = ? LIMIT 1');
    $stmt->execute([$_current_account_id]);
    $ownRow = $stmt->fetch();
    if ($ownRow) {
        $ownFullNameDisplay = trim(preg_replace('/\s+/', ' ', $ownRow['first_name'] . ' ' . $ownRow['last_name']));
        if ($ownFullNameDisplay !== '') {
            $user_name = $ownFullNameDisplay;
            $_SESSION['name'] = $ownFullNameDisplay;
            $_SESSION['full_name'] = $ownFullNameDisplay;
        }
        $_SESSION['avatar_url'] = (!empty($ownRow['avatar_url'])) ? $ownRow['avatar_url'] : null;
    }
} catch (Throwable $e) {
    // Non-fatal
}


// Load user communication settings (default ON for all users)
$user_comm_settings = [
    'allow_typing_preview'     => true,
    'allow_see_typing_preview' => true,
];
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT allow_typing_preview, allow_see_typing_preview FROM account_details WHERE account_id = ? LIMIT 1');
    $stmt->execute([$_current_account_id]);
    $cRow = $stmt->fetch();
    if ($cRow) {
        $user_comm_settings['allow_typing_preview']     = isset($cRow['allow_typing_preview']) ? (bool) $cRow['allow_typing_preview'] : true;
        $user_comm_settings['allow_see_typing_preview'] = isset($cRow['allow_see_typing_preview']) ? (bool) $cRow['allow_see_typing_preview'] : true;
    }
} catch (Throwable $e) {
    // Non-fatal
}


// Check for dark mode preference in cookie
$dark_mode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'enabled';

// ── Legal authorization gate ──────────────────────────────────────────────────
// Check once server-side to avoid a round-trip for users who've already agreed.
// The JS below re-checks on DOM ready and enforces the gate client-side as well.
$hasAgreedToLegal = false;
try {
    $pdo  = Database::getConnection();
    $stmt = $pdo->prepare(
        'SELECT id FROM chatify_legal_agreements WHERE account_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $_current_account_id]);
    $hasAgreedToLegal = (bool) $stmt->fetch();
} catch (Throwable $e) {
    // Non-fatal — default to showing the modal when DB is unavailable
    $hasAgreedToLegal = false;
}
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
  <script>
    window.currentUserCommSettings = <?php echo json_encode($user_comm_settings); ?>;
    window._chatifyHasAgreedToLegal = <?php echo json_encode($hasAgreedToLegal); ?>;
    window.verifiedAccountIds = <?php echo json_encode($verified_account_ids); ?>;
    const verifiedAccountIds = new Set((window.verifiedAccountIds || []).map(Number));
  </script>
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
  <script type="speculationrules">
  {
    "prerender": [
      {
        "where": {
          "and": [
            { "href_matches": "/*" },
            { "not": { "selector_matches": ".do-not-prerender, [target='_blank'], [download]" } }
          ]
        },
        "eagerness": "moderate"
      }
    ]
  }
  </script>
  <script>
    if (document.prerendering) {
      document.addEventListener('prerenderingchange', function() {
        var activationStart = Math.round(performance.getEntriesByType('navigation')[0]?.activationStart || 0);
        console.log('Chatify activated from speculative prerender at:', activationStart, 'ms');
      });
    }
  </script>
  <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">

</head>
  <div class="app-wrapper">
    <!-- Sidebar Backdrop for Mobile/Tablet -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Sidebar -->
    <div class="sidebar no-anim" id="sidebar">
      <div class="sidebar-header">
        <span style="flex-grow:1;">Chatify</span>
        <div class="sidebar-header-actions">
          <?php if (!$is_admin): ?>
          <button id="notificationBellBtn" class="clear-button sidebar-action-btn" title="Notifications" style="position:relative;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
            <span class="bell-badge" id="notificationBellBadge" style="display:none;"></span>
          </button>
          <?php endif; ?>
          <button id="commSettingsBtn" class="clear-button sidebar-action-btn" title="Communication Settings" onclick="openCommSettingsModal()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          </button>
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
        <input type="text" id="searchInput" name="csp_srch_own_2k9" placeholder="Search" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" role="combobox" aria-autocomplete="list">
      </div>
      <!-- Pinned Global Chat entry -->
      <div class="user-item" id="globalChatItem" onclick="selectGlobalChat()" onmouseenter="typeof speculateGlobalChatCard === 'function' && speculateGlobalChatCard(this)" onpointerdown="typeof speculateGlobalChatCard === 'function' && speculateGlobalChatCard(this)" style="border-bottom:1px solid var(--border-color);">
        <div class="user-avatar" style="background:none !important;background-color:transparent !important;">
          <img src="cspc.webp" width="40" height="40" alt="CSPC logo" style="width:40px;height:40px;object-fit:contain;background:transparent;" draggable="false" ondragstart="return false;" oncontextmenu="return false;">
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
          <input type="text" id="adminSearchInput" name="csp_srch_adm_7v3" placeholder="Search" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" role="combobox" aria-autocomplete="list">
        </div>
        <div class="sidebar-users" id="adminConvsList"></div>
      </div>
    </div>

    <!-- Main Chat Area -->
    <div class="app-container">
      <div class="header">
        <div class="header-left">
          <button id="burgerButton" class="clear-button" style="display:none;margin-right:10px;min-width:auto;" aria-label="Open menu" title="Open menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
          </button>
          <button id="backButton" class="clear-button" style="display:none;margin-right:10px;min-width:auto;" aria-label="Go back" title="Go back">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
          </button>
          <div class="header-avatar" id="chatHeaderAvatar" style="display:none;"></div>
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


      <!-- Floating "Replying to: ..." bubble — shown above the input when a
           reply is in progress (triggered via mobile swipe or the desktop
           hover reply button on a message). -->
      <div id="replyBanner" class="reply-banner">
        <div class="reply-banner-bubble">
          <span class="reply-banner-text">Replying to: <span id="replyBannerSnippet"></span></span>
          <button type="button" id="replyBannerCancel" class="reply-banner-cancel" title="Cancel reply" aria-label="Cancel reply">
            <svg viewBox="0 0 24 24" width="11" height="11" fill="#fff">
              <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Display-name field kept in the DOM (send logic still reads its
           value) but no longer shown as a visible row. The attach button
           now lives inline with the message box below. -->
      <input type="text" id="nameInput" aria-hidden="true" tabindex="-1" required readonly value="<?php echo htmlspecialchars($user_name); ?>" style="display:none;">

      <form id="chatForm" class="message-input-container">
        <label id="attachBtn" class="attachment-btn" title="Attach image or file" for="fileAttachmentInput">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="20" height="20">
            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </label>
        <input type="file" id="fileAttachmentInput" multiple accept="image/*,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.flac,.aac,.m4a,.mp4,.webm,.mov" style="display:none;">
        <!-- Wraps the real textarea + its @mention highlight layer so the
             layer can sit pixel-perfectly behind it. The textarea's own
             text is made transparent (see .message-input-wrap #messageInput
             in style.css) so only the highlight layer's colored spans are
             actually visible — the textarea itself still owns typing,
             selection, and the caret exactly as before. -->
        <div class="message-input-wrap">
          <div id="messageInputHighlight" class="message-input-highlight" aria-hidden="true"></div>
          <textarea id="messageInput" placeholder="Type a message..." required autocomplete="off" rows="1" enterkeyhint="enter"></textarea>
        </div>
        <!-- @mention modal opens on its own the moment "@" is typed — see
             the "input" listener on messageInput in app-part1.js. Same
             structure/behavior as the User Verification modal above:
             search inside the modal itself, one result row, click to pick. -->
        <button type="button" id="cancelEditXBtn" title="Cancel editing" aria-label="Cancel editing">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="#fff">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>
        <button type="submit" id="sendButton" aria-label="Send message" title="Send">
          <svg class="send-icon" viewBox="0 0 24 24" width="19" height="19" fill="#fff" xmlns="http://www.w3.org/2000/svg">
            <path d="M2 21l21-9L2 3v7l15 2-15 2z"/>
          </svg>
        </button>
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

  <!-- Backup Confirmation Modal - Only show if admin -->
  <?php if ($is_admin): ?>
  <div class="modal" id="backupConfirmModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Run Full Chat Backup</h3>
      </div>
      <div class="modal-body">
        <p>This snapshots every conversation (all DMs + global chat) into the backup archive as it stands right now. Nothing in the live chat is changed or deleted.</p>
        <label for="backupSecretInput" style="display:block;margin-top:10px;">Enter secret key to confirm:</label>
        <input type="password" id="backupSecretInput" autocomplete="off" class="secret-key-input" />
        <div id="backupSecretError" style="color:#b00;font-size:12px;display:none;margin-top:5px;text-align:center;">Invalid secret key.</div>
      </div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="cancelBackup">[n] Cancel</button>
        <button class="modal-button confirm-button" id="confirmBackup" disabled>[y] Backup</button>
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

  <!-- Modal shown when a notification toast is clicked, same layout as readMoreModal -->
  <div class="modal" id="notifyContentModal" aria-hidden="true">
    <div class="modal-content" style="min-height:0;">
      <div class="modal-header">
        <h3 id="notifyContentTitle">Mention</h3>
        <div id="notifyContentSender" style="font-size:12px;color:var(--text-secondary);margin-top:2px;"></div>
      </div>
      <div class="modal-body" id="notifyContentBody"></div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="notifyContentClose">Close</button>
      </div>
    </div>
  </div>

  <?php if (!$is_admin): ?>
  <!-- Notification bell modal — lists every @mention that hasn't been
       opened yet (is_seen = 0 in chat_message_mentions). Clicking an entry
       opens the same #notifyContentModal a toast click would, which marks
       it seen and removes it from this list. Not rendered for the super
       admin (account id 1) — the admin can never be @mentioned/notified. -->
  <div class="modal" id="notificationBellModal" aria-hidden="true">
    <div class="modal-content" style="min-height:0;">
      <div class="modal-header">
        <h3>Notifications</h3>
      </div>
      <div class="modal-body bell-list" id="notificationBellList"></div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="notificationBellClose">Close</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Modal shown when tapping "Read more..." on a long chat message.
       Same markup renders full messages from Global Chat and from Private
       (DM) chat — both funnel through the same #chatBox message bubbles. -->
  <div class="modal" id="readMoreModal" aria-hidden="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Full Message</h3>
      </div>
      <div class="modal-body" id="readMoreModalBody"></div>
      <div class="modal-footer">
        <button class="modal-button cancel-button" id="readMoreModalClose">Close</button>
      </div>
    </div>
  </div>


  <?php if ($is_admin): ?>
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
          Update the secret key used for deleting conversations and wiping chat history.
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

  <!-- Settings Modal (Communication Settings + Admin: User Verification) -->
  <div class="modal" id="commSettingsModal" aria-hidden="true">
    <div class="modal-content" style="max-width:440px;">
      <div class="modal-header" style="padding:16px;border-bottom:1px solid var(--border-color);">
        <h3 style="margin:0;font-size:16px;font-weight:600;color:var(--text-primary);">Settings</h3>
      </div>
      <div class="modal-body" style="padding:16px;display:flex;flex-direction:column;gap:0;text-align:left;">
        <p style="margin:0 0 10px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-secondary);">Communication</p>
        <label style="display:flex;flex-direction:column;gap:4px;cursor:pointer;padding:8px 0;">
          <div style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--text-primary);font-size:14px;">
            <input type="checkbox" id="chkAllowTypingPreview" style="accent-color:var(--primary-color);width:18px;height:18px;">
            <span>Allow Real-Time Typing Preview</span>
          </div>
          <span style="font-size:12px;color:var(--text-secondary);margin-left:26px;line-height:1.4;">
            Let others see your message in real time before you send it. Enabled by default for faster and more engaging conversations.
          </span>
        </label>

        <label style="display:flex;flex-direction:column;gap:4px;cursor:pointer;padding:8px 0;">
          <div style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--text-primary);font-size:14px;">
            <input type="checkbox" id="chkAllowSeeTypingPreview" style="accent-color:var(--primary-color);width:18px;height:18px;">
            <span>Show Live Typing Previews</span>
          </div>
          <span style="font-size:12px;color:var(--text-secondary);margin-left:26px;line-height:1.4;">
            View messages as others type them in real time. Turn this off if you prefer to only see messages after they are sent.
          </span>
        </label>

        <?php if ($is_admin): ?>
        <div style="border-top:1px solid var(--border-color);margin-top:8px;padding-top:14px;">
          <p style="margin:0 0 10px 0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-secondary);">Administration</p>
          <button type="button" id="openUserVerificationBtn"
            style="display:flex;align-items:center;gap:10px;width:100%;background:none;border:1px solid var(--border-color);border-radius:8px;padding:10px 14px;cursor:pointer;color:var(--text-primary);font-size:14px;font-weight:600;text-align:left;transition:background 0.15s;"
            onmouseover="this.style.background='var(--bg-drop-overlay)'"
            onmouseout="this.style.background='none'"
            onclick="openUserVerificationModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            User Verification
            <svg style="margin-left:auto;opacity:0.4;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button cancel-button" onclick="closeCommSettingsModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- User Verification Modal (Super Admin only) -->
  <div class="modal" id="userVerificationModal" aria-hidden="true">
    <div class="modal-content" style="max-width:460px;">
      <div class="modal-header" style="padding:16px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <h3 style="margin:0;font-size:16px;font-weight:600;color:var(--text-primary);">User Verification</h3>
      </div>
      <div class="modal-body" style="padding:16px;display:flex;flex-direction:column;gap:14px;text-align:left;">
        <p style="margin:0;font-size:13px;color:var(--text-secondary);line-height:1.5;">Search for a user by name and toggle their blue verification badge.</p>
        <div style="position:relative;">
          <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:0.45;pointer-events:none;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="verifySearchInput" placeholder="Search by first or last name..." autocomplete="off"
            style="width:100%;box-sizing:border-box;padding:9px 12px 9px 34px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-secondary);color:var(--text-primary);font-size:14px;outline:none;">
        </div>
        <div id="verifySearchResults" style="display:flex;flex-direction:column;gap:8px;min-height:40px;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button cancel-button" onclick="closeUserVerificationModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- @Mention User Modal (Global Chat) — opens the instant "@" is typed
       in the message box. Same look/behavior as the User Verification
       modal above: search happens inside the modal's own input, and only
       ONE best-matching user is ever rendered as a result — never a full
       list. Picking the row closes the modal and drops "@Full Name " into
       the message box, highlighted blue there via #messageInputHighlight. -->
  <div class="modal" id="mentionModal" aria-hidden="true">
    <div class="modal-content" style="max-width:420px;">
      <div class="modal-header" style="padding:16px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h1"/><path d="M20 21a4 4 0 0 0-3-3.87M14 11a4 4 0 1 0 4 4"/><circle cx="18" cy="15" r="3"/></svg>
        <h3 style="margin:0;font-size:16px;font-weight:600;color:var(--text-primary);">Mention a User</h3>
      </div>
      <div class="modal-body" style="padding:16px;display:flex;flex-direction:column;gap:14px;text-align:left;">
        <p style="margin:0;font-size:13px;color:var(--text-secondary);line-height:1.5;">Search for a user to mention in Global Chat.</p>
        <div style="position:relative;">
          <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:0.45;pointer-events:none;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="mentionSearchInput" placeholder="Search by first or last name..." autocomplete="off"
            style="width:100%;box-sizing:border-box;padding:9px 12px 9px 34px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-secondary);color:var(--text-primary);font-size:14px;outline:none;">
        </div>
        <div id="mentionSearchResults" style="display:flex;flex-direction:column;gap:8px;min-height:40px;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button cancel-button" onclick="closeMentionModal()">Cancel</button>
      </div>
    </div>
  </div>

  <!-- File Uploading Progress Modal -->
  <div class="modal" id="uploadingModal" aria-hidden="true" style="display:none;align-items:center;justify-content:center;z-index:99999;">
    <div class="modal-content" style="max-width:320px;min-height:0;">
      <div class="modal-header">
        <h3>Uploading File...</h3>
      </div>
      <div class="modal-body" style="text-align:center;padding:14px 16px;">
        <div style="margin-bottom:10px;display:flex;justify-content:center;align-items:center;">
          <div class="upload-spinner" style="width:32px;height:32px;border:3px solid rgba(27,116,228,0.2);border-top-color:#1b74e4;border-radius:50%;animation:uploadSpin 0.8s linear infinite;"></div>
        </div>
        <p id="uploadingFileName" style="margin:0 0 10px 0;font-size:13px;color:var(--text-secondary);word-break:break-all;line-height:1.4;max-height:calc(1.4em * 2);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">Preparing upload...</p>
        <div style="width:100%;height:6px;background:var(--border-color, #e4e6eb);border-radius:4px;overflow:hidden;margin-bottom:4px;">
          <div id="uploadProgressBar" style="width:0%;height:100%;background:linear-gradient(90deg, #1b74e4, #00c3ff);transition:width 0.15s ease;border-radius:4px;"></div>
        </div>
        <div id="uploadProgressText" style="font-size:12px;font-weight:600;color:#1b74e4;text-align:right;">0%</div>
      </div>
    </div>
  </div>

  <!-- Upload Error Modal -->
  <div class="modal" id="uploadErrorModal" aria-hidden="true" style="display:none;align-items:center;justify-content:center;z-index:99999;">
    <div class="modal-content" style="max-width:320px;min-height:0;">
      <div class="modal-header">
        <h3 style="color:#c0392b;">Upload Failed</h3>
      </div>
      <div class="modal-body" id="uploadErrorList">
        An unexpected error occurred while uploading.
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button confirm-button" id="uploadErrorCloseBtn" style="border-right:none;" onclick="closeUploadErrorModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- Attach/Staging Modal — opens whenever file(s) are picked via the
       attach button OR dropped/dragged into the chat, for BOTH images and
       any other allowed file type. Lets the user review what's about to be
       sent, remove items, or drag & drop in more, before anything actually
       uploads/sends — selecting a file never sends it immediately. Thumbnail
       grid scrolls but keeps its scrollbar hidden (matches the rest of the
       app's modals). Uses the same responsive .modal/.modal-content rules
       as every other modal, so it behaves the same on mobile. -->
  <div class="modal" id="imageStagingModal" aria-hidden="true" style="display:none;align-items:center;justify-content:center;z-index:99999;">
    <div class="modal-content image-staging-content">
      <div class="modal-header">
        <h3>Send Files</h3>
      </div>
      <div class="modal-body image-staging-body">
        <label class="image-staging-dropzone" id="imageStagingDropzone" for="imageStagingFileInput">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="17 8 12 3 7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="3" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Drag &amp; drop more files here</span>
          <input type="file" id="imageStagingFileInput" accept="image/*,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z,.mp3,.wav,.ogg,.flac,.aac,.m4a,.mp4,.webm,.mov" multiple style="display:none;">
        </label>
        <div class="image-staging-grid" id="imageStagingGrid">
          <div class="image-staging-empty">No files added yet.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button cancel-button" id="imageStagingCancelBtn">Cancel</button>
        <button type="button" class="modal-button confirm-button" id="imageStagingSendBtn" disabled>Send</button>
      </div>
    </div>
  </div>

  <!-- Image Viewer Modal — full-screen preview for images tapped/clicked in
       chat. Replaces the old behavior of opening the image in a new browser
       tab: clicking any .chat-viewable-image now opens it here instead, with
       an X button in the top-right corner to close. -->
  <div class="image-viewer-modal" id="imageViewerModal" aria-hidden="true" style="display:none;">
    <button type="button" class="image-viewer-close" id="imageViewerCloseBtn" aria-label="Close image preview" title="Close">&times;</button>
    <img src="" alt="" id="imageViewerImg" class="image-viewer-img">
  </div>

  <!-- Clearing Chat Progress Modal — mirrors the file upload progress modal -->
  <div class="modal" id="clearingChatModal" aria-hidden="true" style="display:none;align-items:center;justify-content:center;z-index:99999;">
    <div class="modal-content" style="max-width:320px;min-height:0;">
      <div class="modal-header">
        <h3>Clearing Chat...</h3>
      </div>
      <div class="modal-body" style="text-align:center;padding:14px 16px;">
        <div style="margin-bottom:10px;display:flex;justify-content:center;align-items:center;">
          <div class="upload-spinner" style="width:32px;height:32px;border:3px solid rgba(27,116,228,0.2);border-top-color:#1b74e4;border-radius:50%;animation:uploadSpin 0.8s linear infinite;"></div>
        </div>
        <p id="clearingChatLabel" style="margin:0 0 10px 0;font-size:13px;color:var(--text-secondary);word-break:break-all;line-height:1.4;max-height:calc(1.4em * 2);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">Clearing conversation...</p>
        <div style="width:100%;height:6px;background:var(--border-color, #e4e6eb);border-radius:4px;overflow:hidden;margin-bottom:4px;">
          <div id="clearingChatProgressBar" style="width:0%;height:100%;background:linear-gradient(90deg, #1b74e4, #00c3ff);transition:width 0.15s ease;border-radius:4px;"></div>
        </div>
        <div id="clearingChatProgressText" style="font-size:12px;font-weight:600;color:#1b74e4;text-align:right;">0%</div>
      </div>
    </div>
  </div>

  <!-- Backup Progress Modal — mirrors the clearing-chat progress modal
       exactly (same size, same "no buttons while running" behavior). The
       admin waits for it like /clear; if it fails, this closes and the
       confirm modal reopens with the error shown, same as /clear does. -->
  <?php if ($is_admin): ?>
  <div class="modal" id="backupChatModal" aria-hidden="true" style="display:none;align-items:center;justify-content:center;z-index:99999;">
    <div class="modal-content" style="max-width:320px;min-height:0;">
      <div class="modal-header">
        <h3>Backing Up Chats...</h3>
      </div>
      <div class="modal-body" style="text-align:center;padding:14px 16px;">
        <div style="margin-bottom:10px;display:flex;justify-content:center;align-items:center;">
          <div class="upload-spinner" style="width:32px;height:32px;border:3px solid rgba(27,116,228,0.2);border-top-color:#1b74e4;border-radius:50%;animation:uploadSpin 0.8s linear infinite;"></div>
        </div>
        <p id="backupChatLabel" style="margin:0 0 10px 0;font-size:13px;color:var(--text-secondary);word-break:break-all;line-height:1.4;">Backup in progress...</p>
        <div style="width:100%;height:6px;background:var(--border-color, #e4e6eb);border-radius:4px;overflow:hidden;margin-bottom:4px;">
          <div id="backupChatProgressBar" style="width:0%;height:100%;background:linear-gradient(90deg, #1b74e4, #00c3ff);transition:width 0.15s ease;border-radius:4px;"></div>
        </div>
        <div id="backupChatProgressText" style="font-size:12px;font-weight:600;color:#1b74e4;text-align:right;">0%</div>
      </div>
    </div>
  </div>

  <!-- "Already backed up" info modal — same structure AND same compact
       sizing as uploadErrorModal (header / body / single Close button)
       so it matches the rest of the app's simple info modals. -->
  <div class="modal" id="backupAlreadyDoneModal" aria-hidden="true" style="display:none;align-items:center;justify-content:center;z-index:99999;">
    <div class="modal-content" style="max-width:320px;min-height:0;">
      <div class="modal-header">
        <h3>Backup</h3>
      </div>
      <div class="modal-body">
        Everything is already backed up. There are no cleared (inactive) messages waiting to be archived right now.
      </div>
      <div class="modal-footer">
        <button type="button" class="modal-button confirm-button" id="backupAlreadyDoneCloseBtn" style="border-right:none;" onclick="closeBackupAlreadyDoneModal()">Close</button>
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
      token: <?php echo json_encode($ws_token); ?>,
      avatarUrl: <?php echo json_encode($_SESSION['avatar_url'] ?? null); ?>
    };
  </script>
  <script src="assets/js/websocket-reauth.js?v=<?php echo filemtime(__DIR__ . '/assets/js/websocket-reauth.js'); ?>"></script>

  <script src="assets/js/app-part1.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app-part1.js'); ?>"></script>
  <script>
    // Get the user's name and admin status from PHP
    const userName = "<?php echo addslashes($user_name); ?>";
    const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
  </script>
  <script src="assets/js/app-part2.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app-part2.js'); ?>"></script>
  <script>
    // Verified account IDs for badge injection (array of integers)
    // Replaces the old name-based adminNames approach.
    const adminNames = []; // kept for backward-compat; badge logic now uses verifiedAccountIds
    // verifiedAccountIds is already defined in the header block as a Set
  </script>
  <script src="assets/js/app-part3.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app-part3.js'); ?>"></script>
  <!-- ═══════════════════════════════════════════════════════════════════════
       LEGAL AUTHORIZATION MODAL
       Uses the existing Chatify .modal / .modal.active pattern.
       Shown only once per account — tracked in chatify_legal_agreements.
       ═══════════════════════════════════════════════════════════════════════ -->
  
  <!-- Add CSS for backdrop blur -->
  <link rel="stylesheet" href="assets/css/legal-modal.css">

  <!-- Blur overlay -->
  <div class="modal-blur-overlay" id="legalBlurOverlay"></div>

  <div class="modal" id="legalAuthModal" aria-modal="true" aria-labelledby="legalAuthTitle"
       style="z-index: 99999;">
    <div class="modal-content" style="max-width: 500px; user-select: text; touch-action: pan-y;">

      <!-- Header -->
      <div class="modal-header">
        <h3 id="legalAuthTitle">Chatify — Authorization &amp; Usage Policy</h3>
      </div>

      <!-- Body -->
      <div class="modal-body" style="max-height: 55vh; overflow-y: auto; touch-action: pan-y;">

        <p style="margin: 0 0 12px; color: var(--text-primary); font-size: 13.5px; line-height: 1.55;">
          Before accessing Chatify, please read and acknowledge the following usage policy.
        </p>

        <div style="background: rgba(27,116,228,0.07);
                    border-radius: 0 6px 6px 0; padding: 10px 14px; margin-bottom: 14px;">
          <p style="margin: 0; font-size: 13px; color: var(--text-primary); font-weight: 600; line-height: 1.5;">
            MONITORED COMMUNICATION
          </p>
          <p style="margin: 6px 0 0; font-size: 13px; color: var(--text-secondary); line-height: 1.5;">
            All communication within Chatify — including messages, file transfers, and user
            actions — is recorded and monitored by CSPC administration for institutional
            and security purposes.
          </p>
        </div>

        <p style="margin: 0 0 8px; font-size: 12px; font-weight: 600;
                  color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.4px;">
          By using Chatify, you agree to the following:
        </p>

        <ul style="margin: 0 0 14px; padding-left: 18px;
                   font-size: 13px; color: var(--text-secondary);
                   line-height: 1.6; display: flex; flex-direction: column; gap: 4px;">
          <li>You acknowledge that this system is intended solely for <strong style="color:var(--text-primary);">authorized academic, administrative, and institutional use</strong>.</li>
          <li>You will not send any content that is <strong style="color:var(--text-primary);">abusive, threatening, or illegal</strong>.</li>
          <li>You will not use Chatify for <strong style="color:var(--text-primary);">unauthorized personal or commercial activities</strong>.</li>
          <li>Your actions within the system may be <strong style="color:var(--text-primary);">reviewed by authorized administrators</strong> at any time.</li>
          <li>Violations of this policy may result in <strong style="color:var(--text-primary);">disciplinary action</strong> under CSPC regulations.</li>
        </ul>

        <p style="margin: 0; font-size: 11.5px; color: var(--text-secondary);
                  border-top: 1px solid var(--border-color); padding-top: 10px; line-height: 1.5;">
          Your acceptance is recorded with your account ID, timestamp, and IP address
          for institutional records.
        </p>

        <!-- Inline error message -->
        <div id="legalAuthError"
             style="display:none; margin-top:10px; padding:8px 12px; border-radius:6px;
                    background:rgba(239,68,68,0.1); color:#dc2626;
                    font-size:12.5px; font-weight:600; text-align:center;">
        </div>
      </div>

      <!-- Footer -->
      <div class="modal-footer">
        <button class="modal-button confirm-button" id="legalAgreeBtn" type="button" style="border-left: none;">
          I Understand and Agree
        </button>
      </div>
    </div>
  </div>
  <script src="assets/js/legal-modal.js?v=<?php echo filemtime(__DIR__ . '/assets/js/legal-modal.js'); ?>"></script>
</body>
</html>