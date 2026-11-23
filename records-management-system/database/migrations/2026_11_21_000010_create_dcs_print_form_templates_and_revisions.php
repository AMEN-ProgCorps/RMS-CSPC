<?php

use App\Helpers\DcsPrintTemplateHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_print_form_templates')) {
            Schema::create('dcs_print_form_templates', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('label');
                $table->string('form_code')->nullable();
                $table->string('form_title')->nullable();
                $table->string('republic')->nullable();
                $table->string('institution_name')->nullable();
                $table->string('institution_address')->nullable();
                $table->string('footer_left')->nullable();
                $table->string('footer_center')->nullable();
                $table->string('default_effectivity')->nullable();
                $table->string('default_rev')->nullable();
                $table->string('logo_path')->nullable();
                $table->json('layout_json')->nullable();
                $table->json('layout_json_previous')->nullable();
                $table->timestamp('layout_previous_at')->nullable();
                $table->json('columns_json')->nullable();
                $table->json('page_json')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('dcs_print_form_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_print_form_templates', 'layout_json_previous')) {
                    $table->json('layout_json_previous')->nullable()->after('layout_json');
                }
                if (! Schema::hasColumn('dcs_print_form_templates', 'layout_previous_at')) {
                    $table->timestamp('layout_previous_at')->nullable()->after('layout_json_previous');
                }
            });
        }

        if (! Schema::hasTable('dcs_print_form_template_revisions')) {
            Schema::create('dcs_print_form_template_revisions', function (Blueprint $table) {
                $table->id();
                $table->string('template_key')->index();
                $table->string('label')->nullable();
                $table->json('layout_json');
                $table->json('columns_json')->nullable();
                $table->json('page_json')->nullable();
                $table->unsignedInteger('block_count')->default(0);
                $table->string('source')->default('save'); // save | restore | reset
                $table->string('kind', 32)->default('layout'); // layout | columns | full
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['template_key', 'created_at']);
            });
        } else {
            Schema::table('dcs_print_form_template_revisions', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_print_form_template_revisions', 'columns_json')) {
                    $table->json('columns_json')->nullable()->after('layout_json');
                }
                if (! Schema::hasColumn('dcs_print_form_template_revisions', 'kind')) {
                    $table->string('kind', 32)->default('layout')->after('source');
                }
            });
        }

        $now = now();
        foreach (DcsPrintTemplateHelper::defaultSeeds() as $key => $seed) {
            $exists = DB::table('dcs_print_form_templates')->where('key', $key)->exists();
            if ($exists) {
                continue;
            }
            DB::table('dcs_print_form_templates')->insert([
                'key' => $key,
                'label' => $seed['label'],
                'form_code' => $seed['form_code'] ?? null,
                'form_title' => $seed['form_title'] ?? null,
                'republic' => $seed['republic'] ?? null,
                'institution_name' => $seed['institution_name'] ?? null,
                'institution_address' => $seed['institution_address'] ?? null,
                'footer_left' => $seed['footer_left'] ?? null,
                'footer_center' => $seed['footer_center'] ?? null,
                'default_effectivity' => $seed['default_effectivity'] ?? null,
                'default_rev' => $seed['default_rev'] ?? null,
                'logo_path' => $seed['logo_path'] ?? null,
                'layout_json' => isset($seed['layout_json']) ? json_encode($seed['layout_json']) : null,
                'columns_json' => isset($seed['columns_json']) ? json_encode($seed['columns_json']) : null,
                'page_json' => isset($seed['page_json']) ? json_encode($seed['page_json']) : null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_print_form_template_revisions');
        Schema::dropIfExists('dcs_print_form_templates');
    }
};
