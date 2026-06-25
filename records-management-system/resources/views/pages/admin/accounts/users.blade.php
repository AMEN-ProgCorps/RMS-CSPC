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

new #[Layout('layouts.admin')] #[Title('Admin Console - Users')] class extends Component {
    /** @var string Holds the active search input value */
    public string $search = '';

    /** @var string Holds the column key to sort users by */
    public string $sortBy = 'name';

    /** @var string Sorting order direction ('asc' or 'desc') */
    public string $sortDir = 'asc';

    /** @var int|null The ID of the currently selected user profile being viewed/edited. (-1 = create mode) */
    public ?int $selectedUserId = null;

    /**
     * Toggles the sort direction between ascending (asc) and descending (desc).
     * Automatically clears any active success or error banners.
     */
    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
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

    /** @var string Holds the active user's contact number for editing */
    public string $contactNumber = '';

    /** @var string Holds the active user's username */
    public string $username = '';

    /** @var string Holds the password for creation mode */
    public string $password = '';

    /** @var string Holds the password confirmation for creation mode */
    public string $password_confirmation = '';

    /** @var int|null ID of the selected role (references key_id/id in condition_key) */
    public ?int $roleId = null;

    /** @var int|null ID of the selected office (references id in office) */
    public ?int $officeId = null;

    /** @var bool Active status of the user account */
    public bool $isActive = true;

    /** @var string Holds successful transaction alert messages */
    public string $successMessage = '';

    /** @var string Holds failure transaction alert messages */
    public string $errorMessage = '';

    /**
     * Initializes "Create Mode" for configuring a new user profile.
     */
    public function startCreate(): void
    {
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
            $this->isActive = (bool) $user->account_active;
            
            $details = $user->details;
            if ($details) {
                $this->firstName = $details->first_name ?? '';
                $this->lastName = $details->last_name ?? '';
                $this->middleName = $details->middle_name ?? '';
                $this->email = $details->email ?? '';
                $this->contactNumber = $details->contact_number ?? '';
                $this->officeId = $details->office_id;
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
        $this->contactNumber = '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->roleId = null;
        $this->officeId = null;
        $this->isActive = true;
        $this->clearMessages();
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

        // 1. Validation Rules
        if ($this->selectedUserId === -1) {
            // Create mode validation rules
            $this->validate([
                'username' => 'required|string|max:255|unique:account,username',
                'password' => 'required|string|min:8|confirmed',
                'firstName' => 'required|string|max:255',
                'lastName' => 'required|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => 'required|email|max:255|unique:account_details,email',
                'contactNumber' => 'nullable|string|max:25',
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
                'contactNumber' => 'nullable|string|max:25',
                'roleId' => 'required|exists:condition_key,id',
                'officeId' => 'nullable|exists:office,id',
            ]);
        }

        try {
            \DB::transaction(function () {
                if ($this->selectedUserId === -1) {
                    // Create account record
                    $user = new \App\Models\User();
                    $user->username = $this->username;
                    $user->password = \Hash::make($this->password);
                    $user->account_status = 1;
                    $user->account_role = $this->roleId;
                    $user->account_active = $this->isActive;
                    $user->date_created = now();
                    $user->date_updated = now();
                    $user->save();

                    // Create account details record
                    $details = new \App\Models\AccountDetail();
                    $details->account_id = $user->id;
                    $details->first_name = $this->firstName;
                    $details->last_name = $this->lastName;
                    $details->middle_name = $this->middleName;
                    $details->email = $this->email;
                    $details->contact_number = $this->contactNumber;
                    $details->office_id = $this->officeId;
                    $details->is_currently_online = false;
                    $details->save();

                    // Audit Log: created user account
                    \DB::table('admin_logs')->insert([
                        'changes' => "Created user account: {$user->username} ({$this->firstName} {$this->lastName})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Update local state to switch into edit mode for the new user
                    $this->selectedUserId = $user->id;
                    
                    // Clear out sensitive password inputs
                    $this->password = '';
                    $this->password_confirmation = '';
                    
                    $this->successMessage = 'User account created successfully!';
                } else {
                    // Update general Account details
                    $user = \App\Models\User::findOrFail($this->selectedUserId);
                    
                    // Detect if status changed
                    $statusChanged = $user->account_active != $this->isActive;

                    $user->update([
                        'account_role' => $this->roleId,
                        'account_active' => $this->isActive,
                        'date_updated' => now(),
                    ]);

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
                    $details->contact_number = $this->contactNumber;
                    $details->office_id = $this->officeId;
                    $details->save();

                    // Audit Log: updated details
                    \DB::table('admin_logs')->insert([
                        'changes' => "Updated details for user: {$user->username}",
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

        $users = $query->get();

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

<div class="admin-users-container">
    <!-- Left Pane: Users Directory -->
    <div class="directory-panel">
        <div class="directory-header-row">
            <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Users Directory</span>
            <button type="button" class="btn-create-new" wire:click="startCreate">
                <i class="fa-solid fa-plus"></i> New User
            </button>
        </div>

        <div class="search-box-wrapper">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" class="search-box" placeholder="Search by name, email, user..." wire:model.live="search">
        </div>
        
        <div style="display: flex; align-items: center; gap: 8px; width: 100%;">
            <span style="font-size: 11px; font-weight: 700; color: #8b95a5; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap;">Sort By:</span>
            <select class="form-select" wire:model.live="sortBy" style="flex: 1; padding: 6px 32px 6px 12px; font-size: 12.5px; border-radius: 99px; height: 32px; border: 1.5px solid #e2e8f0;">
                <option value="name">Name</option>
                <option value="username">Username</option>
                <option value="role">Role</option>
                <option value="office">Office</option>
                <option value="date_created">Date Created</option>
            </select>
            <button type="button" wire:click="toggleSortDir" style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; border: 1.5px solid #e2e8f0; background: #ffffff; cursor: pointer; color: #003699; font-size: 13px; transition: all 0.2s ease; padding: 0;" onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.background='#ffffff'; this.style.borderColor='#e2e8f0';" title="Toggle Order Direction ({{ strtoupper($sortDir) }})">
                @if($sortDir === 'asc')
                    <i class="fa-solid fa-arrow-up-a-z"></i>
                @else
                    <i class="fa-solid fa-arrow-down-z-a"></i>
                @endif
            </button>
        </div>
        
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
                        <span>{{ $initials }}</span>
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
    </div>

    <!-- Right Pane: User Details & Form -->
    <div class="details-panel">
        @if($selectedUserId)
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
                    <!-- Username -->
                    <div class="form-group">
                        <span class="form-label">Username</span>
                        @if($selectedUserId === -1)
                            <input type="text" class="form-input" placeholder="e.g. jdoe" wire:model="username">
                            @error('username') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                        @else
                            <input type="text" class="form-input" value="{{ $username }}" readonly>
                        @endif
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <span class="form-label">Email Address</span>
                        <input type="email" class="form-input" placeholder="e.g. john.doe@cspc.edu.ph" wire:model="email">
                        @error('email') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    @if($selectedUserId === -1)
                        <!-- Password -->
                        <div class="form-group">
                            <span class="form-label">Password</span>
                            <input type="password" class="form-input" placeholder="Choose a secure password..." wire:model="password">
                            @error('password') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group">
                            <span class="form-label">Confirm Password</span>
                            <input type="password" class="form-input" placeholder="Confirm your password..." wire:model="password_confirmation">
                            @error('password_confirmation') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                        </div>
                    @endif

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
                    <div class="form-group">
                        <span class="form-label">Middle Name</span>
                        <input type="text" class="form-input" placeholder="Enter middle name..." wire:model="middleName">
                        @error('middleName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Contact Number -->
                    <div class="form-group">
                        <span class="form-label">Contact Number</span>
                        <input type="text" class="form-input" placeholder="Enter contact number..." wire:model="contactNumber">
                        @error('contactNumber') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Role -->
                    <div class="form-group">
                        <span class="form-label">Assigned Role</span>
                        <select class="form-select" wire:model="roleId">
                            <option value="">Select Role</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->key_name }}</option>
                            @endforeach
                        </select>
                        @error('roleId') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Office -->
                    <div class="form-group">
                        <span class="form-label">Assigned Office</span>
                        <select class="form-select" wire:model="officeId">
                            <option value="">No Office Assigned</option>
                            @foreach($offices as $office)
                                <option value="{{ $office->id }}">{{ $office->office_name }}</option>
                            @endforeach
                        </select>
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
                @if($selectedUserId > 0)
                    <button type="button" class="btn-delete" wire:click="deleteUser" style="margin-right: auto;">
                        <i class="fa-solid fa-trash-can"></i> Delete Account
                    </button>
                @endif
                <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                <button type="button" class="btn-save" wire:click="saveUserChanges">
                    <i class="fa-solid fa-floppy-disk"></i> {{ $selectedUserId === -1 ? 'Create User' : 'Save Changes' }}
                </button>
            </div>
        @else
            <!-- Placeholder -->
            <div class="details-placeholder">
                <i class="fa-solid fa-users-gear"></i>
                <h3>User Accounts Directory</h3>
                <p>Click on any user's name from the directory list on the left to view and update their profile details, assigned roles, and access settings.</p>
            </div>
        @endif
    </div>
</div>
