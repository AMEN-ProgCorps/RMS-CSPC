<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateBarcodedDocumentsTable extends Migration
{
    public function up()
    {
        Schema::table('barcoded_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('barcoded_documents', 'Barcode')) {
                $table->string('Barcode')->unique()->nullable();
            }
            if (!Schema::hasColumn('barcoded_documents', 'Date_created')) {
                $table->dateTime('Date_created')->nullable();
            }
            if (!Schema::hasColumn('barcoded_documents', 'requestorid')) {
                $table->unsignedBigInteger('requestorid')->nullable();
            }
            if (!Schema::hasColumn('barcoded_documents', 'current_office')) {
                $table->unsignedBigInteger('current_office')->nullable();
            }
            if (!Schema::hasColumn('barcoded_documents', 'nature_id')) {
                $table->unsignedBigInteger('nature_id')->nullable();
            }
            if (!Schema::hasColumn('barcoded_documents', 'completed')) {
                $table->boolean('completed')->default(0);
            }
        });
    }

    public function down()
    {
        Schema::table('barcoded_documents', function (Blueprint $table) {
            $table->dropColumn(['Barcode','Date_created','requestorid','current_office','nature_id','completed']);
        });
    }
}
