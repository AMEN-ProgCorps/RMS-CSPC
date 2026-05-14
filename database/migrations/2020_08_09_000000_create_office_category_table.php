<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOfficeCategoryTable extends Migration
{
    public function up()
    {
        Schema::create('office_category', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('office_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
            
            $table->foreign('office_id')->references('id')->on('office')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('category')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('office_category');
    }
}
