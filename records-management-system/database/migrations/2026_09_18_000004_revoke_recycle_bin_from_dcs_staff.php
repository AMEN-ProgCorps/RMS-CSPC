<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recycle Bin (review + permanent delete) is Head of Document Control / Super Admin.
 * Soft-delete with reason stays available to DCS register staff.
 * Revoke recycle_bin from non–Super Admin roles so regular DCS admins cannot permanently delete.
 * Re-enable Recycle Bin only on the Head of Document Control role in Admin → Roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $details = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
        if (! Schema::hasTable($details) || ! Schema::hasColumn($details, 'dcs_can_recycle_bin')) {
            return;
        }

        $query = DB::table($details)->where('dcs_can_recycle_bin', true);
        if (Schema::hasColumn($details, 'is_sadm')) {
            $query->where(function ($q) {
                $q->where('is_sadm', false)->orWhereNull('is_sadm');
            });
        }
        $query->update(['dcs_can_recycle_bin' => false]);
    }

    public function down(): void
    {
        // Intentionally empty — do not re-grant Recycle Bin to all former holders.
    }
};
