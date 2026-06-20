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
        Schema::create('notif_content', function (Blueprint $table) {
            $table->increments('id'); 
            $table->integer('system'); 
            $table->string('content');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('system')->references('subsystem_id')->on('subsystems')->onDelete('cascade');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->string('office', 50); 
            $table->unsignedInteger('contents');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('office')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('contents')->references('id')->on('notif_content')->onDelete('cascade');
        });

        Schema::create('notification_div', function (Blueprint $table) {
            $table->unsignedInteger('id');
            $table->unsignedInteger('account_rec');
            $table->enum('status', ['read', 'unread'])->default('unread'); 
            $table->timestamp('processed_on')->useCurrent();
            $table->boolean('is_in_user_list')->default(true);            
            $table->foreign('id')->references('id')->on('notifications')->onDelete('cascade');
            $table->foreign('account_rec')->references('id')->on('account')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_div');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notif_content');
    }
};
