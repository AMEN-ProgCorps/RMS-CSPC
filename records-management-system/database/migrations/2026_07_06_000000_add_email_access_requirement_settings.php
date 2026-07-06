<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert default setting values if they don't already exist
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'dts_email_access_required_external'],
            [
                'value' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'dts_email_access_required_application'],
            [
                'value' => 'true',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'dts_email_access_required_internal'],
            [
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', [
                'dts_email_access_required_external',
                'dts_email_access_required_application',
                'dts_email_access_required_internal'
            ])
            ->delete();
    }
};
