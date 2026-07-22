<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminLogsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Authenticate admin user (ID 1)
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_user_creation_and_update_logs()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        $username = 'testuser_' . uniqid();
        $email = $username . '@example.com';

        // Clear existing admin logs for testing
        DB::table('admin_logs')->where('changes', 'like', '%testuser_%')->delete();

        Volt::test('pages.admin.accounts.users')
            ->set('selectedUserId', -1)
            ->set('username', $username)
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->set('firstName', 'Test')
            ->set('lastName', 'User')
            ->set('middleName', 'Middle')
            ->set('email', $email)
            ->set('contactNumber', '1234567890')
            ->set('roleId', 1)
            ->set('officeId', null)
            ->set('isActive', true)
            ->call('saveUserChanges')
            ->assertHasNoErrors();

        // Assert log entry exists
        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Created user account: {$username} (Test User)",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        // Find the created user id
        $createdUser = User::where('username', $username)->first();
        $this->assertNotNull($createdUser);

        // Update user details & status
        Volt::test('pages.admin.accounts.users')
            ->call('selectUser', $createdUser->id)
            ->set('firstName', 'UpdatedTest')
            ->set('lastName', 'UpdatedUser')
            ->set('isActive', false) // Toggle status
            ->call('saveUserChanges')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Updated details for user: {$username}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Toggled active status (Value: 0) for user: {$username}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        // 3. Test Delete User (Soft Delete / Deactivate)
        Volt::test('pages.admin.accounts.users')
            ->call('selectUser', $createdUser->id)
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Soft-deleted user account (Deactivated for transparency): {$username}",
            'admin_id' => 1,
            'what_system' => 3
        ]);
    }

    public function test_role_creation_and_update_logs()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        $roleName = 'TestRole_' . uniqid();

        Volt::test('pages.admin.accounts.roles')
            ->set('selectedRoleId', -1)
            ->set('keyName', $roleName)
            ->set('keyDescription', 'Test Description')
            ->set('isActive', true)
            ->call('saveRoleChanges')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Created role: {$roleName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        $role = DB::table('condition_key')->where('key_name', $roleName)->first();
        $this->assertNotNull($role);

        // Update role
        Volt::test('pages.admin.accounts.roles')
            ->call('selectRole', $role->id)
            ->set('keyName', $roleName)
            ->set('keyDescription', 'Updated Description')
            ->set('isActive', false) // Toggle status
            ->call('saveRoleChanges')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Updated permissions/details for role: {$roleName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Toggled active status (Value: 0) for role: {$roleName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        // 3. Test Delete Role (Soft Delete)
        Volt::test('pages.admin.accounts.roles')
            ->call('selectRole', $role->id)
            ->call('deleteRole')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Soft-deleted role (Deactivated for transparency): {$roleName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);
    }

    public function test_office_creation_and_update_logs()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        $officeName = 'Test Office ' . uniqid();
        $officeCode = 'TO_' . rand(1000, 9999);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -1)
            ->set('officeName', $officeName)
            ->set('officeCode', $officeCode)
            ->set('isActive', true)
            ->call('saveOfficeChanges')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Created office: {$officeName} ({$officeCode})",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        $office = DB::table('office')->where('office_name', $officeName)->first();
        $this->assertNotNull($office);

        // Update office
        Volt::test('pages.admin.accounts.offices')
            ->call('selectOffice', $office->id)
            ->set('officeName', $officeName)
            ->set('officeCode', $officeCode)
            ->set('isActive', false) // Toggle status
            ->call('saveOfficeChanges')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Updated office details for: {$officeName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Toggled active status (Value: 0) for office: {$officeName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);

        // 3. Test Delete Office (Soft Delete)
        Volt::test('pages.admin.accounts.offices')
            ->call('selectOffice', $office->id)
            ->call('deleteOffice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Soft-deleted office (Deactivated for transparency): {$officeName}",
            'admin_id' => 1,
            'what_system' => 3
        ]);
    }

    public function test_transaction_flows_logs_page()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        // Insert a dummy flow log entry
        $logChange = 'Created predefined transaction flow: TEST FLOW FOR TESTING LOGS (' . uniqid() . ')';
        DB::table('admin_logs')->insert([
            'changes' => $logChange,
            'admin_id' => 1,
            'what_system' => 3, // Admin Console
            'when_changes' => now(),
        ]);

        // Assert log entry is fetched by the flow-logs component
        Volt::test('pages.admin.activity.dts.flow-logs')
            ->set('search', 'TEST FLOW FOR TESTING LOGS')
            ->assertSee($logChange);

        // Cleanup
        DB::table('admin_logs')->where('changes', $logChange)->delete();
    }
}
