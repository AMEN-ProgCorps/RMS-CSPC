<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_distribution_office_groups')) {
            Schema::create('dcs_distribution_office_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique('name');
            });
        }

        if (! Schema::hasTable('dcs_distribution_office_group_items')) {
            Schema::create('dcs_distribution_office_group_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')
                    ->constrained('dcs_distribution_office_groups')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('office_id');
                $table->unsignedInteger('copies')->default(1);
                $table->unsignedInteger('sort_order')->default(0);

                $table->unique(['group_id', 'office_id'], 'dcs_dist_group_office_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_distribution_office_group_items');
        Schema::dropIfExists('dcs_distribution_office_groups');
    }
};
