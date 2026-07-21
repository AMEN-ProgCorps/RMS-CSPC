<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('personal_settings')) {
            Schema::create('personal_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user')->unique();
                $table->boolean('auto_open_chat')->default(true);
                $table->timestamps();

                $table->foreign('user')
                      ->references('id')
                      ->on('account')
                      ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_settings');
    }
};
