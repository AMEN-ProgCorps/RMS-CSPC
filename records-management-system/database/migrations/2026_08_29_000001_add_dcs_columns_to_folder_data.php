<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('folder_data')) {
            return;
        }

        Schema::table('folder_data', function (Blueprint $table) {
            if (! Schema::hasColumn('folder_data', 'is_dcs_available')) {
                $table->boolean('is_dcs_available')->default(false)->after('current_rdp_size');
            }
            if (! Schema::hasColumn('folder_data', 'current_dcs_size')) {
                $table->unsignedBigInteger('current_dcs_size')->default(0)->after('is_dcs_available');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('folder_data')) {
            return;
        }

        Schema::table('folder_data', function (Blueprint $table) {
            if (Schema::hasColumn('folder_data', 'current_dcs_size')) {
                $table->dropColumn('current_dcs_size');
            }
            if (Schema::hasColumn('folder_data', 'is_dcs_available')) {
                $table->dropColumn('is_dcs_available');
            }
        });
    }
};
