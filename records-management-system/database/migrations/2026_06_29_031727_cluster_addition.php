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
        Schema::create('cluster', function (Blueprint $table) {
            $table->increments('id');
            $table->string('cluster_name');
            $table->string('cluster_code', 50)->unique();
            $table->string('cluster_head')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->foreign('cluster_head')->references('office_code')->on('office')->onDelete('set null');
        });

        Schema::table('office', function (Blueprint $table) {
            $table->string('cluster')->nullable();
            $table->foreign('cluster')->references('cluster_code')->on('cluster')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('office', function (Blueprint $table) {
            $table->dropForeign(['cluster']);
            $table->dropColumn('cluster');
        });

        Schema::dropIfExists('cluster');
    }
};
