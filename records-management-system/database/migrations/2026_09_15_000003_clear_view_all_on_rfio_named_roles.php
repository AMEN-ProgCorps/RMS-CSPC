<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RFIO office already grants full DCS. Keeping dcs_view_all_documents ON for
 * RFIO-named roles made non-RFIO office users on those roles look like full DCS.
 * Clear View All on those roles so office assignment controls intake vs full.
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

        if (! Schema::hasTable($details) || ! Schema::hasTable($keys)) {
            return;
        }
        if (! Schema::hasColumn($details, 'dcs_view_all_documents')) {
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
        DB::table($details)->whereIn('key_id', $detailIds)->update([
            'dcs_view_all_documents' => false,
        ]);
    }

    public function down(): void
    {
        // Intentionally empty — do not re-enable View All on RFIO roles.
    }
};
