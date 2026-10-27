<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sys_error_tickets')) {
            return;
        }

        $accountTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        Schema::create('sys_error_tickets', function (Blueprint $table) use ($accountTable) {
            $table->id();
            $table->string('ticket_number', 32)->unique();
            $table->string('source', 16)->default('auto'); // auto | user
            $table->string('reference', 32)->nullable()->index();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('kind', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('url')->nullable();
            $table->string('subsystem', 32)->nullable();
            $table->string('exception_class')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->json('context')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 16)->default('medium')->index();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('reported_by')->nullable();
            $table->unsignedInteger('assigned_to')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('created_at');

            $table->foreign('reported_by')
                ->references('id')
                ->on($accountTable)
                ->nullOnDelete();

            $table->foreign('assigned_to')
                ->references('id')
                ->on($accountTable)
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_error_tickets');
    }
};
