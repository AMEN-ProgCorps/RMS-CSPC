<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dcs:purge-recycle-bin', function () {
    $count = \App\Helpers\RegisterUpdateHelper::purgeExpiredRecycleBin();
    $this->info("Purged {$count} expired Recycle Bin document(s).");
})->purpose('Permanently delete DCS Recycle Bin items older than 1 year');

Schedule::command('dcs:purge-recycle-bin')->daily();
