<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Apply the requested colors to existing calendar categories. */
    public function up(): void
    {
        if (! Schema::hasTable('dcs_calendar_categories')) {
            return;
        }

        foreach ([
            'suspension' => '#dc2626',
            'leave' => '#16a34a',
            'wfh' => '#2563eb',
        ] as $name => $color) {
            DB::table('dcs_calendar_categories')
                ->whereRaw('LOWER(name) = ?', [$name])
                ->update(['color' => $color, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Colors are presentation data; preserve the administrator's values on rollback.
    }
};
