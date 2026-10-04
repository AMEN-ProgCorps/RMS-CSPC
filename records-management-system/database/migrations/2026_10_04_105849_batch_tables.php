<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('rdp_batch')) {
            Schema::create('rdp_batch', function (Blueprint $table) {
                $table->id();
                $table->string('batch_name')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('rdp_record', function (Blueprint $table) {
            if (!Schema::hasColumn('rdp_record', 'batch_id')) {
                $table->foreignId('batch_id')
                      ->nullable()
                      ->after('id')
                      ->constrained('rdp_batch')
                      ->nullOnDelete();
            }

            if (!Schema::hasColumn('rdp_record', 'ispartof_batch')) {
                $table->boolean('ispartof_batch')
                      ->default(false)
                      ->after('is_draft');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record')) {
            Schema::table('rdp_record', function (Blueprint $table) {
                if (Schema::hasColumn('rdp_record', 'batch_id')) {
                    $table->dropForeign(['batch_id']);
                    $table->dropColumn('batch_id');
                }
                if (Schema::hasColumn('rdp_record', 'ispartof_batch')) {
                    $table->dropColumn('ispartof_batch');
                }
            });
        }

        Schema::dropIfExists('rdp_batch');
    }
};
