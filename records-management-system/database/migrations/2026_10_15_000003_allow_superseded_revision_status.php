<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recur marks the previous latest row as superseded.
 * The live check only allowed latest, obsolete, and archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        $attr = DB::selectOne("
            SELECT a.atttypid, t.typtype, t.typname
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid
            JOIN pg_type t ON t.oid = a.atttypid
            WHERE c.relname = 'dcs_masterlist_registration'
              AND a.attname = 'revision_status'
              AND a.attnum > 0
              AND NOT a.attisdropped
            LIMIT 1
        ");
        if (! $attr) {
            return;
        }

        if ($attr->typtype === 'e') {
            $typeName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $attr->typname);
            if ($typeName !== '') {
                DB::statement('ALTER TYPE '.$typeName." ADD VALUE IF NOT EXISTS 'superseded'");
            }

            return;
        }

        $constraint = DB::selectOne("
            SELECT c.conname
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            WHERE t.relname = 'dcs_masterlist_registration'
              AND c.contype = 'c'
              AND pg_get_constraintdef(c.oid) ILIKE '%revision_status%'
            LIMIT 1
        ");
        if (! $constraint) {
            return;
        }

        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $constraint->conname);
        if ($name === '') {
            return;
        }

        DB::statement('ALTER TABLE dcs_masterlist_registration DROP CONSTRAINT '.$name);
        DB::statement("
            ALTER TABLE dcs_masterlist_registration
            ADD CONSTRAINT {$name}
            CHECK (revision_status IS NULL OR revision_status::text = ANY (ARRAY['latest'::text, 'obsolete'::text, 'archived'::text, 'superseded'::text, ''::text]))
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        DB::table('dcs_masterlist_registration')
            ->where('revision_status', 'superseded')
            ->update(['revision_status' => 'obsolete', 'updated_at' => now()]);

        $constraint = DB::selectOne("
            SELECT c.conname
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            WHERE t.relname = 'dcs_masterlist_registration'
              AND c.contype = 'c'
              AND pg_get_constraintdef(c.oid) ILIKE '%revision_status%'
            LIMIT 1
        ");
        if (! $constraint) {
            return;
        }

        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $constraint->conname);
        if ($name === '') {
            return;
        }

        DB::statement('ALTER TABLE dcs_masterlist_registration DROP CONSTRAINT '.$name);
        DB::statement("
            ALTER TABLE dcs_masterlist_registration
            ADD CONSTRAINT {$name}
            CHECK (revision_status::text = ANY (ARRAY['latest'::text, 'obsolete'::text, 'archived'::text]))
        ");
    }
};
