<?php

/**
 * DCS file: Column groups for the document database table. Group keys drive colspan and which cells a row renders.
 */

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DCS Inventory / Database table column groups (colspan + which cells a row shows).
 *
 * Visibility is system-wide (Admin Settings → Document Control), not per-user.
 *
 * UI: resources/views/pages/dcs/database/* + Admin Settings DCS tab
 *
 * DEBUG MAP:
 *   Wrong colspan / missing cells     → GROUP_KEYS, BUILTIN_COUNTS, group column defs below
 *   New column not appearing          → add to the group list used by database/_row.blade.php
 *   Wrong visibility after Admin save → systemVisibleGroups / persistSystemVisibleGroups
 */
class DcsDatabaseColumns
{
    public const SYSTEM_SETTING_KEY = 'dcs_db_visible_groups';

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

    /**
     * System-wide visible groups from Admin Settings (fallback: all visible).
     *
     * @return array<string, bool>
     */
    public static function systemVisibleGroups(): array
    {
        try {
            $table = Schema::hasTable('sys_system_settings')
                ? 'sys_system_settings'
                : (Schema::hasTable('system_settings') ? 'system_settings' : null);
            if ($table === null) {
                return self::defaultVisibleGroups();
            }

            $raw = DB::table($table)->where('key', self::SYSTEM_SETTING_KEY)->value('value');
            if (! is_string($raw) || trim($raw) === '') {
                return self::defaultVisibleGroups();
            }

            $decoded = json_decode($raw, true);

            return self::normalizeVisibleGroups(is_array($decoded) ? $decoded : null);
        } catch (\Throwable) {
            return self::defaultVisibleGroups();
        }
    }

    /**
     * Persist system-wide Inventory column group visibility.
     *
     * @param  array<string, bool>  $groups
     * @return array<string, bool>
     */
    public static function persistSystemVisibleGroups(array $groups): array
    {
        $normalized = self::normalizeVisibleGroups($groups);
        $table = Schema::hasTable('sys_system_settings')
            ? 'sys_system_settings'
            : (Schema::hasTable('system_settings') ? 'system_settings' : null);

        if ($table === null) {
            return $normalized;
        }

        $payload = [
            'value' => json_encode($normalized),
            'updated_at' => now(),
        ];
        $exists = DB::table($table)->where('key', self::SYSTEM_SETTING_KEY)->exists();
        if (! $exists) {
            $payload['created_at'] = now();
        }

        DB::table($table)->updateOrInsert(
            ['key' => self::SYSTEM_SETTING_KEY],
            $payload
        );

        return $normalized;
    }
}
