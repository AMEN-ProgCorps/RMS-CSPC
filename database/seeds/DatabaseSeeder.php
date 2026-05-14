<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        DB::table('account')->insert([
            'uname' => 'admin',
            'emailadd' => 'admin@test.com',
            'password' => bcrypt('password'),
            'disabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
