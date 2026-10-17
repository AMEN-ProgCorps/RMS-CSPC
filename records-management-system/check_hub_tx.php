<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$parent = DB::table('dts_transaction_details')->where('control_number', 'NM-2026-10-ZRNq6Y')->first();
echo "Originated from: {$parent->originated_from}\n";

$children = DB::table('dts_transaction_details')
    ->where('control_number', 'like', 'NM-2026-10-ZRNq6Y-%')
    ->get(['control_number', 'current_office_hold']);

echo "Total children count: " . count($children) . "\n";

$foundOrigin = false;
foreach($children as $c) {
    if (strtolower($c->current_office_hold) === strtolower($parent->originated_from)) {
        $foundOrigin = true;
        echo "Found originating office in child: {$c->control_number} => {$c->current_office_hold}\n";
    }
}

if (!$foundOrigin) {
    echo "Originating office ({$parent->originated_from}) is NOT in the child branches!\n";
}

// Also check the flow hub list in dts_hub_flow_datas for flow 495
$hubRows = DB::table('dts_hub_flow_datas')->where('flow_owner', 495)->get();
echo "Total in dts_hub_flow_datas: " . count($hubRows) . "\n";
$inHubDatas = false;
foreach ($hubRows as $hr) {
    if (strtolower($hr->offices_hub) === strtolower($parent->originated_from)) {
        $inHubDatas = true;
        echo "Originated from {$parent->originated_from} found in dts_hub_flow_datas!\n";
    }
}
if (!$inHubDatas) {
    echo "Originated from ({$parent->originated_from}) is NOT in dts_hub_flow_datas!\n";
}
