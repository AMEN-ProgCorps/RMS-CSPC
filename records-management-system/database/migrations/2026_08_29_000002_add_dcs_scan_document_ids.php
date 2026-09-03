<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Formerly added scanned_*_document_id columns + FK to document_data.
 * DCS scans no longer use document_data; columns are dropped by
 * 2026_09_04_000003. Kept as a no-op so already-ran environments stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op — scan document_id columns are obsolete.
    }

    public function down(): void
    {
        // No-op.
    }
};
