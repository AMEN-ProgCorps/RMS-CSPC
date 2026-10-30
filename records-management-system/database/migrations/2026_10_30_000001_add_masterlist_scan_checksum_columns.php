<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixity metadata for scanned masterlist PDFs (SHA-256 + size + mime).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_masterlist_registration', 'file_checksum')) {
                $table->string('file_checksum', 64)->nullable();
            }
            if (! Schema::hasColumn('dcs_masterlist_registration', 'file_checksum_algo')) {
                $table->string('file_checksum_algo', 16)->nullable();
            }
            if (! Schema::hasColumn('dcs_masterlist_registration', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable();
            }
            if (! Schema::hasColumn('dcs_masterlist_registration', 'file_mime')) {
                $table->string('file_mime', 127)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        $drop = [];
        foreach (['file_checksum', 'file_checksum_algo', 'file_size', 'file_mime'] as $column) {
            if (Schema::hasColumn('dcs_masterlist_registration', $column)) {
                $drop[] = $column;
            }
        }
        if ($drop === []) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });
    }
};
