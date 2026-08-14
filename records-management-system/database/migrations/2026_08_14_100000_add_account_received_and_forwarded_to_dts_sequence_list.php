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
        Schema::table('dts_sequence_list', function (Blueprint $table) {
            $table->unsignedInteger('account_received')->nullable()->after('note');
            $table->unsignedInteger('account_forwarded')->nullable()->after('account_received');

            $table->foreign('account_received')->references('id')->on('account')->onDelete('set null');
            $table->foreign('account_forwarded')->references('id')->on('account')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_sequence_list', function (Blueprint $table) {
            $table->dropForeign(['account_received']);
            $table->dropForeign(['account_forwarded']);
            $table->dropColumn(['account_received', 'account_forwarded']);
        });
    }
};
