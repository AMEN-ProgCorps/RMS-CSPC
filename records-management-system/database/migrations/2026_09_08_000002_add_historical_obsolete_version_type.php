<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Version Type for registering obsolete-only / historical documents (no Latest tip).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_version_type')) {
            return;
        }

        $exists = DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) LIKE ?', ['%historical%'])
            ->exists();

        if (! $exists) {
            DB::table('dcs_version_type')->insert([
                'version_name' => 'Historical (Obsolete)',
            ]);
        }

        // Also attach New's checklists if Historical already exists from a prior migrate.
        $historicalId = DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) LIKE ?', ['%historical%'])
            ->value('id');
        $newId = DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) = ?', ['new'])
            ->value('id');

        if ($historicalId && Schema::hasTable('dcs_checklist_version')) {
            $checklistIds = $newId
                ? DB::table('dcs_checklist_version')->where('version_id', $newId)->pluck('checklist_id')->all()
                : [1, 3, 5];
            foreach ($checklistIds as $checklistId) {
                $linked = DB::table('dcs_checklist_version')
                    ->where('version_id', $historicalId)
                    ->where('checklist_id', $checklistId)
                    ->exists();
                if (! $linked) {
                    DB::table('dcs_checklist_version')->insert([
                        'checklist_id' => (int) $checklistId,
                        'version_id' => (int) $historicalId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_version_type')) {
            return;
        }

        DB::table('dcs_version_type')
            ->whereRaw('LOWER(TRIM(version_name)) LIKE ?', ['%historical%'])
            ->delete();
    }
};
