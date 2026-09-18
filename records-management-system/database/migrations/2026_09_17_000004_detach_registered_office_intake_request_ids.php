<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registered office-intake DRF/DCN must not keep request_id pointing at the
 * controlled registration — that made Update/Database exclude the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->detachTable('dcs_document_request_form');
        $this->detachTable('dcs_document_change_notice');
    }

    public function down(): void
    {
        // Irreversible data repair.
    }

    private function detachTable(string $table): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'is_office_intake')
            || ! Schema::hasColumn($table, 'request_id')) {
            return;
        }

        $update = ['request_id' => null];
        if (Schema::hasColumn($table, 'updated_at')) {
            $update['updated_at'] = now();
        }

        DB::table($table)
            ->where('is_office_intake', true)
            ->whereNotNull('request_id')
            ->update($update);
    }
};
