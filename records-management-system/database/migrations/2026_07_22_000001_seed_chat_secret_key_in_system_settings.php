<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed Chat Deletion Secret Key into system_settings table.
     */
    public function up(): void
    {
        $secretKey = 'boss';

        // Check if secret.json exists and read stored secret key if present
        $secretFile = public_path('chatify/secret.json');
        if (file_exists($secretFile)) {
            $data = json_decode(file_get_contents($secretFile), true);
            $storedKey = $data['secret_key'] ?? $data['secret'] ?? '';
            if (!empty($storedKey)) {
                $secretKey = $storedKey;
            }
        }

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'chat_delete_secret_key'],
            [
                'value' => $secretKey,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'chat_delete_secret_key')->delete();
    }
};
