<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subsystem activation helpers shared by the Chatify gating checks and the
 * force soft-refresh mechanism (see /api/systems/refresh-token).
 */
class SubsystemHelper
{
    /**
     * Subsystems table name (renamed with the sys_ prefix on newer installs).
     */
    public static function table(): string
    {
        return Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
    }

    /**
     * Whether a subsystem (by exact name) is currently activated.
     */
    public static function isActive(string $name): bool
    {
        try {
            return DB::table(self::table())
                ->where('subsystem_name', $name)
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Token derived from every subsystem's activation state.
     *
     * It changes whenever any subsystem is activated or deactivated. The
     * session guard polls this token and soft-refreshes the page when it
     * differs from the one the page was rendered with, so every open tab of
     * every online user picks up the change without waiting for navigation.
     */
    public static function refreshToken(): string
    {
        try {
            $rows = DB::table(self::table())
                ->orderBy('subsystem_id')
                ->get(['subsystem_id', 'subsystem_name', 'is_active']);

            return md5($rows->toJson());
        } catch (\Throwable) {
            return '';
        }
    }
}
