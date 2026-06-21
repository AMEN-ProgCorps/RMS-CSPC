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

new #[Layout('layouts.admin')] #[Title('Admin Console - Roles')] class extends Component {
    /** @var string Holds the active search input query */
    public string $search = '';

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
    public bool $canAccessDts = false;
    public bool $canAccessArchv = false;
    public bool $canAccessDcs = false;
    public bool $canModifyDocflow = false;
    public bool $canModifyAccountlist = false;
    public bool $canModifyPass = false;
    public bool $canModifyUser = false;
    public bool $canViewAllList = false;
    public bool $canViewAllArchive = false;

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    /**
     * Component mount hook - initializes the default view.
     */
    public function mount(): void
    {
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
        $this->canAccessDts = false;
        $this->canAccessArchv = false;
        $this->canAccessDcs = false;
        $this->canModifyDocflow = false;
        $this->canModifyAccountlist = false;
        $this->canModifyPass = false;
        $this->canModifyUser = false;
        $this->canViewAllList = false;
        $this->canViewAllArchive = false;
        
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
                $this->canAccessDts = (bool) $perms->can_access_dts;
                $this->canAccessArchv = (bool) $perms->can_access_archv;
                $this->canAccessDcs = (bool) $perms->can_access_dcs;
                $this->canModifyDocflow = (bool) $perms->can_modify_docflow;
                $this->canModifyAccountlist = (bool) $perms->can_modify_accountlist;
                $this->canModifyPass = (bool) $perms->can_modify_pass;
                $this->canModifyUser = (bool) $perms->can_modify_user;
                $this->canViewAllList = (bool) $perms->can_view_all_list;
                $this->canViewAllArchive = (bool) $perms->can_view_all_archive;
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

            $this->successMessage = 'Role configuration updated successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save changes: ' . $e->getMessage();
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
        $perms->can_access_dts = $this->canAccessDts;
        $perms->can_access_archv = $this->canAccessArchv;
        $perms->can_access_dcs = $this->canAccessDcs;
        $perms->can_modify_docflow = $this->canModifyDocflow;
        $perms->can_modify_accountlist = $this->canModifyAccountlist;
        $perms->can_modify_pass = $this->canModifyPass;
        $perms->can_modify_user = $this->canModifyUser;
        $perms->can_view_all_list = $this->canViewAllList;
        $perms->can_view_all_archive = $this->canViewAllArchive;
    }

    /**
     * Computed Lifecycle Provider - returns list of roles with details.
     * 
     * @return array Contains roles collection
     */
    public function with(): array
    {
        $query = \App\Models\role_list::query();

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where('key_name', 'like', $searchVal)
                  ->orWhere('key_description', 'like', $searchVal);
        }

        // List active roles first, then inactive ones, sorted alphabetically
        $roles = $query->orderBy('is_active', 'desc')
                       ->orderBy('key_name', 'asc')
                       ->get();

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

<div class="admin-roles-container">
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
    </div>

    <!-- Right Pane: Role Form Configurator -->
    <div class="details-panel">
        @if($selectedRoleId)
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
            </div>

            <!-- Body Form -->
            <div class="details-body">
                @if($successMessage)
                    <div class="toast-alert success">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $successMessage }}</span>
                    </div>
                @endif

                @if($errorMessage)
                    <div class="toast-alert error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>{{ $errorMessage }}</span>
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
                                            <input type="checkbox" wire:model="isSadm">
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
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Lists</span>
                                            <span class="permission-toggle-desc">View documents registered across all offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllList">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all archive -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Archives</span>
                                            <span class="permission-toggle-desc">Query and search historical files.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllArchive">
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
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Accounts Directory</span>
                                            <span class="permission-toggle-desc">Clearance to alter personal user data.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyUser">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Role lists -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Subsystem Roles</span>
                                            <span class="permission-toggle-desc">Clearance to manage roles and access matrices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyAccountlist">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Passwords -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Passwords</span>
                                            <span class="permission-toggle-desc">Override and reset user passwords.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyPass">
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
                <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                <button type="button" class="btn-save" wire:click="saveRoleChanges">
                    <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                </button>
            </div>
        @else
            <!-- Selection Placeholder -->
            <div class="details-placeholder">
                <i class="fa-solid fa-user-shield"></i>
                <h3>Clearance & Roles Configuration</h3>
                <p>Click on any role in the directory list to edit its access matrix. Click the <strong>New Role</strong> button above to construct a new role from scratch.</p>
            </div>
        @endif
    </div>
</div>
