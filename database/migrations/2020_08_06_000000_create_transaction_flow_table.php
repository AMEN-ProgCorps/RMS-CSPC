<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionFlowTable extends Migration
{
    public function up()
    {
        Schema::create('transaction_flow', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_nature_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->timestamps();
            
            $table->foreign('transaction_nature_id')->references('id')->on('transaction_nature')->onDelete('cascade');
            $table->foreign('office_id')->references('id')->on('office')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_flow');
    }
}
