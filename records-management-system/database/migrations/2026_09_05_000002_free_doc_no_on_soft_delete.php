<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-deleted documents must free (doc_no, revise_no, doc_type_id) so the
 * number can be reused. Unique index only covers latest|obsolete; archived is free.
 * Restore still blocks when an active document already took that key.
 */
return new class extends Migration
{
    /** Enum ALTER TYPE ADD VALUE cannot run inside a transaction on some PG versions. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        $this->ensureArchivedStatusAllowed();
        $this->archiveSoftDeletedMasterlistRows();
    }

    public function down(): void
    {
        // Keep archived label — do not shrink enum or re-occupy unique keys.
    }

    private function ensureArchivedStatusAllowed(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->ensurePostgresArchived();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("
                ALTER TABLE dcs_masterlist_registration
                MODIFY revision_status ENUM('latest', 'obsolete', 'archived')
                NOT NULL DEFAULT 'latest'
            ");
        }
    }

    private function ensurePostgresArchived(): void
    {
        $attr = DB::selectOne(
            '
            SELECT t.typtype, t.typname, a.atttypid
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_type t ON t.oid = a.atttypid
            WHERE c.relname = ?
              AND a.attname = ?
              AND a.attnum > 0
              AND NOT a.attisdropped
            LIMIT 1
            ',
            ['dcs_masterlist_registration', 'revision_status']
        );

        if ($attr && ($attr->typtype ?? null) === 'e') {
            $exists = DB::selectOne(
                'SELECT 1 FROM pg_enum WHERE enumtypid = ? AND enumlabel = ? LIMIT 1',
                [(int) $attr->atttypid, 'archived']
            );
            if ($exists === null) {
                $typeName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $attr->typname);
                if ($typeName !== '') {
                    DB::statement('ALTER TYPE '.$typeName." ADD VALUE IF NOT EXISTS 'archived'");
                }
            }

            return;
        }

        $constraint = DB::selectOne(
            "
            SELECT c.conname, pg_get_constraintdef(c.oid) AS def
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            WHERE t.relname = 'dcs_masterlist_registration'
              AND c.contype = 'c'
              AND pg_get_constraintdef(c.oid) ILIKE '%revision_status%'
            LIMIT 1
            "
        );

        if ($constraint && stripos((string) $constraint->def, 'archived') === false) {
            $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $constraint->conname);
            if ($name !== '') {
                DB::statement('ALTER TABLE dcs_masterlist_registration DROP CONSTRAINT '.$name);
                DB::statement("
                    ALTER TABLE dcs_masterlist_registration
                    ADD CONSTRAINT {$name}
                    CHECK (revision_status::text = ANY (ARRAY['latest'::text, 'obsolete'::text, 'archived'::text]))
                ");
            }
        }
    }

    private function archiveSoftDeletedMasterlistRows(): void
    {
        if (! Schema::hasTable('dcs_document_requests')
            || ! Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            return;
        }

        $ids = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereNotNull('dr.deleted_at')
            ->where(function ($q) {
                $q->whereIn('ml.revision_status', ['latest', 'obsolete'])
                    ->orWhereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '');
            })
            ->pluck('ml.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('dcs_masterlist_registration')
            ->whereIn('id', $ids->all())
            ->update([
                'revision_status' => 'archived',
                'updated_at' => now(),
            ]);
    }
};
