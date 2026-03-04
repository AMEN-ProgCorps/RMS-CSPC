<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionCfTable extends Migration
{
    public function up()
    {
        Schema::create('transaction_cf', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('barcode_value')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->dateTime('date_in')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();

            $table->foreign('office_id')->references('id')->on('office')->onDelete('set null');
            $table->foreign('received_by')->references('id')->on('account')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_cf');
    }
}
