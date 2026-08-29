<?php
// =============================================================================
// core/Reactions.php — Emoji Reactions: allowed set + shared render helper
// =============================================================================
// Reactions are stored in chat_reactions (see
// migrations/2026_07_14_000001_create_chatify_tables.php) and toggled
// through the shared GlobalChatManager::upsertReaction(), called from both
// GlobalChatManager::toggleReaction() (global chat) and
// ConversationManager::toggleReaction() (private DMs). react.php is the one
// HTTP endpoint for both.
// =============================================================================

class Reactions
{
    // The only 3 emoji a message can be reacted with. Keep this list and the
    // client-side picker (REACTION_EMOJIS in assets/js/app-part3.js) in sync —
    // react.php also rejects anything not in this list server-side.
    public const ALLOWED = ['❤️', '😆', '😍'];

    /**
     * Build the "<div class='msg-reactions'>...</div>" block rendered under a
     * message bubble (load.php / load_dm.php). Renders a single compact pill
     * containing distinct reacted emojis and total count. Returns '' when the
     * message has no reactions at all.
     *
     * @param array<string,int[]> $emojiMap  emoji => list of account_ids
     */
    public static function buildBadgesHtml(array $emojiMap, int $myAccountId): string
    {
        if (empty($emojiMap)) {
            return '';
        }

        $totalCount = 0;
        $distinctEmojis = [];
        $hasMine = false;

        foreach ($emojiMap as $rawEmoji => $accountIds) {
            $count = count($accountIds);
            if ($count === 0) {
                continue;
            }
            $emoji = ($rawEmoji === '❤' || $rawEmoji === '🖤' || $rawEmoji === '🤍') ? '❤️' : $rawEmoji;
            if (!in_array($emoji, $distinctEmojis, true)) {
                $distinctEmojis[] = $emoji;
            }
            $totalCount += $count;
            if (in_array($myAccountId, $accountIds, true)) {
                $hasMine = true;
            }
        }

        if ($totalCount === 0) {
            return '';
        }

        $mineClass = $hasMine ? ' mine' : '';
        $emojisStr = implode('', $distinctEmojis);
        $emojisEsc = htmlspecialchars($emojisStr, ENT_QUOTES);

        $countHtml = '';
        if ($totalCount >= 2) {
            $countLabel = $totalCount > 99 ? '99+' : (string) $totalCount;
            $countHtml = "<span class='reaction-total-count'>{$countLabel}</span>";
        }

        return "<div class='msg-reactions'><button type='button' class='msg-reactions-pill{$mineClass}' data-msg-reactions='1'><span class='reaction-emojis'>{$emojisEsc}</span>{$countHtml}</button></div>";
    }
}
