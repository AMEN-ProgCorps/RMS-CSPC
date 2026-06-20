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
            $table->increments('id')->primary(); 
            $table->integer('system'); 
            $table->string('content')->notNull();
            $table->timestamp('created_at')->useCurrent(); // Timestamp
            $table->foreign('system')->reference('subsystems_id')->on('subsystems')->onDelete('cascade');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('id')->primary();
            $table->string('office'); 
            $table->integer('contents');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('office')->reference('office_code')->on('office')->onDelete('cascade');
            $table->foreign('contents')->reference('id')->on('notif_content')->onDelete('cascade');
        });
        Schema::create('notification_div', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('account_rec');
            $table->enum('status', ['read', 'unread'])->default('unread'); 
            $table->timestamp('processed_on')->notNull();
            $table->boolean('is_in_user_list')->default(true);            
            $table->foreign('id')->reference('id')->on('notificationws')->onDelete('cascade');
            $table->foreign('account_rec')->reference('id')->on('account')->onDelete('cascade');
            $table->foreign('id')->reference('id')->on('notifications')->onDelete('cascade');
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

