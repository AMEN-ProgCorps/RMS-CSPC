<?php
/**
 * Admin Console - Accounts Management Roles Volt Component
 * 
 * This component provides an interface for administrators to manage user roles and their associated permissions.
 * Features:
 *  - Real-time search of roles by name or description
 *  - Creation of new custom roles
 *  - Fine-grained permission toggles mapped to condition_details
 *  - Soft-deactivation (deletes) of roles to maintain system transparency
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Roles')] class extends Component {
    use WithPagination;

    /** @var string Holds the active search input query */
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** @var int|null ID of the selected role. (null = placeholder, -1 = create mode, >0 = edit mode) */
    public ?int $selectedRoleId = null;

    // Form fields
    /** @var string Holds the role name */
    public string $keyName = '';

    /** @var string Holds the role description */
    public string $keyDescription = '';

    /** @var bool Active status flag of the role */
    public bool $isActive = true;

    // Permissions check flags (mapped to condition_details table columns)
    public bool $isSadm = false;
    public bool $isAdmin = false;
    public bool $canAccessDts = false;
    public bool $canAccessArchv = false;
    public bool $canAccessDcs = false;
    public bool $canModifyDocflow = false;
    public bool $canModifyAccountlist = false;
    public bool $canModifyPass = false;
    public bool $canModifyUser = false;
    public bool $canViewAllList = false;
    public bool $canViewAllArchive = false;
    public bool $canViewAllCurrentTrans = false;
    public bool $canCreateOwnFlow = false;
    public bool $canDtsUseInternal = false;
    public bool $canDtsUseExternal = false;
    public bool $canDtsUseApplication = false;
    public bool $canDtsUseIssuance = false;
    public bool $canDtsUserReceived = false;
    public bool $canDtsModifyTransaction = false;
    public bool $canDtsModifyControlNo = false;

    // Admin Console Clearance flags
    public bool $canAccessActivityLogs = false;
    public bool $canAccessSubsystems = false;
    public bool $canAccessDtsAdmin = false;
    public bool $canAccessRdpAdmin = false;
    public bool $canAccessSettings = false;
    public bool $canAccessRecycleBin = false;

    // RDP Specific Clearance flags
    public bool $rdpViewAllFiles = false;
    public bool $canRdpModifySeries = true;
    public bool $canRdpGenerateReports = true;

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    // Super Admin Verification Modal
    public bool $showVerificationModal = false;
    public string $verifyUsername = '';
    public string $verifyPassword = '';
    public string $verificationError = '';

    /**
     * Component mount hook - initializes the default view.
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_sadm_modify_accountlist)) {
            $this->redirect(route('portal'));
            return;
        }
        $this->cancelSelection();
    }

    /**
     * Clears all session alert banners.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Resets form fields and returns to the selection placeholder screen.
     */
    public function cancelSelection(): void
    {
        $this->selectedRoleId = null;
        $this->keyName = '';
        $this->keyDescription = '';
        $this->isActive = true;
        
        // Reset all permission flags
        $this->isSadm = false;
        $this->isAdmin = false;
        $this->canAccessDts = false;
        $this->canAccessArchv = false;
        $this->canAccessDcs = false;
        $this->canModifyDocflow = false;
        $this->canModifyAccountlist = false;
        $this->canModifyPass = false;
        $this->canModifyUser = false;
        $this->canViewAllList = false;
        $this->canViewAllArchive = false;
        $this->canViewAllCurrentTrans = false;
        $this->canCreateOwnFlow = false;
        $this->canDtsUseInternal = false;
        $this->canDtsUseExternal = false;
        $this->canDtsUseApplication = false;
        $this->canDtsUseIssuance = false;
        $this->canDtsUserReceived = false;
        $this->canDtsModifyTransaction = false;
        $this->rdpViewAllFiles = false;
        $this->canRdpModifySeries = true;
        $this->canRdpGenerateReports = true;
        
        $this->showVerificationModal = false;
        $this->verifyUsername = '';
        $this->verifyPassword = '';
        $this->verificationError = '';
        
        $this->clearMessages();
    }

    /**
     * Initializes "Create Mode" for configuring a new role.
     */
    public function startCreate(): void
    {
        $this->cancelSelection();
        $this->selectedRoleId = -1; // -1 denotes "Create Mode"
    }

    /**
     * Selects a role from the directory to load details and permission flags.
     * 
     * @param int $id The ID of the role record (condition_key.id)
     */
    public function selectRole(int $id): void
    {
        $this->cancelSelection();
        $this->selectedRoleId = $id;

        $role = \App\Models\role_list::with('permissions')->find($id);
        if ($role) {
            $this->keyName = $role->key_name;
            $this->keyDescription = $role->key_description ?? '';
            $this->isActive = (bool) $role->is_active;

            $perms = $role->permissions;
            if ($perms) {
                $this->isSadm = (bool) $perms->is_sadm;
                $this->isAdmin = (bool) $perms->is_admin;
                $this->canAccessDts = (bool) $perms->can_access_dts;
                $this->canAccessArchv = (bool) $perms->can_access_rdp;
                $this->canAccessDcs = (bool) $perms->can_access_dcs;
                $this->canModifyDocflow = (bool) $perms->can_dts_modify_docflow;
                $this->canModifyAccountlist = (bool) $perms->can_sadm_modify_accountlist;
                $this->canModifyPass = (bool) $perms->can_sadm_modify_pass;
                $this->canModifyUser = (bool) $perms->can_sadm_modify_account;
                $this->canViewAllList = (bool) $perms->can_dts_view_all_list;
                $this->canViewAllArchive = (bool) $perms->can_dts_view_all_archive;
                $this->canViewAllCurrentTrans = (bool) $perms->can_dts_view_all_current_trans;
                $this->canCreateOwnFlow = (bool) $perms->can_dts_create_own_flow;
                $this->canDtsUseInternal = (bool) $perms->can_dts_use_internal;
                $this->canDtsUseExternal = (bool) $perms->can_dts_use_external;
                $this->canDtsUseApplication = (bool) $perms->can_dts_use_application;
                $this->canDtsUseIssuance = (bool) $perms->can_dts_use_issuance;
                $this->canDtsUserReceived = (bool) $perms->can_dts_user_received;
                $this->canDtsModifyTransaction = (bool) $perms->can_dts_modify_transaction;
                $this->canDtsModifyControlNo = (bool) ($perms->can_dts_modify_control_no ?? false);

                // Admin Console Clearance flags
                $this->canAccessActivityLogs = (bool) ($perms->can_access_activity_logs ?? false);
                $this->canAccessSubsystems = (bool) ($perms->can_access_subsystems ?? false);
                $this->canAccessDtsAdmin = (bool) ($perms->can_access_dts_admin ?? false);
                $this->canAccessRdpAdmin = (bool) ($perms->can_access_rdp_admin ?? false);
                $this->canAccessSettings = (bool) ($perms->can_access_settings ?? false);
                $this->canAccessRecycleBin = (bool) ($perms->can_access_recycle_bin ?? false);

                // RDP Clearances
                $this->rdpViewAllFiles = (bool) ($perms->rdp_view_all_files ?? false);
                $this->canRdpModifySeries = (bool) ($perms->can_rdp_modify_series ?? true);
                $this->canRdpGenerateReports = (bool) ($perms->can_rdp_generate_reports ?? true);
            }
        }
    }

    /**
     * Creates a new role or updates an existing role in the database.
     * Wraps modifications in a Transaction to ensure details and permissions map correctly.
     */
    public function saveRoleChanges(): void
    {
        if ($this->selectedRoleId === null) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_accountlist clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_accountlist)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify subsystem roles.';
            return;
        }

        // Validation rules: unique check excludes selected record if updating
        $uniqueRule = $this->selectedRoleId > 0 
            ? 'unique:condition_key,key_name,' . $this->selectedRoleId . ',id'
            : 'unique:condition_key,key_name';

        $this->validate([
            'keyName' => 'required|string|max:255|' . $uniqueRule,
            'keyDescription' => 'nullable|string',
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedRoleId === -1) {
                    // --- CREATE MODE ---
                    // 1. Create permissions row in condition_details
                    $perms = new \App\Models\role_permission();
                    $this->setPermissionModelAttributes($perms);
                    $perms->save();

                    // 2. Create role row in condition_key pointing to details key_id
                    $role = new \App\Models\role_list();
                    $role->key_name = $this->keyName;
                    $role->key_description = $this->keyDescription;
                    $role->modifier_key = $perms->key_id;
                    $role->is_active = true; // Always active on initial creation
                    $role->save();
                    
                    // Audit Log: created role
                    \DB::table('admin_logs')->insert([
                        'changes' => "Created role: {$this->keyName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);
                    
                    // Update state to newly created ID
                    $this->selectedRoleId = $role->id;
                } else {
                    // --- EDIT MODE ---
                    // 1. Update role definition
                    $role = \App\Models\role_list::findOrFail($this->selectedRoleId);
                    
                    // Detect if status changed
                    $statusChanged = $role->is_active != $this->isActive;

                    $role->update([
                        'key_name' => $this->keyName,
                        'key_description' => $this->keyDescription,
                        'is_active' => $this->isActive,
                        'date_updated' => now(),
                    ]);

                    // 2. Update permission flags
                    $perms = \App\Models\role_permission::findOrFail($role->modifier_key);
                    $this->setPermissionModelAttributes($perms);
                    $perms->save();

                    // Audit Log: updated details
                    \DB::table('admin_logs')->insert([
                        'changes' => "Updated permissions/details for role: {$this->keyName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Audit Log: status changed
                    if ($statusChanged) {
                        \DB::table('admin_logs')->insert([
                            'changes' => "Toggled active status (Value: " . ($this->isActive ? '1' : '0') . ") for role: {$this->keyName}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3, // Admin Console
                            'when_changes' => now()
                        ]);
                    }
                }
            });

            $currentUserRole = auth()->user() ? auth()->user()->account_role : null;
            if ($currentUserRole && $currentUserRole === $this->selectedRoleId) {
                session()->flash('success', 'Role configuration updated successfully!');
                $this->js('window.location.reload();');
            } else {
                $this->successMessage = 'Role configuration updated successfully!';
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save changes: ' . $e->getMessage();
        }
    }

    /**
     * Soft-deactivates (soft-deletes) the selected role for transparency.
     */
    public function deleteRole(): void
    {
        if ($this->selectedRoleId === null || $this->selectedRoleId === -1) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_accountlist clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_accountlist)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to delete subsystem roles.';
            return;
        }

        try {
            \DB::transaction(function () {
                $role = \App\Models\role_list::findOrFail($this->selectedRoleId);
                
                $role->update([
                    'is_active' => false,
                    'date_updated' => now(),
                ]);

                // Audit Log: soft-deleted role
                \DB::table('admin_logs')->insert([
                    'changes' => "Soft-deleted role (Deactivated for transparency): {$role->key_name}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now()
                ]);

                $roleId = $this->selectedRoleId;
                // Reset selection to close edit details pane but set success message
                $this->cancelSelection();
                
                $currentUserRole = auth()->user() ? auth()->user()->account_role : null;
                if ($currentUserRole && $currentUserRole === $roleId) {
                    session()->flash('success', 'Role soft-deleted successfully!');
                    $this->js('window.location.reload();');
                } else {
                    $this->successMessage = 'Role soft-deleted successfully!';
                }
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete role: ' . $e->getMessage();
        }
    }

    /**
     * Map checkbox property states directly to Eloquent permission model.
     * 
     * @param \App\Models\role_permission $perms The model instance to configure
     */
    private function setPermissionModelAttributes(\App\Models\role_permission $perms): void
    {
        $perms->is_sadm = $this->isSadm;
        $perms->is_admin = $this->isAdmin;
        $perms->can_access_dts = $this->canAccessDts;
        $perms->can_access_rdp = $this->canAccessArchv;
        $perms->can_access_dcs = $this->canAccessDcs;
        $perms->can_dts_modify_docflow = $this->canModifyDocflow;
        $perms->can_sadm_modify_accountlist = $this->canModifyAccountlist;
        $perms->can_sadm_modify_pass = $this->canModifyPass;
        $perms->can_sadm_modify_account = $this->canModifyUser;
        $perms->can_dts_view_all_list = $this->canViewAllList;
        $perms->can_dts_view_all_archive = $this->canViewAllArchive;
        $perms->can_dts_view_all_current_trans = $this->canViewAllCurrentTrans;
        $perms->can_dts_create_own_flow = $this->canCreateOwnFlow;
        $perms->can_dts_use_internal = $this->canDtsUseInternal;
        $perms->can_dts_use_external = $this->canDtsUseExternal;
        $perms->can_dts_use_application = $this->canDtsUseApplication;
        $perms->can_dts_use_issuance = $this->canDtsUseIssuance;
        $perms->can_dts_user_received = $this->canDtsUserReceived;
        $perms->can_dts_modify_transaction = $this->canDtsModifyTransaction;
        $perms->can_dts_modify_control_no = $this->canDtsModifyControlNo;

        // Admin Console Clearance flags
        $perms->can_access_activity_logs = $this->canAccessActivityLogs;
        $perms->can_access_subsystems = $this->canAccessSubsystems;
        $perms->can_access_dts_admin = $this->canAccessDtsAdmin;
        $perms->can_access_rdp_admin = $this->canAccessRdpAdmin;
        $perms->can_access_settings = $this->canAccessSettings;
        $perms->can_access_recycle_bin = $this->canAccessRecycleBin;

        // RDP Clearances (View All Office Files is strictly for admin roles)
        $perms->rdp_view_all_files = ($this->isSadm || $this->isAdmin) ? $this->rdpViewAllFiles : false;
        $perms->can_rdp_modify_series = $this->canRdpModifySeries;
        $perms->can_rdp_generate_reports = $this->canRdpGenerateReports;
    }

    /**
     * Hook to reset dependent permissions if is_admin is toggled off.
     */
    public function updatedIsAdmin($value): void
    {
        if (! $value) {
            $this->isSadm = false;
            $this->canViewAllList = false;
            $this->canViewAllArchive = false;
            $this->canViewAllCurrentTrans = false;
            $this->canModifyUser = false;
            $this->canModifyAccountlist = false;
            $this->canModifyPass = false;
            $this->canDtsModifyTransaction = false;
            $this->rdpViewAllFiles = false;
        }
    }

    /**
     * Hook to intercept Super Admin toggle and request credentials if needed.
     */
    public function updatedIsSadm($value): void
    {
        if ($value) {
            $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
            if ($currentUserPerms && $currentUserPerms->is_sadm) {
                // Current user is already Super Admin, allow immediately
                $this->isSadm = true;
                $this->enableAllPermissions();
            } else {
                // Intercept and require credentials
                $this->isSadm = false;
                $this->showVerificationModal = true;
                $this->verifyUsername = '';
                $this->verifyPassword = '';
                $this->verificationError = '';
            }
        }
    }

    /**
     * Submit Super Admin verification credentials.
     */
    public function submitVerification(): void
    {
        $this->verificationError = '';

        if (empty($this->verifyUsername) || empty($this->verifyPassword)) {
            $this->verificationError = 'Please enter both username and password.';
            return;
        }

        $superAdmin = \App\Models\User::where('username', $this->verifyUsername)->first();
        if ($superAdmin && $superAdmin->permissions && $superAdmin->permissions->is_sadm && \Hash::check($this->verifyPassword, $superAdmin->password)) {
            $this->isSadm = true;
            $this->enableAllPermissions();
            $this->showVerificationModal = false;
            $this->verifyUsername = '';
            $this->verifyPassword = '';
        } else {
            $this->verificationError = 'Invalid Super Admin credentials.';
        }
    }

    /**
     * Cancel Super Admin verification modal.
     */
    public function cancelVerification(): void
    {
        $this->showVerificationModal = false;
        $this->isSadm = false;
    }

    /**
     * Enable all permissions/clearances when Super Admin is turned on.
     */
    private function enableAllPermissions(): void
    {
        $this->isAdmin = true;
        $this->canAccessDts = true;
        $this->canAccessArchv = true;
        $this->canAccessDcs = true;
        $this->canModifyDocflow = true;
        $this->canModifyAccountlist = true;
        $this->canModifyPass = true;
        $this->canModifyUser = true;
        $this->canViewAllList = true;
        $this->canViewAllArchive = true;
        $this->canViewAllCurrentTrans = true;
        $this->canCreateOwnFlow = true;
        $this->canDtsUseInternal = true;
        $this->canDtsUseExternal = true;
        $this->canDtsUseApplication = true;
        $this->canDtsUseIssuance = true;
        $this->canDtsUserReceived = true;
        $this->canDtsModifyTransaction = true;
    }

    /**
     * Hook called whenever any model property is updated.
     * If a permission toggle is turned off while isSadm is true, isSadm turns off and isAdmin remains true.
     */
    public function updated($name, $value): void
    {
        $permissionProperties = [
            'isAdmin',
            'canAccessDts',
            'canAccessArchv',
            'canAccessDcs',
            'canModifyDocflow',
            'canModifyAccountlist',
            'canModifyPass',
            'canModifyUser',
            'canViewAllList',
            'canViewAllArchive',
            'canViewAllCurrentTrans',
            'canCreateOwnFlow',
            'canDtsUseInternal',
            'canDtsUseExternal',
            'canDtsUseApplication',
            'canDtsUseIssuance',
            'canDtsUserReceived',
            'canDtsModifyTransaction',
        ];

        if (in_array($name, $permissionProperties) && !$value && $this->isSadm) {
            $this->isSadm = false;
            $this->isAdmin = true;
        }
    }

    /**
     * Computed Lifecycle Provider - returns list of roles with details.
     * 
     * @return array Contains roles collection
     */
    public function with(): array
    {
        // Only return active roles in the directory to match deletion expectations
        $query = \App\Models\role_list::query()->where('is_active', true);

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function($q) use ($searchVal) {
                $q->where('key_name', 'like', $searchVal)
                  ->orWhere('key_description', 'like', $searchVal);
            });
        }

        $roles = $query->orderBy('key_name', 'asc')->paginate(20);

        return [
            'roles' => $roles,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/accounts_roles.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="admin-roles-container {{ !$selectedRoleId ? 'no-selection' : 'has-selection' }}">
    <!-- Left Pane: Roles Directory -->
    <div class="directory-panel">
        <div class="directory-header-row">
            <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Roles Directory</span>
            <button type="button" class="btn-create-new" wire:click="startCreate">
                <i class="fa-solid fa-plus"></i> New Role
            </button>
        </div>

        <div class="search-box-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" class="search-box" placeholder="Search roles..." wire:model.live="search">
        </div>
        
        <div class="roles-list">
            @forelse($roles as $role)
                @php
                    $roleInitials = strtoupper(substr($role->key_name ?: '?', 0, 2));
                @endphp
                <div class="role-item-card {{ $selectedRoleId === $role->id ? 'active' : '' }}" wire:key="role-{{ $role->id }}" wire:click="selectRole({{ $role->id }})">
                    <div class="role-avatar-small">
                        <span>{{ $roleInitials }}</span>
                    </div>
                    <div class="role-meta-info">
                        <span class="role-display-name">{{ $role->key_name }}</span>
                        <span class="role-display-desc">{{ $role->key_description ?: 'No description provided.' }}</span>
                        @if($role->is_active)
                            <span class="role-status-badge active-badge">Active</span>
                        @else
                            <span class="role-status-badge inactive-badge">Suspended</span>
                        @endif
                    </div>
                </div>
            @empty
                <div style="text-align: center; color: #94a3b8; padding: 20px; font-family: 'Inter', sans-serif; font-size: 13.5px;">
                    No roles configured.
                </div>
            @endforelse
        </div>

        @if($roles->hasPages())
            <div class="roles-pagination-bar" style="padding-top: 10px; border-top: 1px solid #f1f5f9; display: flex; justify-content: center;">
                {{ $roles->links('components.pagination') }}
            </div>
        @endif
    </div>

    <!-- Right Pane: Role Form Configurator -->
    @if($selectedRoleId)
        <div class="details-panel">
            <!-- Header -->
            <div class="details-header">
                <div class="details-header-avatar">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div class="details-header-info">
                    <h2 class="details-header-name">
                        {{ $selectedRoleId === -1 ? 'Configure New Custom Role' : $keyName }}
                    </h2>
                    <span class="details-header-sub">
                        {{ $selectedRoleId === -1 ? 'Configure access clearance permissions from scratch' : 'Review & adjust active clearance clearances' }}
                    </span>
                </div>
                <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close details panel" style="background: transparent; border: none; color: #94a3b8; font-size: 18px; cursor: pointer; padding: 6px 10px; border-radius: 8px; margin-left: auto;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Body Form -->
            <div class="details-body">
                @if($successMessage || session('success'))
                    <div class="toast-alert success">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $successMessage ?: session('success') }}</span>
                    </div>
                @endif

                @if($errorMessage || session('error'))
                    <div class="toast-alert error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>{{ $errorMessage ?: session('error') }}</span>
                    </div>
                @endif

                <form wire:submit.prevent="saveRoleChanges" style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Role Name -->
                    <div class="form-group">
                        <span class="form-label">Role Title / Designation</span>
                        <input type="text" class="form-input" placeholder="e.g. Records Liaison Officer" wire:model="keyName">
                        @error('keyName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Role Description -->
                    <div class="form-group">
                        <span class="form-label">Description / Scope of Authority</span>
                        <input type="text" class="form-input" placeholder="e.g. Handles document tracking within college division" wire:model="keyDescription">
                        @error('keyDescription') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Active Toggle Wrapper (Only shown for edit mode, not create mode) -->
                    @if($selectedRoleId > 0)
                        <div class="form-group">
                            <div class="status-toggle-wrapper">
                                <div class="status-toggle-label">
                                    <span class="status-toggle-title">Clearance Active Status</span>
                                    <span class="status-toggle-desc">Toggle whether this role is active or soft-deactivated for transparency.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="isActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    @endif

                    <!-- Permissions Clearance Matrix -->
                    <div class="form-group" style="margin-top: 8px;">
                        <span class="form-label" style="display: block; margin-bottom: 12px;">Access Permissions Matrix</span>
                        
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <!-- Category 1: Platform/Subsystem Access -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-desktop"></i> System Access Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- Super Admin -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Super Administrator</span>
                                            <span class="permission-toggle-desc">Unrestricted platform-wide control.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="isSadm">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Normal Admin -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Administrator Access</span>
                                            <span class="permission-toggle-desc">Access Admin Console with restricted permissions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="isAdmin">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- DTS -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DTS</span>
                                            <span class="permission-toggle-desc">Clearance to use Document Tracking System.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDts">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Archives -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Archives</span>
                                            <span class="permission-toggle-desc">Clearance to view/query records disposal archives.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessArchv">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- DCS -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DCS Subsystem</span>
                                            <span class="permission-toggle-desc">Clearance to utilize DCS functionality.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDcs">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Category 2: Document Flow Modifiers -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-file-signature"></i> Document Flow Modifiers</span>
                                <div class="permissions-grid-layout">
                                    <!-- Modify Docflow -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Doc Flow</span>
                                            <span class="permission-toggle-desc">Clearance to override document track routes.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyDocflow">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all list -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Lists</span>
                                            <span class="permission-toggle-desc">View documents registered across all offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllList" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all archive -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Archives</span>
                                            <span class="permission-toggle-desc">Query and search historical files.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllArchive" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all current trans -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Current Transactions</span>
                                            <span class="permission-toggle-desc">View all active transactions from all offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllCurrentTrans" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Create own transaction flow -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Create Own Transaction Flow</span>
                                            <span class="permission-toggle-desc">Define and construct custom transaction flows.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canCreateOwnFlow">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Use Internal Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Use Internal Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to create, list, and filter Internal Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsUseInternal">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Use External Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Use External Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to create, list, and filter External Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsUseExternal">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Use Application Letter Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Use Application Letter Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to create, list, and filter Application Letter Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsUseApplication">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Use Issuance Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Use Issuance Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to create, list, and filter Issuance Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsUseIssuance">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Receive Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Receive & Forward Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to receive, forward, update, or complete transactions. If disabled, actions will be read-only.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsUserReceived">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Transactions -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Transactions Details</span>
                                            <span class="permission-toggle-desc">Clearance to modify metadata details (Control #, Subject/Particulars, CF, etc.) on active transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsModifyTransaction" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Control Number on Create -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Control Number</span>
                                            <span class="permission-toggle-desc">Allows user to see and manually edit the control number on the create transaction form. When disabled, control numbers are auto-generated.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsModifyControlNo">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Category: RDP Clearances -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-folder-tree"></i> Records Disposition Program (RDP) Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- RDP Subsystem Clearance -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Portal</span>
                                            <span class="permission-toggle-desc">General clearance to access the Records Disposition Program subsystem.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessArchv">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View All Office Files -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Office Files</span>
                                            <span class="permission-toggle-desc">Clearance to search and inspect repository document files across all college offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="rdpViewAllFiles" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Record Series & Appraisal -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Record Series & Appraisal</span>
                                            <span class="permission-toggle-desc">Clearance to add, edit, or update record series, inventory appraisal schedules, and retention values.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpModifySeries">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Generate & Print NAP Reports -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Generate & Print NAP Reports</span>
                                            <span class="permission-toggle-desc">Clearance to configure metadata, signature blocks, and print official NAP Form 1, Form 2, and Form 3 documents.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpGenerateReports">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Category 3: Security & Accounts Control -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-user-lock"></i> Administration Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- Modify Users -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Accounts Directory</span>
                                            <span class="permission-toggle-desc">Clearance to alter personal user data.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyUser" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Role lists -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Subsystem Roles</span>
                                            <span class="permission-toggle-desc">Clearance to manage roles and access matrices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyAccountlist" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Passwords -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Passwords</span>
                                            <span class="permission-toggle-desc">Override and reset user passwords.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyPass" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View Activity Logs -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Activity Logs</span>
                                            <span class="permission-toggle-desc">Clearance to view the login, transaction, and update log history.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessActivityLogs" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Subsystems -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Manage Subsystems</span>
                                            <span class="permission-toggle-desc">Clearance to add, activate, or deactivate portal subsystems.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessSubsystems" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access DTS Admin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DTS Admin Log</span>
                                            <span class="permission-toggle-desc">Clearance to view transaction log history and predefined flow patterns.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDtsAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access RDP Admin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Admin Log</span>
                                            <span class="permission-toggle-desc">Clearance to view retention schedules and records logs.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessRdpAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access System Settings -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Manage System Settings</span>
                                            <span class="permission-toggle-desc">Clearance to adjust portal variables and configurations.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessSettings" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Recycle Bin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Recycle Bin</span>
                                            <span class="permission-toggle-desc">Clearance to view and restore deactivated portal items.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessRecycleBin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Footer Actions -->
            <div class="details-footer">
                @if($selectedRoleId > 0)
                    <button type="button" class="btn-delete" wire:click="deleteRole" style="margin-right: auto;">
                        <i class="fa-solid fa-trash-can"></i> Delete Role
                    </button>
                @endif
                <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                <button type="button" class="btn-save" wire:click="saveRoleChanges">
                    <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                </button>
            </div>
        </div>
    @endif
        @if($showVerificationModal)
        <!-- Modal Backdrop -->
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; padding: 28px; width: 420px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); display: flex; flex-direction: column; gap: 20px; border: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; color: #1d4ed8;">
                        <i class="fa-solid fa-shield-halved" style="font-size: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; font-family: 'Inter', sans-serif;">Super Admin Verification</h3>
                        <p style="font-size: 12px; color: #64748b; margin: 0; margin-top: 2px; font-family: 'Inter', sans-serif;">Authorization required to toggle Super Admin clearance.</p>
                    </div>
                </div>
                
                @if($verificationError)
                    <div style="background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; padding: 10px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 500; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>{{ $verificationError }}</span>
                    </div>
                @endif

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-family: 'Inter', sans-serif;">Super Admin Username</label>
                        <input type="text" placeholder="Enter username..." wire:model="verifyUsername" style="height: 38px; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 0 12px; font-size: 13.5px; outline: none; font-family: 'Inter', sans-serif; width: 100%; box-sizing: border-box;" required>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-family: 'Inter', sans-serif;">Super Admin Password</label>
                        <input type="password" placeholder="Enter password..." wire:model="verifyPassword" style="height: 38px; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 0 12px; font-size: 13.5px; outline: none; font-family: 'Inter', sans-serif; width: 100%; box-sizing: border-box;" required>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                    <button type="button" wire:click="cancelVerification" style="background: #f1f5f9; color: #475569; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#e2e8f0'" onmouseout="this.style.backgroundColor='#f1f5f9'">Cancel</button>
                    <button type="button" wire:click="submitVerification" style="background: #003699; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#002d80'" onmouseout="this.style.backgroundColor='#003699'">Verify & Enable</button>
                </div>
            </div>
        </div>
    @endif
</div>
</div>
