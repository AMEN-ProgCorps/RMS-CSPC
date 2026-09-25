<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move office-intake DRF/DCN off the Register tables (same ids so /dcs/office/{type}/{id} stays valid)
 * and drop unused Retrieval scan path.
 */
return new class extends Migration
{
    private const OLD_DRF = 'dcs_document_request_form';

    private const OLD_DCN = 'dcs_document_change_notice';

    private const NEW_DRF = 'dcs_office_intake_drf';

    private const NEW_DCN = 'dcs_office_intake_dcn';

    /** @var list<string> */
    private const DRF_COPY = [
        'id', 'drf_no', 'drf_date', 'drf_receipt_date', 'drf_receipt_time', 'doc_title', 'scanned_drf',
        'originator_name', 'doc_type_kind', 'description_reason', 'distribute_to',
        'prepared_by_name', 'prepared_by_designation', 'reviewed_by_name', 'reviewed_by_designation',
        'approved_by_name', 'approved_by_designation', 'created_by', 'source_office_dcn_id',
        'rfio_received_at', 'rfio_received_by', 'rfio_claimed_at', 'rfio_registered_at',
        'registered_request_id', 'edit_unlocked_at', 'edit_unlocked_by', 'edit_unlock_reason',
        'rfio_print_notified_at', 'rfio_print_notified_by', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    private const DCN_COPY = [
        'id', 'dcn_no', 'dcn_date', 'dcn_receipt_date', 'dcn_receipt_time', 'scanned_dcn', 'brief_purpose',
        'document_no', 'document_title', 'change_from', 'change_to', 'originator_name', 'department_date',
        'reviewed_by_date', 'reviewed_by_date_2', 'reviewed_by_name', 'reviewed_by_on',
        'reviewed_by_name_2', 'reviewed_by_on_2', 'created_by',
        'rfio_received_at', 'rfio_received_by', 'rfio_claimed_at', 'rfio_registered_at',
        'registered_request_id', 'edit_unlocked_at', 'edit_unlocked_by', 'edit_unlock_reason',
        'rfio_print_notified_at', 'rfio_print_notified_by', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    private const DROP_DRF = [
        'is_office_intake', 'originator_name', 'doc_type_kind', 'description_reason', 'distribute_to',
        'prepared_by_name', 'prepared_by_designation', 'reviewed_by_name', 'reviewed_by_designation',
        'approved_by_name', 'approved_by_designation', 'source_office_dcn_id',
        'rfio_received_at', 'rfio_received_by', 'rfio_claimed_at', 'rfio_registered_at',
        'registered_request_id', 'edit_unlocked_at', 'edit_unlocked_by', 'edit_unlock_reason',
        'rfio_print_notified_at', 'rfio_print_notified_by',
    ];

    /** @var list<string> */
    private const DROP_DCN = [
        'is_office_intake', 'document_no', 'document_title', 'change_from', 'change_to', 'originator_name',
        'department_date', 'reviewed_by_date', 'reviewed_by_date_2', 'reviewed_by_name', 'reviewed_by_on',
        'reviewed_by_name_2', 'reviewed_by_on_2',
        'rfio_received_at', 'rfio_received_by', 'rfio_claimed_at', 'rfio_registered_at',
        'registered_request_id', 'edit_unlocked_at', 'edit_unlocked_by', 'edit_unlock_reason',
        'rfio_print_notified_at', 'rfio_print_notified_by',
    ];

    public function up(): void
    {
        $this->createOfficeDrfTable();
        $this->createOfficeDcnTable();
        $this->createOfficeChildTables();
        $this->copyIntakeRows();
        $this->deleteOldIntakeRows();
        $this->dropRegisterIntakeColumns();
        $this->dropRetrievalScan();
    }

    public function down(): void
    {
        // Non-reversible: intake rows already left the Register tables.
    }

    private function createOfficeDrfTable(): void
    {
        if (Schema::hasTable(self::NEW_DRF)) {
            return;
        }

        Schema::create(self::NEW_DRF, function (Blueprint $table) {
            $table->id();
            $table->string('drf_no', 100)->nullable();
            $table->date('drf_date')->nullable();
            $table->date('drf_receipt_date')->nullable();
            $table->time('drf_receipt_time')->nullable();
            $table->string('doc_title')->nullable();
            $table->string('scanned_drf')->nullable();
            $table->string('originator_name')->nullable();
            $table->string('doc_type_kind', 20)->nullable();
            $table->text('description_reason')->nullable();
            $table->json('distribute_to')->nullable();
            $table->string('prepared_by_name')->nullable();
            $table->string('prepared_by_designation')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->string('reviewed_by_designation')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->string('approved_by_designation')->nullable();
            $table->unsignedInteger('created_by');
            $table->unsignedBigInteger('source_office_dcn_id')->nullable();
            $table->timestamp('rfio_received_at')->nullable();
            $table->unsignedInteger('rfio_received_by')->nullable();
            $table->timestamp('rfio_claimed_at')->nullable();
            $table->timestamp('rfio_registered_at')->nullable();
            $table->unsignedInteger('registered_request_id')->nullable();
            $table->timestamp('edit_unlocked_at')->nullable();
            $table->unsignedInteger('edit_unlocked_by')->nullable();
            $table->string('edit_unlock_reason', 1000)->nullable();
            $table->timestamp('rfio_print_notified_at')->nullable();
            $table->unsignedInteger('rfio_print_notified_by')->nullable();
            $table->timestamps();

            $table->index('created_by');
            $table->index('source_office_dcn_id');
            $table->index('registered_request_id');
        });
    }

    private function createOfficeDcnTable(): void
    {
        if (Schema::hasTable(self::NEW_DCN)) {
            return;
        }

        Schema::create(self::NEW_DCN, function (Blueprint $table) {
            $table->id();
            $table->string('dcn_no', 100)->nullable();
            $table->date('dcn_date')->nullable();
            $table->date('dcn_receipt_date')->nullable();
            $table->time('dcn_receipt_time')->nullable();
            $table->string('scanned_dcn')->nullable();
            $table->text('brief_purpose')->nullable();
            $table->string('document_no', 150)->nullable();
            $table->string('document_title')->nullable();
            $table->text('change_from')->nullable();
            $table->text('change_to')->nullable();
            $table->string('originator_name')->nullable();
            $table->string('department_date')->nullable();
            $table->string('reviewed_by_date')->nullable();
            $table->string('reviewed_by_date_2')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->date('reviewed_by_on')->nullable();
            $table->string('reviewed_by_name_2')->nullable();
            $table->date('reviewed_by_on_2')->nullable();
            $table->unsignedInteger('created_by');
            $table->timestamp('rfio_received_at')->nullable();
            $table->unsignedInteger('rfio_received_by')->nullable();
            $table->timestamp('rfio_claimed_at')->nullable();
            $table->timestamp('rfio_registered_at')->nullable();
            $table->unsignedInteger('registered_request_id')->nullable();
            $table->timestamp('edit_unlocked_at')->nullable();
            $table->unsignedInteger('edit_unlocked_by')->nullable();
            $table->string('edit_unlock_reason', 1000)->nullable();
            $table->timestamp('rfio_print_notified_at')->nullable();
            $table->unsignedInteger('rfio_print_notified_by')->nullable();
            $table->timestamps();

            $table->index('created_by');
            $table->index('registered_request_id');
            $table->index('document_no');
        });
    }

    private function createOfficeChildTables(): void
    {
        if (! Schema::hasTable('dcs_office_drf_offices')) {
            Schema::create('dcs_office_drf_offices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_intake_drf_id')
                    ->constrained(self::NEW_DRF)
                    ->cascadeOnDelete();
                $table->unsignedInteger('office_id');
                $table->timestamps();
                $table->unique(['office_intake_drf_id', 'office_id'], 'dcs_office_drf_offices_unique');
            });
        }

        if (! Schema::hasTable('dcs_office_dcn_offices')) {
            Schema::create('dcs_office_dcn_offices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_intake_dcn_id')
                    ->constrained(self::NEW_DCN)
                    ->cascadeOnDelete();
                $table->unsignedInteger('office_id');
                $table->timestamps();
                $table->unique(['office_intake_dcn_id', 'office_id'], 'dcs_office_dcn_offices_unique');
            });
        }

        if (! Schema::hasTable('dcs_office_dcn_reviewers')) {
            Schema::create('dcs_office_dcn_reviewers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_intake_dcn_id')
                    ->constrained(self::NEW_DCN)
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('name', 255);
                $table->date('reviewed_on')->nullable();
                $table->timestamps();
                $table->index(['office_intake_dcn_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('dcs_office_dcn_approvals')) {
            Schema::create('dcs_office_dcn_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_intake_dcn_id')
                    ->constrained(self::NEW_DCN)
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('position', 255)->nullable();
                $table->string('name', 255)->nullable();
                $table->date('approved_on')->nullable();
                $table->timestamps();
                $table->index(['office_intake_dcn_id', 'sort_order']);
            });
        }
    }

    private function copyIntakeRows(): void
    {
        if (! Schema::hasTable(self::OLD_DRF) || ! Schema::hasColumn(self::OLD_DRF, 'is_office_intake')) {
            return;
        }

        $drfIds = [];
        DB::table(self::OLD_DRF)->where('is_office_intake', true)->orderBy('id')->each(function ($row) use (&$drfIds) {
            $id = (int) $row->id;
            $drfIds[] = $id;
            if (DB::table(self::NEW_DRF)->where('id', $id)->exists()) {
                return;
            }
            $payload = $this->copyPayload((array) $row, self::DRF_COPY, self::NEW_DRF);
            if ($payload === []) {
                return;
            }
            $payload['id'] = $id;
            DB::table(self::NEW_DRF)->insert($payload);
        });

        $dcnIds = [];
        DB::table(self::OLD_DCN)->where('is_office_intake', true)->orderBy('id')->each(function ($row) use (&$dcnIds) {
            $id = (int) $row->id;
            $dcnIds[] = $id;
            if (DB::table(self::NEW_DCN)->where('id', $id)->exists()) {
                return;
            }
            $payload = $this->copyPayload((array) $row, self::DCN_COPY, self::NEW_DCN);
            if ($payload === []) {
                return;
            }
            $payload['id'] = $id;
            DB::table(self::NEW_DCN)->insert($payload);
        });

        if ($drfIds !== [] && Schema::hasTable('dcs_drf_offices')) {
            $offices = DB::table('dcs_drf_offices')->whereIn('document_request_form_id', $drfIds)->get();
            foreach ($offices as $office) {
                $exists = DB::table('dcs_office_drf_offices')
                    ->where('office_intake_drf_id', $office->document_request_form_id)
                    ->where('office_id', $office->office_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('dcs_office_drf_offices')->insert([
                    'office_intake_drf_id' => $office->document_request_form_id,
                    'office_id' => $office->office_id,
                    'created_at' => $office->created_at ?? now(),
                    'updated_at' => $office->updated_at ?? now(),
                ]);
            }
        }

        if ($dcnIds !== [] && Schema::hasTable('dcs_dcn_offices')) {
            $offices = DB::table('dcs_dcn_offices')->whereIn('dcn_id', $dcnIds)->get();
            foreach ($offices as $office) {
                $exists = DB::table('dcs_office_dcn_offices')
                    ->where('office_intake_dcn_id', $office->dcn_id)
                    ->where('office_id', $office->office_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('dcs_office_dcn_offices')->insert([
                    'office_intake_dcn_id' => $office->dcn_id,
                    'office_id' => $office->office_id,
                    'created_at' => $office->created_at ?? now(),
                    'updated_at' => $office->updated_at ?? now(),
                ]);
            }
        }

        if ($dcnIds !== [] && Schema::hasTable('dcs_dcn_reviewers')) {
            $rows = DB::table('dcs_dcn_reviewers')->whereIn('dcn_id', $dcnIds)->get();
            foreach ($rows as $row) {
                $exists = DB::table('dcs_office_dcn_reviewers')
                    ->where('office_intake_dcn_id', $row->dcn_id)
                    ->where('sort_order', $row->sort_order ?? 0)
                    ->where('name', $row->name)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('dcs_office_dcn_reviewers')->insert([
                    'office_intake_dcn_id' => $row->dcn_id,
                    'sort_order' => $row->sort_order ?? 0,
                    'name' => $row->name,
                    'reviewed_on' => $row->reviewed_on ?? null,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        if ($dcnIds !== [] && Schema::hasTable('dcs_dcn_approvals')) {
            $rows = DB::table('dcs_dcn_approvals')->whereIn('dcn_id', $dcnIds)->get();
            foreach ($rows as $row) {
                $exists = DB::table('dcs_office_dcn_approvals')
                    ->where('office_intake_dcn_id', $row->dcn_id)
                    ->where('sort_order', $row->sort_order ?? 0)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('dcs_office_dcn_approvals')->insert([
                    'office_intake_dcn_id' => $row->dcn_id,
                    'sort_order' => $row->sort_order ?? 0,
                    'position' => $row->position ?? null,
                    'name' => $row->name ?? null,
                    'approved_on' => $row->approved_on ?? null,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }
        }

        $this->resetSequence(self::NEW_DRF);
        $this->resetSequence(self::NEW_DCN);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $wanted
     * @return array<string, mixed>
     */
    private function copyPayload(array $row, array $wanted, string $newTable): array
    {
        $out = [];
        foreach ($wanted as $column) {
            if ($column === 'id' || ! Schema::hasColumn($newTable, $column) || ! array_key_exists($column, $row)) {
                continue;
            }
            $out[$column] = $row[$column];
        }

        return $out;
    }

    private function resetSequence(string $table): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $max = (int) DB::table($table)->max('id');
        if ($max < 1) {
            return;
        }

        DB::statement("SELECT setval(pg_get_serial_sequence(?, 'id'), ?)", [$table, $max]);
    }

    private function deleteOldIntakeRows(): void
    {
        if (! Schema::hasTable(self::OLD_DRF) || ! Schema::hasColumn(self::OLD_DRF, 'is_office_intake')) {
            return;
        }

        $drfIds = DB::table(self::OLD_DRF)->where('is_office_intake', true)->pluck('id')->all();
        $dcnIds = Schema::hasTable(self::OLD_DCN)
            ? DB::table(self::OLD_DCN)->where('is_office_intake', true)->pluck('id')->all()
            : [];

        if ($drfIds !== [] && Schema::hasTable('dcs_drf_offices')) {
            DB::table('dcs_drf_offices')->whereIn('document_request_form_id', $drfIds)->delete();
        }
        if ($dcnIds !== [] && Schema::hasTable('dcs_dcn_offices')) {
            DB::table('dcs_dcn_offices')->whereIn('dcn_id', $dcnIds)->delete();
        }
        if ($dcnIds !== [] && Schema::hasTable('dcs_dcn_reviewers')) {
            DB::table('dcs_dcn_reviewers')->whereIn('dcn_id', $dcnIds)->delete();
        }
        if ($dcnIds !== [] && Schema::hasTable('dcs_dcn_approvals')) {
            DB::table('dcs_dcn_approvals')->whereIn('dcn_id', $dcnIds)->delete();
        }
        if ($dcnIds !== [] && Schema::hasTable('dcs_doc_revision')) {
            DB::table('dcs_doc_revision')->whereIn('dcn_id', $dcnIds)->delete();
        }

        if ($drfIds !== []) {
            DB::table(self::OLD_DRF)->whereIn('id', $drfIds)->delete();
        }
        if ($dcnIds !== []) {
            DB::table(self::OLD_DCN)->whereIn('id', $dcnIds)->delete();
        }
    }

    private function dropRegisterIntakeColumns(): void
    {
        if (Schema::hasTable(self::OLD_DRF)) {
            $drop = array_values(array_filter(
                self::DROP_DRF,
                fn (string $column) => Schema::hasColumn(self::OLD_DRF, $column)
            ));
            if ($drop !== []) {
                Schema::table(self::OLD_DRF, function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }

        if (Schema::hasTable(self::OLD_DCN)) {
            $drop = array_values(array_filter(
                self::DROP_DCN,
                fn (string $column) => Schema::hasColumn(self::OLD_DCN, $column)
            ));
            if ($drop !== []) {
                Schema::table(self::OLD_DCN, function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }
    }

    private function dropRetrievalScan(): void
    {
        if (! Schema::hasTable('dcs_document_retrieval')
            || ! Schema::hasColumn('dcs_document_retrieval', 'scanned_retrieval')) {
            return;
        }

        Schema::table('dcs_document_retrieval', function (Blueprint $table) {
            $table->dropColumn('scanned_retrieval');
        });
    }
};
