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
        Schema::create('folder_data', function (Blueprint $table) {
            $table->id();
            $table->string('office_name')->unique();
            $table->bigunsignedinteger('total_folder_size')->default(0);
            $table->boolean('is_dts_available')->default(false);
            $table->bigunsignedinteger('current_dts_size')->default(0);
            $table->boolean('is_rdp_available')->default(false);
            $table->bigunsignedinteger('current_rdp_size')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folder_data');
    }
};
