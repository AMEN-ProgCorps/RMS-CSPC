<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot DCS schema cleanup for migrate-only deploys:
 * - Drop unused stamp CTC columns (certified_by, designation)
 * - Drop deferred custom Database column tables
 * - Drop masterlist brief_purpose (replaced by keywords)
 * - Drop scanned_*_document_id columns (document_data link abandoned)
 *
 * dcs_view_all_documents is intentionally kept.
 */
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
        $this->dropStampCtcColumns();
        $this->dropCustomColumnTables();
        $this->dropMasterlistBriefPurpose();
        $this->dropScanDocumentIdColumns();
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_document_stamps')) {
            Schema::table('dcs_document_stamps', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_document_stamps', 'certified_by')) {
                    $table->string('certified_by')->nullable();
                }
                if (! Schema::hasColumn('dcs_document_stamps', 'designation')) {
                    $table->string('designation')->nullable();
                }
            });
        }

        if (Schema::hasTable('dcs_masterlist_registration')
            && ! Schema::hasColumn('dcs_masterlist_registration', 'brief_purpose')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->text('brief_purpose')->nullable();
            });
        }

        foreach (self::SCAN_COLUMNS as $tableName => $pathColumn) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $docIdColumn = $pathColumn . '_document_id';
            if (Schema::hasColumn($tableName, $docIdColumn)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($pathColumn, $docIdColumn) {
                $table->string($docIdColumn)->nullable()->after($pathColumn);
            });
        }
    }

    private function dropStampCtcColumns(): void
    {
        if (! Schema::hasTable('dcs_document_stamps')) {
            return;
        }

        Schema::table('dcs_document_stamps', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_document_stamps', 'certified_by')) {
                $table->dropColumn('certified_by');
            }
            if (Schema::hasColumn('dcs_document_stamps', 'designation')) {
                $table->dropColumn('designation');
            }
        });
    }

    private function dropCustomColumnTables(): void
    {
        Schema::dropIfExists('dcs_db_custom_column_values');
        Schema::dropIfExists('dcs_db_custom_columns');
    }

    private function dropMasterlistBriefPurpose(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'brief_purpose')) {
            return;
        }

        if (Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
            DB::table('dcs_masterlist_registration')
                ->where(function ($q) {
                    $q->whereNull('keywords')->orWhere('keywords', '');
                })
                ->whereNotNull('brief_purpose')
                ->where('brief_purpose', '!=', '')
                ->update(['keywords' => DB::raw('brief_purpose')]);
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $table->dropColumn('brief_purpose');
        });
    }

    private function dropScanDocumentIdColumns(): void
    {
        foreach (self::SCAN_COLUMNS as $tableName => $pathColumn) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $docIdColumn = $pathColumn . '_document_id';
            if (! Schema::hasColumn($tableName, $docIdColumn)) {
                continue;
            }

            // dropForeign is queued — try/catch around it never catches the SQL error.
            // FKs were already removed by 2026_08_29_000003 on most envs.
            $fkName = $tableName . '_' . $docIdColumn . '_foreign';
            if ($this->hasForeignKey($fkName)) {
                Schema::table($tableName, function (Blueprint $table) use ($docIdColumn) {
                    $table->dropForeign([$docIdColumn]);
                });
            }

            Schema::table($tableName, function (Blueprint $table) use ($docIdColumn) {
                $table->dropColumn($docIdColumn);
            });
        }
    }

    private function hasForeignKey(string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            return DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conname = ? LIMIT 1',
                [$name]
            ) !== null;
        }

        return false;
    }
};
