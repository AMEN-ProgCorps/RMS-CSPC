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
        if (Schema::hasTable('sys_backup_logs')) {
            return;
        }

        $accountTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        Schema::create('sys_backup_logs', function (Blueprint $table) use ($accountTable) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('snap_type', 50)->default('full');
            $table->string('filename')->unique();
            $table->string('file_size', 50)->nullable();
            $table->unsignedInteger('tables_count')->nullable();
            $table->unsignedInteger('total_records')->nullable();
            $table->json('categories_included')->nullable();
            $table->boolean('is_saved_on_cloud')->default(false);
            $table->string('cloud_service_name')->nullable();
            $table->boolean('is_saved_on_local')->default(true);
            $table->string('status', 30)->default('completed');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')
                ->references('id')
                ->on($accountTable)
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_backup_logs');
    }
};
