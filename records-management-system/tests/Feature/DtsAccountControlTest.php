<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use App\Models\role_list;
use App\Models\role_permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class DtsAccountControlTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminId = 1;
    private int $standardRoleId;
    private int $standardUserId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we have a standard test user with a custom role
        DB::transaction(function () {
            // Clean up any stale records from previous failed runs
            DB::table('account_details')->where('email', 'restricted@example.com')->delete();
            DB::table('account')->where('username', 'dts_restricted_user')->delete();
            DB::table('condition_key')->where('key_name', 'DTS Restricted Role')->delete();

            $maxDetailsId = DB::table('condition_details')->max('key_id') ?: 0;
            $maxKeyId = DB::table('condition_key')->max('id') ?: 0;
            $newId = max($maxDetailsId, $maxKeyId) + 1;
            
            $this->standardRoleId = $newId;

            // Create role permission details
            DB::table('condition_details')->insert([
                'key_id' => $newId,
                'is_sadm' => false,
                'can_access_dts' => true,
                'can_access_rdp' => false,
                'can_access_dcs' => false,
                'can_dts_modify_docflow' => false,
                'can_sadm_modify_accountlist' => false,
                'can_sadm_modify_pass' => false,
                'can_sadm_modify_account' => false,
                'can_dts_view_all_list' => false,
                'can_dts_view_all_archive' => false,
                'can_dts_view_all_current_trans' => false,
                'can_dts_create_own_flow' => false,
                'can_dts_use_internal' => false,
                'can_dts_use_external' => false,
                'can_dts_use_application' => false,
                'can_dts_use_issuance' => false,
                'can_dts_user_received' => false,
            ]);

            // Create role list entry
            DB::table('condition_key')->insert([
                'id' => $newId,
                'key_name' => 'DTS Restricted Role',
                'key_description' => 'Role for testing DTS restrictions',
                'modifier_key' => $newId,
                'is_active' => true,
            ]);

            // Create test account
            $this->standardUserId = DB::table('account')->insertGetId([
                'username' => 'dts_restricted_user',
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $newId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            // Create account details pointing to an office (e.g. VPAA)
            DB::table('account_details')->insert([
                'account_id' => $this->standardUserId,
                'first_name' => 'Restricted',
                'last_name' => 'User',
                'email' => 'restricted@example.com',
                'office_id' => DB::table('office')->where('office_code', 'VPAA')->value('id') ?: 1,
            ]);
        });
    }

    protected function tearDown(): void
    {
        // Clean up test records
        DB::table('account_details')->where('account_id', $this->standardUserId)->delete();
        DB::table('account')->where('id', $this->standardUserId)->delete();
        DB::table('condition_key')->where('id', $this->standardRoleId)->delete();
        DB::table('condition_details')->where('key_id', $this->standardRoleId)->delete();
        parent::tearDown();
    }

    /**
     * Test role permissions management via the Admin roles screen.
     */
    public function test_admin_can_manage_dts_permissions_on_roles_page()
    {
        Auth::login(User::find($this->adminId));

        Volt::test('pages.admin.accounts.roles')
            ->call('selectRole', $this->standardRoleId)
            ->assertSet('canDtsUseInternal', false)
            ->assertSet('canDtsUseExternal', false)
            ->assertSet('canDtsUseApplication', false)
            ->assertSet('canDtsUseIssuance', false)
            ->assertSet('canDtsUserReceived', false)
            ->set('canDtsUseInternal', true)
            ->set('canDtsUseExternal', true)
            ->set('canDtsUseApplication', true)
            ->set('canDtsUseIssuance', true)
            ->set('canDtsUserReceived', true)
            ->call('saveRoleChanges')
            ->assertSet('successMessage', 'Role configuration updated successfully!');

        // Assert DB updated
        $role = role_list::find($this->standardRoleId);
        $perms = $role->permissions;
        $this->assertTrue((bool)$perms->can_dts_use_internal);
        $this->assertTrue((bool)$perms->can_dts_use_external);
        $this->assertTrue((bool)$perms->can_dts_use_application);
        $this->assertTrue((bool)$perms->can_dts_use_issuance);
        $this->assertTrue((bool)$perms->can_dts_user_received);
    }

    /**
     * Test block access to creation pages when permissions are false.
     */
    public function test_user_is_blocked_from_creation_pages_if_disallowed()
    {
        Auth::login(User::find($this->standardUserId));

        // Expect 403 status code on creation pages
        Volt::test('pages.dts.create.internal')->assertStatus(403);
        Volt::test('pages.dts.create.external')->assertStatus(403);
        Volt::test('pages.dts.create.application-letters')->assertStatus(403);
        Volt::test('pages.dts.create.issuances')->assertStatus(403);
    }

    /**
     * Test block access to list pages when permissions are false.
     */
    public function test_user_is_blocked_from_listing_pages_if_disallowed()
    {
        Auth::login(User::find($this->standardUserId));

        // Expect 403 status code on list pages
        Volt::test('pages.dts.list.internal')->assertStatus(403);
        Volt::test('pages.dts.list.external')->assertStatus(403);
        Volt::test('pages.dts.list.application-letters')->assertStatus(403);
        Volt::test('pages.dts.list.issuances')->assertStatus(403);
    }

    /**
     * Test block access to receive transactions page when can_dts_user_received is false.
     */
    public function test_user_is_blocked_from_receive_page_if_can_receive_is_false()
    {
        Auth::login(User::find($this->standardUserId));

        // Expect 403 status code on receive page
        Volt::test('pages.dts.receive')->assertStatus(403);
    }

    /**
     * Test current transaction dashboard: allowed tabs, setTab block, read-only details pane.
     */
    public function test_dashboard_respects_dts_permissions_correctly()
    {
        Auth::login(User::find($this->standardUserId));

        // 1. Initially, no tabs are accessible because permissions are false
        $component = Volt::test('pages.dts.index');

        // Verify switching to restricted tabs is ignored / has no effect
        $component->call('setTab', 'internal')
            ->assertSet('activeTab', 'all'); // Should remain 'all'

        // 2. Grant only 'internal' permission and verify behavior
        $role = role_list::find($this->standardRoleId);
        $role->permissions->update(['can_dts_use_internal' => true]);

        // Refresh Auth user to clear cached permissions relation
        Auth::setUser(User::find($this->standardUserId));

        // Refresh test component and assert switching is allowed now
        $component = Volt::test('pages.dts.index')
            ->call('setTab', 'internal')
            ->assertSet('activeTab', 'internal');

        // Switching to external should still be ignored
        $component->call('setTab', 'external')
            ->assertSet('activeTab', 'internal'); // remains internal

        // 3. Verify read-only modal restrictions since can_dts_user_received is false
        // Create dummy transaction at office VPAA (which standard user belongs to)
        $txDetailsId = 'TRANS-' . strtoupper(Str::random(10));
        $qrCode = 'QR-TST-' . strtoupper(Str::random(10));

        $flowCode = null;
        $sequence = 1;
        
        $sequenceRow = DB::table('dts_sequence_list')->first();
        if ($sequenceRow) {
            $sequence = $sequenceRow->sequence_ranking;
            $flowCode = DB::table('dts_transaction_flow')
                ->where('id', $sequenceRow->control_id)
                ->value('flow_code');
        }

        if (!$flowCode) {
            // Fallback: create flow and sequence ranking
            $flowCode = 'FLOW-TEST';
            $maxFlowId = DB::table('dts_transaction_flow')->max('id') ?: 0;
            DB::table('dts_transaction_flow')->insert([
                'id' => $maxFlowId + 1,
                'flow_code' => $flowCode,
                'flow_name' => 'Test Flow',
                'is_active' => true,
                'added_by' => $this->adminId,
                'date_added' => now(),
                'flow_use' => 'none',
            ]);

            DB::table('dts_sequence_list')->insert([
                'control_id' => $maxFlowId + 1,
                'sequence_ranking' => 1,
                'office_code' => 'VPAA',
            ]);
            $sequence = 1;
        }

        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCode,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => $txDetailsId,
            'current_office' => 'VPAA',
            'status' => 'ongoing',
            'trans_type' => 'internal',
            'sequence' => $sequence,
            'enable_notif' => 1,
            'qr_code' => $qrCode,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $txDetailsId,
            'type' => 'internal',
            'control_number' => 'CTRL-TEST-RESTRICTED',
            'originated_from' => 'VPAA',
            'current_office_hold' => 'VPAA',
            'status' => 'ongoing',
            'subject' => 'Restricted subject test',
            'classification' => 'Normal',
            'action_needed' => 'Please review',
            'is_active' => true,
            'created_by' => $this->standardUserId,
            'transaction_flow' => $flowCode,
            'date_created' => now(),
        ]);

        // Open transaction in details sidebar
        $component->call('openTransaction', $txDetailsId)
            ->assertSet('selectedTransactionId', $txDetailsId);

        // Attempting to forward/complete should be blocked/ignored
        $component->call('completeTransaction');

        // Verify transaction remains ongoing/unchanged
        $this->assertDatabaseHas('dts_transactions', [
            'transaction_id' => $txDetailsId,
            'current_office' => 'VPAA',
            'status' => 'ongoing',
        ]);

        // Clean up dummy transaction
        DB::table('dts_transactions')->where('transaction_id', $txDetailsId)->delete();
        DB::table('dts_transaction_details')->where('id', $txDetailsId)->delete();
        DB::table('dts_qr_code')->where('code_id', $qrCode)->delete();
    }

    /**
     * Test list pages: respect can_dts_view_all_list permission.
     */
    public function test_list_pages_respect_view_all_list_permission()
    {
        // 1. Grant internal use permission so they can access the page
        $role = role_list::find($this->standardRoleId);
        $role->permissions->update([
            'can_dts_use_internal' => true,
            'can_dts_view_all_list' => false,
        ]);
        Auth::setUser(User::find($this->standardUserId));

        // Create dummy transaction created by another user (e.g. adminId)
        $txDetailsId = 'TRANS-' . strtoupper(Str::random(10));
        $qrCode = 'QR-TST-' . strtoupper(Str::random(10));

        $flowCode = null;
        $sequence = 1;
        $sequenceRow = DB::table('dts_sequence_list')->first();
        if ($sequenceRow) {
            $sequence = $sequenceRow->sequence_ranking;
            $flowCode = DB::table('dts_transaction_flow')
                ->where('id', $sequenceRow->control_id)
                ->value('flow_code');
        }

        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCode,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => $txDetailsId,
            'current_office' => 'VPAA',
            'status' => 'ongoing',
            'trans_type' => 'internal',
            'sequence' => $sequence,
            'enable_notif' => 1,
            'qr_code' => $qrCode,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $txDetailsId,
            'type' => 'internal',
            'control_number' => 'CTRL-TEST-RESTRICTED',
            'originated_from' => 'VPAA',
            'current_office_hold' => 'VPAA',
            'status' => 'ongoing',
            'subject' => 'Other User Subject',
            'classification' => 'Normal',
            'action_needed' => 'Please review',
            'is_active' => true,
            'created_by' => $this->adminId, // Created by ADMIN
            'transaction_flow' => $flowCode,
            'date_created' => now(),
        ]);

        Auth::login(User::find($this->standardUserId));

        // Initially with can_dts_view_all_list = false, the list should NOT contain the transaction
        $component = Volt::test('pages.dts.list.internal');
        $transactions = $component->get('transactions');
        $hasTx = collect($transactions->items())->contains('transaction_id', $txDetailsId);
        $this->assertFalse($hasTx);

        // Enable view all list permission
        $role->permissions->update(['can_dts_view_all_list' => true]);
        Auth::setUser(User::find($this->standardUserId));

        // Now the list SHOULD contain the transaction
        $component = Volt::test('pages.dts.list.internal');
        $transactions = $component->get('transactions');
        $hasTx = collect($transactions->items())->contains('transaction_id', $txDetailsId);
        $this->assertTrue($hasTx);

        // Clean up
        DB::table('dts_transactions')->where('transaction_id', $txDetailsId)->delete();
        DB::table('dts_transaction_details')->where('id', $txDetailsId)->delete();
        DB::table('dts_qr_code')->where('code_id', $qrCode)->delete();
    }

    /**
     * Test custom flow sequence reordering and removal functionality.
     */
    public function test_user_can_reorder_and_remove_offices_in_custom_flow()
    {
        Auth::login(User::find($this->standardUserId));

        // Grant can_dts_create_own_flow and can_dts_use_internal permission to standard user
        $role = role_list::find($this->standardRoleId);
        $role->permissions->update([
            'can_dts_use_internal' => true,
            'can_dts_create_own_flow' => true,
        ]);
        Auth::setUser(User::find($this->standardUserId));

        Volt::test('pages.dts.create.internal')
            ->set('customFlowSelectedOffice', 'VPAA')
            ->call('addToCustomFlowSequence')
            ->set('customFlowSelectedOffice', 'ORIGIN')
            ->call('addToCustomFlowSequence')
            ->set('customFlowSelectedOffice', '[H]')
            ->call('addToCustomFlowSequence')
            ->assertSet('customFlowSequence', ['VPAA', 'ORIGIN', '[H]'])
            
            // Reorder: Move 'VPAA' down (index 0 down to index 1)
            ->call('moveDownCustomFlowSequence', 0)
            ->assertSet('customFlowSequence', ['ORIGIN', 'VPAA', '[H]'])

            // Reorder: Move '[H]' up (index 2 up to index 1)
            ->call('moveUpCustomFlowSequence', 2)
            ->assertSet('customFlowSequence', ['ORIGIN', '[H]', 'VPAA'])

            // Remove: Remove item at index 1 ('[H]')
            ->call('removeFromCustomFlowSequence', 1)
            ->assertSet('customFlowSequence', ['ORIGIN', 'VPAA']);
    }

    /**
     * Test custom flow sharing and visibility (user vs office vs system).
     */
    public function test_custom_flow_sharing_visibility()
    {
        // 1. Setup accounts
        $officeVpaaId = DB::table('office')->where('office_code', 'VPAA')->value('id') ?: 1;
        $officeCashId = DB::table('office')->where('office_code', 'CASHIER')->value('id') ?: 2;

        $otherUserId = null;
        $officePeerUserId = null;
        
        DB::transaction(function() use (&$otherUserId, &$officePeerUserId, $officeVpaaId, $officeCashId) {
            $otherUserId = DB::table('account')->insertGetId([
                'username' => 'other_office_user',
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->standardRoleId,
                'account_active' => true,
                'date_created' => now(),
            ]);
            DB::table('account_details')->insert([
                'account_id' => $otherUserId,
                'first_name' => 'Other',
                'last_name' => 'User',
                'email' => 'other@example.com',
                'office_id' => $officeCashId,
            ]);

            $officePeerUserId = DB::table('account')->insertGetId([
                'username' => 'peer_office_user',
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->standardRoleId,
                'account_active' => true,
                'date_created' => now(),
            ]);
            DB::table('account_details')->insert([
                'account_id' => $officePeerUserId,
                'first_name' => 'Peer',
                'last_name' => 'User',
                'email' => 'peer@example.com',
                'office_id' => $officeVpaaId,
            ]);
        });

        // Grant can_dts_create_own_flow and can_dts_use_internal permission to standard user
        $role = role_list::find($this->standardRoleId);
        $role->permissions->update([
            'can_dts_use_internal' => true,
            'can_dts_create_own_flow' => true,
        ]);

        try {
            // 2. Log in as standard user and create private flow ('user')
            Auth::login(User::find($this->standardUserId));
            $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
            $userFlowId = $maxId + 1;
            $userFlowCode = 'FLOW-CUSTOM-TEST-USER-' . time();
            DB::table('dts_transaction_flow')->insert([
                'id' => $userFlowId,
                'flow_name' => 'Private User Flow',
                'flow_code' => $userFlowCode,
                'is_active' => true,
                'flow_use' => 'internal',
                'flow_for' => 'user',
                'added_by' => $this->standardUserId,
                'date_added' => now(),
            ]);

            $officeFlowId = $userFlowId + 1;
            $officeFlowCode = 'FLOW-CUSTOM-TEST-OFFICE-' . time();
            DB::table('dts_transaction_flow')->insert([
                'id' => $officeFlowId,
                'flow_name' => 'Shared Office Flow',
                'flow_code' => $officeFlowCode,
                'is_active' => true,
                'flow_use' => 'internal',
                'flow_for' => 'office',
                'added_by' => $this->standardUserId,
                'date_added' => now(),
            ]);

            // 4. Verify standard user (creator) sees both
            Auth::setUser(User::find($this->standardUserId));
            $component = Volt::test('pages.dts.create.internal');
            $flows = $component->get('flows');
            $flowCodes = collect($flows)->pluck('flow_code')->toArray();
            $this->assertContains($userFlowCode, $flowCodes);
            $this->assertContains($officeFlowCode, $flowCodes);

            // 5. Verify peer user (same office VPAA) sees office shared flow but NOT private flow
            Auth::login(User::find($officePeerUserId));
            Auth::setUser(User::find($officePeerUserId));
            $componentPeer = Volt::test('pages.dts.create.internal');
            $flowsPeer = $componentPeer->get('flows');
            $flowCodesPeer = collect($flowsPeer)->pluck('flow_code')->toArray();
            $this->assertNotContains($userFlowCode, $flowCodesPeer);
            $this->assertContains($officeFlowCode, $flowCodesPeer);

            // 6. Verify other user (different office CASHIER) sees NEITHER
            Auth::login(User::find($otherUserId));
            Auth::setUser(User::find($otherUserId));
            $componentOther = Volt::test('pages.dts.create.internal');
            $flowsOther = $componentOther->get('flows');
            $flowCodesOther = collect($flowsOther)->pluck('flow_code')->toArray();
            $this->assertNotContains($userFlowCode, $flowCodesOther);
            $this->assertNotContains($officeFlowCode, $flowCodesOther);

        } finally {
            // Clean up flows
            if (isset($userFlowId)) {
                DB::table('dts_transaction_flow')->where('id', $userFlowId)->delete();
            }
            if (isset($officeFlowId)) {
                DB::table('dts_transaction_flow')->where('id', $officeFlowId)->delete();
            }

            // Clean up users
            if ($otherUserId) {
                DB::table('account_details')->where('account_id', $otherUserId)->delete();
                DB::table('account')->where('id', $otherUserId)->delete();
            }
            if ($officePeerUserId) {
                DB::table('account_details')->where('account_id', $officePeerUserId)->delete();
                DB::table('account')->where('id', $officePeerUserId)->delete();
            }
        }
    }
}
