<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_dcn_reviewers')) {
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

        if (! Schema::hasTable('dcs_dcn_approvals')) {
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

        $normalizeDate = static function (mixed $value): ?string {
            $value = trim((string) ($value ?? ''));
            if ($value === '') {
                return null;
            }
            try {
                return \Carbon\Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        };

        if (Schema::hasTable('dcs_document_change_notice') && Schema::hasTable('dcs_dcn_reviewers')) {
            $hasName = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_name');
            $hasOn = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_on');
            $hasDate = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_date');
            $hasName2 = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_name_2');
            $hasOn2 = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_on_2');
            $hasDate2 = Schema::hasColumn('dcs_document_change_notice', 'reviewed_by_date_2');

            DB::table('dcs_document_change_notice')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($hasName, $hasOn, $hasDate, $hasName2, $hasOn2, $hasDate2, $normalizeDate) {
                    foreach ($rows as $dcn) {
                        if (DB::table('dcs_dcn_reviewers')->where('dcn_id', $dcn->id)->exists()) {
                            continue;
                        }

                        $inserts = [];
                        $now = now();

                        $name1 = $hasName ? trim((string) ($dcn->reviewed_by_name ?? '')) : '';
                        $on1 = $hasOn ? ($dcn->reviewed_by_on ?? null) : null;
                        if ($name1 === '' && $hasDate) {
                            $name1 = trim((string) ($dcn->reviewed_by_date ?? ''));
                        }
                        if ($name1 !== '' || $on1) {
                            $inserts[] = [
                                'dcn_id' => $dcn->id,
                                'sort_order' => 0,
                                'name' => $name1 !== '' ? $name1 : '—',
                                'reviewed_on' => $normalizeDate($on1),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }

                        $name2 = $hasName2 ? trim((string) ($dcn->reviewed_by_name_2 ?? '')) : '';
                        $on2 = $hasOn2 ? ($dcn->reviewed_by_on_2 ?? null) : null;
                        if ($name2 === '' && $hasDate2) {
                            $name2 = trim((string) ($dcn->reviewed_by_date_2 ?? ''));
                        }
                        if ($name2 !== '' || $on2) {
                            $inserts[] = [
                                'dcn_id' => $dcn->id,
                                'sort_order' => 1,
                                'name' => $name2 !== '' ? $name2 : '—',
                                'reviewed_on' => $normalizeDate($on2),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }

                        if ($inserts !== []) {
                            DB::table('dcs_dcn_reviewers')->insert($inserts);
                        }
                    }
                });
        }

        if (
            Schema::hasTable('dcs_document_change_notice')
            && Schema::hasColumn('dcs_document_change_notice', 'approvals')
            && Schema::hasTable('dcs_dcn_approvals')
        ) {
            DB::table('dcs_document_change_notice')
                ->whereNotNull('approvals')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($normalizeDate) {
                    foreach ($rows as $dcn) {
                        if (DB::table('dcs_dcn_approvals')->where('dcn_id', $dcn->id)->exists()) {
                            continue;
                        }

                        $raw = $dcn->approvals;
                        if (is_string($raw)) {
                            $decoded = json_decode($raw, true);
                            $raw = is_array($decoded) ? $decoded : [];
                        }
                        if (! is_array($raw)) {
                            continue;
                        }

                        $inserts = [];
                        $now = now();
                        $order = 0;
                        foreach ($raw as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $position = trim((string) ($row['position'] ?? ''));
                            $name = trim((string) ($row['name'] ?? ''));
                            $date = $row['date'] ?? null;
                            if ($position === '' && $name === '' && empty($date)) {
                                continue;
                            }
                            $inserts[] = [
                                'dcn_id' => $dcn->id,
                                'sort_order' => $order++,
                                'position' => $position !== '' ? $position : null,
                                'name' => $name !== '' ? $name : null,
                                'approved_on' => $normalizeDate($date),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            if ($order >= 9) {
                                break;
                            }
                        }
                        if ($inserts !== []) {
                            DB::table('dcs_dcn_approvals')->insert($inserts);
                        }
                    }
                });

            Schema::table('dcs_document_change_notice', function (Blueprint $table) {
                $table->dropColumn('approvals');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('dcs_document_change_notice')
            && ! Schema::hasColumn('dcs_document_change_notice', 'approvals')
        ) {
            Schema::table('dcs_document_change_notice', function (Blueprint $table) {
                $table->json('approvals')->nullable();
            });
        }

        Schema::dropIfExists('dcs_dcn_approvals');
        Schema::dropIfExists('dcs_dcn_reviewers');
    }
};
