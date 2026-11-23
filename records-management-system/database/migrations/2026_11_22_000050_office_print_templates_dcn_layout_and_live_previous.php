<?php

use App\Helpers\DcsPrintTemplateHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single follow-up for office print templates after live_json:
 * 1) live_json_previous / live_previous_at (Reset → previous published live)
 * 2) refresh office_dcn draft (+ live snapshot) from defaultSeeds() official layout
 *
 * Replaces the split migrations that called removed officialLayoutSeed().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        Schema::table('dcs_print_form_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_print_form_templates', 'live_json_previous')) {
                $after = Schema::hasColumn('dcs_print_form_templates', 'live_at') ? 'live_at' : null;
                if ($after) {
                    $table->json('live_json_previous')->nullable()->after($after);
                } else {
                    $table->json('live_json_previous')->nullable();
                }
            }
            if (! Schema::hasColumn('dcs_print_form_templates', 'live_previous_at')) {
                $after = Schema::hasColumn('dcs_print_form_templates', 'live_json_previous')
                    ? 'live_json_previous'
                    : null;
                if ($after) {
                    $table->timestamp('live_previous_at')->nullable()->after($after);
                } else {
                    $table->timestamp('live_previous_at')->nullable();
                }
            }
        });

        $row = DB::table('dcs_print_form_templates')->where('key', 'office_dcn')->first();
        if (! $row) {
            return;
        }

        $seed = DcsPrintTemplateHelper::defaultSeeds()['office_dcn'] ?? null;
        if (! is_array($seed) || ! isset($seed['layout_json'])) {
            return;
        }

        $update = [
            'layout_json' => json_encode($seed['layout_json']),
            'form_title' => $seed['form_title'] ?? 'Document Change Notice',
            'form_code' => $seed['form_code'] ?? 'CSPC-F-DCC-01',
            'default_rev' => $seed['default_rev'] ?? '1',
            'republic' => $seed['republic'] ?? 'Republic of the Philippines',
            'institution_name' => $seed['institution_name'] ?? 'Camarines Sur Polytechnic Colleges',
            'institution_address' => $seed['institution_address'] ?? 'Nabua, Camarines Sur',
            'updated_at' => now(),
        ];
        if (isset($seed['page_json'])) {
            $update['page_json'] = json_encode($seed['page_json']);
        }

        if (Schema::hasColumn('dcs_print_form_templates', 'live_json')) {
            $live = $row->live_json ?? null;
            if (is_string($live)) {
                $live = json_decode($live, true);
            }
            $livePayload = is_array($live) ? $live : [];
            foreach ([
                'layout_json', 'form_title', 'form_code', 'default_rev',
                'republic', 'institution_name', 'institution_address', 'page_json',
            ] as $k) {
                if (array_key_exists($k, $seed)) {
                    $livePayload[$k] = $seed[$k];
                }
            }
            $update['live_json'] = json_encode($livePayload);
            if (Schema::hasColumn('dcs_print_form_templates', 'live_at') && empty($row->live_at)) {
                $update['live_at'] = now();
            }
        }

        DB::table('dcs_print_form_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        Schema::table('dcs_print_form_templates', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_print_form_templates', 'live_previous_at')) {
                $table->dropColumn('live_previous_at');
            }
            if (Schema::hasColumn('dcs_print_form_templates', 'live_json_previous')) {
                $table->dropColumn('live_json_previous');
            }
        });
    }
};
