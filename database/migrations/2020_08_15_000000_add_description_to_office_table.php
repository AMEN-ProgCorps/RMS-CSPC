<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDescriptionToOfficeTable extends Migration
{
    public function up()
    {
        Schema::table('office', function (Blueprint $table) {
            if (!Schema::hasColumn('office','description')) {
                $table->string('description')->nullable()->after('name');
            }
        });
    }

    public function down()
    {
        Schema::table('office', function (Blueprint $table) {
            if (Schema::hasColumn('office','description')) {
                $table->dropColumn('description');
            }
        });
    }
}
