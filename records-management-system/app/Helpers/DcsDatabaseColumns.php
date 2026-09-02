<?php

namespace App\Helpers;

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
        'retrieval' => 4,
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
    public static function defaultVisibleGroups(): array
    {
        $visible = [];
        foreach (self::GROUP_KEYS as $key) {
            $visible[$key] = true;
        }

        return $visible;
    }

    /**
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

    /** @return list<string> */
    public static function placementKeys(): array
    {
        return array_merge(['main'], self::GROUP_KEYS);
    }

    public static function isValidPlacement(string $groupKey): bool
    {
        return in_array($groupKey, self::placementKeys(), true);
    }
}
