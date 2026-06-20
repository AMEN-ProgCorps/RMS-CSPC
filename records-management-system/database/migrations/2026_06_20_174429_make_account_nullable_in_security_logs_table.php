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
        Schema::table('security_logs', function (Blueprint $table) {
            $table->dropForeign(['account']);
        });

        Schema::table('security_logs', function (Blueprint $table) {
            $table->unsignedInteger('account')->nullable()->change();
            $table->foreign('account')->references('id')->on('account')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_logs', function (Blueprint $table) {
            $table->dropForeign(['account']);
        });

        Schema::table('security_logs', function (Blueprint $table) {
            $table->unsignedInteger('account')->nullable(false)->change();
            $table->foreign('account')->references('id')->on('account')->onDelete('cascade');
        });
    }
};
