<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DtsFlowHelper
{
    /**
     * Check if users are permitted to choose between "Only Me" (user) and "My Office" (office)
     * when creating custom transaction flows.
     *
     * Default: false (scope selection disabled; all custom flows default to "My Office").
     */
    public static function isScopeSelectionAllowed(): bool
    {
        $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        if (!Schema::hasTable($table)) {
            return false;
        }

        return DB::table($table)
            ->where('key', 'dts_allow_custom_flow_scope_selection')
            ->value('value') === 'true';
    }
}
