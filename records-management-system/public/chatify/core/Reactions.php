<?php
// =============================================================================
// core/Reactions.php — Emoji Reactions: allowed set + shared render helper
// =============================================================================
// Reactions are stored in messify_chat_reactions (see
// migrations/2026_07_14_000001_create_messify_tables.php) and toggled
// through the shared GlobalChatManager::upsertReaction(), called from both
// GlobalChatManager::toggleReaction() (global chat) and
// ConversationManager::toggleReaction() (private DMs). react.php is the one
// HTTP endpoint for both.
// =============================================================================

class Reactions
{
    // Default system reaction emojis (3 slots). Users can customize their own
    // 3 reaction emojis from Chatify's bundled Emoji Mart catalog via Settings.
    public const DEFAULT_EMOJIS = ['😆', '❤️', '👍'];

    private const SHEET_URL = 'assets/vendor/emoji-mart/apple-sheet-64.png';
    private static ?array $emojiMartCoords = null;
    private static int $sheetCols = 61;
    private static int $sheetRows = 61;

    /**
     * Load and cache the native-emoji => [x, y] coordinate map from the locally
     * bundled Emoji Mart dataset (assets/vendor/emoji-mart/apple-data.js).
     *
     * @return array<string, array{0:int,1:int}>
     */
    public static function getEmojiMartCoords(): array
    {
        if (self::$emojiMartCoords !== null) {
            return self::$emojiMartCoords;
        }

        self::$emojiMartCoords = [];
        $dataPath = __DIR__ . '/../assets/vendor/emoji-mart/apple-data.js';
        if (is_file($dataPath)) {
            $raw = @file_get_contents($dataPath);
            if (is_string($raw) && $raw !== '') {
                $start = strpos($raw, '{');
                $end = strrpos($raw, '}');
                if ($start !== false && $end !== false && $end > $start) {
                    $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
                    if (is_array($decoded)) {
                        if (isset($decoded['sheet']['cols'], $decoded['sheet']['rows'])) {
                            self::$sheetCols = max(2, (int) $decoded['sheet']['cols']);
                            self::$sheetRows = max(2, (int) $decoded['sheet']['rows']);
                        }
                        if (!empty($decoded['emojis']) && is_array($decoded['emojis'])) {
                            foreach ($decoded['emojis'] as $em) {
                                if (empty($em['skins']) || !is_array($em['skins'])) {
                                    continue;
                                }
                                foreach ($em['skins'] as $skin) {
                                    if (!isset($skin['native'], $skin['x'], $skin['y'])) {
                                        continue;
                                    }
                                    $native = (string) $skin['native'];
                                    $coords = [(int) $skin['x'], (int) $skin['y']];
                                    self::$emojiMartCoords[$native] = $coords;
                                    $stripped = str_replace("\xEF\xB8\x8F", '', $native);
                                    if ($stripped !== '' && !isset(self::$emojiMartCoords[$stripped])) {
                                        self::$emojiMartCoords[$stripped] = $coords;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fallback entries for the 3 default system emojis in case file reading fails
        if (empty(self::$emojiMartCoords)) {
            self::$emojiMartCoords = [
                '😆' => [32, 27],
                '❤️' => [59, 41],
                '❤'  => [59, 41],
                '👍' => [12, 51],
            ];
        }

        return self::$emojiMartCoords;
    }

    /**
     * Check if a given string is a valid reaction emoji from Chatify's bundled Emoji Mart catalog.
     */
    public static function isValidEmoji(string $emoji): bool
    {
        if ($emoji === '') {
            return false;
        }
        if ($emoji === '❤') {
            $emoji = '❤️';
        }
        $coords = self::getEmojiMartCoords();
        if (isset($coords[$emoji])) {
            return true;
        }
        $stripped = str_replace("\xEF\xB8\x8F", '', $emoji);
        return $stripped !== '' && isset($coords[$stripped]);
    }

    /**
     * Render a single emoji as a fixed Emoji Mart spritesheet span so all
     * desktop and mobile users see the exact same emoji graphics.
     */
    public static function renderEmojiSpriteHtml(string $emoji, int $sizePx = 15): string
    {
        if ($emoji === '❤') {
            $emoji = '❤️';
        }
        $coordsMap = self::getEmojiMartCoords();
        $coords = $coordsMap[$emoji] ?? ($coordsMap[str_replace("\xEF\xB8\x8F", '', $emoji)] ?? null);
        $esc = htmlspecialchars($emoji, ENT_QUOTES, 'UTF-8');
        if ($coords === null) {
            return "<span class='em-sprite-fallback'>{$esc}</span>";
        }
        $cols = self::$sheetCols;
        $rows = self::$sheetRows;
        $bgW = 100 * $cols;
        $bgH = 100 * $rows;
        $posX = round((100 / ($cols - 1)) * $coords[0], 4);
        $posY = round((100 / ($rows - 1)) * $coords[1], 4);
        $sheetUrl = self::SHEET_URL;

        return "<span class='em-sprite-icon' role='img' aria-label='{$esc}' title='{$esc}' style=\"display:inline-block;width:{$sizePx}px;height:{$sizePx}px;background-image:url('{$sheetUrl}');background-size:{$bgW}% {$bgH}%;background-position:{$posX}% {$posY}%;background-repeat:no-repeat;vertical-align:middle;flex-shrink:0;\">{$esc}</span>";
    }

    /**
     * Parse and normalize a user's stored reaction emojis JSON (or array) into
     * exactly 3 valid emojis, falling back to DEFAULT_EMOJIS.
     */
    public static function normalizeUserReactionEmojis($raw): array
    {
        $arr = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($arr)) {
            return self::DEFAULT_EMOJIS;
        }
        $result = [];
        foreach (self::DEFAULT_EMOJIS as $idx => $defaultEm) {
            $candidate = isset($arr[$idx]) && is_string($arr[$idx]) ? trim($arr[$idx]) : '';
            if ($candidate === '❤') {
                $candidate = '❤️';
            }
            $result[] = self::isValidEmoji($candidate) ? $candidate : $defaultEm;
        }
        return $result;
    }

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
            $emoji = ($rawEmoji === '❤') ? '❤️' : $rawEmoji;
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
        $spritesHtml = '';
        foreach ($distinctEmojis as $em) {
            $spritesHtml .= self::renderEmojiSpriteHtml($em, 15);
        }

        $countHtml = '';
        if ($totalCount >= 2) {
            $countLabel = $totalCount > 99 ? '99+' : (string) $totalCount;
            $countHtml = "<span class='reaction-total-count'>{$countLabel}</span>";
        }

        return "<div class='msg-reactions'><button type='button' class='msg-reactions-pill{$mineClass}' data-msg-reactions='1'><span class='reaction-emojis'>{$spritesHtml}</span>{$countHtml}</button></div>";
    }
}
