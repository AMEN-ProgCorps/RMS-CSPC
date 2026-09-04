<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Historical: briefly forced dcs_view_all_documents = false while full DCS was
 * RFIO-only. The flag is active again (any office + can_access_dcs + this flag
 * = full DCS). Kept as a no-op so already-ran environments stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op — do not clear dcs_view_all_documents.
    }

    public function down(): void
    {
        // No-op.
    }
};
