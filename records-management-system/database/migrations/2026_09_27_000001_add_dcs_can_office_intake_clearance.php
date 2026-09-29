<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Office Intake is an explicit DCS clearance.
 * Existing Access DCS roles without admin modules keep intake (Document Controller).
 * Roles that already have any admin module get every admin page except Recycle Bin.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function adminColumns(string $details): array
    {
        $columns = [
            'dcs_can_register',
            'dcs_can_settings',
            'dcs_can_review_intake',
            'dcs_can_reports',
            'dcs_can_review',
            'dcs_can_stamping',
            'dcs_can_database',
            'dcs_can_manage_files',
            'dcs_can_random_check',
        ];

        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($details, $column)
        ));
    }

    public function up(): void
    {
        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! Schema::hasTable($details)) {
            return;
        }

        if (! Schema::hasColumn($details, 'dcs_can_office_intake')) {
            $after = Schema::hasColumn($details, 'can_access_dcs')
                ? 'can_access_dcs'
                : null;

            Schema::table($details, function (Blueprint $blueprint) use ($after) {
                if ($after) {
                    $blueprint->boolean('dcs_can_office_intake')->default(false)->after($after);
                } else {
                    $blueprint->boolean('dcs_can_office_intake')->default(false);
                }
            });
        }

        try {
            DB::statement("ALTER TABLE {$details} ALTER COLUMN dcs_can_office_intake SET DEFAULT false");
        } catch (\Throwable) {
            // SQLite / drivers without ALTER COLUMN DEFAULT
        }

        $adminColumns = $this->adminColumns($details);

        $intake = DB::table($details)->where('can_access_dcs', true);
        foreach ($adminColumns as $column) {
            $intake->where(function ($query) use ($column) {
                $query->where($column, false)->orWhereNull($column);
            });
        }
        $intake->update(['dcs_can_office_intake' => true]);

        if ($adminColumns === []) {
            return;
        }

        $admins = DB::table($details)->where(function ($query) use ($adminColumns) {
            foreach ($adminColumns as $column) {
                $query->orWhere($column, true);
            }
        });

        $sync = array_fill_keys($adminColumns, true);
        $admins->update($sync);
    }

    public function down(): void
    {
        $details = Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! Schema::hasTable($details) || ! Schema::hasColumn($details, 'dcs_can_office_intake')) {
            return;
        }

        Schema::table($details, function (Blueprint $blueprint) {
            $blueprint->dropColumn('dcs_can_office_intake');
        });
    }
};
