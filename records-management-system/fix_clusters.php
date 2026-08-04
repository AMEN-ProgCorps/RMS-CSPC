<?php

use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$recordIds = DB::table('rdp_record')->pluck('id')->take(3)->toArray();

DB::beginTransaction();
$mainPendingId = DB::table('main_pending_id')->insertGetId([
    'status'     => 'UNUSED',
    'is_active'  => true,
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('rdp_pending_record')->insert([
    'cluster_id'       => $mainPendingId,
    'cluster_name'     => 'Test Inventory Cluster 2026',
    'status_id'        => 1,
    'office'           => 'ORIGIN',
    'created_by'       => 1,
    'is_for_nap_one'   => true,
    'is_for_nap_three' => false,
    'is_active'        => true,
    'created_at'       => now(),
    'updated_at'       => now(),
]);

foreach ($recordIds as $rid) {
    DB::table('rdp_grouped_record')->insert([
        'group_head' => $mainPendingId,
        'record_id'  => (int)$rid,
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

DB::commit();

echo "SUCCESS! Created cluster #$mainPendingId with " . count($recordIds) . " items.\n";
