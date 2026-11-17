<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedExistingBracketsAndSubs extends Seeder
{
    public function run(): void
    {
        \App\Services\RdpBracketSyncService::syncAllBrackets();
    }
}
