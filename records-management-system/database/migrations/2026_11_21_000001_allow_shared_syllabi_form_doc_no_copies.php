<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore masterlist.allows_revision so "Register another copy with this number"
 * works for every document number (not only COL-13/16).
 *
 * Unique Latest index only applies when allows_revision = true.
 * Separate copies (allow_duplicate_doc_no) store allows_revision = false and
 * stay out of insert/shift renumbering of the main series.
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
                $table->boolean('allows_revision')->default(true)->after('revision_status');
            });
        }

        // Existing rows default to series members (revisable / shift-eligible).
        DB::table('dcs_masterlist_registration')
            ->whereNull('allows_revision')
            ->update(['allows_revision' => true]);

        // If multiple Latest share a slot, keep the newest as the series tip;
        // older same-number Latests become separate copies (not shifted as a group).
        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $duplicates = DB::table('dcs_masterlist_registration')
                ->select('doc_no', 'revise_no', 'doc_type_id')
                ->where('revision_status', 'latest')
                ->where('allows_revision', true)
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

                array_shift($ids); // keep newest as series tip
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

        // Collapse separate copies before restoring the strict unique index.
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
            ");
        }
    }
};
