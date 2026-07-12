<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds a Super Admin role in condition_details / condition_key
     * and assigns it to the test admin account.
     * On Production, assign real admin accounts manually via the admin console.
     */
    public function up(): void
    {
        // Insert super admin permissions row
        $detailsId = DB::table('condition_details')->insertGetId([
            'is_sadm'                => true,
            'can_access_dts'         => true,
            'can_access_rdp'       => true,
            'can_access_dcs'         => true,
            'can_dts_modify_docflow'     => true,
            'can_sadm_modify_accountlist' => true,
            'can_sadm_modify_pass'        => true,
            'can_sadm_modify_account'        => true,
            'can_dts_view_all_list'      => true,
            'can_dts_view_all_archive'   => true,
        ], 'key_id');

        // Insert the Super Admin role entry
        $roleId = DB::table('condition_key')->insertGetId([
            'key_name'        => 'Super Admin',
            'key_description' => 'Full access to all subsystems and management functions',
            'modifier_key'    => $detailsId,
        ]);

        // Assign the super admin role to the test admin account
        DB::table('account')
            ->where('username', 'admin')
            ->update(['account_role' => $roleId]);
    }

    public function down(): void
    {
        $account = DB::table('account')->where('username', 'admin')->first();
        if ($account) {
            $roleId = $account->account_role;

            DB::table('account')
                ->where('username', 'admin')
                ->update(['account_role' => null]);

            if ($roleId) {
                $role = DB::table('condition_key')->where('id', $roleId)->first();
                if ($role) {
                    DB::table('condition_details')->where('key_id', $role->modifier_key)->delete();
                }
                DB::table('condition_key')->where('id', $roleId)->delete();
            }
        }
    }
};
