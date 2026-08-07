<?php
/**
 * Admin Console - Accounts Management Users Volt Component
 * 
 * This component provides an interface for administrators to manage user accounts.
 * Features:
 *  - Real-time search (by name, email, or username)
 *  - Interactive directory listing
 *  - Configurable sorting (by name, username, assigned role, assigned office, or date created)
 *  - Custom sort direction toggles (ascending / descending)
 *  - Inline form editor (updates personal details, role, office, active state)
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Users')] class extends Component {
    use WithPagination;

    /** @var string Holds the active search input value */
    public string $search = '';

    /** @var string Holds the column key to sort users by */
    public string $sortBy = 'name';

    /** @var string Sorting order direction ('asc' or 'desc') */
    public string $sortDir = 'asc';

    /** @var string View Mode toggle ('table' or 'grid') */
    public string $viewMode = 'table';

    /** @var int|null The ID of the currently selected user profile being viewed/edited. (-1 = create mode) */
    public ?int $selectedUserId = null;

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

    /**
     * Toggles the sort direction between ascending (asc) and descending (desc).
     * Automatically clears any active success or error banners.
     */
    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
        $this->clearMessages();
    }
    
    /** @var string Holds the active user's first name for editing */
    public string $firstName = '';

    /** @var string Holds the active user's last name for editing */
    public string $lastName = '';

    /** @var string Holds the active user's middle name for editing */
    public string $middleName = '';

    /** @var string Holds the active user's email address for editing */
    public string $email = '';

    /** @var string Holds the active user's username */
    public string $username = '';

    /** @var int|null ID of the selected role (references key_id/id in condition_key) */
    public ?int $roleId = null;

    /** @var int|null ID of the selected office (references id in office) */
    public ?int $officeId = null;

    /** @var bool Active status of the user account */
    public bool $isActive = true;

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->is_admin && !$perms->can_sadm_modify_accountlist && !$perms->can_sadm_modify_account)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /** @var string Holds successful transaction alert messages */
    public string $successMessage = '';

    /** @var string Holds failure transaction alert messages */
    public string $errorMessage = '';

    // Searchable dropdown properties
    public string $roleSearch = '';
    public bool $showRoleDropdown = false;
    public string $officeSearch = '';
    public bool $showOfficeDropdown = false;

    /**
     * Initializes "Create Mode" for configuring a new user profile.
     */
    public function startCreate(): void
    {
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            return;
        }
        $this->cancelSelection();
        $this->selectedUserId = -1; // -1 denotes "Create Mode"
    }

    /**
     * Selects a user from the left-hand directory list.
     * Fetches user credentials and details, populating form fields.
     * 
     * @param int $id The ID of the user record to select
     */
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
            } else {
                $this->roleSearch = '';
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
                } else {
                    $this->officeSearch = '';
                }
            }
        }
    }

    /**
     * Clears all session toast/alert message banners.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Resets the selected user state, hiding the edit form.
     */
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

    /**
     * Validates and saves changes made to the user's details and settings.
     * Performed inside a Database Transaction to preserve data integrity.
     */
    public function saveUserChanges(): void
    {
        if (!$this->selectedUserId) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_account clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify user accounts.';
            return;
        }

        // 1. Validation Rules
        if ($this->selectedUserId === -1) {
            // Create mode validation rules (Email-First registration: First & Last Name are optional and auto-sync on 1st login)
            $this->validate([
                'firstName' => 'nullable|string|max:255',
                'lastName' => 'nullable|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => 'required|email|max:255|unique:account_details,email',
                'roleId' => 'required|exists:condition_key,id',
                'officeId' => 'nullable|exists:office,id',
            ]);
        } else {
            // Edit mode validation rules
            $this->validate([
                'firstName' => 'required|string|max:255',
                'lastName' => 'required|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => 'required|email|max:255|unique:account_details,email,' . $this->selectedUserId . ',account_id',
                'roleId' => 'required|exists:condition_key,id',
                'officeId' => 'nullable|exists:office,id',
            ]);
        }

        try {
            \DB::transaction(function () {
                if ($this->selectedUserId === -1) {
                    // Derive unique username from email prefix for system record
                    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $this->email)[0] ?? 'user'));
                    if (empty($baseUsername)) {
                        $baseUsername = 'user';
                    }
                    $derivedUsername = $baseUsername;
                    $counter = 1;
                    while (\App\Models\User::where('username', $derivedUsername)->exists()) {
                        $derivedUsername = $baseUsername . $counter;
                        $counter++;
                    }

                    // Create account record with auto-generated secure random password
                    $user = new \App\Models\User();
                    $user->username = $derivedUsername;
                    $user->password = \Hash::make(\Illuminate\Support\Str::random(32));
                    $user->account_status = 1;
                    $user->account_role = $this->roleId;
                    $user->account_active = $this->isActive;
                    $user->date_created = now();
                    $user->date_updated = now();
                    $user->save();

                    // Create account details record (Auto-sync placeholders if name not entered)
                    $details = new \App\Models\AccountDetail();
                    $details->account_id = $user->id;
                    $details->first_name = !empty(trim($this->firstName)) ? trim($this->firstName) : 'Pending';
                    $details->last_name = !empty(trim($this->lastName)) ? trim($this->lastName) : 'Google Sync';
                    $details->middle_name = $this->middleName;
                    $details->email = $this->email;
                    $details->office_id = $this->officeId;
                    $details->is_currently_online = false;
                    $details->save();

                    // Audit Log: created user account
                    \DB::table('admin_logs')->insert([
                        'changes' => "Created user account via Google SSO: {$user->username} ({$this->firstName} {$this->lastName} <{$this->email}>)",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Update local state to switch into edit mode for the new user
                    $this->selectedUserId = $user->id;
                    $this->username = $user->username;
                    
                    $this->successMessage = 'User account created successfully!';
                } else {
                    // Update general Account details
                    $user = \App\Models\User::findOrFail($this->selectedUserId);
                    
                    // Detect if status changed
                    $statusChanged = $user->account_active != $this->isActive;

                    $userData = [
                        'account_role' => $this->roleId,
                        'account_active' => $this->isActive,
                        'date_updated' => now(),
                    ];

                    $user->update($userData);

                    // Update/Create personal Profile details
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
                    // Force logout modified user account (unless it is the current logged-in admin)
                    if ($this->selectedUserId !== auth()->id()) {
                        $details->force_logout_at = now();
                        $details->is_currently_online = false;
                        $details->save();

                        try {
                            \DB::table('sessions')
                                ->where('user_id', (string) $this->selectedUserId)
                                ->orWhere('user_id', (int) $this->selectedUserId)
                                ->delete();
                        } catch (\Throwable) {}
                    } else {
                        $details->save();
                    }

                    // Audit Log: updated details
                    \DB::table('admin_logs')->insert([
                        'changes' => "Updated details for user: {$user->username} ({$this->firstName} {$this->lastName})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Audit Log: status changed
                    if ($statusChanged) {
                        \DB::table('admin_logs')->insert([
                            'changes' => "Toggled active status (Value: " . ($this->isActive ? '1' : '0') . ") for user: {$user->username}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3, // Admin Console
                            'when_changes' => now()
                        ]);
                    }
                    
                    $this->successMessage = 'User account details updated successfully!';
                }
            });

            if ($this->selectedUserId === auth()->id()) {
                session()->flash('success', 'User account details updated successfully!');
                $this->js('window.location.reload();');
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save changes: ' . $e->getMessage();
        }
    }

    /**
     * Soft-deactivates (soft-deletes) the selected user for transparency.
     */
    public function deleteUser(): void
    {
        if ($this->selectedUserId === null || $this->selectedUserId === -1) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_account clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to delete user accounts.';
            return;
        }

        if ($this->selectedUserId === auth()->id()) {
            $this->errorMessage = 'You cannot delete your own logged-in account!';
            return;
        }

        try {
            \DB::transaction(function () {
                $user = \App\Models\User::findOrFail($this->selectedUserId);
                
                $user->update([
                    'account_active' => false,
                    'date_updated' => now(),
                ]);

                // Force logout deactivated user account
                \DB::table('account_details')
                    ->where('account_id', $this->selectedUserId)
                    ->update([
                        'force_logout_at' => now(),
                        'is_currently_online' => false,
                    ]);

                try {
                    \DB::table('sessions')
                        ->where('user_id', (string) $this->selectedUserId)
                        ->orWhere('user_id', (int) $this->selectedUserId)
                        ->delete();
                } catch (\Throwable) {}

                // Audit Log: soft-deleted/deactivated
                \DB::table('admin_logs')->insert([
                    'changes' => "Soft-deleted user account (Deactivated for transparency): {$user->username}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now()
                ]);

                // Reset selection to close edit details pane but set success message
                $this->cancelSelection();
                $this->successMessage = 'User account soft-deleted successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete user: ' . $e->getMessage();
        }
    }

    /**
     * Component Lifecycle Hook - passes computed query variables to the view.
     * Queries users with live search criteria and applies custom sorting.
     * 
     * @return array Contains queried users list, available roles, and offices
     */
    public function with(): array
    {
        // Only return active users in the directory to match deletion expectations
        $query = \App\Models\User::with(['details'])->where('account_active', true);

        // Handle Search Filters (searches across credentials and details)
        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function($q) use ($searchVal) {
                $q->where('username', 'like', $searchVal)
                  ->orWhereHas('details', function($qDet) use ($searchVal) {
                      $qDet->where('first_name', 'like', $searchVal)
                           ->orWhere('last_name', 'like', $searchVal)
                           ->orWhere('email', 'like', $searchVal);
                  });
            });
        }

        // Handle Sorting Options using subquery ordering
        switch ($this->sortBy) {
            case 'username':
                $query->orderBy('username', $this->sortDir);
                break;
            case 'role':
                $query->orderBy(
                    \DB::raw('(select key_name from condition_key where condition_key.id = account.account_role)'),
                    $this->sortDir
                );
                break;
            case 'office':
                $query->orderBy(
                    \DB::raw('(select office_name from office join account_details on account_details.office_id = office.id where account_details.account_id = account.id)'),
                    $this->sortDir
                );
                break;
            case 'date_created':
                $query->orderBy('date_created', $this->sortDir);
                break;
            case 'name':
            default:
                $query->orderBy(
                    \DB::raw('(select last_name from account_details where account_details.account_id = account.id)'),
                    $this->sortDir
                )->orderBy(
                    \DB::raw('(select first_name from account_details where account_details.account_id = account.id)'),
                    $this->sortDir
                );
                break;
        }

        $users = $query->paginate(20);

        // Fetch auxiliary lists for form dropdown lists (only active ones)
        $roles = \App\Models\role_list::where('is_active', true)->orderBy('key_name')->get();
        $offices = \App\Models\office::where('is_active', true)->orderBy('office_name')->get();

        return [
            'users' => $users,
            'roles' => $roles,
            'offices' => $offices,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/accounts_users.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="admin-users-container {{ !$selectedUserId ? 'no-selection' : 'has-selection' }}">
    <!-- Left Pane: Users Directory -->
    <div class="directory-panel">
        <div class="directory-header-row">
            <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Users Directory</span>
            @php
                $canModify = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_sadm_modify_account;
            @endphp
            @if($canModify)
            <button type="button" class="btn-create-new" wire:click="startCreate">
                <i class="fa-solid fa-plus"></i> New User
            </button>
            @endif
        </div>

        <div class="directory-controls-bar">
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" class="search-box" placeholder="Search by name, email, user..." wire:model.live="search">
            </div>
            
            <div class="sort-controls-wrapper">
                <span class="sort-label">Sort By:</span>
                <select class="sort-select" wire:model.live="sortBy">
                    <option value="name">Name</option>
                    <option value="username">Username</option>
                    <option value="role">Role</option>
                    <option value="office">Office</option>
                    <option value="date_created">Date Created</option>
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
            <!-- Table Layout View -->
            <div style="overflow-x: auto; max-height: calc(100vh - 280px); overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; font-size: 12.5px;">
                    <thead>
                        <tr style="background: #f8fafc; color: #475569; text-align: left; position: sticky; top: 0; z-index: 10; font-weight: 600; font-size: 11.5px; border-bottom: 1.5px solid #cbd5e1;">
                            <th style="padding: 8px 10px;">User Name</th>
                            <th style="padding: 8px 10px;">Email (Google Account)</th>
                            <th style="padding: 8px 10px;">Assigned Role</th>
                            <th style="padding: 8px 10px; text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            @php
                                $userDet = $user->details;
                                $displayName = $userDet ? ($userDet->first_name . ' ' . $userDet->last_name) : $user->username;
                                $roleKey = \DB::table('condition_key')->where('id', $user->account_role)->first();
                            @endphp
                            <tr class="user-tbl-row {{ $selectedUserId === $user->id ? 'selected-row' : '' }}" 
                                wire:key="user-tbl-{{ $user->id }}" 
                                wire:click="selectUser({{ $user->id }})"
                                style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $selectedUserId === $user->id ? '#eff6ff' : '#ffffff' }}; transition: background 0.12s ease;">
                                <td style="padding: 8px 10px; font-weight: 600; color: #1e293b;">{{ $displayName }}</td>
                                <td style="padding: 8px 10px; color: #0284c7;">{{ $userDet?->email ?: '—' }}</td>
                                <td style="padding: 8px 10px; color: #475569;">
                                    <span style="background: #f1f5f9; color: #334155; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; border: 1px solid #cbd5e1;">
                                        {{ $roleKey?->key_name ?: 'User' }}
                                    </span>
                                </td>
                                <td style="padding: 8px 10px; text-align: center;">
                                    @if($user->account_active)
                                        <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;">Active</span>
                                    @else
                                        <span style="padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3;">Blocked</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px;">No users found matching your search.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <!-- Cards Grid Layout View -->
            <div class="users-list">
                @forelse($users as $user)
                    @php
                        $userDet = $user->details;
                        $initials = strtoupper(substr($userDet?->first_name ?: '?', 0, 1) . substr($userDet?->last_name ?: '?', 0, 1));
                        $displayName = $userDet ? ($userDet->first_name . ' ' . $userDet->last_name) : $user->username;
                        $roleKey = \DB::table('condition_key')->where('id', $user->account_role)->first();
                    @endphp
                    <div class="user-item-card {{ $selectedUserId === $user->id ? 'active' : '' }}" wire:key="user-{{ $user->id }}" wire:click="selectUser({{ $user->id }})">
                        <div class="user-avatar-small">
                            @if(!empty($userDet?->avatar_url))
                                <img src="{{ $userDet->avatar_url }}" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            @else
                                <span>{{ $initials }}</span>
                            @endif
                        </div>
                        <div class="user-meta-info">
                            <span class="user-display-name">{{ $displayName }}</span>
                            <span class="user-display-email">{{ $userDet?->email ?: '@' . $user->username }}</span>
                            <span class="user-display-role-badge">{{ $roleKey?->key_name ?: 'User' }}</span>
                        </div>
                        @if(!$user->account_active)
                            <i class="fa-solid fa-ban" style="color: #ef4444; font-size: 13px;" title="Account Blocked"></i>
                        @endif
                    </div>
                @empty
                    <div style="text-align: center; color: #94a3b8; padding: 20px; font-family: 'Inter', sans-serif; font-size: 13.5px;">
                        No users found matching your search.
                    </div>
                @endforelse
            </div>
        @endif

        @if($users->hasPages())
            <div class="users-pagination-bar">
                {{ $users->links('components.pagination') }}
            </div>
        @endif
    </div>

    <!-- Right Pane: User Details & Form (Only rendered when a user is selected or creating a new user) -->
    @if($selectedUserId)
        <div class="details-panel">
            @php
                if ($selectedUserId > 0) {
                    $selectedUser = \App\Models\User::with('details')->find($selectedUserId);
                    $selectedUserDet = $selectedUser?->details;
                    $selInitials = strtoupper(substr($selectedUserDet?->first_name ?: '?', 0, 1) . substr($selectedUserDet?->last_name ?: '?', 0, 1));
                    $selDisplayName = $selectedUserDet ? ($selectedUserDet->first_name . ' ' . $selectedUserDet->last_name) : $selectedUser->username;
                    $selRoleKey = \DB::table('condition_key')->where('id', $selectedUser->account_role)->first();
                    $selRoleName = $selRoleKey?->key_name ?: 'User';
                    $isOnline = $selectedUserDet?->is_currently_online;
                } else {
                    $selInitials = 'NU';
                    $selDisplayName = 'Configure New User';
                    $selRoleName = 'Guest';
                    $isOnline = false;
                }
            @endphp
            
            <!-- Header -->
            <div class="details-header">
                <div class="details-header-avatar">
                    @if($selectedUserId === -1)
                        <i class="fa-solid fa-user-plus" style="font-size: 20px;"></i>
                    @elseif(!empty($selectedUserDet?->avatar_url))
                        <img src="{{ $selectedUserDet->avatar_url }}" alt="Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    @else
                        <span>{{ $selInitials }}</span>
                    @endif
                </div>
                <div class="details-header-info">
                    <h2 class="details-header-name">{{ $selDisplayName }}</h2>
                    <span class="details-header-sub">
                        @if($selectedUserId === -1)
                            Configure access credentials & profile details for a new platform account
                        @else
                            <i class="fa-solid fa-user-shield"></i> {{ $selRoleName }} &nbsp;•&nbsp; 
                            <i class="fa-solid fa-circle {{ $isOnline ? 'online' : 'offline' }}" style="color: {{ $isOnline ? '#10b981' : '#cbd5e1' }}; font-size: 8px;"></i>
                            {{ $isOnline ? 'Online Now' : 'Offline' }}
                        @endif
                    </span>
                </div>
                <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close Details Panel">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Body/Form -->
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

                <form wire:submit.prevent="saveUserChanges" class="form-grid-layout">
                    <!-- Email -->
                    <div class="form-group full-width">
                        <span class="form-label">Email Address (Google Account)</span>
                        <input type="email" class="form-input" placeholder="e.g. john.doe@cspc.edu.ph" wire:model="email">
                        @error('email') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- First Name -->
                    <div class="form-group">
                        <span class="form-label">First Name</span>
                        <input type="text" class="form-input" placeholder="Enter first name..." wire:model="firstName">
                        @error('firstName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Last Name -->
                    <div class="form-group">
                        <span class="form-label">Last Name</span>
                        <input type="text" class="form-input" placeholder="Enter last name..." wire:model="lastName">
                        @error('lastName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Middle Name -->
                    <div class="form-group full-width">
                        <span class="form-label">Middle Name</span>
                        <input type="text" class="form-input" placeholder="Enter middle name..." wire:model="middleName">
                        @error('middleName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Role -->
                    <div class="form-group" wire:click.outside="$set('showRoleDropdown', false)">
                        <span class="form-label">Assigned Role</span>
                        <div style="position: relative;">
                            <input type="text" 
                                   class="form-input" 
                                   placeholder="Search and select role..." 
                                   wire:model.live="roleSearch" 
                                   wire:focus="$set('showRoleDropdown', true)" 
                                   autocomplete="off" 
                                   style="padding-right: 32px; background-color: white; font-family: 'Inter', sans-serif;">
                            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 10px;">▼</span>
                            
                            @if($showRoleDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-height: 160px; overflow-y: auto; z-index: 50; font-family: 'Inter', sans-serif;">
                                    <div wire:click="selectRole(null, '')" style="padding: 9px 14px; font-size: 13px; color: #64748b; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-style: italic;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                        Select Role
                                    </div>
                                    @php
                                        $rSearchLower = strtolower($roleSearch);
                                        $filteredRoles = $roles->filter(function($r) use ($rSearchLower) {
                                            return empty($rSearchLower) || str_contains(strtolower($r->key_name), $rSearchLower);
                                        });
                                    @endphp
                                    @forelse($filteredRoles as $role)
                                        <div wire:click="selectRole({{ $role->id }}, '{{ addslashes($role->key_name) }}')" style="padding: 9px 14px; font-size: 13px; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                            {{ $role->key_name }}
                                        </div>
                                    @empty
                                        <div style="padding: 12px 14px; font-size: 13px; color: #94a3b8; text-align: center;">No matching roles found</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                        @error('roleId') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Office -->
                    <div class="form-group" wire:click.outside="$set('showOfficeDropdown', false)">
                        <span class="form-label">Assigned Office</span>
                        <div style="position: relative;">
                            <input type="text" 
                                   class="form-input" 
                                   placeholder="Search and select office..." 
                                   wire:model.live="officeSearch" 
                                   wire:focus="$set('showOfficeDropdown', true)" 
                                   autocomplete="off" 
                                   style="padding-right: 32px; background-color: white; font-family: 'Inter', sans-serif;">
                            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 10px;">▼</span>
                            
                            @if($showOfficeDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-height: 160px; overflow-y: auto; z-index: 50; font-family: 'Inter', sans-serif;">
                                    <div wire:click="selectOffice(null, '')" style="padding: 9px 14px; font-size: 13px; color: #64748b; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-style: italic;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                        No Office Assigned
                                    </div>
                                    @php
                                        $oSearchLower = strtolower($officeSearch);
                                        $filteredOffices = $offices->filter(function($o) use ($oSearchLower) {
                                            return empty($oSearchLower) 
                                                || str_contains(strtolower($o->office_name), $oSearchLower)
                                                || str_contains(strtolower($o->office_code), $oSearchLower);
                                        });
                                    @endphp
                                    @forelse($filteredOffices as $office)
                                        <div wire:click="selectOffice({{ $office->id }}, '{{ addslashes($office->office_name) }}')" style="padding: 9px 14px; font-size: 13px; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                            {{ $office->office_name }} ({{ $office->office_code }})
                                        </div>
                                    @empty
                                        <div style="padding: 12px 14px; font-size: 13px; color: #94a3b8; text-align: center;">No matching offices found</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                        @error('officeId') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Active Toggle Wrapper -->
                    <div class="form-group full-width">
                        <div class="status-toggle-wrapper">
                            <div class="status-toggle-label">
                                <span class="status-toggle-title">Account Status</span>
                                <span class="status-toggle-desc">Toggle whether this user account is active or suspended.</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" wire:model="isActive">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Footer -->
            <div class="details-footer">
                @php
                    $canModify = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_sadm_modify_account;
                @endphp
                @if($selectedUserId > 0)
                    <button type="button" class="btn-delete" wire:click="deleteUser" style="margin-right: auto;" {{ !$canModify ? 'disabled style=opacity:0.6;cursor:not-allowed;' : '' }}>
                        <i class="fa-solid fa-trash-can"></i> Delete Account
                    </button>
                @endif
                <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                <button type="button" class="btn-save" wire:click="saveUserChanges" {{ !$canModify ? 'disabled style=opacity:0.6;cursor:not-allowed;' : '' }}>
                    <i class="fa-solid fa-floppy-disk"></i> {{ $selectedUserId === -1 ? 'Create User' : 'Save Changes' }}
                </button>
            </div>
        </div>
    @endif
</div>
