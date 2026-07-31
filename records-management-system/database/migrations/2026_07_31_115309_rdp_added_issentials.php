<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rdp_pending_status', function (Blueprint $table) {
            $table->id();
            $table->string('status_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default statuses
        $statuses = [
            ['id' => 1, 'status_name' => 'Pending Verification', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'status_name' => 'Approved', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'status_name' => 'Rejected', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'status_name' => 'Returned for Correction', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ];
        foreach ($statuses as $s) {
            DB::table('rdp_pending_status')->updateOrInsert(['id' => $s['id']], $s);
        }

        Schema::create('rdp_pending_record_series', function (Blueprint $table) {
            $table->id('cluster_id');
            $table->string('cluster_name');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('office')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->boolean('is_active')->default(true);
            $table->foreign('status_id')->references('id')->on('rdp_pending_status')->onDelete('cascade');
            $table->foreign('office')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('account')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rdp_grouped_record_series', function (Blueprint $table) {
            $table->id('group_id');
            $table->unsignedBigInteger('group_head');
            $table->unsignedBigInteger('record_series_id');
            $table->boolean('is_active')->default(true);
            $table->foreign('group_head')->references('cluster_id')->on('rdp_pending_record_series')->onDelete('cascade');
            $table->foreign('record_series_id')->references('id')->on('rdp_record_series')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rdp_pending_record', function (Blueprint $table) {
            $table->id('cluster_id');
            $table->string('cluster_name');
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('office')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->boolean('is_for_nap_one')->default(false);
            $table->boolean('is_for_nap_two')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreign('status_id')->references('id')->on('rdp_pending_status')->onDelete('cascade');
            $table->foreign('office')->references('office_code')->on('office')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('account')->onDelete('cascade');
            $table->timestamps();
        });

        Schema::create('rdp_grouped_record', function (Blueprint $table) {
            $table->id('group_id');
            $table->unsignedBigInteger('group_head');
            $table->unsignedBigInteger('record_id');
            $table->boolean('is_active')->default(true);
            $table->foreign('group_head')->references('cluster_id')->on('rdp_pending_record')->onDelete('cascade');
            $table->foreign('record_id')->references('id')->on('rdp_record')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rdp_grouped_record');
        Schema::dropIfExists('rdp_pending_record');
        Schema::dropIfExists('rdp_grouped_record_series');
        Schema::dropIfExists('rdp_pending_record_series');
        Schema::dropIfExists('rdp_pending_status');
    }
};
