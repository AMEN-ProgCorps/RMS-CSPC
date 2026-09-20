<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-revisable document types (e.g. Syllabi / TOS): always Rev 0, stack same doc_no
 * as sibling "latest" rows without DCN / obsolescence.
 */
return new class extends Migration
{
    public function up(): void
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
                    DB::table('dcs_doc_types')
                        ->where('id', $type->id)
                        ->update(['allows_revision' => false]);
                }
            }
        }

        // Denormalize onto existing masterlist rows from request subtype (else parent type).
        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')
            && Schema::hasColumn('dcs_doc_types', 'allows_revision')
            && Schema::hasTable('dcs_document_requests')) {
            $rows = DB::table('dcs_masterlist_registration as ml')
                ->leftJoin('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->select('ml.id', 'dr.doc_type_id', 'dr.sub_type_id')
                ->get();

            $typeFlags = DB::table('dcs_doc_types')
                ->pluck('allows_revision', 'id')
                ->map(fn ($v) => (bool) $v)
                ->all();

            foreach ($rows as $row) {
                $typeId = $row->sub_type_id ?: $row->doc_type_id;
                $allows = $typeId !== null
                    ? ($typeFlags[(int) $typeId] ?? true)
                    : true;
                DB::table('dcs_masterlist_registration')
                    ->where('id', $row->id)
                    ->update(['allows_revision' => $allows]);
            }
        }

        $this->rebuildUniqueIndex(true);
    }

    public function down(): void
    {
        $this->rebuildUniqueIndex(false);

        if (Schema::hasTable('dcs_masterlist_registration')
            && Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->dropColumn('allows_revision');
            });
        }

        if (Schema::hasTable('dcs_doc_types') && Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
            Schema::table('dcs_doc_types', function (Blueprint $table) {
                $table->dropColumn('allows_revision');
            });
        }
    }

    private function rebuildUniqueIndex(bool $withAllowsRevision): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS dcs_ml_doc_no_revise_type_active_unique');

        if ($withAllowsRevision && Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            // Keep latest-only uniqueness (Sept 17) and limit it to revisable docs so
            // non-revisable stacks may share (doc_no, revise_no=0) as many latest rows.
            DB::statement("
                CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
                ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
                WHERE revision_status = 'latest' AND allows_revision = true
            ");
        } else {
            DB::statement("
                CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
                ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
                WHERE revision_status = 'latest'
            ");
        }
    }
};
