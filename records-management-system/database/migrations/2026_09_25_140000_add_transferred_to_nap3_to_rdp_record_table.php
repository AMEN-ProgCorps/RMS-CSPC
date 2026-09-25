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
        Schema::table('rdp_record', function (Blueprint $table) {
            if (!Schema::hasColumn('rdp_record', 'transferred_to_nap3')) {
                $table->boolean('transferred_to_nap3')->default(false)->index();
            }
            if (!Schema::hasColumn('rdp_record', 'transferred_to_nap3_at')) {
                $table->timestamp('transferred_to_nap3_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rdp_record', function (Blueprint $table) {
            if (Schema::hasColumn('rdp_record', 'transferred_to_nap3')) {
                $table->dropColumn('transferred_to_nap3');
            }
            if (Schema::hasColumn('rdp_record', 'transferred_to_nap3_at')) {
                $table->dropColumn('transferred_to_nap3_at');
            }
        });
    }
};
