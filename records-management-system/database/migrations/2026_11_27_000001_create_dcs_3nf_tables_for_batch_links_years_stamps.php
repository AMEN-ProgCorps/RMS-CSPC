<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. 3NF Year Levels lookup table
        if (! Schema::hasTable('dcs_year_levels')) {
            Schema::create('dcs_year_levels', function (Blueprint $table) {
                $table->id();
                $table->string('year_level_name', 60)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });

            $levels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
            $jsonPath = storage_path('app/private/dcs_year_levels.json');
            if (is_file($jsonPath)) {
                $decoded = json_decode((string) @file_get_contents($jsonPath), true);
                if (is_array($decoded) && $decoded !== []) {
                    $clean = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $decoded))));
                    if ($clean !== []) {
                        $levels = $clean;
                    }
                }
            }
            $now = now();
            foreach ($levels as $idx => $name) {
                DB::table('dcs_year_levels')->insertOrIgnore([
                    'year_level_name' => $name,
                    'sort_order' => $idx + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 2. 3NF Batch DRF per-course items table (1 DRF -> N course items)
        if (! Schema::hasTable('dcs_office_drf_batch_items')) {
            Schema::create('dcs_office_drf_batch_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('office_intake_drf_id');
                $table->unsignedBigInteger('course_id');
                $table->string('doc_title', 500)->default('');
                $table->string('originator_name', 500)->nullable();
                $table->text('description_reason')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['office_intake_drf_id', 'course_id'], 'dcs_drf_batch_items_drf_course_uq');
                $table->index('course_id', 'dcs_drf_batch_items_course_idx');

                if (Schema::hasTable('dcs_office_intake_drf')) {
                    $table->foreign('office_intake_drf_id', 'dcs_drf_batch_items_drf_fk')
                        ->references('id')->on('dcs_office_intake_drf')->cascadeOnDelete();
                }
                if (Schema::hasTable('dcs_program_courses')) {
                    $table->foreign('course_id', 'dcs_drf_batch_items_course_fk')
                        ->references('id')->on('dcs_program_courses')->cascadeOnDelete();
                }
            });

            // Migrate any existing JSON batch items into PostgreSQL
            $itemsPath = storage_path('app/private/dcs_drf_batch_items.json');
            if (is_file($itemsPath) && Schema::hasTable('dcs_office_intake_drf') && Schema::hasTable('dcs_program_courses')) {
                $decoded = json_decode((string) @file_get_contents($itemsPath), true);
                if (is_array($decoded)) {
                    $now = now();
                    foreach ($decoded as $drfIdStr => $items) {
                        $drfId = (int) $drfIdStr;
                        if ($drfId < 1 || ! is_array($items) || ! DB::table('dcs_office_intake_drf')->where('id', $drfId)->exists()) {
                            continue;
                        }
                        foreach (array_values($items) as $idx => $item) {
                            $cid = (int) ($item['course_id'] ?? 0);
                            if ($cid < 1 || ! DB::table('dcs_program_courses')->where('id', $cid)->exists()) {
                                continue;
                            }
                            DB::table('dcs_office_drf_batch_items')->insertOrIgnore([
                                'office_intake_drf_id' => $drfId,
                                'course_id' => $cid,
                                'doc_title' => mb_substr(trim((string) ($item['drfTitle'] ?? '')), 0, 500),
                                'originator_name' => mb_substr(trim((string) ($item['originatorName'] ?? '')), 0, 500) ?: null,
                                'description_reason' => trim((string) ($item['descriptionReason'] ?? '')) ?: null,
                                'sort_order' => $idx + 1,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                }
            }
        }

        // 3. 3NF review_document_link column directly on DRF & DCN (1 optional link per form)
        if (Schema::hasTable('dcs_office_intake_drf') && ! Schema::hasColumn('dcs_office_intake_drf', 'review_document_link')) {
            Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
                $table->string('review_document_link', 2000)->nullable();
            });
        }
        if (Schema::hasTable('dcs_office_intake_dcn') && ! Schema::hasColumn('dcs_office_intake_dcn', 'review_document_link')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->string('review_document_link', 2000)->nullable();
            });
        }

        $linksPath = storage_path('app/private/dcs_intake_review_links.json');
        if (is_file($linksPath)) {
            $decoded = json_decode((string) @file_get_contents($linksPath), true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $url) {
                    $parts = explode(':', (string) $key, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    [$type, $idStr] = $parts;
                    $id = (int) $idStr;
                    $cleanUrl = trim((string) $url);
                    if ($id < 1 || $cleanUrl === '') {
                        continue;
                    }
                    $table = $type === 'dcn' ? 'dcs_office_intake_dcn' : ($type === 'drf' ? 'dcs_office_intake_drf' : '');
                    if ($table !== '' && Schema::hasTable($table) && Schema::hasColumn($table, 'review_document_link')) {
                        DB::table($table)->where('id', $id)->update(['review_document_link' => mb_substr($cleanUrl, 0, 2000)]);
                    }
                }
            }
        }

        // 4. 3NF Custom Stamps metadata table (image binaries remain on disk, referenced by filename)
        if (! Schema::hasTable('dcs_custom_stamps')) {
            Schema::create('dcs_custom_stamps', function (Blueprint $table) {
                $table->id();
                $table->string('stamp_key', 64)->unique();
                $table->string('label', 120);
                $table->string('filename', 255);
                $table->decimal('width_mm', 6, 2)->default(36.00);
                $table->decimal('height_mm', 6, 2)->default(15.00);
                $table->unsignedInteger('px_width')->default(0);
                $table->unsignedInteger('px_height')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });

            $manifestPath = storage_path('app/private/stamps/custom/manifest.json');
            if (is_file($manifestPath)) {
                $decoded = json_decode((string) @file_get_contents($manifestPath), true);
                if (is_array($decoded)) {
                    $now = now();
                    foreach ($decoded as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $key = trim((string) ($item['id'] ?? ''));
                        $file = basename((string) ($item['filename'] ?? ''));
                        if ($key === '' || $file === '') {
                            continue;
                        }
                        DB::table('dcs_custom_stamps')->insertOrIgnore([
                            'stamp_key' => mb_substr($key, 0, 64),
                            'label' => mb_substr(trim((string) ($item['label'] ?? 'Custom Stamp')), 0, 120),
                            'filename' => mb_substr($file, 0, 255),
                            'width_mm' => max(10.0, min(160.0, (float) ($item['width_mm'] ?? 36.0))),
                            'height_mm' => max(6.0, min(160.0, (float) ($item['height_mm'] ?? 15.0))),
                            'px_width' => max(0, (int) ($item['px_width'] ?? 0)),
                            'px_height' => max(0, (int) ($item['px_height'] ?? 0)),
                            'created_by' => ! empty($item['created_by']) ? (int) $item['created_by'] : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_custom_stamps');
        Schema::dropIfExists('dcs_office_drf_batch_items');
        Schema::dropIfExists('dcs_year_levels');
        if (Schema::hasTable('dcs_office_intake_drf') && Schema::hasColumn('dcs_office_intake_drf', 'review_document_link')) {
            Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
                $table->dropColumn('review_document_link');
            });
        }
        if (Schema::hasTable('dcs_office_intake_dcn') && Schema::hasColumn('dcs_office_intake_dcn', 'review_document_link')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                $table->dropColumn('review_document_link');
            });
        }
    }
};
