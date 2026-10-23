<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rdp_remark_key_triggers', function (Blueprint $table) {
            $table->id();
            $table->text('key_word');
            $table->boolean('is_action_before')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('rdp_remarks_logic', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('key_word_id')->nullable();
            $table->foreign('key_word_id')->references('id')->on('rdp_remark_key_triggers')->onDelete('cascade');
            $table->text('remarks_value')->nullable();
            $table->text('active_period_backup')->nullable();
            $table->text('storage_period_backup')->nullable();
            $table->text('total_period_backup')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('rdp_location_suggestion', function (Blueprint $table) {
            $table->id();
            $table->text('location_name');
            $table->unsignedBigInteger('suggestion_from');
            $table->foreign('suggestion_from')->references('id')->on('rdp_record_series')->onDelete('cascade');
            $table->text('location_type');
            $table->timestamp('created_at')->useCurrent();
        });

        // Convert the existing free-text remarks on rdp_record_series into the
        // remarks_logic FK. Every non-empty remark is first copied into its own
        // rdp_remarks_logic row (backfill, decided 2026-10-23) so no data is
        // lost, then the series is pointed at that row's id. Postgres cannot
        // cast varchar -> bigint implicitly, so the conversion is done with an
        // explicit USING clause.
        if (Schema::hasTable('rdp_record_series') && Schema::hasColumn('rdp_record_series', 'remarks')) {
            $legacyRemarks = DB::table('rdp_record_series')
                ->whereNotNull('remarks')
                ->whereRaw("btrim(remarks) <> ''")
                ->get(['id', 'remarks']);

            foreach ($legacyRemarks as $series) {
                $logicId = DB::table('rdp_remarks_logic')->insertGetId([
                    'remarks_value' => $series->remarks,
                    'created_at'    => now(),
                ]);

                DB::table('rdp_record_series')->where('id', $series->id)->update(['remarks' => $logicId]);
            }

            DB::statement('ALTER TABLE rdp_record_series ALTER COLUMN remarks TYPE bigint USING (NULL::bigint)');
        }

        if (Schema::hasTable('rdp_remarks_logic') && Schema::hasColumn('rdp_record_series', 'remarks')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                $table->index('remarks');
                $table->foreign('remarks')->references('id')->on('rdp_remarks_logic')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record_series') && Schema::hasColumn('rdp_record_series', 'remarks')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                $table->dropForeign(['remarks']);
                $table->dropIndex(['remarks']);
            });

            DB::statement('ALTER TABLE rdp_record_series ALTER COLUMN remarks TYPE varchar(255) USING (NULL::varchar)');

            // Restore the text from rdp_remarks_logic before those rows go away.
            if (Schema::hasTable('rdp_remarks_logic')) {
                DB::statement('
                    UPDATE rdp_record_series
                    SET remarks = LEFT(rl.remarks_value, 255)
                    FROM rdp_remarks_logic rl
                    WHERE rdp_record_series.remarks = rl.id
                ');
            }
        }

        Schema::dropIfExists('rdp_location_suggestion');
        Schema::dropIfExists('rdp_remarks_logic');
        Schema::dropIfExists('rdp_remark_key_triggers');
    }
};
