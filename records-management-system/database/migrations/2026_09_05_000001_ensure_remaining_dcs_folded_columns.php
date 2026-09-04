<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive ensure for columns folded into CREATE migrations after deploy
 * already ran them (migrate-only). Complements 2026_08_28_000001.
 * Safe no-op when columns/indexes already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureDistributionDate();
        $this->ensureRetrievalOfficeColumns();
        $this->ensureOpcrRemarksOverride();
        $this->ensureMasterlistScanOriginalName();
        $this->ensureMasterlistOriginatorId();
        $this->ensureMasterlistActiveUniqueIndex();
    }

    public function down(): void
    {
        // Additive ensure — do not drop columns that may contain production data.
    }

    private function ensureDistributionDate(): void
    {
        if (! Schema::hasTable('dcs_distribution_offices')
            || Schema::hasColumn('dcs_distribution_offices', 'distribution_date')) {
            return;
        }

        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            $table->date('distribution_date')->nullable()->after('copies');
        });
    }

    private function ensureRetrievalOfficeColumns(): void
    {
        if (! Schema::hasTable('dcs_retrieval_offices')) {
            return;
        }

        Schema::table('dcs_retrieval_offices', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')) {
                $table->string('retrieval_status', 20)->default('pending')->after('copies');
            }
            if (! Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date')) {
                $table->date('retrieval_date')->nullable();
            }
        });
    }

    private function ensureOpcrRemarksOverride(): void
    {
        if (! Schema::hasTable('dcs_opcr_ratings')
            || Schema::hasColumn('dcs_opcr_ratings', 'remarks_override')) {
            return;
        }

        Schema::table('dcs_opcr_ratings', function (Blueprint $table) {
            $table->text('remarks_override')->nullable()->after('rating_a');
        });
    }

    private function ensureMasterlistScanOriginalName(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || Schema::hasColumn('dcs_masterlist_registration', 'scanned_masterlist_original_name')) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $after = Schema::hasColumn('dcs_masterlist_registration', 'scanned_masterlist')
                ? 'scanned_masterlist'
                : null;

            $col = $table->string('scanned_masterlist_original_name')->nullable();
            if ($after !== null) {
                $col->after($after);
            }
        });
    }

    private function ensureMasterlistOriginatorId(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasTable('dcs_originators')
            || Schema::hasColumn('dcs_masterlist_registration', 'originator_id')) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $after = Schema::hasColumn('dcs_masterlist_registration', 'originator_name')
                ? 'originator_name'
                : null;

            $col = $table->unsignedBigInteger('originator_id')->nullable();
            if ($after !== null) {
                $col->after($after);
            }
        });

        if (! $this->hasForeignKey('dcs_masterlist_registration_originator_id_foreign')) {
            Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                $table->foreign('originator_id')
                    ->references('id')
                    ->on('dcs_originators')
                    ->nullOnDelete();
            });
        }
    }

    private function ensureMasterlistActiveUniqueIndex(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')
            || $this->hasIndex('dcs_masterlist_registration', 'dcs_ml_doc_no_revise_type_active_unique')) {
            return;
        }

        if (! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'doc_no')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'revise_no')
            || ! Schema::hasColumn('dcs_masterlist_registration', 'doc_type_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement("
            CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
            ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
            WHERE revision_status IN ('latest', 'obsolete')
        ");
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

    private function hasIndex(string $table, string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            return DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                [$table, $name]
            ) !== null;
        }

        return Schema::hasIndex($table, $name);
    }
};
