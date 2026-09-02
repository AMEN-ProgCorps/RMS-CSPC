<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_db_custom_columns')) {
            Schema::create('dcs_db_custom_columns', function (Blueprint $table) {
                $table->id();
                $table->string('label', 150);
                $table->string('group_key', 40);
                $table->unsignedInteger('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['group_key', 'sort_order']);
            });
        }

        if (! Schema::hasTable('dcs_db_custom_column_values')) {
            Schema::create('dcs_db_custom_column_values', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('custom_column_id');
                $table->unsignedBigInteger('request_id');
                $table->text('value')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['custom_column_id', 'request_id'], 'dcs_db_custom_col_val_unique');
                $table->index('request_id');

                $table->foreign('custom_column_id')
                    ->references('id')
                    ->on('dcs_db_custom_columns')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_db_custom_column_values');
        Schema::dropIfExists('dcs_db_custom_columns');
    }
};
