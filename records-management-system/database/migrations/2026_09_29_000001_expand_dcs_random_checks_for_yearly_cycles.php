<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accountTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        if (! Schema::hasTable('dcs_random_check_schedules')) {
            Schema::create('dcs_random_check_schedules', function (Blueprint $table) use ($officeTable, $accountTable) {
                $table->id();
                $table->unsignedSmallInteger('check_year');
                $table->string('cycle', 16);
                $table->unsignedInteger('office_id');
                $table->foreign('office_id')->references('id')->on($officeTable);
                $table->date('scheduled_date');
                $table->timestamp('notified_at')->nullable();
                $table->unsignedInteger('created_by');
                $table->foreign('created_by')->references('id')->on($accountTable);
                $table->string('status', 20)->default('scheduled');
                $table->timestamps();

                $table->unique(['check_year', 'cycle', 'office_id'], 'dcs_rc_sched_year_cycle_office');
                $table->index(['check_year', 'cycle']);
            });
        }

        if (Schema::hasTable('dcs_random_checks')) {
            Schema::table('dcs_random_checks', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_random_checks', 'check_year')) {
                    $table->unsignedSmallInteger('check_year')->nullable()->after('office_id');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'cycle')) {
                    $table->string('cycle', 16)->nullable()->after('check_year');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'schedule_id')) {
                    $table->unsignedBigInteger('schedule_id')->nullable()->after('cycle');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'check_date')) {
                    $table->date('check_date')->nullable()->after('checked_at');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'is_draft')) {
                    $table->boolean('is_draft')->default(false)->after('check_date');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'conducted_by')) {
                    $table->string('conducted_by', 255)->nullable()->after('is_draft');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'tested_by')) {
                    $table->string('tested_by', 255)->nullable()->after('conducted_by');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'finalized_at')) {
                    $table->timestamp('finalized_at')->nullable()->after('tested_by');
                }
                if (! Schema::hasColumn('dcs_random_checks', 'shared_at')) {
                    $table->timestamp('shared_at')->nullable()->after('finalized_at');
                }
            });

            if (
                Schema::hasColumn('dcs_random_checks', 'schedule_id')
                && Schema::hasTable('dcs_random_check_schedules')
                && ! $this->constraintExists('dcs_random_checks_schedule_id_foreign')
            ) {
                Schema::table('dcs_random_checks', function (Blueprint $table) {
                    $table->foreign('schedule_id', 'dcs_random_checks_schedule_id_foreign')
                        ->references('id')
                        ->on('dcs_random_check_schedules')
                        ->nullOnDelete();
                });
            }

            if (
                Schema::hasColumn('dcs_random_checks', 'check_year')
                && Schema::hasColumn('dcs_random_checks', 'cycle')
                && ! $this->indexExists('dcs_rc_office_year_cycle')
            ) {
                Schema::table('dcs_random_checks', function (Blueprint $table) {
                    $table->index(['office_id', 'check_year', 'cycle'], 'dcs_rc_office_year_cycle');
                });
            }
        }

        if (Schema::hasTable('dcs_random_check_items')) {
            Schema::table('dcs_random_check_items', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_random_check_items', 'compliance_status')) {
                    $table->string('compliance_status', 20)->nullable()->after('recommended_actions');
                }
                if (! Schema::hasColumn('dcs_random_check_items', 'notes')) {
                    $table->text('notes')->nullable()->after('compliance_status');
                }
            });

            $this->preventSnapshotCascade();
        }

        $this->backfillYearsAndCycles();
    }

    public function down(): void
    {
        if (Schema::hasTable('dcs_random_checks') && $this->constraintExists('dcs_random_checks_schedule_id_foreign')) {
            Schema::table('dcs_random_checks', function (Blueprint $table) {
                $table->dropForeign('dcs_random_checks_schedule_id_foreign');
            });
        }

        Schema::dropIfExists('dcs_random_check_schedules');
    }

    /**
     * Keep historical random-check item rows even if a masterlist registration is removed.
     * Avoid empty try/catch around DDL — a swallowed Postgres error aborts the whole migration TX.
     */
    private function preventSnapshotCascade(): void
    {
        $fkName = $this->foreignKeyNameOn('dcs_random_check_items', 'masterlist_id');
        if ($fkName) {
            Schema::table('dcs_random_check_items', function (Blueprint $table) use ($fkName) {
                $table->dropForeign($fkName);
            });
        }

        if ($this->foreignKeyNameOn('dcs_random_check_items', 'masterlist_id')) {
            return;
        }

        Schema::table('dcs_random_check_items', function (Blueprint $table) {
            $table->foreign('masterlist_id')
                ->references('id')
                ->on('dcs_masterlist_registration')
                ->restrictOnDelete();
        });
    }

    private function backfillYearsAndCycles(): void
    {
        if (! Schema::hasTable('dcs_random_checks') || ! Schema::hasColumn('dcs_random_checks', 'check_year')) {
            return;
        }

        $rows = DB::table('dcs_random_checks')->whereNull('check_year')->get(['id', 'checked_at', 'created_at']);
        foreach ($rows as $row) {
            $stamp = $row->checked_at ?: $row->created_at;
            $year = $stamp ? (int) date('Y', strtotime((string) $stamp)) : (int) date('Y');
            $month = $stamp ? (int) date('n', strtotime((string) $stamp)) : (int) date('n');
            $cycle = $month <= 6 ? 'june' : 'december';
            DB::table('dcs_random_checks')->where('id', $row->id)->update([
                'check_year' => $year,
                'cycle' => $cycle,
                'check_date' => $stamp ? date('Y-m-d', strtotime((string) $stamp)) : null,
                'is_draft' => false,
                'finalized_at' => $row->checked_at,
            ]);
        }
    }

    private function constraintExists(string $name): bool
    {
        $row = DB::selectOne(
            'select 1 as x from pg_constraint where conname = ? limit 1',
            [$name]
        );

        return $row !== null;
    }

    private function indexExists(string $name): bool
    {
        $row = DB::selectOne(
            'select 1 as x from pg_indexes where schemaname = current_schema() and indexname = ? limit 1',
            [$name]
        );

        return $row !== null;
    }

    private function foreignKeyNameOn(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'select tc.constraint_name
             from information_schema.table_constraints as tc
             join information_schema.key_column_usage as kcu
               on tc.constraint_name = kcu.constraint_name
              and tc.table_schema = kcu.table_schema
             where tc.constraint_type = ?
               and tc.table_schema = current_schema()
               and tc.table_name = ?
               and kcu.column_name = ?
             limit 1',
            ['FOREIGN KEY', $table, $column]
        );

        return $row->constraint_name ?? null;
    }
};
