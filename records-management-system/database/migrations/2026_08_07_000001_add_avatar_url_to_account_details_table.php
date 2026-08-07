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
        if (!Schema::hasColumn('account_details', 'avatar_url')) {
            Schema::table('account_details', function (Blueprint $table) {
                $table->text('avatar_url')->nullable()->after('email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('account_details', 'avatar_url')) {
            Schema::table('account_details', function (Blueprint $table) {
                $table->dropColumn('avatar_url');
            });
        }
    }
};
