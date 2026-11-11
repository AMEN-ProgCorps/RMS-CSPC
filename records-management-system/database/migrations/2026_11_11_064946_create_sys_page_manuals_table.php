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
        Schema::create('sys_page_manuals', function (Blueprint $table) {
            $table->id();
            $table->string('target_key', 120)->unique()->index(); // e.g. 'dts.create.internal', 'modal.dts.transaction.details'
            $table->string('target_name', 160)->nullable();
            $table->string('subsystem', 40)->default('common')->index();
            $table->string('type', 20)->default('page')->index(); // 'page' or 'modal'
            $table->string('relative_path', 255)->nullable()->index(); // e.g. 'dts/dts.create.internal.md' or 'disabled'
            $table->string('title', 200)->nullable();
            $table->text('purpose')->nullable();
            $table->integer('line_count')->default(0);
            $table->boolean('is_custom_mapped')->default(false);
            $table->json('parsed_content')->nullable(); // Cached sections, callouts, steps when loaded
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_page_manuals');
    }
};
