<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecordTable extends Migration
{
    public function up()
    {
        Schema::create('record', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('barcode')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('record');
    }
}
