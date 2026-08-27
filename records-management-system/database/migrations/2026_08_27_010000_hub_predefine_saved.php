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
        Schema::create('hub_flow_datas', function (Blueprint $table) {
            $table->id();
            $table->integer('flow_owner')->index();
            $table->string('offices_hub')->index();
            $table->timestamps();

            $table->foreign('flow_owner')
                ->references('id')
                ->on('dts_transaction_flow')
                ->cascadeOnDelete();

            $table->foreign('offices_hub')
                ->references('office_code')
                ->on('office')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hub_flow_datas');
    }
};
