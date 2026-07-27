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
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('retention_period')->nullable();
            $table->boolean('is_retention_period_permanent')->default(false);
            $table->string('remarks')->nullable();
            $table->string('recorded_at_office')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreign('parent_id')->references('id')->on('rdp_record_series')->onDelete('cascade');
            $table->foreign('retention_period')->references('id')->on('rdp_retention_period')->onDelete('cascade');
            $table->foreign('recorded_at_office')->references('office_code')->on('office')->onDelete('cascade');
            $table->timestamps();
        });
        
        Schema::create('rdp_recorded_value', function (Blueprint $table) {
            $table->id();
            $table->string('medium_name');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        
        Schema::create('rdp_frequence_use', function (Blueprint $table) {
            $table->id();
            $table->string('freq_type')->unique();
            $table->timestamps();
        });
        
        Schema::create('rdp_restriction_type', function (Blueprint $table) {
            $table->id();
            $table->string('restriction_value')->unique();
            $table->timestamps();
        });
        
        Schema::create('rdp_utility_medium', function (Blueprint $table) {
            $table->id();
            $table->string('utility_name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rdp_time_value', function (Blueprint $table) {
            $table->id();
            $table->char('char_value', 1)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rdp_volume_value', function (Blueprint $table) {
            $table->id("volume_id");
            $table->string('value_standard');
            $table->boolean('cur_used_standard')->default(false);
            $table->boolean('cur_used_converted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        Schema::create('rdp_volume_conversion', function (Blueprint $table) {
            $table->id();
            $table->Integer('amount_standard');
            $table->unsignedBigInteger('value_standard');
            $table->Integer('amount_converted');
            $table->unsignedBigInteger('value_converted');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('value_standard')->references('volume_id')->on('rdp_volume_value')->onDelete('cascade');
            $table->foreign('value_converted')->references('volume_id')->on('rdp_volume_value')->onDelete('cascade');
        });

        Schema::create('rdp_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('record_series_id');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->string('volume')->nullable();
            $table->string('records_location')->nullable();
            $table->string('restriction')->nullable();
            $table->unsignedBigInteger('records_medium')->nullable();
            $table->char('time_value', 1)->nullable();
            $table->string('frequence_use')->nullable();
            $table->unsignedBigInteger('utility_value')->nullable();
            $table->unsignedInteger('user_own')->nullable();
            $table->string('office_own')->nullable();
            $table->string('upload_doc_id_handler')->unique()->nullable();
            $table->unsignedBigInteger('duplication_id')->unique()->nullable();
            $table->foreign('record_series_id')->references('id')->on('rdp_record_series')->onDelete('cascade');
            $table->foreign('period_id')->references('id')->on('rdp_retention_period')->onDelete('set null');
            $table->foreign('restriction')->references('restriction_value')->on('rdp_restriction_type')->onDelete('cascade');
            $table->foreign('records_medium')->references('id')->on('rdp_recorded_value')->onDelete('cascade');
            $table->foreign('time_value')->references('char_value')->on('rdp_time_value')->onDelete('cascade');
            $table->foreign('frequence_use')->references('freq_type')->on('rdp_frequence_use')->onDelete('cascade');
            $table->foreign('utility_value')->references('id')->on('rdp_utility_medium')->onDelete('cascade');
            $table->foreign('user_own')->references('id')->on('account')->onDelete('cascade');
            $table->foreign('office_own')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('upload_doc_id_handler')->references('document_id')->on('document_data')->onDelete('cascade');
            $table->timestamps();
        });
        
        Schema::create('rdp_duplication_section', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dup_id_manager');
            $table->string('office_code')->nullable();
            $table->foreign('dup_id_manager')->references('duplication_id')->on('rdp_record')->onDelete('cascade');
            $table->foreign('office_code')->references('office_code')->on('office')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rdp_period_covered', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_owner')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->foreign('period_owner')->references('id')->on('rdp_record')->onDelete('cascade');
        });
        
        Schema::create('rdp_document_record', function (Blueprint $table) {
            $table->id();
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
        Schema::dropIfExists('rdp_duplication_section');
        Schema::dropIfExists('rdp_period_covered');
        Schema::dropIfExists('rdp_record');
        Schema::dropIfExists('rdp_volume_conversion');
        Schema::dropIfExists('rdp_time_value');
        Schema::dropIfExists('rdp_utility_medium');
        Schema::dropIfExists('rdp_restriction_type');
        Schema::dropIfExists('rdp_frequence_use');
        Schema::dropIfExists('rdp_recorded_value');
        Schema::dropIfExists('rdp_record_series');
        Schema::dropIfExists('rdp_retention_period');        
    }
};
