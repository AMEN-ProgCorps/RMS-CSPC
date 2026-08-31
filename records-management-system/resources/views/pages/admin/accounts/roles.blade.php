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

    /** @var string Holds column key to sort roles by */
    public string $sortBy = 'name';

    /** @var string Sorting direction ('asc' or 'desc') */
    public string $sortDir = 'asc';

    /** @var string View Mode ('table' or 'grid') */
    public string $viewMode = 'table';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSortBy(): void
    {
        $this->resetPage();
    }

    public function updatingSortDir(): void
    {
        $this->resetPage();
    }

    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
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
    public bool $dcsViewAllDocuments = false;
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
    public bool $canAccessDcsAdmin = false;
    public bool $canAccessSettings = false;
    public bool $canAccessRecycleBin = false;

    // RDP Specific Clearance flags
    public bool $rdpViewAllFiles = false;
    public bool $isRdpViewAllPendingList = false;
    public bool $canRdpModifySeries = true;
    public bool $canRdpGenerateReports = true;

    // RDP Per-Form Clearances
    public bool $canRdpAccessForm1 = true;
    public bool $canRdpAccessForm2 = true;
    public bool $canRdpAccessForm3 = true;
    public bool $canRdpModifyForm1 = true;
    public bool $canRdpModifyForm2 = true;
    public bool $canRdpModifyForm3 = true;
    public bool $canRdpPrintForm1 = true;
    public bool $canRdpPrintForm2 = true;
    public bool $canRdpPrintForm3 = true;
    // Admin-only (default false so only granted roles can access other offices' data)
    public bool $canRdpViewOthersForm1 = false;
    public bool $canRdpViewOthersForm2 = false;
    public bool $canRdpViewOthersForm3 = false;
    public bool $canRdpEditOthersForm1 = false;
    public bool $canRdpEditOthersForm2 = false;
    public bool $canRdpEditOthersForm3 = false;
    public bool $canRdpPrintOthersForm1 = false;
    public bool $canRdpPrintOthersForm2 = false;
    public bool $canRdpPrintOthersForm3 = false;

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
        $this->dcsViewAllDocuments = false;
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
        $this->canAccessDcsAdmin = false;
        $this->rdpViewAllFiles = false;
        $this->canRdpModifySeries = true;
        $this->canRdpGenerateReports = true;
        // Per-form clearances
        $this->canRdpAccessForm1 = true;   $this->canRdpAccessForm2 = true;   $this->canRdpAccessForm3 = true;
        $this->canRdpModifyForm1 = true;   $this->canRdpModifyForm2 = true;   $this->canRdpModifyForm3 = true;
        $this->canRdpPrintForm1  = true;   $this->canRdpPrintForm2  = true;   $this->canRdpPrintForm3  = true;
        $this->canRdpViewOthersForm1 = false; $this->canRdpViewOthersForm2 = false; $this->canRdpViewOthersForm3 = false;
        $this->canRdpEditOthersForm1 = false; $this->canRdpEditOthersForm2 = false; $this->canRdpEditOthersForm3 = false;
        $this->canRdpPrintOthersForm1= false; $this->canRdpPrintOthersForm2= false; $this->canRdpPrintOthersForm3= false;
        
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
                $this->dcsViewAllDocuments = (bool) ($perms->dcs_view_all_documents ?? false);
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
                $this->canAccessDcsAdmin = (bool) ($perms->can_access_dcs_admin ?? false);
                $this->canAccessSettings = (bool) ($perms->can_access_settings ?? false);
                $this->canAccessRecycleBin = (bool) ($perms->can_access_recycle_bin ?? false);

                // RDP Clearances
                $this->rdpViewAllFiles = (bool) ($perms->rdp_view_all_files ?? false);
                $this->isRdpViewAllPendingList = (bool) ($perms->is_rdp_view_all_pending_list ?? false);
                $this->canRdpModifySeries = (bool) ($perms->can_rdp_modify_series ?? true);
                $this->canRdpGenerateReports = (bool) ($perms->can_rdp_generate_reports ?? true);
                // Per-form clearances
                $this->canRdpAccessForm1        = (bool) ($perms->can_rdp_access_form_1 ?? true);
                $this->canRdpAccessForm2        = (bool) ($perms->can_rdp_access_form_2 ?? true);
                $this->canRdpAccessForm3        = (bool) ($perms->can_rdp_access_form_3 ?? true);
                $this->canRdpModifyForm1        = (bool) ($perms->can_rdp_modify_form_1 ?? true);
                $this->canRdpModifyForm2        = (bool) ($perms->can_rdp_modify_form_2 ?? true);
                $this->canRdpModifyForm3        = (bool) ($perms->can_rdp_modify_form_3 ?? true);
                $this->canRdpPrintForm1         = (bool) ($perms->can_rdp_print_form_1 ?? true);
                $this->canRdpPrintForm2         = (bool) ($perms->can_rdp_print_form_2 ?? true);
                $this->canRdpPrintForm3         = (bool) ($perms->can_rdp_print_form_3 ?? true);
                $this->canRdpViewOthersForm1    = (bool) ($perms->can_rdp_view_others_form_1 ?? false);
                $this->canRdpViewOthersForm2    = (bool) ($perms->can_rdp_view_others_form_2 ?? false);
                $this->canRdpViewOthersForm3    = (bool) ($perms->can_rdp_view_others_form_3 ?? false);
                $this->canRdpEditOthersForm1    = (bool) ($perms->can_rdp_edit_others_form_1 ?? false);
                $this->canRdpEditOthersForm2    = (bool) ($perms->can_rdp_edit_others_form_2 ?? false);
                $this->canRdpEditOthersForm3    = (bool) ($perms->can_rdp_edit_others_form_3 ?? false);
                $this->canRdpPrintOthersForm1   = (bool) ($perms->can_rdp_print_others_form_1 ?? false);
                $this->canRdpPrintOthersForm2   = (bool) ($perms->can_rdp_print_others_form_2 ?? false);
                $this->canRdpPrintOthersForm3   = (bool) ($perms->can_rdp_print_others_form_3 ?? false);
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
                    \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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
                    \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                        'changes' => "Updated permissions/details for role: {$this->keyName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Audit Log: status changed
                    if ($statusChanged) {
                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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
                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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
        $perms->dcs_view_all_documents = ($this->isSadm || $this->isAdmin) ? $this->dcsViewAllDocuments : false;
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
        $perms->can_access_dcs_admin = $this->canAccessDcsAdmin;
        $perms->can_access_settings = $this->canAccessSettings;
        $perms->can_access_recycle_bin = $this->canAccessRecycleBin;

        // RDP Clearances
        $perms->rdp_view_all_files        = ($this->isSadm || $this->isAdmin) ? $this->rdpViewAllFiles : false;
        $perms->is_rdp_view_all_pending_list = $this->isRdpViewAllPendingList;
        $perms->can_rdp_modify_series     = $this->canRdpModifySeries;
        $perms->can_rdp_generate_reports  = $this->canRdpGenerateReports;
        // Per-form clearances
        $perms->can_rdp_access_form_1     = $this->canRdpAccessForm1;
        $perms->can_rdp_access_form_2     = $this->canRdpAccessForm2;
        $perms->can_rdp_access_form_3     = $this->canRdpAccessForm3;
        $perms->can_rdp_modify_form_1     = $this->canRdpModifyForm1;
        $perms->can_rdp_modify_form_2     = $this->canRdpModifyForm2;
        $perms->can_rdp_modify_form_3     = $this->canRdpModifyForm3;
        $perms->can_rdp_print_form_1      = $this->canRdpPrintForm1;
        $perms->can_rdp_print_form_2      = $this->canRdpPrintForm2;
        $perms->can_rdp_print_form_3      = $this->canRdpPrintForm3;
        // Admin-only others permissions (default false for safety)
        $isAdminEditor = $this->isSadm || $this->isAdmin;
        $perms->can_rdp_view_others_form_1  = $isAdminEditor ? $this->canRdpViewOthersForm1  : ($perms->can_rdp_view_others_form_1  ?? false);
        $perms->can_rdp_view_others_form_2  = $isAdminEditor ? $this->canRdpViewOthersForm2  : ($perms->can_rdp_view_others_form_2  ?? false);
        $perms->can_rdp_view_others_form_3  = $isAdminEditor ? $this->canRdpViewOthersForm3  : ($perms->can_rdp_view_others_form_3  ?? false);
        $perms->can_rdp_edit_others_form_1  = $isAdminEditor ? $this->canRdpEditOthersForm1  : false;
        $perms->can_rdp_edit_others_form_2  = $isAdminEditor ? $this->canRdpEditOthersForm2  : false;
        $perms->can_rdp_edit_others_form_3  = $isAdminEditor ? $this->canRdpEditOthersForm3  : false;
        $perms->can_rdp_print_others_form_1 = $isAdminEditor ? $this->canRdpPrintOthersForm1 : ($perms->can_rdp_print_others_form_1 ?? false);
        $perms->can_rdp_print_others_form_2 = $isAdminEditor ? $this->canRdpPrintOthersForm2 : ($perms->can_rdp_print_others_form_2 ?? false);
        $perms->can_rdp_print_others_form_3 = $isAdminEditor ? $this->canRdpPrintOthersForm3 : ($perms->can_rdp_print_others_form_3 ?? false);
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
            $this->dcsViewAllDocuments = false;
            $this->canAccessDcsAdmin = false;
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
        $this->dcsViewAllDocuments = true;
        $this->canAccessDcsAdmin = true;
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

        switch ($this->sortBy) {
            case 'description':
                $query->orderBy('key_description', $this->sortDir);
                break;
            case 'status':
                $query->orderBy('is_active', $this->sortDir);
                break;
            case 'id':
                $query->orderBy('id', $this->sortDir);
                break;
            case 'name':
            default:
                $query->orderBy('key_name', $this->sortDir);
                break;
        }

        $roles = $query->paginate(20);

        return [
            'roles' => $roles,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/accounts_roles.css', 'resources/css/admin/accounts_users.css'])
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

        <div class="directory-controls-bar">
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" class="search-box" placeholder="Search roles..." wire:model.live="search">
            </div>

            <div class="sort-controls-wrapper">
                <span class="sort-label">Sort By:</span>
                <select class="sort-select" wire:model.live="sortBy">
                    <option value="name">Name</option>
                    <option value="description">Description</option>
                    <option value="status">Status</option>
                    <option value="id">ID</option>
                </select>
                <button type="button" wire:click="toggleSortDir" class="btn-sort-dir" title="Toggle Order Direction ({{ strtoupper($sortDir) }})">
                    @if($sortDir === 'asc')
                        <i class="fa-solid fa-arrow-up-a-z"></i>
                    @else
                        <i class="fa-solid fa-arrow-down-z-a"></i>
                    @endif
                </button>
            </div>

            <!-- View Mode Toggle -->
            <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1; margin-left: auto;">
                <button type="button" wire:click="$set('viewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $viewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $viewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $viewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                    <i class="fa-solid fa-border-all"></i> Cards
                </button>
                <button type="button" wire:click="$set('viewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $viewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $viewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $viewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                    <i class="fa-solid fa-table-list"></i> Table
                </button>
            </div>
        </div>
        
        @if($viewMode === 'table')
            <div class="roles-table-container" style="margin-top: 12px; overflow-x: auto;">
                <table class="users-data-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; color: #475569;">
                            <th style="padding: 10px 12px;">Role Name</th>
                            <th style="padding: 10px 12px;">Description</th>
                            <th style="padding: 10px 12px;">Assigned Users</th>
                            <th style="padding: 10px 12px;">Status</th>
                            <th style="padding: 10px 12px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($roles as $role)
                            @php
                                $userCount = \App\Models\User::where('account_role', $role->id)->count();
                                $roleInitials = strtoupper(substr($role->key_name ?: '?', 0, 2));
                            @endphp
                            <tr style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $selectedRoleId === $role->id ? '#f0f9ff' : 'transparent' }};" wire:click="selectRole({{ $role->id }})" wire:key="role-tbl-{{ $role->id }}">
                                <td style="padding: 10px 12px; font-weight: 600; color: #0f172a;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 28px; height: 28px; border-radius: 50%; background: #003699; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700;">
                                            {{ $roleInitials }}
                                        </div>
                                        <span>{{ $role->key_name }}</span>
                                    </div>
                                </td>
                                <td style="padding: 10px 12px; color: #475569; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ $role->key_description ?: 'No description provided.' }}
                                </td>
                                <td style="padding: 10px 12px; color: #334155;">
                                    <span style="font-weight: 600;">{{ $userCount }}</span> {{ \Illuminate\Support\Str::plural('user', $userCount) }}
                                </td>
                                <td style="padding: 10px 12px;">
                                    @if($role->is_active)
                                        <span class="role-status-badge active-badge">Active</span>
                                    @else
                                        <span class="role-status-badge inactive-badge">Suspended</span>
                                    @endif
                                </td>
                                <td style="padding: 10px 12px; text-align: right;">
                                    <button type="button" class="btn-table-action" wire:click.stop="selectRole({{ $role->id }})" style="padding: 5px 12px; font-size: 11.5px; font-weight: 600; border-radius: 6px; border: 1px solid #3b82f6; background: rgba(37, 99, 235, 0.12); color: #2563eb; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-pen-to-square"></i> Configure
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="padding: 24px; text-align: center; color: #94a3b8;">
                                    No roles configured.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="roles-list" style="margin-top: 12px;">
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
        @endif

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
                <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close Details Panel">
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
                                    <!-- View All Pending List -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Pending List (RDP)</span>
                                            <span class="permission-toggle-desc">Clearance to view pending submission clusters from all college offices instead of only user's own office.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="isRdpViewAllPendingList">
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

                            <!-- Category: RDP Per-Form Report Clearances -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-file-contract"></i> RDP Report Form Clearances</span>
                                <p style="font-size: 12px; color: #64748b; margin: 0 0 12px 0; font-style: italic;">
                                    Controls per-form access for NAP Form 1 (Inventory &amp; Appraisal), Form 2 (Records Disposition Schedule), and Form 3 (Unverified Series).
                                    <strong style="color: #b45309;">🔒 Admin-only</strong> items can only be changed by Admins or Super Admins.
                                </p>

                                <!-- Table-style per-form grid -->
                                <div style="overflow-x: auto;">
                                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                        <thead>
                                            <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                                                <th style="text-align: left; padding: 8px 12px; font-weight: 700; color: #334155; width: 38%;">Permission</th>
                                                <th style="text-align: center; padding: 8px 12px; font-weight: 700; color: #1d4ed8; width: 20%;">NAP Form 1</th>
                                                <th style="text-align: center; padding: 8px 12px; font-weight: 700; color: #0e7490; width: 20%;">NAP Form 2</th>
                                                <th style="text-align: center; padding: 8px 12px; font-weight: 700; color: #7c3aed; width: 20%;">NAP Form 3</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Access -->
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 10px 12px;">
                                                    <span style="font-weight: 600; color: #0f172a; display: block;">Access Form</span>
                                                    <span style="font-size: 11px; color: #64748b;">Can open and view this form page.</span>
                                                </td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpAccessForm1"><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpAccessForm2"><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpAccessForm3"><span class="slider"></span></label></td>
                                            </tr>
                                            <!-- Modify -->
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 10px 12px;">
                                                    <span style="font-weight: 600; color: #0f172a; display: block;">Modify Records on Form</span>
                                                    <span style="font-size: 11px; color: #64748b;">Can edit and save record series entries on this form.</span>
                                                </td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpModifyForm1"><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpModifyForm2"><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpModifyForm3"><span class="slider"></span></label></td>
                                            </tr>
                                            <!-- Print -->
                                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                                <td style="padding: 10px 12px;">
                                                    <span style="font-weight: 600; color: #0f172a; display: block;">Print Form</span>
                                                    <span style="font-size: 11px; color: #64748b;">Can open print preview and generate the official document.</span>
                                                </td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpPrintForm1"><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpPrintForm2"><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpPrintForm3"><span class="slider"></span></label></td>
                                            </tr>
                                            <!-- Divider row -->
                                            <tr style="background: #fef9c3; border-top: 2px solid #fde047; border-bottom: 1px solid #fde047;">
                                                <td colspan="4" style="padding: 6px 12px; font-size: 11.5px; font-weight: 700; color: #92400e;">
                                                    🔒 Admin-Only Clearances — controls cross-office data access
                                                </td>
                                            </tr>
                                            <!-- View Others -->
                                            <tr style="border-bottom: 1px solid #e2e8f0; {{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                                <td style="padding: 10px 12px;">
                                                    <span style="font-weight: 600; color: #0f172a; display: block;">View Other Offices' Records</span>
                                                    <span style="font-size: 11px; color: #64748b;">Can see record series belonging to other offices on this form.</span>
                                                </td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpViewOthersForm1" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpViewOthersForm2" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpViewOthersForm3" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                            </tr>
                                            <!-- Edit Others -->
                                            <tr style="border-bottom: 1px solid #e2e8f0; {{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                                <td style="padding: 10px 12px;">
                                                    <span style="font-weight: 600; color: #0f172a; display: block;">Edit Other Offices' Records</span>
                                                    <span style="font-size: 11px; color: #64748b;">Can modify record series entries that belong to other offices.</span>
                                                </td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpEditOthersForm1" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpEditOthersForm2" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpEditOthersForm3" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                            </tr>
                                            <!-- Print Others -->
                                            <tr style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                                <td style="padding: 10px 12px;">
                                                    <span style="font-weight: 600; color: #0f172a; display: block;">Print Other Offices' Records</span>
                                                    <span style="font-size: 11px; color: #64748b;">Can include other offices' records when printing the official document.</span>
                                                </td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpPrintOthersForm1" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpPrintOthersForm2" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                                <td style="text-align: center; padding: 10px 12px;"><label class="switch"><input type="checkbox" wire:model="canRdpPrintOthersForm3" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}><span class="slider"></span></label></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Document Control System Clearances -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-stamp"></i> Document Control System (DCS) Clearances</span>
                                <div class="permissions-grid-layout">
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All DCS Documents</span>
                                            <span class="permission-toggle-desc">Full DCS access across all offices (same as RFIO). Without this, non-RFIO users with DCS access only create and print their own DRF/DCN forms.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="dcsViewAllDocuments" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
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
                                    <!-- Access DCS Admin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DCS Admin Log</span>
                                            <span class="permission-toggle-desc">Clearance to view Document Control System activity logs in Admin Console.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDcsAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
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
