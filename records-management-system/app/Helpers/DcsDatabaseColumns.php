<?php

/**
 * DCS file: Column groups for the document database table. Group keys drive colspan and which cells a row renders.
 */

namespace App\Helpers;

/**
 * DCS Inventory / Database table column groups (colspan + which cells a row shows).
 *
 * UI: resources/views/pages/dcs/database/* + database.css
 *
 * DEBUG MAP:
 *   Wrong colspan / missing cells     → GROUP_KEYS, BUILTIN_COUNTS, group column defs below
 *   New column not appearing          → add to the group list used by database/_row.blade.php
 */
class DcsDatabaseColumns
{
    /** @var list<string> */
    public const GROUP_KEYS = [
        'approval',
        'deadline',
        'masterlist',
        'dcn',
        'drf',
        'distribution',
        'retrieval',
    ];

    /** Built-in leaf column counts per group (for colspan). */
    public const BUILTIN_COUNTS = [
        'approval' => 2,
        'deadline' => 2,
        'masterlist' => 5,
        'dcn' => 6,
        'drf' => 5,
        'distribution' => 6,
        'retrieval' => 1,
    ];

    public const GROUP_LABELS = [
        'main' => 'Main columns',
        'approval' => 'Approval',
        'deadline' => 'Deadline',
        'masterlist' => 'Masterlist',
        'dcn' => 'Document Change Notice',
        'drf' => 'Document Request Form',
        'distribution' => 'Distribution',
        'retrieval' => 'Retrieval',
    ];

    /** @return array<string, bool> */
    // =====================================================================
    // SECTION: COLUMN GROUPS FOR INVENTORY TABLE
    // =====================================================================

    /**
     * Helper used for default visible groups.
     */
    public static function defaultVisibleGroups(): array
    {
        $visible = [];
        foreach (self::GROUP_KEYS as $key) {
            $visible[$key] = true;
        }

        return $visible;
    }

    /**
     * Parses or normalizes values used by normalize visible groups.
     * @param  mixed  $stored
     * @return array<string, bool>
     */
    public static function normalizeVisibleGroups(mixed $stored): array
    {
        $defaults = self::defaultVisibleGroups();
        if (! is_array($stored)) {
            return $defaults;
        }

        foreach (self::GROUP_KEYS as $key) {
            if (array_key_exists($key, $stored)) {
                $defaults[$key] = filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $defaults;
    }

    /**
     * Helper used for placement keys.
     */
    public static function placementKeys(): array
    {
        return array_merge(['main'], self::GROUP_KEYS);
    }

    /**
     * Tells whether is valid placement — used for access and UI decisions.
     */
    public static function isValidPlacement(string $groupKey): bool
    {
        return in_array($groupKey, self::placementKeys(), true);
    }
}
