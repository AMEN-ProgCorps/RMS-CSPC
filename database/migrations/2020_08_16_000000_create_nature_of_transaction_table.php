<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNatureOfTransactionTable extends Migration
{
    public function up()
    {
        Schema::create('nature_of_transaction', function (Blueprint $table) {
            $table->bigIncrements('Nature_id');
            $table->string('description');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->boolean('isfreeflow')->default(0);
            // no timestamps per model

            $table->foreign('office_id')->references('id')->on('office')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('nature_of_transaction');
    }
}
