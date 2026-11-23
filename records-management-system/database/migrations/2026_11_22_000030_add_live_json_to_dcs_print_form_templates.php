<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        Schema::table('dcs_print_form_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_print_form_templates', 'live_json')) {
                $table->json('live_json')->nullable()->after('page_json');
            }
            if (! Schema::hasColumn('dcs_print_form_templates', 'live_at')) {
                $table->timestamp('live_at')->nullable()->after('live_json');
            }
        });

        // Backfill: current draft becomes the published live snapshot.
        $rows = DB::table('dcs_print_form_templates')->get();
        foreach ($rows as $row) {
            if (! empty($row->live_json)) {
                continue;
            }
            $live = [
                'label' => $row->label ?? null,
                'form_code' => $row->form_code ?? null,
                'form_title' => $row->form_title ?? null,
                'republic' => $row->republic ?? null,
                'institution_name' => $row->institution_name ?? null,
                'institution_address' => $row->institution_address ?? null,
                'footer_left' => $row->footer_left ?? null,
                'footer_center' => $row->footer_center ?? null,
                'default_effectivity' => $row->default_effectivity ?? null,
                'default_rev' => $row->default_rev ?? null,
                'logo_path' => $row->logo_path ?? null,
                'layout_json' => self::decodeJson($row->layout_json ?? null),
                'columns_json' => self::decodeJson($row->columns_json ?? null),
                'page_json' => self::decodeJson($row->page_json ?? null),
            ];
            DB::table('dcs_print_form_templates')->where('id', $row->id)->update([
                'live_json' => json_encode($live),
                'live_at' => $row->updated_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            return;
        }

        Schema::table('dcs_print_form_templates', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_print_form_templates', 'live_at')) {
                $table->dropColumn('live_at');
            }
            if (Schema::hasColumn('dcs_print_form_templates', 'live_json')) {
                $table->dropColumn('live_json');
            }
        });
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
};
