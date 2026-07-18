# Chat System Optimization Tasks

## Phase 1: Database Migration
- `[x]` Optimize indexes in `2026_07_14_000001_create_chatify_tables.php`
  - `[x]` Replace ASC composite index with DESC composite index on `(conv_id, created_at DESC, id DESC)`
  - `[x]` Remove redundant single-column `conv_id` index
  - `[x]` Remove redundant `idx_read_marker_conv_acct` (duplicates unique constraint)
- `[x]` Create new migration for `account_details` search index

## Phase 2: Core PHP Engine
- `[x]` Optimize `ConversationManager.php`
  - `[x]` Implement keyset pagination (`loadRaw` with `$beforeUuid`)
  - `[x]` Remove `countRaw()` usage from load flow
  - `[x]` Implement `getActiveConversations()` (single CTE query eliminates N+1)
  - `[x]` Sequential UUID generation in `insertMessage()`
  - `[x]` Optimize `unreadCount()` to use single query (no two-step lookup)
  - `[x]` Optimize `markRead()` to avoid separate `SELECT` before `UPSERT`
  - `[x]` Optimize `getAllConversations()` to avoid N+1 last-message fetches

- `[x]` Optimize `GlobalChatManager.php`
  - `[x]` Implement keyset pagination in `loadRaw()`
  - `[x]` Sequential UUID generation in `insertMessage()`
  - `[x]` Optimize `pruneOldest()` to use DELETE...RETURNING
  - `[x]` Optimize `loadReactions()` to scope to current page instead of all

- `[x]` Optimize `UserResolver.php`
  - `[x]` Add `searchUsers()` server-side search method
  - `[x]` Add `getConversationMetaBatch()` for batch last-message + unread

## Phase 3: Endpoints
- `[x]` Update `load.php` to keyset pagination
- `[x]` Update `load_dm.php` to keyset pagination
- `[x]` Update `load_dm_admin.php` to keyset pagination
- `[x]` Update `fetch_users_dm.php` with active-conversations query + server-side search

## Phase 4: Frontend
- `[x]` Update `index.php` JS: `loadGlobalChat()` to send `before_uuid`
- `[x]` Update `index.php` JS: `loadChat()` to send `before_uuid`
- `[x]` Update `index.php` JS: `loadAdminConv()` to send `before_uuid`
- `[x]` Update `index.php` JS: debounced server-side user search

## Phase 5: Verification
- `[x]` Create walkthrough summary
