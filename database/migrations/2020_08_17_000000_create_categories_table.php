<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoriesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('code')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->integer('type')->default(1);
                $table->softDeletes();
                $table->timestamps();

                $table->foreign('parent_id')->references('id')->on('categories')->onDelete('set null');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('categories');
    }
}
