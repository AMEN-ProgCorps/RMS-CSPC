<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        DB::table('dcs_print_form_templates')
            ->where('key', 'office_dcn')
            ->where(function ($q) {
                $q->whereNull('default_rev')->orWhere('default_rev', '')->orWhere('default_rev', '0');
            })
            ->update([
                'default_rev' => '1',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        DB::table('dcs_print_form_templates')
            ->where('key', 'office_dcn')
            ->where('default_rev', '1')
            ->update([
                'default_rev' => '0',
                'updated_at' => now(),
            ]);
    }
};
