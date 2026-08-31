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
        Schema::table('personal_settings', function (Blueprint $table) {
            $table->string('modal_close_key', 50)->nullable()->default('Escape')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_settings', function (Blueprint $table) {
            $table->string('modal_close_key', 50)->nullable(false)->default('Escape')->change();
        });
    }
};
