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
        Schema::table('document_data', function (Blueprint $table) {
            if (!Schema::hasColumn('document_data', 'uploaded_by')) {
                $table->unsignedInteger('uploaded_by')->nullable()->after('document_path');
                $table->foreign('uploaded_by')->references('id')->on('account')->onDelete('set null');
            }
            if (!Schema::hasColumn('document_data', 'user_office')) {
                $table->string('user_office')->nullable()->after('uploaded_by');
                $table->foreign('user_office')->references('office_code')->on('office')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_data', function (Blueprint $table) {
            if (Schema::hasColumn('document_data', 'user_office')) {
                $table->dropForeign(['user_office']);
                $table->dropColumn('user_office');
            }
            if (Schema::hasColumn('document_data', 'uploaded_by')) {
                $table->dropForeign(['uploaded_by']);
                $table->dropColumn('uploaded_by');
            }
        });
    }
};
