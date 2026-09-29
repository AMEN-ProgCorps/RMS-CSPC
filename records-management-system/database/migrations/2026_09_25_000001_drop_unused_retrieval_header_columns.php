<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrieval form date/time, time spent, and remarks were removed from the UI.
 * Per-office retrieval date/time stays on dcs_retrieval_offices.
 */
return new class extends Migration
{
    private const TABLE = 'dcs_document_retrieval';

    /** @var list<string> */
    private const COLUMNS = [
        'doc_retrieval_date_actual',
        'doc_retrieval_time_actual',
        'doc_retrieval_date_file',
        'doc_retrieval_time_file',
        'time_spent',
        'remarks',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $drop = array_values(array_filter(
            self::COLUMNS,
            fn (string $column) => Schema::hasColumn(self::TABLE, $column)
        ));

        if ($drop === []) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'doc_retrieval_date_actual')) {
                $table->date('doc_retrieval_date_actual')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'doc_retrieval_time_actual')) {
                $table->time('doc_retrieval_time_actual')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'doc_retrieval_date_file')) {
                $table->date('doc_retrieval_date_file')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'doc_retrieval_time_file')) {
                $table->time('doc_retrieval_time_file')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'time_spent')) {
                $table->integer('time_spent')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'remarks')) {
                $table->text('remarks')->nullable();
            }
        });
    }
};
