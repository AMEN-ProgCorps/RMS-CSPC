<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_monitoring_remarks')) {
            return;
        }
        if (Schema::hasColumn('dcs_monitoring_remarks', 'forwarded_drr')) {
            return;
        }

        Schema::table('dcs_monitoring_remarks', function (Blueprint $table) {
            $table->boolean('forwarded_drr')->default(false)->after('remarks');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_monitoring_remarks')
            || ! Schema::hasColumn('dcs_monitoring_remarks', 'forwarded_drr')) {
            return;
        }

        Schema::table('dcs_monitoring_remarks', function (Blueprint $table) {
            $table->dropColumn('forwarded_drr');
        });
    }
};
