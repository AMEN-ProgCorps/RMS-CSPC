<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> table => scan path column */
    private const SCAN_COLUMNS = [
        'dcs_document_request_form' => 'scanned_drf',
        'dcs_document_change_notice' => 'scanned_dcn',
        'dcs_masterlist_registration' => 'scanned_masterlist',
        'dcs_document_retrieval' => 'scanned_retrieval',
        'dcs_document_distribution' => 'scanned_distribution',
        'dcs_doc_revision' => 'scanned_copy',
        'dcs_syllabi_drf' => 'scanned_drf',
    ];

    public function up(): void
    {
        foreach (self::SCAN_COLUMNS as $table => $pathColumn) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $docIdColumn = $pathColumn . '_document_id';
            if (! Schema::hasColumn($table, $docIdColumn)) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($docIdColumn) {
                try {
                    $table->dropForeign([$docIdColumn]);
                } catch (\Throwable) {
                    // FK may not exist on all environments.
                }
            });
        }

        if (Schema::hasTable('document_data')) {
            DB::table('document_data')
                ->where(function ($q) {
                    $q->where('document_id', 'like', 'DCS%')
                        ->orWhere('document_path', 'like', '%/DCS/%');
                })
                ->delete();
        }
    }

    public function down(): void
    {
        foreach (self::SCAN_COLUMNS as $table => $pathColumn) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $docIdColumn = $pathColumn . '_document_id';
            if (! Schema::hasColumn($table, $docIdColumn)) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($docIdColumn) {
                $table->foreign($docIdColumn)
                    ->references('document_id')
                    ->on('document_data')
                    ->nullOnDelete();
            });
        }
    }
};
