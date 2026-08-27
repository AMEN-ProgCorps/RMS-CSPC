<?php
// 026 — originators

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_originators', function (Blueprint $table) {
            $table->id();
            $table->string('originator_name')->unique();
            $table->timestamps();
        });

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $table->foreignId('originator_id')->nullable()->after('originator_name')
                  ->constrained('dcs_originators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $table->dropConstrainedForeignId('originator_id');
        });

        Schema::dropIfExists('dcs_originators');
    }
};
