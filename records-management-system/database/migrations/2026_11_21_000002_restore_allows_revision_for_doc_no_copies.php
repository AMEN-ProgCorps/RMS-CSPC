<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up: 2026_11_21_000001 may already have applied the COL-13/16-only
 * unique-index carve-out. Restore masterlist.allows_revision for all
 * "Register another copy" saves, and rebuild the unique Latest index to
 * cover only allows_revision = true (series tips).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');
        }

        if (! Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->boolean('allows_revision')->default(true);
            });
        }

        DB::table('dcs_masterlist_registration')
            ->whereNull('allows_revision')
            ->update(['allows_revision' => true]);

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $duplicates = DB::table('dcs_masterlist_registration')
                ->select('doc_no', 'revise_no', 'doc_type_id')
                ->where('revision_status', 'latest')
                ->where(function ($q) {
                    $q->where('allows_revision', true)->orWhereNull('allows_revision');
                })
                ->groupBy('doc_no', 'revise_no', 'doc_type_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                $ids = DB::table('dcs_masterlist_registration')
                    ->where('doc_no', $dup->doc_no)
                    ->where('revise_no', $dup->revise_no)
                    ->where('doc_type_id', $dup->doc_type_id)
                    ->where('revision_status', 'latest')
                    ->orderByDesc('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (count($ids) < 2) {
                    continue;
                }

                array_shift($ids);
                DB::table('dcs_masterlist_registration')
                    ->whereIn('id', $ids)
                    ->update([
                        'allows_revision' => false,
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::getConnection()->getDriverName() !== 'pgsql'
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        DB::statement("
            CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
            ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
            WHERE revision_status = 'latest' AND allows_revision = true
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')
            && Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            $duplicates = DB::table('dcs_masterlist_registration')
                ->select('doc_no', 'revise_no', 'doc_type_id')
                ->where('revision_status', 'latest')
                ->groupBy('doc_no', 'revise_no', 'doc_type_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $dup) {
                $ids = DB::table('dcs_masterlist_registration')
                    ->where('doc_no', $dup->doc_no)
                    ->where('revise_no', $dup->revise_no)
                    ->where('doc_type_id', $dup->doc_type_id)
                    ->where('revision_status', 'latest')
                    ->orderByDesc('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (count($ids) < 2) {
                    continue;
                }

                array_shift($ids);
                DB::table('dcs_masterlist_registration')
                    ->whereIn('id', $ids)
                    ->update([
                        'revision_status' => 'obsolete',
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->dropColumn('allows_revision');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql'
            && Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            DB::statement("
                CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
                ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
                WHERE revision_status = 'latest'
                  AND UPPER(TRIM(doc_no)) NOT IN ('CSPC-F-COL-13', 'CSPC-F-COL-16')
            ");
        }
    }
};
