<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_calendar_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 16)->default('#0d2a7a');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('account')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dcs_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('dcs_calendar_categories')->restrictOnDelete();
            $table->string('title');
            $table->date('event_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('description')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
            $table->index('event_date');
        });

        $now = now();
        DB::table('dcs_calendar_categories')->insert([
            ['name' => 'Travel', 'color' => '#0d2a7a', 'is_system' => true, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Leave', 'color' => '#b45309', 'is_system' => true, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'WFH', 'color' => '#047857', 'is_system' => true, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_calendar_events');
        Schema::dropIfExists('dcs_calendar_categories');
    }
};
