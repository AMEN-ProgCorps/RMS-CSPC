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
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            $table->string('requestor_name')->nullable()->after('originated_from');
            $table->text('subject')->nullable()->after('requestor_name');
            $table->string('classification')->nullable()->after('subject');
            $table->string('action_needed')->nullable()->after('classification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            $table->dropColumn(['requestor_name', 'subject', 'classification', 'action_needed']);
        });
    }
};
