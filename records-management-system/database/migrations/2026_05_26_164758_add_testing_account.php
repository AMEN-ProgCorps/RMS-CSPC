<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * On Production, dont include this migration file
     * this one is for testing purpose only, to create a default admin account with 
     * username: admin
     * password: admin
     */
    public function up(): void
    {
        // Ensure target tables exist
        if (! Schema::hasTable('account') || ! Schema::hasTable('account_details')) {
            return;
        }

        // Avoid inserting duplicate admin account
        $existing = DB::table('account')->where('username', 'admin')->first();
        if ($existing) {
            return;
        }

        // Insert account (matches schema: date_created / date_updated)
        $id = DB::table('account')->insertGetId([
            'username' => 'admin',
            'password' => Hash::make('admin'),
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
            'account_role' => 1, // or set to a valid role ID if needed
        ]);

        // Insert account_details for the created account
        DB::table('account_details')->insert([
            'account_id' => $id,
            'first_name' => 'admin',
            'last_name' => 'Administrator',
            'middle_name' => null,
            'office_id' => null,
            'email' => env('ADMIN_EMAIL', 'nibermundo@my.cspc.edu.ph'),
            'contact_number' => null,
            'is_currently_online' => false,
            'last_online_time' => null,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('account') || ! Schema::hasTable('account_details')) {
            return;
        }

        $account = DB::table('account')->where('username', 'admin')->first();
        if (! $account) {
            return;
        }

        // Remove related details then the account
        DB::table('account_details')->where('account_id', $account->id)->delete();
        DB::table('account')->where('id', $account->id)->delete();
    }
};
