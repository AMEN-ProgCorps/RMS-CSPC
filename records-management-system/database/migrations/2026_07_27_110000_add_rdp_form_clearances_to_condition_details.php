<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            // Access permissions (default true — existing users keep access)
            if (!Schema::hasColumn('condition_details', 'can_rdp_access_form_1')) {
                $table->boolean('can_rdp_access_form_1')->default(true);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_access_form_2')) {
                $table->boolean('can_rdp_access_form_2')->default(true);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_access_form_3')) {
                $table->boolean('can_rdp_access_form_3')->default(true);
            }

            // Modify permissions (default true)
            if (!Schema::hasColumn('condition_details', 'can_rdp_modify_form_1')) {
                $table->boolean('can_rdp_modify_form_1')->default(true);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_modify_form_2')) {
                $table->boolean('can_rdp_modify_form_2')->default(true);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_modify_form_3')) {
                $table->boolean('can_rdp_modify_form_3')->default(true);
            }

            // Print permissions (default true)
            if (!Schema::hasColumn('condition_details', 'can_rdp_print_form_1')) {
                $table->boolean('can_rdp_print_form_1')->default(true);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_print_form_2')) {
                $table->boolean('can_rdp_print_form_2')->default(true);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_print_form_3')) {
                $table->boolean('can_rdp_print_form_3')->default(true);
            }

            // View others — admin-only toggle (default false for safety)
            if (!Schema::hasColumn('condition_details', 'can_rdp_view_others_form_1')) {
                $table->boolean('can_rdp_view_others_form_1')->default(false);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_view_others_form_2')) {
                $table->boolean('can_rdp_view_others_form_2')->default(false);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_view_others_form_3')) {
                $table->boolean('can_rdp_view_others_form_3')->default(false);
            }

            // Edit others — admin-only toggle (default false for safety)
            if (!Schema::hasColumn('condition_details', 'can_rdp_edit_others_form_1')) {
                $table->boolean('can_rdp_edit_others_form_1')->default(false);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_edit_others_form_2')) {
                $table->boolean('can_rdp_edit_others_form_2')->default(false);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_edit_others_form_3')) {
                $table->boolean('can_rdp_edit_others_form_3')->default(false);
            }

            // Print others — admin-only toggle (default false for safety)
            if (!Schema::hasColumn('condition_details', 'can_rdp_print_others_form_1')) {
                $table->boolean('can_rdp_print_others_form_1')->default(false);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_print_others_form_2')) {
                $table->boolean('can_rdp_print_others_form_2')->default(false);
            }
            if (!Schema::hasColumn('condition_details', 'can_rdp_print_others_form_3')) {
                $table->boolean('can_rdp_print_others_form_3')->default(false);
            }
        });

        // Seed existing rows with defaults
        DB::table('condition_details')->update([
            'can_rdp_access_form_1'       => true,
            'can_rdp_access_form_2'       => true,
            'can_rdp_access_form_3'       => true,
            'can_rdp_modify_form_1'       => true,
            'can_rdp_modify_form_2'       => true,
            'can_rdp_modify_form_3'       => true,
            'can_rdp_print_form_1'        => true,
            'can_rdp_print_form_2'        => true,
            'can_rdp_print_form_3'        => true,
            'can_rdp_view_others_form_1'  => false,
            'can_rdp_view_others_form_2'  => false,
            'can_rdp_view_others_form_3'  => false,
            'can_rdp_edit_others_form_1'  => false,
            'can_rdp_edit_others_form_2'  => false,
            'can_rdp_edit_others_form_3'  => false,
            'can_rdp_print_others_form_1' => false,
            'can_rdp_print_others_form_2' => false,
            'can_rdp_print_others_form_3' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            $cols = [
                'can_rdp_access_form_1', 'can_rdp_access_form_2', 'can_rdp_access_form_3',
                'can_rdp_modify_form_1', 'can_rdp_modify_form_2', 'can_rdp_modify_form_3',
                'can_rdp_print_form_1',  'can_rdp_print_form_2',  'can_rdp_print_form_3',
                'can_rdp_view_others_form_1', 'can_rdp_view_others_form_2', 'can_rdp_view_others_form_3',
                'can_rdp_edit_others_form_1', 'can_rdp_edit_others_form_2', 'can_rdp_edit_others_form_3',
                'can_rdp_print_others_form_1','can_rdp_print_others_form_2','can_rdp_print_others_form_3',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('condition_details', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
