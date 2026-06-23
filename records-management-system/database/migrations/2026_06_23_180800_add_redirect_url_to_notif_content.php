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
        Schema::table('notif_content', function (Blueprint $table) {
            $table->string('redirect_url', 500)->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notif_content', function (Blueprint $table) {
            $table->dropColumn('redirect_url');
        });
    }
};
