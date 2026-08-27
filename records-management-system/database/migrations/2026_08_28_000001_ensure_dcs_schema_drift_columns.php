<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive ensure for columns that were later baked into CREATE migrations.
 * Safe on fresh installs (no-op when columns already exist) and on prod that
 * ran older CREATE schemas without revision_status / keywords / etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_document_requests')) {
            Schema::table('dcs_document_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
                    $table->timestamp('deleted_at')->nullable();
                }
                if (! Schema::hasColumn('dcs_document_requests', 'deleted_by')) {
                    $table->unsignedInteger('deleted_by')->nullable();
                }
            });

            if (Schema::hasColumn('dcs_document_requests', 'deleted_by')
                && ! $this->hasForeignKey('dcs_document_requests', 'dcs_document_requests_deleted_by_foreign')) {
                Schema::table('dcs_document_requests', function (Blueprint $table) {
                    $table->foreign('deleted_by')->references('id')->on('account')->nullOnDelete();
                });
            }
        }

        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                $table->string('revised_from_doc_no', 100)->nullable()->after('doc_no');
            }
            if (! Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
                $table->string('revision_status', 20)->default('latest')->after('revise_no');
            }
            if (! Schema::hasColumn('dcs_masterlist_registration', 'brief_purpose')) {
                $table->text('brief_purpose')->nullable();
            }
            if (! Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
                $table->text('keywords')->nullable();
            }
        });

        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            DB::table('dcs_masterlist_registration')
                ->where(function ($q) {
                    $q->whereNull('revision_status')
                        ->orWhere('revision_status', '');
                })
                ->update(['revision_status' => 'latest']);

            if (! $this->hasIndex('dcs_masterlist_registration', 'dcs_ml_doc_no_revision_status_idx')) {
                Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
                    $table->index(['doc_no', 'revision_status'], 'dcs_ml_doc_no_revision_status_idx');
                });
            }
        }

        if (Schema::hasTable('dcs_document_change_notice')
            && ! Schema::hasColumn('dcs_document_change_notice', 'brief_purpose')) {
            Schema::table('dcs_document_change_notice', function (Blueprint $table) {
                $table->text('brief_purpose')->nullable();
            });
        }

        if (Schema::hasTable('dcs_doc_revision')
            && ! Schema::hasColumn('dcs_doc_revision', 'brief_purpose')) {
            Schema::table('dcs_doc_revision', function (Blueprint $table) {
                $table->text('brief_purpose')->nullable();
            });
        }

        if (Schema::hasTable('dcs_program_courses')
            && ! Schema::hasColumn('dcs_program_courses', 'course_code')) {
            Schema::table('dcs_program_courses', function (Blueprint $table) {
                $table->string('course_code', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Additive ensure — do not drop columns that may contain production data.
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conname = ? LIMIT 1',
                [$name]
            );

            return $row !== null;
        }

        return false;
    }

    private function hasIndex(string $table, string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                [$table, $name]
            );

            return $row !== null;
        }

        return Schema::hasIndex($table, $name);
    }
};
