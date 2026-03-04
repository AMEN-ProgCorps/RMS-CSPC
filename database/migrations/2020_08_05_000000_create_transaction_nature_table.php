<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionNatureTable extends Migration
{
    public function up()
    {
        Schema::create('transaction_nature', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->timestamps();
            
            $table->foreign('office_id')->references('id')->on('office')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_nature');
    }
}
