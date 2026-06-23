<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sub_document_tracking_system_logs_types', function (Blueprint $table) {
            $table->string('type_id')->primary();
            $table->string('type_label');
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('sub_document_tracking_system_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('transaction_id')->index();
            $table->string('office_code')->index()->nullable();
            $table->string('type')->index();
            $table->timestamp('date_in')->nullable();
            $table->timestamp('date_out')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('performed_by')->nullable();

            $table->foreign('transaction_id')->references('transaction_id')->on('dts_transactions')->onDelete('cascade');
            $table->foreign('office_code')->references('office_code')->on('office')->onDelete('set null');
            $table->foreign('type')->references('type_id')->on('sub_document_tracking_system_logs_types')->onDelete('cascade');
            $table->foreign('performed_by')->references('id')->on('account')->onDelete('set null');
        });

        // Seed default log types
        DB::table('sub_document_tracking_system_logs_types')->insert([
            [
                'type_id' => 'created',
                'type_label' => 'Created',
                'description' => 'The transaction has been created.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type_id' => 'forwarded',
                'type_label' => 'Forwarded',
                'description' => 'The transaction has been forwarded to the next office.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type_id' => 'received',
                'type_label' => 'Received',
                'description' => 'The transaction has been received.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type_id' => 'returned',
                'type_label' => 'Returned for Revision',
                'description' => 'The transaction has been returned to the originating office for revision.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type_id' => 'completed',
                'type_label' => 'Completed',
                'description' => 'The transaction has been completed.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_document_tracking_system_logs');
        Schema::dropIfExists('sub_document_tracking_system_logs_types');
    }
};
