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
        Schema::create('dts_requestor_history', function (Blueprint $table) {
            $table->id();
            $table->string('requestor_name');
            $table->string('requestor_position');
            $table->string('office');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('dts_source_office', function (Blueprint $table) {
            $table->id();
            $table->string('s_office_name');
            $table->string('s_office_code')->unique();
            $table->string('created_by_office');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('created_by_office')->references('office_code')->on('office')->onDelete('cascade');
        });

        Schema::table('dts_transaction_details', function (Blueprint $table) {
            $table->string('source_office')->nullable()->after('status');
            $table->unsignedBigInteger('requestor_id')->nullable()->after('source_office');
            
            $table->foreign('requestor_id')->references('id')->on('dts_requestor_history')->onDelete('cascade');
            $table->foreign('source_office')->references('s_office_code')->on('dts_source_office')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            $table->dropForeign(['requestor_id']);
            $table->dropForeign(['source_office']);
            $table->dropColumn(['source_office', 'requestor_id']);
        });

        Schema::dropIfExists('dts_source_office');
        Schema::dropIfExists('dts_requestor_history');
    }
};