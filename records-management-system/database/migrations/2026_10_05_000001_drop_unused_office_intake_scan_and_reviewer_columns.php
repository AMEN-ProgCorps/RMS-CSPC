<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop unused DCS leftovers:
 * office-intake scan, receipt, and mirrored reviewer columns;
 * the report-template table;
 * the old Register DCN reviewer and approval tables.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const DCN_COLUMNS = [
        'scanned_dcn',
        'dcn_receipt_date',
        'dcn_receipt_time',
        'reviewed_by_date',
        'reviewed_by_date_2',
        'reviewed_by_name',
        'reviewed_by_on',
        'reviewed_by_name_2',
        'reviewed_by_on_2',
    ];

    /** @var list<string> */
    private const DRF_COLUMNS = [
        'scanned_drf',
        'drf_receipt_date',
        'drf_receipt_time',
    ];

    public function up(): void
    {
        $this->copyLegacyReviewers();
        $this->dropColumns('dcs_office_intake_dcn', self::DCN_COLUMNS);
        $this->dropColumns('dcs_office_intake_drf', self::DRF_COLUMNS);
        Schema::dropIfExists('dcs_report_templates');
        Schema::dropIfExists('dcs_dcn_approvals');
        Schema::dropIfExists('dcs_dcn_reviewers');
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_dcn_reviewers') && Schema::hasTable('dcs_document_change_notice')) {
            Schema::create('dcs_dcn_reviewers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dcn_id')
                    ->constrained('dcs_document_change_notice')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('name', 255);
                $table->date('reviewed_on')->nullable();
                $table->timestamps();
                $table->index(['dcn_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('dcs_dcn_approvals') && Schema::hasTable('dcs_document_change_notice')) {
            Schema::create('dcs_dcn_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dcn_id')
                    ->constrained('dcs_document_change_notice')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('position', 255)->nullable();
                $table->string('name', 255)->nullable();
                $table->date('approved_on')->nullable();
                $table->timestamps();
                $table->index(['dcn_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('dcs_report_templates')) {
            Schema::create('dcs_report_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('pdf_path');
                $table->string('preview_path')->nullable();
                $table->unsignedInteger('created_by');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('dcs_office_intake_dcn')) {
            Schema::table('dcs_office_intake_dcn', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'scanned_dcn')) {
                    $table->string('scanned_dcn')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'dcn_receipt_date')) {
                    $table->date('dcn_receipt_date')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'dcn_receipt_time')) {
                    $table->time('dcn_receipt_time')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_date')) {
                    $table->string('reviewed_by_date')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_date_2')) {
                    $table->string('reviewed_by_date_2')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_name')) {
                    $table->string('reviewed_by_name')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_on')) {
                    $table->date('reviewed_by_on')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_name_2')) {
                    $table->string('reviewed_by_name_2')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_on_2')) {
                    $table->date('reviewed_by_on_2')->nullable();
                }
            });
        }

        if (Schema::hasTable('dcs_office_intake_drf')) {
            Schema::table('dcs_office_intake_drf', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_office_intake_drf', 'scanned_drf')) {
                    $table->string('scanned_drf')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'drf_receipt_date')) {
                    $table->date('drf_receipt_date')->nullable();
                }
                if (! Schema::hasColumn('dcs_office_intake_drf', 'drf_receipt_time')) {
                    $table->time('drf_receipt_time')->nullable();
                }
            });
        }
    }

    private function copyLegacyReviewers(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn') || ! Schema::hasTable('dcs_office_dcn_reviewers')) {
            return;
        }

        $hasName = Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_name');
        $hasDate = Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_date');
        $hasOn = Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_on');
        $hasName2 = Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_name_2');
        $hasDate2 = Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_date_2');
        $hasOn2 = Schema::hasColumn('dcs_office_intake_dcn', 'reviewed_by_on_2');

        if (! $hasName && ! $hasDate && ! $hasName2 && ! $hasDate2) {
            return;
        }

        DB::table('dcs_office_intake_dcn')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($hasName, $hasDate, $hasOn, $hasName2, $hasDate2, $hasOn2) {
                foreach ($rows as $dcn) {
                    if (DB::table('dcs_office_dcn_reviewers')->where('office_intake_dcn_id', $dcn->id)->exists()) {
                        continue;
                    }

                    $now = now();
                    $inserts = [];
                    $pairs = [
                        [$hasName, $hasDate, $hasOn, 'reviewed_by_name', 'reviewed_by_date', 'reviewed_by_on', 0],
                        [$hasName2, $hasDate2, $hasOn2, 'reviewed_by_name_2', 'reviewed_by_date_2', 'reviewed_by_on_2', 1],
                    ];

                    foreach ($pairs as [$hasN, $hasD, $hasO, $nameCol, $dateCol, $onCol, $sort]) {
                        $name = $hasN ? trim((string) ($dcn->{$nameCol} ?? '')) : '';
                        if ($name === '' && $hasD) {
                            $name = trim((string) ($dcn->{$dateCol} ?? ''));
                        }
                        $on = $hasO ? ($dcn->{$onCol} ?? null) : null;
                        if ($name === '' && empty($on)) {
                            continue;
                        }
                        $inserts[] = [
                            'office_intake_dcn_id' => $dcn->id,
                            'sort_order' => $sort,
                            'name' => $name !== '' ? $name : '—',
                            'reviewed_on' => $on ?: null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($inserts !== []) {
                        DB::table('dcs_office_dcn_reviewers')->insert($inserts);
                    }
                }
            });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter(
            $columns,
            static fn (string $column) => Schema::hasColumn($table, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present) {
            $blueprint->dropColumn($present);
        });
    }
};
