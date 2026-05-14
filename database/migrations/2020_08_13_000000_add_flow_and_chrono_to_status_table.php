<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFlowAndChronoToStatusTable extends Migration
{
    public function up()
    {
        Schema::table('status', function (Blueprint $table) {
            if (!Schema::hasColumn('status', 'flow')) {
                $table->integer('flow')->default(0);
            }
            if (!Schema::hasColumn('status', 'chrono')) {
                $table->integer('chrono')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('status', function (Blueprint $table) {
            if (Schema::hasColumn('status', 'flow')) {
                $table->dropColumn('flow');
            }
            if (Schema::hasColumn('status', 'chrono')) {
                $table->dropColumn('chrono');
            }
        });
    }
}
