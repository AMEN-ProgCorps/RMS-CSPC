<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOfficeRecordsTable extends Migration
{
    public function up()
    {
        Schema::create('office_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('office_id');
            $table->unsignedBigInteger('record_id');
            $table->timestamps();
            
            $table->foreign('office_id')->references('id')->on('office')->onDelete('cascade');
            $table->foreign('record_id')->references('id')->on('record')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('office_records');
    }
}
