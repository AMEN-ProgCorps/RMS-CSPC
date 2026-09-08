<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attach New-document checklists to Historical (Obsolete) so Register shows form sections.
 * Uses New's set (DRF, Masterlist, Distribution) — not DCN/Retrieval (Revised-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_version_type') || ! Schema::hasTable('dcs_checklist_version')) {
            return;
        }

        $historicalId = DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) LIKE ?', ['%historical%'])
            ->value('id');

        if (! $historicalId) {
            return;
        }

        $newId = DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) = ?', ['new'])
            ->value('id');

        $checklistIds = $newId
            ? DB::table('dcs_checklist_version')->where('version_id', $newId)->pluck('checklist_id')->all()
            : [1, 3, 5];

        foreach ($checklistIds as $checklistId) {
            $exists = DB::table('dcs_checklist_version')
                ->where('version_id', $historicalId)
                ->where('checklist_id', $checklistId)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('dcs_checklist_version')->insert([
                'checklist_id' => (int) $checklistId,
                'version_id' => (int) $historicalId,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_version_type') || ! Schema::hasTable('dcs_checklist_version')) {
            return;
        }

        $historicalId = DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) LIKE ?', ['%historical%'])
            ->value('id');

        if ($historicalId) {
            DB::table('dcs_checklist_version')->where('version_id', $historicalId)->delete();
        }
    }
};
