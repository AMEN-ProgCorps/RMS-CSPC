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
        Schema::create('rdp_retention_period', function (Blueprint $table) {
            $table->id();
            $table->string('active_period')->nullable();
            $table->string('storage_period')->nullable();
            $table->string('total_period')->nullable();
            $table->timestamps();
        });

        Schema::create('rdp_record_series', function (Blueprint $table) {
            $table->id();
            $table->integer('item_number')->nullable();
            $table->string('series_title')->nullable();
            $table->string('parent_id')->nullable();
            $table->unsignedBigInteger('retention_period')->nullable();
            $table->boolean('is_retention_period_active')->default(false);
            $table->string('remarks')->nullable();
            $table->string('recorded_at_office')->nullable();
            $table->foreign('retention_period')->references('id')->on('rdp_retention_period')->onDelete('cascade');
            $table->foreign('recorded_at_office')->references('office_code')->on('office')->onDelete('cascade');
            $table->timestamps();
        });
        
        Schema::create('rdp_recorded_value', function (Blueprint $table) {
            $table->id();
            $table->string('medium_name')->notNull();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        
        Schema::create('rdp_utility_medium', function (Blueprint $table) {
            $table->id();
            $table->string('utility_name')->notNull();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rdp_time_value', function (Blueprint $table) {
            $table->id();
            $table->char('char_value', 1)->unique()->notNull();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        
        Schema::create('rdp_volumn_convertion', function (Blueprint $table) {
            $table->id();
            $table->string('value_standard')->notNull();
            $table->string('value_converted')->notNull();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('rdp_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('record_series_id');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('period_id')->unique();
            $table->string('volume')->nullable();
            $table->unsignedBigInteger('records_medium')->nullable();
            $table->char('time_value', 1)->nullable();
            $table->unsignedBigInteger('utility_value')->nullable();
            $table->unsignedInteger('user_own')->nullable();
            $table->string('office_own')->nullable();
            $table->string('upload_doc_id_handler')->unique()->nullable();
            $table->foreign('record_series_id')->references('id')->on('rdp_record_series')->onDelete('cascade');
            $table->foreign('period_id')->references('id')->on('rdp_retention_period')->onDelete('cascade');
            $table->foreign('records_medium')->references('id')->on('rdp_recorded_value')->onDelete('cascade');
            $table->foreign('time_value')->references('char_value')->on('rdp_time_value')->onDelete('cascade');
            $table->foreign('utility_value')->references('id')->on('rdp_utility_medium')->onDelete('cascade');
            $table->foreign('user_own')->references('id')->on('account')->onDelete('cascade');
            $table->foreign('office_own')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('upload_doc_id_handler')->references('document_id')->on('document_data')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rdp_document_record', function (Blueprint $table) {
            $table->string('parent_id');
            $table->string('doc_name')->nullable();
            $table->string('doc_path')->nullable();
            $table->string('office_code')->nullable();
            $table->foreign('parent_id')->references('upload_doc_id_handler')->on('rdp_record')->onDelete('cascade');
            $table->foreign('office_code')->references('office_code')->on('office')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rdp_document_record');
        Schema::dropIfExists('rdp_record');
        Schema::dropIfExists('rdp_volumn_convertion');
        Schema::dropIfExists('rdp_time_value');
        Schema::dropIfExists('rdp_utility_medium');
        Schema::dropIfExists('rdp_recorded_value');
        Schema::dropIfExists('rdp_record_series');
        Schema::dropIfExists('rdp_retention_period');        
    }
};
