<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remaining third-normal-form splits: multi-originator junctions, keywords,
 * signatory template fields, and edit-history change rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->splitMasterlistOriginators();
        $this->splitOfficeOriginators('dcs_office_intake_drf', 'dcs_office_drf_originators', 'office_intake_drf_id', 'dcs_office_drf_origin_uq', 'dcs_office_drf_origin_drf_fk', 'dcs_office_drf_origin_og_fk');
        $this->splitOfficeOriginators('dcs_office_intake_dcn', 'dcs_office_dcn_originators', 'office_intake_dcn_id', 'dcs_office_dcn_origin_uq', 'dcs_office_dcn_origin_dcn_fk', 'dcs_office_dcn_origin_og_fk');
        $this->splitMasterlistKeywords();
        $this->dropPreviousCopies();
        $this->splitSignatoryPayload();
        $this->splitEditHistoryChanges();
    }

    public function down(): void
    {
        // Data copied into child tables is not folded back into the old columns.
    }

    private function splitMasterlistOriginators(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration') || ! Schema::hasTable('dcs_originators')) {
            return;
        }

        if (! Schema::hasTable('dcs_masterlist_originators')) {
            Schema::create('dcs_masterlist_originators', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('masterlist_id');
                $table->foreign('masterlist_id', 'dcs_ml_originators_ml_fk')
                    ->references('id')
                    ->on('dcs_masterlist_registration')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('originator_id');
                $table->foreign('originator_id', 'dcs_ml_originators_og_fk')
                    ->references('id')
                    ->on('dcs_originators')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unique(['masterlist_id', 'originator_id'], 'dcs_ml_originators_uq');
            });
        }

        $hasName = Schema::hasColumn('dcs_masterlist_registration', 'originator_name');
        $hasId = Schema::hasColumn('dcs_masterlist_registration', 'originator_id');
        if ($hasName || $hasId) {
            DB::table('dcs_masterlist_registration')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($hasName, $hasId) {
                    foreach ($rows as $row) {
                        $raw = '';
                        if ($hasName) {
                            $raw = trim((string) ($row->originator_name ?? ''));
                        }
                        if ($raw === '' && $hasId && ! empty($row->originator_id)) {
                            $raw = trim((string) (DB::table('dcs_originators')->where('id', $row->originator_id)->value('originator_name') ?? ''));
                        }
                        $this->insertOriginatorLinks('dcs_masterlist_originators', 'masterlist_id', (int) $row->id, $raw);
                    }
                });
        }

        try {
            DB::statement('DROP INDEX IF EXISTS dcs_ml_originator_idx');
        } catch (\Throwable) {
        }

        $this->dropColumns('dcs_masterlist_registration', ['originator_id', 'originator_account_id', 'originator_name']);
    }

    private function splitOfficeOriginators(
        string $parent,
        string $junction,
        string $ownerColumn,
        string $uniqueName,
        string $ownerFk,
        string $originatorFk
    ): void {
        if (! Schema::hasTable($parent) || ! Schema::hasTable('dcs_originators')) {
            return;
        }

        if (! Schema::hasTable($junction)) {
            Schema::create($junction, function (Blueprint $table) use ($parent, $ownerColumn, $uniqueName, $ownerFk, $originatorFk) {
                $table->id();
                $table->unsignedBigInteger($ownerColumn);
                $table->foreign($ownerColumn, $ownerFk)
                    ->references('id')
                    ->on($parent)
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('originator_id');
                $table->foreign('originator_id', $originatorFk)
                    ->references('id')
                    ->on('dcs_originators')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unique([$ownerColumn, 'originator_id'], $uniqueName);
            });
        }

        if (Schema::hasColumn($parent, 'originator_name')) {
            DB::table($parent)
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($junction, $ownerColumn) {
                    foreach ($rows as $row) {
                        $this->insertOriginatorLinks($junction, $ownerColumn, (int) $row->id, (string) ($row->originator_name ?? ''));
                    }
                });

            $this->dropColumns($parent, ['originator_name']);
        }
    }

    private function splitMasterlistKeywords(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        if (! Schema::hasTable('dcs_masterlist_keywords')) {
            Schema::create('dcs_masterlist_keywords', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('masterlist_id');
                $table->foreign('masterlist_id', 'dcs_ml_keywords_ml_fk')
                    ->references('id')
                    ->on('dcs_masterlist_registration')
                    ->cascadeOnDelete();
                $table->string('keyword', 255);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->index('masterlist_id', 'dcs_ml_keywords_ml_idx');
            });
        }

        if (! Schema::hasColumn('dcs_masterlist_registration', 'keywords')) {
            return;
        }

        DB::table('dcs_masterlist_registration')
            ->whereNotNull('keywords')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $sort = 0;
                    foreach ($this->splitList($row->keywords ?? null) as $keyword) {
                        $keyword = mb_substr($keyword, 0, 255);
                        if ($keyword === '') {
                            continue;
                        }
                        $exists = DB::table('dcs_masterlist_keywords')
                            ->where('masterlist_id', $row->id)
                            ->where('keyword', $keyword)
                            ->exists();
                        if ($exists) {
                            continue;
                        }
                        DB::table('dcs_masterlist_keywords')->insert([
                            'masterlist_id' => $row->id,
                            'keyword' => $keyword,
                            'sort_order' => $sort++,
                        ]);
                    }
                }
            });

        $this->dropColumns('dcs_masterlist_registration', ['keywords']);
    }

    private function dropPreviousCopies(): void
    {
        $this->dropColumns('dcs_office_intake_drf', ['previous_document_title', 'previous_originator']);
    }

    private function splitSignatoryPayload(): void
    {
        if (! Schema::hasTable('dcs_signatory_templates')) {
            return;
        }

        if (! Schema::hasTable('dcs_signatory_template_fields')) {
            Schema::create('dcs_signatory_template_fields', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->foreign('template_id', 'dcs_sig_fields_tpl_fk')
                    ->references('id')
                    ->on('dcs_signatory_templates')
                    ->cascadeOnDelete();
                $table->string('role_key', 64);
                $table->string('name')->nullable();
                $table->string('designation')->nullable();
                $table->unique(['template_id', 'role_key'], 'dcs_sig_fields_role_uq');
            });
        }

        if (! Schema::hasColumn('dcs_signatory_templates', 'payload')) {
            return;
        }

        DB::table('dcs_signatory_templates')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    if (DB::table('dcs_signatory_template_fields')->where('template_id', $row->id)->exists()) {
                        continue;
                    }
                    $payload = $this->decodeJson($row->payload ?? null);
                    if ($payload === null) {
                        continue;
                    }
                    foreach ($this->signatoryFieldRows($payload) as $field) {
                        DB::table('dcs_signatory_template_fields')->insert([
                            'template_id' => $row->id,
                            'role_key' => $field['role_key'],
                            'name' => $field['name'],
                            'designation' => $field['designation'],
                        ]);
                    }
                }
            });

        $this->dropColumns('dcs_signatory_templates', ['payload']);
    }

    private function splitEditHistoryChanges(): void
    {
        if (! Schema::hasTable('dcs_document_edit_history')) {
            return;
        }

        if (! Schema::hasTable('dcs_document_edit_history_changes')) {
            Schema::create('dcs_document_edit_history_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('history_id');
                $table->foreign('history_id', 'dcs_edit_hist_changes_fk')
                    ->references('id')
                    ->on('dcs_document_edit_history')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('field', 64);
                $table->string('label');
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->index('history_id', 'dcs_edit_hist_changes_hist_idx');
            });
        }

        if (! Schema::hasColumn('dcs_document_edit_history', 'changes')) {
            return;
        }

        DB::table('dcs_document_edit_history')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    if (DB::table('dcs_document_edit_history_changes')->where('history_id', $row->id)->exists()) {
                        continue;
                    }
                    $decoded = $this->decodeJson($row->changes ?? null);
                    if ($decoded === null) {
                        continue;
                    }
                    $sort = 0;
                    foreach (array_values($decoded) as $change) {
                        if (! is_array($change)) {
                            continue;
                        }
                        $field = mb_substr(trim((string) ($change['field'] ?? '')), 0, 64);
                        if ($field === '') {
                            continue;
                        }
                        DB::table('dcs_document_edit_history_changes')->insert([
                            'history_id' => $row->id,
                            'sort_order' => $sort++,
                            'field' => $field,
                            'label' => mb_substr(trim((string) ($change['label'] ?? $field)), 0, 255),
                            'old_value' => $this->historyText($change['old'] ?? null),
                            'new_value' => $this->historyText($change['new'] ?? null),
                        ]);
                    }
                }
            });

        $this->dropColumns('dcs_document_edit_history', ['changes']);
    }

    private function insertOriginatorLinks(string $junction, string $ownerColumn, int $ownerId, mixed $raw): void
    {
        if ($ownerId < 1) {
            return;
        }
        $sort = 0;
        $seen = [];
        foreach ($this->splitList($raw) as $name) {
            $originatorId = $this->findOrCreateOriginatorId($name);
            if ($originatorId < 1 || isset($seen[$originatorId])) {
                continue;
            }
            $seen[$originatorId] = true;
            $exists = DB::table($junction)
                ->where($ownerColumn, $ownerId)
                ->where('originator_id', $originatorId)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table($junction)->insert([
                $ownerColumn => $ownerId,
                'originator_id' => $originatorId,
                'sort_order' => $sort++,
            ]);
        }
    }

    private function findOrCreateOriginatorId(string $name): int
    {
        $name = trim($name);
        if ($name === '' || ! Schema::hasTable('dcs_originators')) {
            return 0;
        }
        $existing = DB::table('dcs_originators')
            ->whereRaw('LOWER(originator_name) = ?', [mb_strtolower($name)])
            ->first(['id']);
        if ($existing) {
            return (int) $existing->id;
        }
        try {
            return (int) DB::table('dcs_originators')->insertGetId([
                'originator_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $existing = DB::table('dcs_originators')
                ->whereRaw('LOWER(originator_name) = ?', [mb_strtolower($name)])
                ->first(['id']);
            if ($existing) {
                return (int) $existing->id;
            }
            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    private function splitList(mixed $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/u', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || in_array($part, $out, true)) {
                continue;
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{role_key: string, name: ?string, designation: ?string}>
     */
    private function signatoryFieldRows(array $payload): array
    {
        $rows = [];
        $roles = [
            'prepared_by' => ['prepared_by_name', 'prepared_by_designation'],
            'reviewed_by' => ['reviewed_by_name', 'reviewed_by_designation'],
            'approved_by' => ['approved_by_name', 'approved_by_designation'],
        ];
        foreach ($roles as $role => [$nameKey, $designationKey]) {
            if (! array_key_exists($nameKey, $payload) && ! array_key_exists($designationKey, $payload)) {
                continue;
            }
            $rows[] = [
                'role_key' => $role,
                'name' => $this->nullableString($payload[$nameKey] ?? null),
                'designation' => $this->nullableString($payload[$designationKey] ?? null),
            ];
        }

        $index = 1;
        foreach ((array) ($payload['reviewers'] ?? []) as $reviewer) {
            $name = trim((string) (is_array($reviewer) ? ($reviewer['name'] ?? '') : $reviewer));
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'role_key' => 'reviewer_'.$index,
                'name' => $name,
                'designation' => null,
            ];
            $index++;
        }

        $index = 1;
        foreach ((array) ($payload['approvals'] ?? []) as $approval) {
            if (! is_array($approval)) {
                continue;
            }
            $name = trim((string) ($approval['name'] ?? ''));
            $position = trim((string) ($approval['position'] ?? ''));
            if ($name === '' && $position === '') {
                continue;
            }
            $rows[] = [
                'role_key' => 'approval_'.$index,
                'name' => $name !== '' ? $name : null,
                'designation' => $position !== '' ? $position : null,
            ];
            $index++;
        }

        return $rows;
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function historyText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }

        return (string) $value;
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $drop = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));
        if ($drop === []) {
            return;
        }
        foreach ($drop as $column) {
            $this->dropForeignKeysOn($table, $column);
        }
        Schema::table($table, function (Blueprint $blueprint) use ($drop) {
            $blueprint->dropColumn($drop);
        });
    }

    private function dropForeignKeysOn(string $table, string $column): void
    {
        $rows = DB::select(
            'select tc.constraint_name
             from information_schema.table_constraints tc
             join information_schema.key_column_usage kcu
               on tc.constraint_name = kcu.constraint_name
              and tc.table_schema = kcu.table_schema
              and tc.table_name = kcu.table_name
             where tc.constraint_type = ?
               and tc.table_schema = current_schema()
               and tc.table_name = ?
               and kcu.column_name = ?',
            ['FOREIGN KEY', $table, $column]
        );
        foreach ($rows as $row) {
            $name = (string) ($row->constraint_name ?? '');
            if ($name === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS "'.$name.'"');
        }
    }
};
