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
        Schema::table('account_details', function (Blueprint $table) {
            if (!Schema::hasColumn('account_details', 'allow_typing_preview')) {
                $table->boolean('allow_typing_preview')->default(true);
            }
            if (!Schema::hasColumn('account_details', 'allow_see_typing_preview')) {
                $table->boolean('allow_see_typing_preview')->default(true);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_details', function (Blueprint $table) {
            $table->dropColumn([
                'allow_typing_preview',
                'allow_see_typing_preview',
            ]);
        });
    }
};
