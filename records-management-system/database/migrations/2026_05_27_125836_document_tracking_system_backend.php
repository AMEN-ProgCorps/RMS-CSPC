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
        // generating the table that has no dependencies first
        Schema::create('dts_qr_code', function (Blueprint $table) {
            $table->string('code_id')->primary();
            $table->enum('qr_status', ['used', 'not used', 'drafted']);
            $table->timestamp('created_at')->index();
        });

        Schema::create('dts_email_access', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->primary();
            $table->string('email')->index()->notNull();
            $table->boolean('is_active')->default(false);
            $table->timestamp('date_created')->index();
        });

        Schema::create('dts_transaction_flow', function (Blueprint $table) {
            $table->string('flow_code')->notNull()->unique();
            $table->string('flow_name')->notNull()->unique();
            $table->integer('id')->unique()->notNull();
            $table->unsignedInteger('added_by')->notNull();
            $table->timestamp('date_added')->index()->notNull();
            $table->enum('flow_use', ['internal', 'external', 'issuances','application','others','none'])->index()->notNull();
            $table->boolean('is_active')->notNull();
            $table->foreign('added_by')->references('id')->on('account')->onDelete('cascade');
        });

        Schema::create('dts_document_data', function (Blueprint $table) {
            $table->string('document_id')->primary()->unique()->notNull();
            $table->string('document_name')->notNull()->unique();
            $table->string('document_path')->nullable()->index();
            $table->timestamp('date_added')->index()->notNull();
            $table->timestamp('date_modified')->index()->notNull();
            $table->timestamp('date_deleted')->index()->notNull();
        });

        // The table with foreign key dependencies are here
        Schema::create('dts_sequence_list', function (Blueprint $table) {
            $table->integer('control_id')->notNull()->index();
            $table->integer('sequence_ranking')->notNull()->index();
            $table->string('office_code')->index()->notNull();
            $table->foreign('control_id')->references('id')->on('dts_transaction_flow')->onDelete('cascade');
            $table->foreign('office_code')->references('office_code')->on('office')->onDelete('cascade');
        });

        Schema::create('dts_document_trans', function (Blueprint $table) {
            $table->string('dt_name')->primary();
            $table->string('document_id');
            $table->string('office_code');
            $table->boolean('is_active')->default(true);
            $table->foreign('dt_name')->references('document_name')->on('dts_document_data')->onDelete('cascade');
            $table->foreign('document_id')->references('document_id')->on('dts_document_data')->onDelete('cascade');
            $table->foreign('office_code')->references('office_code')->on('office')->onDelete('cascade');
        });

        // here's the main table for the document tracking system
        Schema::create('dts_transactions', function (Blueprint $table) {
            $table->string('transaction_id')->primary();
            $table->boolean('enable_notif')->default(true);
            $table->enum('trans_type', ['internal', 'external', 'memorandom','others'])->index()->notNull();
            $table->string('doc_dir')->nullable();
            $table->string('qr_code')->index()->notNull();
            $table->string('current_office')->index()->notNull();
            $table->enum('status', ['completed','ongoing','revision', 'cancelled','drafted'])->default('ongoing')->index();
            $table->integer('sequence')->index()->notNull();
            $table->foreign('doc_dir')->references('document_path')->on('dts_document_data')->onDelete('cascade');
            $table->foreign('qr_code')->references('code_id')->on('dts_qr_code')->onDelete('cascade');
            $table->foreign('current_office')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('sequence')->references('sequence_ranking')->on('dts_sequence_list')->onDelete('cascade');
        });

        Schema::create('dts_copy_filled_transaction', function (Blueprint $table) {
            $table->increments('id');
            $table->string('control_num');
            $table->integer('total_office');
            $table->unsignedInteger('modified_owner')->nullable();
            $table->boolean('is_modified')->default(false);
            $table->timestamp('data_created')->useCurrent();
            $table->integer('assign_offices_id')->unique();
            $table->timestamp('date_modified')->useCurrent();
            $table->foreign('modified_owner')->references('id')->on('account')->onDelete('cascade');
        });

        Schema::create('dts_copy_filled_to_office', function (Blueprint $table) {
            $table->integer('control_id');
            $table->string('office_code');
            $table->foreign('control_id')->references('assign_offices_id')->on('dts_copy_filled_transaction')->onDelete('cascade');
            $table->foreign('office_code')->references('office_code')->on('office')->onDelete('cascade');
        });

        Schema::create('dts_transaction_details', function (Blueprint $table) {
            $table->string('id')->index();
            $table->enum('type', ['internal', 'external', 'memorandom','others']);
            $table->unsignedInteger('created_by')->notNull();
            $table->string('originated_from')->notNull();
            $table->string('current_office_hold')->notNull();
            $table->enum('status', ['completed','ongoing','revision', 'cancelled','drafted'])->index();
            $table->string('document_password')->nullable();
            $table->integer('email_access')->nullable();
            $table->string('transaction_flow');
            $table->boolean('is_active')->default(true);
            $table->timestamp('date_created')->index()->notNull();
            $table->string('control_number')->index()->notNull();
            $table->unsignedInteger('copy_filled_id')->nullable();
            $table->foreign('id')->references('transaction_id')->on('dts_transactions')->onDelete('cascade');
            $table->foreign('type')->references('trans_type')->on('dts_transactions')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('account')->onDelete('cascade');
            $table->foreign('originated_from')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('current_office_hold')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('status')->references('status')->on('dts_transactions')->onDelete('cascade');
            $table->foreign('email_access')->references('id')->on('dts_email_access')->onDelete('cascade');
            $table->foreign('transaction_flow')->references('flow_code')->on('dts_transaction_flow')->onDelete('cascade');
            $table->foreign('copy_filled_id')->references('id')->on('dts_copy_filled_transaction')->onDelete('cascade');
        });
    }

    /** 
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dts_copy_filled_to_office');
        Schema::dropIfExists('dts_transaction_details');
        Schema::dropIfExists('dts_copy_filled_transaction');
        Schema::dropIfExists('dts_qr_code');
        Schema::dropIfExists('dts_email_access');
        Schema::dropIfExists('dts_transaction_flow');
        Schema::dropIfExists('dts_document_trans');
        Schema::dropIfExists('dts_document_data');
        Schema::dropIfExists('dts_sequence_list');
        Schema::dropIfExists('dts_transactions');
    }
};
