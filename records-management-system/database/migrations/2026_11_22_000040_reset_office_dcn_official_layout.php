<?php

use App\Helpers\DcsPrintTemplateHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        $row = DB::table('dcs_print_form_templates')->where('key', 'office_dcn')->first();
        if (! $row) {
            return;
        }

        $layout = $row->layout_json ?? null;
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        if (! DcsPrintTemplateHelper::isLegacySimplifiedDcnLayout($layout)) {
            return;
        }

        $seed = DcsPrintTemplateHelper::defaultSeeds()['office_dcn'] ?? null;
        if (! is_array($seed) || ! isset($seed['layout_json'])) {
            return;
        }

        $update = [
            'layout_json' => json_encode($seed['layout_json']),
            'default_rev' => $seed['default_rev'] ?? '1',
            'updated_at' => now(),
        ];

        // Keep live snapshot in sync when it was also the simplified seed.
        if (Schema::hasColumn('dcs_print_form_templates', 'live_json')) {
            $live = $row->live_json ?? null;
            if (is_string($live)) {
                $live = json_decode($live, true);
            }
            $liveLayout = is_array($live) ? ($live['layout_json'] ?? null) : null;
            if ($liveLayout === null || DcsPrintTemplateHelper::isLegacySimplifiedDcnLayout($liveLayout)) {
                $livePayload = is_array($live) ? $live : [];
                $livePayload['layout_json'] = $seed['layout_json'];
                $livePayload['default_rev'] = $seed['default_rev'] ?? '1';
                $update['live_json'] = json_encode($livePayload);
                if (Schema::hasColumn('dcs_print_form_templates', 'live_at')) {
                    $update['live_at'] = now();
                }
            }
        }

        DB::table('dcs_print_form_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        // Non-reversible seed upgrade.
    }
};
