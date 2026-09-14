<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Essential DCS module clearances only:
 * register, settings, recycle_bin, review_intake.
 * Default OFF for all roles; ON only for Super Admin and RFIOU/RFOIU Staffs.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function columns(): array
    {
        return [
            'dcs_can_register',
            'dcs_can_settings',
            'dcs_can_recycle_bin',
            'dcs_can_review_intake',
        ];
    }

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

        $after = Schema::hasColumn($details, 'dcs_view_all_documents')
            ? 'dcs_view_all_documents'
            : 'can_access_dcs';

        foreach ($this->columns() as $column) {
            if (Schema::hasColumn($details, $column)) {
                continue;
            }
            Schema::table($details, function (Blueprint $blueprint) use ($column, $after) {
                $blueprint->boolean($column)->default(false)->after($after);
            });
            $after = $column;
        }

        $present = array_values(array_filter(
            $this->columns(),
            fn (string $column) => Schema::hasColumn($details, $column)
        ));
        if ($present === []) {
            return;
        }

        foreach ($present as $column) {
            DB::statement("ALTER TABLE {$details} ALTER COLUMN {$column} SET DEFAULT false");
        }

        $off = array_fill_keys($present, false);
        $on = array_fill_keys($present, true);

        DB::table($details)->update($off);

        if (Schema::hasColumn($details, 'is_sadm')) {
            DB::table($details)->where('is_sadm', true)->update($on);
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

        if (! Schema::hasTable($details)) {
            return;
        }

        foreach (array_reverse($this->columns()) as $column) {
            if (Schema::hasColumn($details, $column)) {
                Schema::table($details, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropColumn($column);
                });
            }
        }
    }
};
