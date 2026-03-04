<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBarcodedDocumentsTable extends Migration
{
    public function up()
    {
        Schema::create('barcoded_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Barcode')->unique()->nullable();
            $table->boolean('completed')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('barcoded_documents');
    }
}
