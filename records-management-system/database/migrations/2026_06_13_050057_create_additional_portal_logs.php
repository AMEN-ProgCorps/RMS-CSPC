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
        Schema::create('security_status', function(Blueprint $table){
            $table->integer('status_id')->primary();
            $table->string('status_name')->unique();
            $table->string('description')->unique();
            $table->timestamp('time')->index();
        });
        Schema::create('security_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('status');
            $table->unsignedInteger('account');
            $table->string('user_ipaddr');
            $table->timestamp('time')->index();
            $table->foreign('status')->references('status_id')->on('security_status')->onDelete('cascade');
            $table->foreign('account')->references('id')->on('account')->onDelete('cascade');
        });
        Schema::create('admin_logs', function (Blueprint $table){
            $table->increments('id');
            $table->string('changes')->index();
            $table->unsignedInteger('admin_id');
            $table->integer('what_system');
            $table->timestamp('when_changes')->index();
            $table->foreign('admin_id')->references('id')->on('account')->onDelete('cascade');
            $table->foreign('what_system')->references('subsystem_id')->on('subsystems')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
        Schema::dropIfExists('security_logs');
        Schema::dropIfExists('security_status');
    }
};
