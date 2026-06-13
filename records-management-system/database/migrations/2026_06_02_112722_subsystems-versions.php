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
        Schema::create('subsystems', function (Blueprint $table) {
            $table->integer('subsystem_id')->primary()->autoIncrement();
            $table->string('subsystem_name', 255)->unique();
            $table->string('subsystem_version', 50)->index();
            $table->timestamp('created_at')->index();
            $table->timestamp('update_at')->index();
        });

        Schema::create('subsystem_versions_log', function (Blueprint $table) {
            $table->integer('changes_id')->primary()->autoIncrement();
            $table->integer('subsystem_key')->index();
            $table->string('version_change', 50);
            $table->timestamp('changes_on')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subsystems');
        Schema::dropIfExists('subsystem_versions_log');
    }
};
