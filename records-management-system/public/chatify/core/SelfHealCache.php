<?php
// =============================================================================
// core/SelfHealCache.php — Cross-request "have we already run this DDL?" flag
// =============================================================================
// Several classes (ConversationManager, GlobalChatManager, ChatNotifier) run
// self-healing schema checks (ALTER TABLE ... ADD COLUMN IF NOT EXISTS,
// CREATE INDEX IF NOT EXISTS, etc.) as a safety net so a missed migration
// doesn't hard-fail requests. Those checks used to be guarded by a
// `static $checked` flag — but under PHP-FPM every request gets a fresh
// interpreter, so that flag resets on every single request, and the "safety
// net" DDL round-trips (several of them, including CREATE INDEX
// CONCURRENTLY) fired on the hottest read/write paths in the app, forever.
//
// This cache makes the check-once behavior real: the first request after a
// deploy (or after the flag expires) pays the DDL cost and marks the key as
// "done" in APCu, which is shared across every PHP-FPM worker on the box.
// Every other request for the next TTL just does a microsecond-scale
// in-memory lookup and skips the DDL entirely.
//
// Falls back to a plain per-request static cache if APCu isn't available
// (e.g. local CLI testing) — behavior is then identical to before, but only
// for that one process.
// =============================================================================

class SelfHealCache
{
    /** @var array<string, bool> per-process fallback when APCu is unavailable */
    private static array $fallback = [];

    /** Default: recheck at most once an hour across the whole fleet. */
    private const TTL_SECONDS = 3600;

    /**
     * Run $work() only if it hasn't already run (and succeeded) for $key
     * within the TTL. Returns true if $work() ran this call, false if it
     * was skipped because the cache says it's already done.
     */
    public static function once(string $key, callable $work): bool
    {
        $cacheKey = 'chatify_selfheal_' . $key;

        if (function_exists('apcu_fetch')) {
            if (apcu_fetch($cacheKey) !== false) {
                return false; // already done recently — skip the DDL entirely
            }
            $work();
            // add() not set(): a losing race just means another worker's
            // write wins, which is fine — both ran the (idempotent) DDL.
            apcu_add($cacheKey, true, self::TTL_SECONDS);
            return true;
        }

        // No APCu: fall back to old per-process-only behavior.
        if (isset(self::$fallback[$key])) {
            return false;
        }
        self::$fallback[$key] = true;
        $work();
        return true;
    }
}
