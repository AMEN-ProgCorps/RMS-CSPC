<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove legacy allows_revision flags.
 *
 * Document-type revision behavior is name-based (syllabi/TOS/rubric stay Rev 0)
 * and Register Recurring version_type covers non-revise filings. The Settings
 * toggle no longer writes these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            $this->dropDocTypeColumn();

            return;
        }

        $this->dropActiveUniqueIndex();
        $this->collapseDuplicateLatestRows();
        $this->dropMasterlistColumn();
        $this->dropDocTypeColumn();
        $this->rebuildActiveUniqueIndex();
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_doc_types') && ! Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
            Schema::table('dcs_doc_types', function (Blueprint $table) {
                $table->boolean('allows_revision')->default(true);
            });
        }

        if (Schema::hasTable('dcs_masterlist_registration')
            && ! Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->boolean('allows_revision')->default(true);
            });
        }

        if (Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
            $types = DB::table('dcs_doc_types')->get(['id', 'doc_type_name']);
            foreach ($types as $type) {
                $name = mb_strtolower((string) ($type->doc_type_name ?? ''));
                $nonRevisable = str_contains($name, 'syllab')
                    || str_contains($name, 'tos')
                    || str_contains($name, 'rubric');
                if ($nonRevisable) {
                    DB::table('dcs_doc_types')->where('id', $type->id)->update(['allows_revision' => false]);
                }
            }
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')
            && Schema::hasTable('dcs_document_requests')
            && Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
            $typeFlags = DB::table('dcs_doc_types')
                ->pluck('allows_revision', 'id')
                ->map(fn ($v) => (bool) $v)
                ->all();

            DB::table('dcs_masterlist_registration as ml')
                ->leftJoin('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->select('ml.id', 'dr.doc_type_id', 'dr.sub_type_id')
                ->orderBy('ml.id')
                ->chunkById(200, function ($rows) use ($typeFlags) {
                    foreach ($rows as $row) {
                        $typeId = $row->sub_type_id ?: $row->doc_type_id;
                        $allows = $typeId !== null
                            ? ($typeFlags[(int) $typeId] ?? true)
                            : true;
                        DB::table('dcs_masterlist_registration')
                            ->where('id', $row->id)
                            ->update(['allows_revision' => $allows]);
                    }
                }, 'ml.id', 'id');
        }

        $this->dropActiveUniqueIndex();
        if (Schema::getConnection()->getDriverName() === 'pgsql'
            && Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')
            && Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            DB::statement("
                CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
                ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
                WHERE revision_status = 'latest' AND allows_revision = true
            ");
        } else {
            $this->rebuildActiveUniqueIndex();
        }
    }

    private function dropDocTypeColumn(): void
    {
        if (Schema::hasTable('dcs_doc_types') && Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
            Schema::table('dcs_doc_types', function (Blueprint $table) {
                $table->dropColumn('allows_revision');
            });
        }
    }

    private function dropMasterlistColumn(): void
    {
        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->dropColumn('allows_revision');
            });
        }
    }

    private function dropActiveUniqueIndex(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');
    }

    private function rebuildActiveUniqueIndex(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql'
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');
        DB::statement("
            CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
            ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
            WHERE revision_status = 'latest'
        ");
    }

    /**
     * Rows that were allowed to share (doc_no, revise_no, doc_type_id) as many
     * "latest" entries under allows_revision=false must be collapsed before the
     * stricter unique index is recreated.
     */
    private function collapseDuplicateLatestRows(): void
    {
        if (! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

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

            array_shift($ids); // keep newest as latest
            DB::table('dcs_masterlist_registration')
                ->whereIn('id', $ids)
                ->update([
                    'revision_status' => 'obsolete',
                    'updated_at' => now(),
                ]);
        }
    }
};
