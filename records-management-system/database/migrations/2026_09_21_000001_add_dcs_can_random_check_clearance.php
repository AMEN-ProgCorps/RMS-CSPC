<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Random Check module clearance for full DCS admins.
 * Defaults OFF; ON for Super Admin, RFIO-named roles, and roles with dcs_view_all_documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';
        $keys = Schema::hasTable('sys_condition_key')
            ? 'sys_condition_key'
            : 'condition_key';

        if (! Schema::hasTable($details)) {
            return;
        }

        if (! Schema::hasColumn($details, 'dcs_can_random_check')) {
            $after = Schema::hasColumn($details, 'dcs_can_manage_files')
                ? 'dcs_can_manage_files'
                : (Schema::hasColumn($details, 'dcs_can_database')
                    ? 'dcs_can_database'
                    : 'can_access_dcs');

            Schema::table($details, function (Blueprint $blueprint) use ($after) {
                $blueprint->boolean('dcs_can_random_check')->default(false)->after($after);
            });
        }

        try {
            DB::statement("ALTER TABLE {$details} ALTER COLUMN dcs_can_random_check SET DEFAULT false");
        } catch (\Throwable) {
            // SQLite / drivers without ALTER COLUMN DEFAULT
        }

        $on = ['dcs_can_random_check' => true];

        if (Schema::hasColumn($details, 'is_sadm')) {
            DB::table($details)->where('is_sadm', true)->update($on);
        }

        if (Schema::hasColumn($details, 'dcs_view_all_documents')) {
            DB::table($details)->where('dcs_view_all_documents', true)->update($on);
        }

        if (! Schema::hasTable($keys)) {
            return;
        }

        $rfioKeyIds = DB::table($keys)
            ->where(function ($q) {
                $q->whereRaw('LOWER(key_name) LIKE ?', ['%rfio%'])
                    ->orWhereRaw('LOWER(key_name) LIKE ?', ['%rfiou%'])
                    ->orWhereRaw('LOWER(key_name) LIKE ?', ['%rfoiu%']);
            })
            ->pluck('id');

        if ($rfioKeyIds->isEmpty()) {
            return;
        }

        $modifierIds = Schema::hasColumn($keys, 'modifier_key')
            ? DB::table($keys)->whereIn('id', $rfioKeyIds)->pluck('modifier_key')->filter()->values()
            : collect();

        $detailIds = $modifierIds->isNotEmpty() ? $modifierIds : $rfioKeyIds;
        DB::table($details)->whereIn('key_id', $detailIds)->update($on);
    }

    public function down(): void
    {
        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! Schema::hasTable($details) || ! Schema::hasColumn($details, 'dcs_can_random_check')) {
            return;
        }

        Schema::table($details, function (Blueprint $blueprint) {
            $blueprint->dropColumn('dcs_can_random_check');
        });
    }
};
