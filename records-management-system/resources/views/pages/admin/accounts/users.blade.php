<?php
/**
 * Admin Console - Accounts Management Users & Requestors Volt Component
 * 
 * ESXi File Manager / Miller Columns Inspired User Accounts Browser.
 * Features:
 *  - 4-Column Miller Columns browser (Clusters/Roles -> Offices -> Staff Users -> User Profile Inspector)
 *  - Scope switcher: Internal Staff Accounts vs. External Document Requestors (DTS)
 *  - Instant search across username, full name, and email address
 *  - Office and Role assignment pickers with live badge previews
 *  - Account security controls, Google SSO status, and force logout synchronization
 *  - Soft-deactivation and audit logging for system transparency
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - Users & Accounts')] class extends Component {
    /** @var string Active scope: 'users' (Internal Staff) or 'requestors' (DTS Document Requestors) */
    public string $activeTab = 'users';

    // ---- NAVIGATION & FILTERING PROPERTIES ----
    public ?string $selectedClusterCode = null; // null = All
    public ?int $selectedRoleIdFilter = null;   // null = All
    public ?int $selectedOfficeIdFilter = null; // null = All in Cluster/Group
    public string $clusterSearch = '';
    public string $officeColSearch = '';
    public string $search = '';

    // ---- USER PROFILE FORM PROPERTIES ----
    public ?int $selectedUserId = null; // -1 = Create Mode
    public string $firstName = '';
    public string $lastName = '';
    public string $middleName = '';
    public string $email = '';
    public string $username = '';
    public ?int $roleId = null;
    public ?int $officeId = null;
    public bool $isActive = true;

    // Searchable dropdown properties for form editor
    public string $roleSearch = '';
    public bool $showRoleDropdown = false;
    public string $officeSearch = '';
    public bool $showOfficeDropdown = false;

    // ---- REQUESTOR MANAGEMENT PROPERTIES ----
    public string $requestorSearch = '';
    public string $reqCategoryFilter = 'all'; // 'all' | 'Internal' | 'External' | 'Custom'
    public ?int $selectedRequestorId = null;  // -1 = Create Mode
    public string $reqName = '';
    public string $reqPosition = '';
    public string $reqOffice = '';
    public bool $reqIsActive = true;

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    /**
     * Component mount hook - initializes authorization.
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->is_admin && !$perms->can_sadm_modify_accountlist && !$perms->can_sadm_modify_account)) {
            $this->redirect(route('portal'));
            return;
        }
        $this->cancelSelection();
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->cancelSelection();
        $this->cancelRequestorSelection();
    }

    // ==========================================
    // FILTERING NAVIGATION HANDLERS
    // ==========================================
    public function filterByAll(): void
    {
        $this->selectedClusterCode = null;
        $this->selectedRoleIdFilter = null;
        $this->selectedOfficeIdFilter = null;
        $this->selectedUserId = null;
        $this->clearMessages();
    }

    public function filterByCluster(?string $code): void
    {
        $this->selectedClusterCode = $code;
        $this->selectedRoleIdFilter = null;
        $this->selectedOfficeIdFilter = null;
        $this->selectedUserId = null;
        $this->clearMessages();
    }

    public function filterByRole(?int $roleId): void
    {
        $this->selectedRoleIdFilter = $roleId;
        $this->selectedClusterCode = null;
        $this->selectedOfficeIdFilter = null;
        $this->selectedUserId = null;
        $this->clearMessages();
    }

    public function filterByOffice(?int $officeId): void
    {
        $this->selectedOfficeIdFilter = $officeId;
        $this->selectedUserId = null;
        $this->clearMessages();
    }

    // ==========================================
    // STAFF USER SELECTION & CRUD
    // ==========================================
    public function cancelSelection(): void
    {
        $this->selectedUserId = null;
        $this->username = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->middleName = '';
        $this->email = '';
        $this->roleId = null;
        $this->officeId = null;
        $this->isActive = true;
        $this->roleSearch = '';
        $this->showRoleDropdown = false;
        $this->officeSearch = '';
        $this->showOfficeDropdown = false;
        $this->clearMessages();
    }

    public function startCreate(): void
    {
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to create user accounts.';
            return;
        }

        $this->cancelSelection();
        $this->selectedUserId = -1;
        $this->isActive = true;

        if ($this->selectedOfficeIdFilter) {
            $this->officeId = $this->selectedOfficeIdFilter;
            $off = \App\Models\office::find($this->officeId);
            $this->officeSearch = $off ? $off->office_name : '';
        }

        if ($this->selectedRoleIdFilter) {
            $this->roleId = $this->selectedRoleIdFilter;
            $r = \App\Models\role_list::find($this->roleId);
            $this->roleSearch = $r ? $r->key_name : '';
        }
    }

    public function selectUser(int $id): void
    {
        $this->cancelSelection();
        $this->selectedUserId = $id;

        $user = \App\Models\User::with('details')->find($id);
        if ($user) {
            $this->username = $user->username;
            $this->roleId = $user->account_role;
            if ($this->roleId) {
                $role = \App\Models\role_list::find($this->roleId);
                $this->roleSearch = $role ? $role->key_name : '';
            }

            $this->isActive = (bool) $user->account_active;

            $details = $user->details;
            if ($details) {
                $this->firstName = $details->first_name ?? '';
                $this->lastName = $details->last_name ?? '';
                $this->middleName = $details->middle_name ?? '';
                $this->email = $details->email ?? '';
                $this->officeId = $details->office_id;
                if ($this->officeId) {
                    $office = \App\Models\office::find($this->officeId);
                    $this->officeSearch = $office ? $office->office_name : '';
                }
            }
        }
    }

    public function selectRole(?int $id, string $name): void
    {
        $this->roleId = $id;
        $this->roleSearch = $name;
        $this->showRoleDropdown = false;
    }

    public function selectOffice(?int $id, string $name): void
    {
        $this->officeId = $id;
        $this->officeSearch = $name;
        $this->showOfficeDropdown = false;
    }

    public function updatedOfficeSearch(): void
    {
        $this->showOfficeDropdown = true;
    }

    public function updatedRoleSearch(): void
    {
        $this->showRoleDropdown = true;
    }

    public function saveUserChanges(): void
    {
        if (!$this->selectedUserId) return;

        $this->clearMessages();

        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify user accounts.';
            return;
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $condKeyTbl = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        if ($this->selectedUserId === -1) {
            $this->validate([
                'firstName' => 'nullable|string|max:255',
                'lastName' => 'nullable|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => "required|email|max:255|unique:{$accDetailsTbl},email",
                'roleId' => "required|exists:{$condKeyTbl},id",
                'officeId' => "nullable|exists:{$officeTbl},id",
            ]);
        } else {
            $this->validate([
                'firstName' => 'required|string|max:255',
                'lastName' => 'required|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => "required|email|max:255|unique:{$accDetailsTbl},email,{$this->selectedUserId},account_id",
                'roleId' => "required|exists:{$condKeyTbl},id",
                'officeId' => "nullable|exists:{$officeTbl},id",
            ]);
        }

        try {
            DB::transaction(function () {
                if ($this->selectedUserId === -1) {
                    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $this->email)[0] ?? 'user'));
                    if (empty($baseUsername)) $baseUsername = 'user';
                    $derivedUsername = $baseUsername;
                    $counter = 1;
                    while (\App\Models\User::where('username', $derivedUsername)->exists()) {
                        $derivedUsername = $baseUsername . $counter;
                        $counter++;
                    }

                    $user = new \App\Models\User();
                    $user->username = $derivedUsername;
                    $user->password = \Hash::make(Str::random(32));
                    $user->account_status = 1;
                    $user->account_role = $this->roleId;
                    $user->account_active = $this->isActive;
                    $user->date_created = now();
                    $user->date_updated = now();
                    $user->save();

                    $details = new \App\Models\AccountDetail();
                    $details->account_id = $user->id;
                    $details->first_name = !empty(trim($this->firstName)) ? trim($this->firstName) : 'Pending';
                    $details->last_name = !empty(trim($this->lastName)) ? trim($this->lastName) : 'Google Sync';
                    $details->middle_name = $this->middleName;
                    $details->email = $this->email;
                    $details->office_id = $this->officeId;
                    $details->is_currently_online = false;
                    $details->save();

                    $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                    DB::table($adminLogsTbl)->insert([
                        'changes' => "Created user account: {$user->username} ({$this->firstName} {$this->lastName} <{$this->email}>)",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);

                    $this->selectedUserId = $user->id;
                    $this->username = $user->username;
                    $this->successMessage = 'User account created successfully!';
                } else {
                    $user = \App\Models\User::findOrFail($this->selectedUserId);
                    $statusChanged = $user->account_active != $this->isActive;

                    $user->update([
                        'account_role' => $this->roleId,
                        'account_active' => $this->isActive,
                        'date_updated' => now(),
                    ]);

                    $details = \App\Models\AccountDetail::find($this->selectedUserId);
                    if (!$details) {
                        $details = new \App\Models\AccountDetail();
                        $details->account_id = $this->selectedUserId;
                    }
                    $details->first_name = $this->firstName;
                    $details->last_name = $this->lastName;
                    $details->middle_name = $this->middleName;
                    $details->email = $this->email;
                    $details->office_id = $this->officeId;

                    if ($this->selectedUserId !== auth()->id()) {
                        $details->force_logout_at = now();
                        $details->is_currently_online = false;
                        $details->save();

                        try {
                            DB::table('sessions')->where('user_id', (string) $this->selectedUserId)->orWhere('user_id', (int) $this->selectedUserId)->delete();
                        } catch (\Throwable) {}
                    } else {
                        $details->save();
                    }

                    $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                    DB::table($adminLogsTbl)->insert([
                        'changes' => "Updated details for user: {$user->username} ({$this->firstName} {$this->lastName})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);

                    if ($statusChanged) {
                        DB::table($adminLogsTbl)->insert([
                            'changes' => "Toggled active status (Value: " . ($this->isActive ? '1' : '0') . ") for user: {$user->username}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now()
                        ]);
                    }

                    $this->successMessage = 'User account profile updated successfully!';
                }
            });

            try {
                if (file_exists(public_path('messify/core/WsPush.php'))) {
                    require_once public_path('messify/bootstrap.php');
                    \WsPush::broadcast('users_changed', [
                        'account_id' => $this->selectedUserId,
                        'office_id'  => $this->officeId,
                    ]);
                } elseif (file_exists(public_path('messify/core/WsPush.php'))) {
                    require_once public_path('messify/bootstrap.php');
                    \WsPush::broadcast('users_changed', [
                        'account_id' => $this->selectedUserId,
                        'office_id'  => $this->officeId,
                    ]);
                }
            } catch (\Throwable) {}

            if ($this->selectedUserId === auth()->id()) {
                session()->flash('success', 'User account updated successfully!');
                $this->js('window.location.reload();');
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save user account: ' . $e->getMessage();
        }
    }

    public function deleteUser(): void
    {
        $this->clearMessages();

        if (!$this->selectedUserId || $this->selectedUserId <= 0) return;

        if ($this->selectedUserId === auth()->id()) {
            $this->errorMessage = 'You cannot delete your own logged-in account!';
            return;
        }

        try {
            DB::transaction(function () {
                $user = \App\Models\User::findOrFail($this->selectedUserId);
                $user->update(['account_active' => false, 'date_updated' => now()]);

                $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
                DB::table($accDetailsTbl)->where('account_id', $this->selectedUserId)->update([
                    'force_logout_at' => now(),
                    'is_currently_online' => false,
                ]);

                try {
                    DB::table('sessions')->where('user_id', (string) $this->selectedUserId)->orWhere('user_id', (int) $this->selectedUserId)->delete();
                } catch (\Throwable) {}

                $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                DB::table($adminLogsTbl)->insert([
                    'changes' => "Soft-deleted user account (Deactivated for transparency): {$user->username}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->cancelSelection();
                $this->successMessage = 'User account deactivated successfully!';
            });

            try {
                if (file_exists(public_path('messify/core/WsPush.php'))) {
                    require_once public_path('messify/bootstrap.php');
                    \WsPush::broadcast('users_changed', ['account_id' => $this->selectedUserId]);
                } elseif (file_exists(public_path('messify/core/WsPush.php'))) {
                    require_once public_path('messify/bootstrap.php');
                    \WsPush::broadcast('users_changed', ['account_id' => $this->selectedUserId]);
                }
            } catch (\Throwable) {}
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete user: ' . $e->getMessage();
        }
    }

    /**
     * Drag-and-drop: Reassign a user to a different office.
     */
    public function assignUserOffice(int $userId, int $officeId): void
    {
        $this->clearMessages();

        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify user accounts.';
            return;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $office = DB::table($officeTbl)->where('id', $officeId)->first();
        if (!$office) {
            $this->errorMessage = 'Selected office does not exist.';
            return;
        }

        try {
            DB::transaction(function () use ($userId, $officeId, $office) {
                $user = \App\Models\User::with('details')->findOrFail($userId);

                $details = \App\Models\AccountDetail::find($userId);
                if (!$details) {
                    $details = new \App\Models\AccountDetail();
                    $details->account_id = $userId;
                    $details->first_name = $user->username;
                    $details->last_name = '';
                    $details->email = $user->username . '@cspc.edu.ph';
                }
                $details->office_id = $officeId;
                $details->save();

                $user->update(['date_updated' => now()]);

                if ($this->selectedUserId === $userId) {
                    $this->officeId = $officeId;
                    $this->officeSearch = $office->office_name;
                }

                $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                DB::table($adminLogsTbl)->insert([
                    'changes' => "Reassigned user @{$user->username} to office: {$office->office_name} ({$office->office_code}) via Miller drag-and-drop",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->successMessage = "User @{$user->username} successfully reassigned to {$office->office_name} ({$office->office_code})!";
            });

            try {
                if (file_exists(public_path('messify/core/WsPush.php'))) {
                    require_once public_path('messify/bootstrap.php');
                    \WsPush::broadcast('users_changed', [
                        'account_id' => $userId,
                        'office_id'  => $officeId,
                    ]);
                } elseif (file_exists(public_path('messify/core/WsPush.php'))) {
                    require_once public_path('messify/bootstrap.php');
                    \WsPush::broadcast('users_changed', [
                        'account_id' => $userId,
                        'office_id'  => $officeId,
                    ]);
                }
            } catch (\Throwable) {}
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to reassign user office: ' . $e->getMessage();
        }
    }

    /**
     * Drag-and-drop: Reassign an office to a different cluster.
     */
    public function assignOfficeCluster(int $officeId, ?string $clusterCode): void
    {
        $this->clearMessages();

        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account && ! $currentUserPerms->is_admin)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify office organization.';
            return;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        try {
            DB::transaction(function () use ($officeId, $clusterCode, $officeTbl) {
                $office = DB::table($officeTbl)->where('id', $officeId)->first();
                if (!$office) {
                    throw new \Exception('Office not found.');
                }

                $targetCode = !empty($clusterCode) ? $clusterCode : null;
                $clusterLabel = $targetCode;
                if ($targetCode) {
                    $cluster = DB::table('sys_cluster')->where('cluster_code', $targetCode)->first();
                    if ($cluster) {
                        $clusterLabel = "{$cluster->cluster_name} ({$targetCode})";
                    }
                } else {
                    $clusterLabel = 'Unassigned';
                }

                DB::table($officeTbl)->where('id', $officeId)->update([
                    'cluster' => $targetCode,
                ]);

                $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                DB::table($adminLogsTbl)->insert([
                    'changes' => "Reassigned office {$office->office_name} ({$office->office_code}) to cluster: {$clusterLabel} via Miller drag-and-drop",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $this->successMessage = "Office {$office->office_name} assigned to cluster {$clusterLabel} successfully!";
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to reassign office cluster: ' . $e->getMessage();
        }
    }

    // ==========================================
    // REQUESTOR MANAGEMENT ACTIONS
    // ==========================================
    public function startCreateRequestor(): void
    {
        $this->cancelRequestorSelection();
        $this->selectedRequestorId = -1;
        $this->reqName = 'New Requestor Contact';
        $this->reqPosition = '';
        $this->reqOffice = '';
        $this->reqIsActive = true;
    }

    public function selectRequestorItem(int $id): void
    {
        $this->cancelRequestorSelection();
        $this->selectedRequestorId = $id;

        $req = DB::table('dts_requestor_history')->where('id', $id)->first();
        if ($req) {
            $this->reqName = $req->requestor_name;
            $this->reqPosition = $req->requestor_position ?? '';
            $this->reqOffice = $req->office ?? '';
            $this->reqIsActive = (bool) $req->is_active;
        }
    }

    public function cancelRequestorSelection(): void
    {
        $this->selectedRequestorId = null;
        $this->reqName = '';
        $this->reqPosition = '';
        $this->reqOffice = '';
        $this->reqIsActive = true;
        $this->clearMessages();
    }

    public function saveRequestorChanges(): void
    {
        $this->clearMessages();
        $this->validate([
            'reqName' => 'required|string|max:255',
            'reqOffice' => 'required|string|max:100',
            'reqPosition' => 'nullable|string|max:255',
        ]);

        try {
            if ($this->selectedRequestorId === -1) {
                $newId = DB::table('dts_requestor_history')->insertGetId([
                    'requestor_name' => trim($this->reqName),
                    'requestor_position' => trim($this->reqPosition),
                    'office' => trim($this->reqOffice),
                    'is_active' => $this->reqIsActive,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                DB::table($adminLogsTbl)->insert([
                    'changes' => "Created requestor contact: {$this->reqName} ({$this->reqOffice})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);

                $this->selectRequestorItem($newId);
                $this->successMessage = 'Requestor contact created successfully!';
            } elseif ($this->selectedRequestorId > 0) {
                DB::table('dts_requestor_history')
                    ->where('id', $this->selectedRequestorId)
                    ->update([
                        'requestor_name' => trim($this->reqName),
                        'requestor_position' => trim($this->reqPosition),
                        'office' => trim($this->reqOffice),
                        'is_active' => $this->reqIsActive,
                        'updated_at' => now(),
                    ]);

                $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                DB::table($adminLogsTbl)->insert([
                    'changes' => "Updated requestor contact ID {$this->selectedRequestorId}: {$this->reqName}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);

                $this->successMessage = 'Requestor details updated successfully!';
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save requestor: ' . $e->getMessage();
        }
    }

    public function deleteRequestor(): void
    {
        if ($this->selectedRequestorId === null || $this->selectedRequestorId <= 0) return;

        $this->clearMessages();

        try {
            $req = DB::table('dts_requestor_history')->where('id', $this->selectedRequestorId)->first();
            if ($req) {
                DB::table('dts_requestor_history')->where('id', $this->selectedRequestorId)->update(['is_active' => false, 'updated_at' => now()]);

                $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                DB::table($adminLogsTbl)->insert([
                    'changes' => "Deactivated requestor contact: {$req->requestor_name}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);

                $this->cancelRequestorSelection();
                $this->successMessage = 'Requestor contact deactivated successfully!';
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to deactivate requestor: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $accTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accDetailsTable = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $roleTable = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        // 1. Clusters Collection for Column 1
        $clusterQuery = \App\Models\Cluster::query()->where('is_active', true);
        if ($this->clusterSearch !== '') {
            $cSearch = '%' . $this->clusterSearch . '%';
            $clusterQuery->where(function($q) use ($cSearch) {
                $q->where('cluster_name', 'ilike', $cSearch)->orWhere('cluster_code', 'ilike', $cSearch);
            });
        }
        $clusters = $clusterQuery->orderBy('cluster_name', 'asc')->get();

        // 2. Roles list for authority filter & dropdowns
        $roles = \App\Models\role_list::where('is_active', true)->orderBy('key_name')->get();

        // 3. User counts per role and per cluster
        $roleUserCounts = \App\Models\User::where('account_active', true)
            ->groupBy('account_role')
            ->selectRaw('account_role, count(*) as count')
            ->pluck('count', 'account_role')
            ->toArray();

        $totalUsersCount = \App\Models\User::where('account_active', true)->count();

        // 4. Offices Query for Column 2
        $officeQuery = \App\Models\office::query()->where('is_active', true)->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB']);
        if ($this->selectedClusterCode !== null) {
            $officeQuery->where('cluster', $this->selectedClusterCode);
        }
        if ($this->officeColSearch !== '') {
            $oSearch = '%' . $this->officeColSearch . '%';
            $officeQuery->where(function($q) use ($oSearch) {
                $q->where('office_name', 'ilike', $oSearch)->orWhere('office_code', 'ilike', $oSearch);
            });
        }
        $offices = $officeQuery->orderBy('office_name', 'asc')->get();

        // Inspector Assigned Office picker — filter by name/code independently of miller column search.
        $assignableOfficesQuery = \App\Models\office::query()
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'HUB']);
        $officePickerTerm = trim($this->officeSearch);
        if ($officePickerTerm !== '') {
            $pickerSearch = '%' . $officePickerTerm . '%';
            $assignableOfficesQuery->where(function ($q) use ($pickerSearch) {
                $q->where('office_name', 'ilike', $pickerSearch)
                    ->orWhere('office_code', 'ilike', $pickerSearch);
            });
        }
        $assignableOffices = $assignableOfficesQuery->orderBy('office_name', 'asc')->limit(150)->get();

        $rolePickerTerm = trim($this->roleSearch);
        $rolePickerOptions = $rolePickerTerm === ''
            ? $roles
            : $roles->filter(function ($r) use ($rolePickerTerm) {
                return stripos((string) ($r->key_name ?? ''), $rolePickerTerm) !== false;
            })->values();

        // Staff counts per office
        $officeStaffCounts = [];
        try {
            if (Schema::hasTable($accDetailsTable) && Schema::hasTable($accTable)) {
                $officeStaffCounts = DB::table($accDetailsTable . ' as ad')
                    ->join($accTable . ' as a', 'a.id', '=', 'ad.account_id')
                    ->where('a.account_active', true)
                    ->groupBy('ad.office_id')
                    ->selectRaw('ad.office_id, count(*) as count')
                    ->pluck('count', 'office_id')
                    ->toArray();
            }
        } catch (\Throwable $e) {}

        // 5. Users Query for Column 3
        $userQuery = \App\Models\User::with(['details', 'details.office'])->where('account_active', true);

        if ($this->selectedOfficeIdFilter !== null) {
            $userQuery->whereHas('details', function($q) {
                $q->where('office_id', $this->selectedOfficeIdFilter);
            });
        } elseif ($this->selectedClusterCode !== null) {
            $userQuery->whereHas('details.office', function($q) {
                $q->where('cluster', $this->selectedClusterCode);
            });
        } elseif ($this->selectedRoleIdFilter !== null) {
            $userQuery->where('account_role', $this->selectedRoleIdFilter);
        }

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $userQuery->where(function($q) use ($s) {
                $q->where('username', 'ilike', $s)
                  ->orWhereHas('details', function($d) use ($s) {
                      $d->where('first_name', 'ilike', $s)
                        ->orWhere('last_name', 'ilike', $s)
                        ->orWhere('email', 'ilike', $s);
                  });
            });
        }
        $users = $userQuery->orderBy('username', 'asc')->get();

        // 6. Requestors Query (Mode B)
        $requestorsQuery = DB::table('dts_requestor_history as req')
            ->leftJoin($officeTable . ' as off', 'off.office_code', '=', 'req.office')
            ->leftJoin('dts_source_office as src', 'src.s_office_code', '=', 'req.office')
            ->when(!empty($this->requestorSearch), function($q) {
                $s = trim($this->requestorSearch);
                $q->where(function($sub) use ($s) {
                    $sub->where('req.requestor_name', 'ilike', "%{$s}%")
                        ->orWhere('req.requestor_position', 'ilike', "%{$s}%")
                        ->orWhere('req.office', 'ilike', "%{$s}%")
                        ->orWhere('off.office_name', 'ilike', "%{$s}%")
                        ->orWhere('src.s_office_name', 'ilike', "%{$s}%");
                });
            })
            ->select(
                'req.*',
                DB::raw("COALESCE(off.office_name, src.s_office_name, req.office) as office_display_name"),
                DB::raw("CASE WHEN off.office_code IS NOT NULL THEN 'Internal' WHEN src.s_office_code IS NOT NULL THEN 'External' ELSE 'Custom' END as office_category")
            );

        if ($this->reqCategoryFilter !== 'all') {
            if ($this->reqCategoryFilter === 'active') {
                $requestorsQuery->where('req.is_active', true);
            } elseif ($this->reqCategoryFilter === 'inactive') {
                $requestorsQuery->where('req.is_active', false);
            }
        }
        $requestors = $requestorsQuery->orderBy('req.requestor_name', 'asc')->get();

        // Requestor filing history in DTS for Column 3
        $reqTransactions = collect();
        if ($this->selectedRequestorId && $this->selectedRequestorId > 0 && Schema::hasTable('dts_transaction_details') && Schema::hasColumn('dts_transaction_details', 'requestor_id')) {
            try {
                $orderCol = Schema::hasColumn('dts_transaction_details', 'date_created') ? 'date_created' : 'id';
                $reqTransactions = DB::table('dts_transaction_details')
                    ->where('requestor_id', $this->selectedRequestorId)
                    ->orderBy($orderCol, 'desc')
                    ->limit(8)
                    ->get();
            } catch (\Throwable $e) {}
        }

        return [
            'clusters'            => $clusters,
            'roles'               => $roles,
            'rolePickerOptions'   => $rolePickerOptions,
            'roleUserCounts'      => $roleUserCounts,
            'totalUsersCount'     => $totalUsersCount,
            'offices'             => $offices,
            'assignableOffices'   => $assignableOffices,
            'officeStaffCounts'   => $officeStaffCounts,
            'users'               => $users,
            'requestors'          => $requestors,
            'reqTransactions'     => $reqTransactions,
        ];
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            user-select: none;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* 4-Column Miller Browser Layout */
        .miller-browser {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 260px 260px 1fr 340px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            min-height: 540px;
        }

        .miller-column {
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-height: 0;
            position: relative;
        }
        .miller-column:nth-child(even) {
            background: #fafbfc;
        }
        .miller-column:last-child {
            border-right: none;
        }

        .m-col-header {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .m-col-header span {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .m-col-search {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            color: #0f172a;
        }
        .m-col-search input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-col-list {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 4px 0;
        }

        .m-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
            gap: 8px;
        }
        .m-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .m-item.active {
            background: #0284c7;
            color: #ffffff;
            border-left-color: #0369a1;
            font-weight: 600;
        }
        .m-item.active .m-item-icon,
        .m-item.active .m-item-arrow,
        .m-item.active .m-item-sub {
            color: #ffffff !important;
            opacity: 0.9;
        }
        .m-item.active .m-item-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.35);
        }

        .m-item-left {
            display: flex;
            align-items: center;
            gap: 9px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1 1 auto;
        }
        .m-item-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0369a1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .m-item.active .m-item-avatar {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .m-item-text {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .m-item-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12.5px;
            font-weight: 600;
        }
        .m-item-sub {
            font-size: 10.5px;
            color: #64748b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .m-item-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .m-item-badge {
            font-size: 10.5px;
            padding: 2px 7px;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 700;
            border: 1px solid #cbd5e1;
        }
        .m-item-arrow {
            font-size: 11px;
            color: #94a3b8;
        }

        /* Scope Pills Toolbar */
        .scope-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .scope-pill.active {
            background: #0284c7;
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(2, 132, 199, 0.2);
        }
        .scope-pill:not(.active) {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }
        .scope-pill:not(.active):hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Buttons */
        .m-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        .m-btn-primary {
            background: #0284c7;
            color: #ffffff;
        }
        .m-btn-primary:hover {
            background: #0369a1;
        }
        .m-btn-danger {
            background: #ef4444;
            color: #ffffff;
        }
        .m-btn-danger:hover {
            background: #dc2626;
        }
        .m-btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .m-btn-secondary:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Column 4: Inspector Panel */
        .m-inspector {
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow-y: auto;
            flex: 1 1 auto;
        }
        .m-inspector-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .m-inspector-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.25);
        }
        .m-inspector-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
            margin: 0;
        }
        .m-inspector-sub {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .m-field-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .m-field-label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .m-field-input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12.5px;
            color: #0f172a;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }
        .m-field-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-metric-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .m-metric-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #64748b;
        }
        .m-metric-value {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
        }

        /* Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 22px;
            flex-shrink: 0;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .2s; border-radius: 22px;
        }
        .slider:before {
            position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px;
            background-color: white; transition: .2s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        input:checked + .slider { background-color: #0284c7; }
        input:checked + .slider:before { transform: translateX(16px); }

        /* Bottom Breadcrumb Bar */
        .miller-path-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 9px 16px;
            font-size: 12px;
            color: #475569;
            overflow-x: auto;
            white-space: nowrap;
        }
        .miller-path-tag {
            font-weight: 700;
            color: #0f172a;
        }

        .m-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
            color: #94a3b8;
            height: 100%;
            box-sizing: border-box;
        }
        .m-empty-icon {
            font-size: 32px;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        /* Drag and Drop Styling */
        .m-item.draggable-item {
            cursor: pointer;
        }
        .m-item.draggable-item:active {
            cursor: grabbing;
        }
        .m-item.is-dragging {
            cursor: grabbing;
            opacity: 0.45;
            background: #e0f2fe;
            border: 1px dashed #0284c7;
        }
        .drop-target-hover {
            background: #e0f2fe !important;
            border: 1.5px dashed #0284c7 !important;
            transform: scale(1.02);
            transition: all 0.12s ease;
        }

        /* Dark mode */
        [data-theme="dark"] .miller-browser,
        [data-theme="dark"] .miller-column {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .miller-column:nth-child(even) { background: #182234; }
        [data-theme="dark"] .m-col-header,
        [data-theme="dark"] .miller-path-bar {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-col-search {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .m-col-search input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-item { color: #cbd5e1; }
        [data-theme="dark"] .m-item:hover { background: #334155; color: #f8fafc; }
        [data-theme="dark"] .m-item.active { background: #0284c7; color: #ffffff; }
        [data-theme="dark"] .m-item.is-dragging { background: #0c4a6e; border-color: #38bdf8; }
        [data-theme="dark"] .drop-target-hover { background: #0c4a6e !important; border-color: #38bdf8 !important; }
        [data-theme="dark"] .m-field-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-metric-card {
            background: #0f172a;
            border-color: #334155;
        }
    </style>
@endpush

<div class="miller-container">
    {{-- Top Action Toolbar --}}
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
        <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
            <div>
                <h1 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-users-gear" style="color: #0284c7;"></i> User Accounts & Credentials
                </h1>
                <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
                    Directory browser: inspect departmental staff, role clearance mappings, and DTS requestor history.
                </p>
            </div>

            {{-- Scope Selector --}}
            <div style="display: flex; gap: 6px; background: #e2e8f0; padding: 3px; border-radius: 10px;">
                <button type="button" class="scope-pill {{ $activeTab === 'users' ? 'active' : '' }}" wire:click="switchTab('users')">
                    <i class="fa-solid fa-user-shield"></i> Internal Staff Accounts
                </button>
                <button type="button" class="scope-pill {{ $activeTab === 'requestors' ? 'active' : '' }}" wire:click="switchTab('requestors')">
                    <i class="fa-solid fa-address-book"></i> External Requestors (DTS)
                </button>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            @if($activeTab === 'users')
                <button type="button" wire:click="startCreate" class="m-btn m-btn-primary">
                    <i class="fa-solid fa-user-plus"></i> New Staff User
                </button>
            @else
                <button type="button" wire:click="startCreateRequestor" class="m-btn m-btn-primary">
                    <i class="fa-solid fa-plus"></i> New Requestor
                </button>
            @endif

            @if($selectedUserId || $selectedRequestorId)
                <button type="button" wire:click="cancelSelection" class="m-btn m-btn-secondary" title="Close active selection">
                    <i class="fa-solid fa-xmark"></i> Close Selection
                </button>
            @endif
        </div>
    </div>

    {{-- Feedback Messages --}}
    @if($successMessage || session('success'))
        <div role="status" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage ?: session('success') }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if($errorMessage || session('error'))
        <div role="alert" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage ?: session('error') }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    {{-- ======================================================== --}}
    {{-- MODE 1: INTERNAL STAFF USERS --}}
    {{-- ======================================================== --}}
    @if($activeTab === 'users')
        <div class="miller-browser">
            {{-- COLUMN 1: SCOPE GROUPS (CLUSTERS / ROLES) --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sitemap" style="color: #64748b;"></i> 1. Organization & Scopes</span>
                    <span class="m-item-badge">{{ $totalUsersCount }} Staff</span>
                </div>

                <div class="m-col-list">
                    {{-- All Users --}}
                    <div class="m-item {{ $selectedClusterCode === null && $selectedRoleIdFilter === null ? 'active' : '' }}" wire:click="filterByAll">
                        <div class="m-item-left">
                            <i class="fa-solid fa-users m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">All College Staff</span>
                                <span class="m-item-sub">System-wide directory</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $totalUsersCount }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    {{-- Section Divider: Clusters --}}
                    <div style="padding: 6px 14px 2px 14px; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">
                        Divisions & Clusters
                    </div>

                    @foreach($clusters as $c)
                        <div class="m-item {{ $selectedClusterCode === $c->cluster_code ? 'active' : '' }}" 
                             wire:click="filterByCluster('{{ $c->cluster_code }}')" 
                             wire:key="c-scope-{{ $c->id }}"
                             ondragover="if (event.dataTransfer.types.includes('text/office-id')) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover'); }"
                             ondragleave="this.classList.remove('drop-target-hover');"
                             ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const offId = event.dataTransfer.getData('text/office-id'); if (offId) { window.usersAssignOfficeCluster(offId, '{{ $c->cluster_code }}', this); }">
                            <div class="m-item-left">
                                <i class="fa-solid fa-building-columns m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $c->cluster_name }}</span>
                                    <span class="m-item-sub">Code: {{ $c->cluster_code }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @endforeach

                    {{-- Section Divider: Roles --}}
                    <div style="padding: 10px 14px 2px 14px; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">
                        Authority & Roles
                    </div>

                    @foreach($roles as $r)
                        @php $rCount = $roleUserCounts[$r->id] ?? 0; @endphp
                        <div class="m-item {{ $selectedRoleIdFilter === $r->id ? 'active' : '' }}" wire:click="filterByRole({{ $r->id }})" wire:key="r-scope-{{ $r->id }}">
                            <div class="m-item-left">
                                <i class="fa-solid fa-user-shield m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $r->key_name }}</span>
                                    <span class="m-item-sub">Role ID #{{ $r->id }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">{{ $rCount }}</span>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- COLUMN 2: OFFICES DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span>
                        <i class="fa-solid fa-building" style="color: #64748b;"></i> 
                        2. Offices
                        @if($selectedClusterCode)
                            <span style="font-size: 10px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 1px 6px; border-radius: 4px;">{{ $selectedClusterCode }}</span>
                        @endif
                    </span>
                    <span class="m-item-badge">{{ count($offices) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Search offices..." wire:model.live="officeColSearch">
                </div>

                <div class="m-col-list">
                    {{-- All Offices in Scope item --}}
                    <div class="m-item {{ $selectedOfficeIdFilter === null ? 'active' : '' }}" wire:click="filterByOffice(null)">
                        <div class="m-item-left">
                            <i class="fa-solid fa-layer-group m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">All Offices in Scope</span>
                                <span class="m-item-sub">View all staff in selected scope</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    @forelse($offices as $off)
                        @php
                            $sCount = $officeStaffCounts[$off->id] ?? 0;
                            $isSelectedOff = ($selectedOfficeIdFilter === $off->id);
                        @endphp
                        <div class="m-item draggable-item {{ $isSelectedOff ? 'active' : '' }}" 
                             wire:click="filterByOffice({{ $off->id }})" 
                             wire:key="off-col-{{ $off->id }}"
                             draggable="true"
                             ondragstart="event.dataTransfer.setData('text/office-id', '{{ $off->id }}'); event.dataTransfer.effectAllowed = 'move'; this.classList.add('is-dragging');"
                             ondragend="this.classList.remove('is-dragging');"
                             ondragover="if (event.dataTransfer.types.includes('text/user-id')) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; this.classList.add('drop-target-hover'); }"
                             ondragleave="this.classList.remove('drop-target-hover');"
                             ondrop="event.preventDefault(); this.classList.remove('drop-target-hover'); const uId = event.dataTransfer.getData('text/user-id'); if (uId) { window.usersAssignUserOffice(uId, {{ $off->id }}, this); }"
                             title="Drag onto a cluster in Column 1 to change cluster, or drop a user here to assign office">
                            <div class="m-item-left">
                                <i class="fa-solid fa-grip-vertical" style="color: #cbd5e1; font-size: 11px; margin-right: 2px;"></i>
                                <div class="m-item-avatar" style="border-radius: 6px; width: 26px; height: 26px; font-size: 10px;">
                                    {{ strtoupper(substr($off->office_code ?: '?', 0, 3)) }}
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $off->office_name }}</span>
                                    <span class="m-item-sub">{{ $off->office_code }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">{{ $sCount }}</span>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <span style="font-size: 12px; font-weight: 600;">No offices match</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 3: STAFF USERS DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-id-badge" style="color: #64748b;"></i> 3. Staff Users</span>
                    <span class="m-item-badge">{{ count($users) }} Users</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Search by name, username, or email..." wire:model.live="search">
                </div>

                <div class="m-col-list">
                    @if($selectedUserId === -1)
                        <div class="m-item active" style="background: #0284c7;">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: rgba(255,255,255,0.25); color: #fff;">
                                    <i class="fa-solid fa-user-plus"></i>
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $firstName ? $firstName . ' ' . $lastName : 'New Staff Account' }}</span>
                                    <span class="m-item-sub">[Draft Registration]</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">New</span>
                            </div>
                        </div>
                    @endif

                    @forelse($users as $u)
                        @php
                            $det = $u->details;
                            $uInitials = strtoupper(substr($det?->first_name ?: $u->username, 0, 1) . substr($det?->last_name ?: '', 0, 1));
                            if (strlen($uInitials) === 1) $uInitials = strtoupper(substr($u->username, 0, 2));
                            $isUserActive = ($selectedUserId === $u->id);
                            $userRoleName = $roles->firstWhere('id', $u->account_role)?->key_name ?: 'Basic User';
                            $userOfficeName = $det?->office?->office_code ?: 'Unassigned';
                        @endphp
                        <div class="m-item draggable-item {{ $isUserActive ? 'active' : '' }}" 
                             wire:click="selectUser({{ $u->id }})" 
                             wire:key="user-row-{{ $u->id }}"
                             draggable="true"
                             ondragstart="event.dataTransfer.setData('text/user-id', '{{ $u->id }}'); event.dataTransfer.effectAllowed = 'move'; this.classList.add('is-dragging');"
                             ondragend="this.classList.remove('is-dragging');"
                             title="Drag onto an office in Column 2 to reassign office">
                            <div class="m-item-left">
                                <i class="fa-solid fa-grip-vertical" style="color: #cbd5e1; font-size: 11px; margin-right: 2px;"></i>
                                <div class="m-item-avatar" style="background: {{ $det?->is_currently_online ? '#dcfce7' : '#e0f2fe' }}; color: {{ $det?->is_currently_online ? '#15803d' : '#0369a1' }}; border: 1.5px solid {{ $det?->is_currently_online ? '#86efac' : '#bae6fd' }};">
                                    {{ $uInitials }}
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $det?->first_name ? $det->first_name . ' ' . $det->last_name : $u->username }}</span>
                                    <span class="m-item-sub">{{ '@' . $u->username }} · {{ $userOfficeName }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">{{ $userRoleName }}</span>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <i class="fa-solid fa-user-slash m-empty-icon"></i>
                            <span style="font-size: 12px; font-weight: 600;">No users in this scope</span>
                            <span style="font-size: 11px; margin-top: 4px;">Click <strong>+ New Staff User</strong> to create an account</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 4: USER PROFILE INSPECTOR --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sliders" style="color: #64748b;"></i> 4. Profile Inspector</span>
                    @if($selectedUserId && $selectedUserId > 0)
                        <span class="m-item-badge" style="background: {{ $isActive ? '#dcfce7' : '#fee2e2' }}; color: {{ $isActive ? '#15803d' : '#991b1b' }}; border-color: {{ $isActive ? '#bbf7d0' : '#fecaca' }};">
                            {{ $isActive ? 'Active' : 'Suspended' }}
                        </span>
                    @endif
                </div>

                @if($selectedUserId)
                    <div class="m-inspector">
                        <div class="m-inspector-header">
                            <div class="m-inspector-avatar">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                            <div style="overflow: hidden; flex: 1 1 auto;">
                                <h3 class="m-inspector-title">{{ $firstName ? $firstName . ' ' . $lastName : ($username ?: 'New Staff Account') }}</h3>
                                <span class="m-inspector-sub">{{ $selectedUserId === -1 ? 'New User Registration' : '@' . $username . ' · ID #' . $selectedUserId }}</span>
                            </div>
                        </div>

                        {{-- Email Input --}}
                        <div class="m-field-group">
                            <label class="m-field-label">Institutional Email Address</label>
                            <input type="email" class="m-field-input" placeholder="e.g. staff@cspc.edu.ph" wire:model="email">
                            @error('email') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        {{-- Name Fields --}}
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div class="m-field-group">
                                <label class="m-field-label">First Name</label>
                                <input type="text" class="m-field-input" placeholder="First Name" wire:model="firstName">
                                @error('firstName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                            </div>

                            <div class="m-field-group">
                                <label class="m-field-label">Last Name</label>
                                <input type="text" class="m-field-input" placeholder="Last Name" wire:model="lastName">
                                @error('lastName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Middle Name (Optional)</label>
                            <input type="text" class="m-field-input" placeholder="Middle Name" wire:model="middleName">
                        </div>

                        {{-- Office Assignment Picker --}}
                        <div class="m-field-group" style="position: relative;">
                            <label class="m-field-label">Assigned Office / Station</label>
                            <input type="text" class="m-field-input" placeholder="Search name or abbreviation…" wire:model.live.debounce.150ms="officeSearch" wire:focus="$set('showOfficeDropdown', true)" autocomplete="off">
                            @if($showOfficeDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 50; max-height: 180px; overflow-y: auto; margin-top: 2px;">
                                    <div wire:click="selectOffice(null, 'No Assigned Office')" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #64748b;">
                                        None (Unassigned)
                                    </div>
                                    @forelse($assignableOffices as $o)
                                        <div wire:click="selectOffice({{ $o->id }}, @js($o->office_name))" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #0f172a;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                                            <strong>{{ $o->office_name }}</strong> ({{ $o->office_code }})
                                        </div>
                                    @empty
                                        <div style="padding: 10px; font-size: 12px; color: #64748b;">No office matches “{{ $officeSearch }}”.</div>
                                    @endforelse
                                </div>
                            @endif
                            @error('officeId') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        {{-- Role Assignment Picker --}}
                        <div class="m-field-group" style="position: relative;">
                            <label class="m-field-label">System Role & Clearance Level</label>
                            <input type="text" class="m-field-input" placeholder="Search and assign role…" wire:model.live.debounce.150ms="roleSearch" wire:focus="$set('showRoleDropdown', true)" autocomplete="off">
                            @if($showRoleDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 50; max-height: 180px; overflow-y: auto; margin-top: 2px;">
                                    @forelse($rolePickerOptions as $r)
                                        <div wire:click="selectRole({{ $r->id }}, @js($r->key_name))" style="padding: 7px 10px; font-size: 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; color: #0f172a;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                                            <strong>{{ $r->key_name }}</strong>
                                        </div>
                                    @empty
                                        <div style="padding: 10px; font-size: 12px; color: #64748b;">No role matches “{{ $roleSearch }}”.</div>
                                    @endforelse
                                </div>
                            @endif
                            @error('roleId') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        {{-- Active Status Toggle --}}
                        @if($selectedUserId > 0)
                            <div class="m-metric-card">
                                <span class="m-metric-label">Account Active Status</span>
                                <label class="switch">
                                    <input type="checkbox" wire:model="isActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        {{-- Security info --}}
                        <div style="padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px; color: #64748b; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-brands fa-google" style="color: #ea4335; font-size: 13px;"></i>
                            <span>Google SSO Login Enabled. Staff log in with their institutional email.</span>
                        </div>

                        {{-- Sticky Action Buttons at bottom of Inspector --}}
                        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <button type="button" class="m-btn m-btn-primary" wire:click="saveUserChanges" wire:loading.attr="disabled" style="width: 100%;">
                                <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveUserChanges"></i>
                                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveUserChanges"></i>
                                <span>Save User Account</span>
                            </button>

                            <div style="display: flex; gap: 8px;">
                                @if($selectedUserId > 0 && $selectedUserId !== auth()->id())
                                    <button type="button" class="m-btn m-btn-danger" wire:click="deleteUser" wire:confirm="Are you sure you want to deactivate this account?" style="flex: 1;" title="Soft-deactivate user account">
                                        <i class="fa-solid fa-user-xmark"></i> Deactivate
                                    </button>
                                @endif
                                <button type="button" class="m-btn m-btn-secondary" wire:click="cancelSelection" style="flex: 1;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="m-empty-state">
                        <i class="fa-solid fa-user-gear m-empty-icon"></i>
                        <span style="font-size: 13px; font-weight: 700; color: #475569;">Profile Inspector</span>
                        <span style="font-size: 11.5px; margin-top: 4px; max-width: 220px; line-height: 1.4;">
                            Select a user account or click <strong>+ New Staff User</strong> to inspect profile credentials and role assignments.
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Breadcrumb Path Bar --}}
        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-users" style="color: #64748b;"></i>
                <span>Staff Directory</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span class="miller-path-tag">{{ $selectedClusterCode ?: 'All Clusters' }}</span>
                @if($selectedOfficeIdFilter)
                    <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                    <span>{{ $offices->firstWhere('id', $selectedOfficeIdFilter)?->office_code ?: 'Office' }}</span>
                @endif
                @if($selectedUserId && $selectedUserId > 0)
                    <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">{{ $firstName ? $firstName . ' ' . $lastName : $username }} ({{ '@' . $username }})</span>
                @endif
            </div>

            @if($selectedUserId)
                <button type="button" wire:click="saveUserChanges" style="background: none; border: none; color: #0284c7; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save User
                </button>
            @endif
        </div>

    {{-- ======================================================== --}}
    {{-- MODE 2: EXTERNAL REQUESTORS (DTS) --}}
    {{-- ======================================================== --}}
    @else
        <div class="miller-browser">
            {{-- COLUMN 1: REQUESTOR CATEGORIES --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-filter" style="color: #64748b;"></i> 1. Requestor Filters</span>
                    <span class="m-item-badge">{{ count($requestors) }}</span>
                </div>

                <div class="m-col-list">
                    <div class="m-item {{ $reqCategoryFilter === 'all' ? 'active' : '' }}" wire:click="$set('reqCategoryFilter', 'all')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-globe m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">All Requestors</span>
                                <span class="m-item-sub">Total registered contacts</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    <div class="m-item {{ $reqCategoryFilter === 'active' ? 'active' : '' }}" wire:click="$set('reqCategoryFilter', 'active')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-check m-item-icon" style="font-size: 13px; color: #16a34a; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Active Contacts</span>
                                <span class="m-item-sub">Available in DTS dropdown</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    <div class="m-item {{ $reqCategoryFilter === 'inactive' ? 'active' : '' }}" wire:click="$set('reqCategoryFilter', 'inactive')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-circle-xmark m-item-icon" style="font-size: 13px; color: #ef4444; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Deactivated Contacts</span>
                                <span class="m-item-sub">Soft-deleted contacts</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                </div>
            </div>

            {{-- COLUMN 2: REQUESTORS DIRECTORY --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-address-book" style="color: #64748b;"></i> 2. Requestor Directory</span>
                    <span class="m-item-badge">{{ count($requestors) }}</span>
                </div>

                <div class="m-col-search">
                    <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Search requestor name or agency..." wire:model.live="requestorSearch">
                </div>

                <div class="m-col-list">
                    @if($selectedRequestorId === -1)
                        <div class="m-item active" style="background: #0284c7;">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: rgba(255,255,255,0.25); color: #fff;">
                                    <i class="fa-solid fa-plus"></i>
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $reqName ?: 'New Requestor Contact' }}</span>
                                    <span class="m-item-sub">[Draft Entry]</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <span class="m-item-badge">New</span>
                            </div>
                        </div>
                    @endif

                    @forelse($requestors as $rq)
                        <div class="m-item {{ $selectedRequestorId === $rq->id ? 'active' : '' }}" wire:click="selectRequestorItem({{ $rq->id }})" wire:key="rq-{{ $rq->id }}">
                            <div class="m-item-left">
                                <div class="m-item-avatar" style="background: #f1f5f9; color: #475569;">
                                    {{ strtoupper(substr($rq->requestor_name ?: '?', 0, 2)) }}
                                </div>
                                <div class="m-item-text">
                                    <span class="m-item-title">{{ $rq->requestor_name }}</span>
                                    <span class="m-item-sub">{{ $rq->requestor_position ? $rq->requestor_position . ' · ' : '' }}{{ $rq->office }}</span>
                                </div>
                            </div>
                            <div class="m-item-right">
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div class="m-empty-state">
                            <span style="font-size: 12px; font-weight: 600;">No requestors found</span>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- COLUMN 3: FILING HISTORY IN DTS --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-file-invoice" style="color: #64748b;"></i> 3. Document Filing History</span>
                    @if($selectedRequestorId && $selectedRequestorId > 0)
                        <span class="m-item-badge">{{ count($reqTransactions) }} Filed</span>
                    @endif
                </div>

                <div class="m-col-list" style="padding: 12px 14px;">
                    @if($selectedRequestorId && $selectedRequestorId > 0)
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="m-metric-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                <span class="m-metric-label">Agency / Institutional Affiliation</span>
                                <span class="m-metric-value" style="color: #0284c7;">{{ $reqOffice ?: 'Private Citizen / Unaffiliated' }}</span>
                            </div>

                            <div class="m-metric-card" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                <span class="m-metric-label">Official Position / Title</span>
                                <span class="m-metric-value">{{ $reqPosition ?: 'Designation Not Provided' }}</span>
                            </div>

                            <div>
                                <span style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; display: block; margin: 10px 0 6px 0;">
                                    Recent DTS Transactions Filed
                                </span>

                                @forelse($reqTransactions as $rt)
                                    <div style="padding: 8px 10px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; margin-bottom: 6px; display: flex; flex-direction: column; gap: 2px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <span style="font-size: 11.5px; font-weight: 800; color: #0284c7; font-family: monospace;">{{ $rt->control_number ?? ($rt->id ?? 'DTS-DOC') }}</span>
                                            <span style="font-size: 10px; color: #64748b;">
                                                @if(!empty($rt->date_created))
                                                    {{ \Carbon\Carbon::parse($rt->date_created)->format('M d, Y') }}
                                                @endif
                                            </span>
                                        </div>
                                        <span style="font-size: 11.5px; color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            {{ $rt->subject ?? 'No subject' }}
                                        </span>
                                    </div>
                                @empty
                                    <div class="m-empty-state" style="padding: 16px 0;">
                                        <span style="font-size: 11.5px; color: #94a3b8;">No transactions registered under this contact</span>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="m-empty-state">
                            <i class="fa-solid fa-hand-pointer m-empty-icon"></i>
                            <span style="font-size: 12px; font-weight: 600;">Select a requestor from Column 2</span>
                            <span style="font-size: 11px; margin-top: 4px;">to view documents and transactions filed</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- COLUMN 4: REQUESTOR INSPECTOR --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-sliders" style="color: #64748b;"></i> 4. Requestor Inspector</span>
                    @if($selectedRequestorId && $selectedRequestorId > 0)
                        <span class="m-item-badge" style="background: {{ $reqIsActive ? '#dcfce7' : '#fee2e2' }}; color: {{ $reqIsActive ? '#15803d' : '#991b1b' }}; border-color: {{ $reqIsActive ? '#bbf7d0' : '#fecaca' }};">
                            {{ $reqIsActive ? 'Active' : 'Suspended' }}
                        </span>
                    @endif
                </div>

                @if($selectedRequestorId)
                    <div class="m-inspector">
                        <div class="m-inspector-header">
                            <div class="m-inspector-avatar" style="background: linear-gradient(135deg, #059669 0%, #047857 100%);">
                                <i class="fa-solid fa-address-card"></i>
                            </div>
                            <div style="overflow: hidden; flex: 1 1 auto;">
                                <h3 class="m-inspector-title">{{ $reqName ?: 'Untitled Requestor' }}</h3>
                                <span class="m-inspector-sub">{{ $selectedRequestorId === -1 ? 'New Requestor Contact' : 'Requestor ID #' . $selectedRequestorId }}</span>
                            </div>
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Full Requestor Name</label>
                            <input type="text" class="m-field-input" placeholder="e.g. Atty. Roberto Morales" wire:model="reqName">
                            @error('reqName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Official Position / Title</label>
                            <input type="text" class="m-field-input" placeholder="e.g. Regional Director" wire:model="reqPosition">
                            @error('reqPosition') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        <div class="m-field-group">
                            <label class="m-field-label">Agency / Office Affiliation</label>
                            <input type="text" class="m-field-input" placeholder="e.g. CHED Regional Office V" wire:model="reqOffice">
                            @error('reqOffice') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                        </div>

                        @if($selectedRequestorId > 0)
                            <div class="m-metric-card">
                                <span class="m-metric-label">Active Contact in DTS</span>
                                <label class="switch">
                                    <input type="checkbox" wire:model="reqIsActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                            <button type="button" class="m-btn m-btn-primary" wire:click="saveRequestorChanges" wire:loading.attr="disabled" style="width: 100%;">
                                <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveRequestorChanges"></i>
                                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveRequestorChanges"></i>
                                <span>Save Requestor Details</span>
                            </button>

                            <div style="display: flex; gap: 8px;">
                                @if($selectedRequestorId > 0)
                                    <button type="button" class="m-btn m-btn-danger" wire:click="deleteRequestor" wire:confirm="Are you sure you want to deactivate this requestor?" style="flex: 1;" title="Soft-deactivate requestor">
                                        <i class="fa-solid fa-user-xmark"></i> Deactivate
                                    </button>
                                @endif
                                <button type="button" class="m-btn m-btn-secondary" wire:click="cancelRequestorSelection" style="flex: 1;">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="m-empty-state">
                        <i class="fa-solid fa-address-book m-empty-icon"></i>
                        <span style="font-size: 13px; font-weight: 700; color: #475569;">Requestor Inspector</span>
                        <span style="font-size: 11.5px; margin-top: 4px; max-width: 220px; line-height: 1.4;">
                            Select a requestor or click <strong>+ New Requestor</strong> to configure contact info.
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Breadcrumb Path Bar --}}
        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-address-book" style="color: #64748b;"></i>
                <span>Requestors Directory</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span class="miller-path-tag">{{ $selectedRequestorId ? $reqName . ' (' . $reqOffice . ')' : 'Directory' }}</span>
                @if($selectedRequestorId && $selectedRequestorId > 0)
                    <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                    <span style="color: #0284c7; font-weight: 700;">{{ count($reqTransactions) }} Transactions</span>
                @endif
            </div>

            @if($selectedRequestorId)
                <button type="button" wire:click="saveRequestorChanges" style="background: none; border: none; color: #0284c7; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save Requestor
                </button>
            @endif
        </div>
    @endif
</div>

<script>
    window.usersGetLivewire = function(el) {
        try {
            const root = el ? el.closest('[wire\\:id]') : document.querySelector('.miller-container[wire\\:id]');
            if (root && window.Livewire) {
                return window.Livewire.find(root.getAttribute('wire:id'));
            }
        } catch (e) {
            console.error('usersGetLivewire error:', e);
        }
        return null;
    };

    window.usersAssignUserOffice = function(userId, officeId, el) {
        const lw = window.usersGetLivewire(el);
        if (lw) {
            lw.call('assignUserOffice', parseInt(userId), parseInt(officeId));
        }
    };

    window.usersAssignOfficeCluster = function(officeId, clusterCode, el) {
        const lw = window.usersGetLivewire(el);
        if (lw) {
            lw.call('assignOfficeCluster', parseInt(officeId), clusterCode || null);
        }
    };
</script>
