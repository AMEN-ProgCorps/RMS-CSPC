<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStatusTable extends Migration
{
    public function up()
    {
        Schema::create('status', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('barcode_value')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->dateTime('date_in')->nullable();
            $table->dateTime('date_out')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('originating_office')->nullable();
            $table->unsignedBigInteger('forwarded_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            
            $table->foreign('office_id')->references('id')->on('office')->onDelete('set null');
            $table->foreign('originating_office')->references('id')->on('office')->onDelete('set null');
            $table->foreign('forwarded_by')->references('id')->on('account')->onDelete('set null');
            $table->foreign('received_by')->references('id')->on('account')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('status');
    }
}
