<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $statuses = [
            [
                'status_id' => 1,
                'status_name' => 'Login Successful',
                'description' => 'User successfully logged into the system.',
            ],
            [
                'status_id' => 2,
                'status_name' => 'Login Failed',
                'description' => 'User failed to log into the system.',
            ],
            [
                'status_id' => 3,
                'status_name' => 'Logout',
                'description' => 'User logged out from the system.',
            ],
            [
                'status_id' => 4,
                'status_name' => 'Unauthorized Access',
                'description' => 'User attempted to access a restricted resource.',
            ],
            [
                'status_id' => 5,
                'status_name' => 'Account Locked',
                'description' => 'User account has been locked due to multiple failed attempts.',
            ],
            [
                'status_id' => 6,
                'status_name' => 'Password Reset Requested',
                'description' => 'User has requested a password reset.',
            ],
            [
                'status_id' => 7,
                'status_name' => 'Password Reset Successful',
                'description' => 'User has successfully reset their password.',
            ],
        ];

        foreach ($statuses as $status) {
            $exists = DB::table('security_status')->where('status_id', $status['status_id'])->exists();
            if (!$exists) {
                DB::table('security_status')->insert(array_merge($status, ['time' => now()]));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('security_status')->whereIn('status_id', [1, 2, 3, 4, 5, 6, 7])->delete();
    }
};
