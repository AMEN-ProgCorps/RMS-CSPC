<?php
/**
 * Admin Console - Active Subsystems Volt Component
 * 
 * Lists active subsystems and allows administrators to deactivate them.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Active Subsystems')] class extends Component {
    /** @var string Success message */
    public string $successMessage = '';

    /** @var string Error message */
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_subsystems)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /**
     * Clear alert messages.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Deactivates a subsystem to halt its operations and block access.
     * 
     * @param int $id Subsystem ID
     */
    public function deactivateSubsystem(int $id): void
    {
        $this->clearMessages();

        $subsystem = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')->where('subsystem_id', $id)->first();
        if (!$subsystem) {
            $this->errorMessage = 'Subsystem not found.';
            return;
        }

        // Prevent locking out critical management panels
        if ($subsystem->subsystem_name === 'Admin Console' || $subsystem->subsystem_name === 'Profile Manager') {
            $this->errorMessage = "For system security, you cannot deactivate the '" . $subsystem->subsystem_name . "' module.";
            return;
        }

        try {
            \DB::transaction(function () use ($subsystem, $id) {
                // Update status
                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
                    ->where('subsystem_id', $id)
                    ->update([
                        'is_active' => false,
                        'update_at' => now(),
                    ]);

                // Log to admin changes logs
                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                    'changes' => "Deactivated subsystem: {$subsystem->subsystem_name} (Halting application access)",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = "Subsystem '{$subsystem->subsystem_name}' has been successfully deactivated!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to deactivate subsystem: ' . $e->getMessage();
        }
    }

    /**
     * Fetch active subsystems.
     */
    public function with(): array
    {
        $activeSubsystems = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
            ->where('is_active', true)
            ->orderBy('subsystem_name')
            ->get();

        return [
            'activeSubsystems' => $activeSubsystems,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Active Subsystems</h1>
        <p>Manage currently active modules. Deactivating a module halts its access and removes it from user interfaces.</p>
    </div>

    <!-- Alerts -->
    @if($successMessage)
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i> {{ $successMessage }}
        </div>
    @endif
    @if($errorMessage)
        <div class="alert alert-error">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ $errorMessage }}
        </div>
    @endif

    <!-- Table Card -->
    <div class="logs-table-card">
        <div class="table-responsive">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th style="width: 30%">Subsystem Name</th>
                        <th style="width: 15%">Version</th>
                        <th style="width: 25%">Registered On</th>
                        <th style="width: 20%">Last Updated</th>
                        <th style="width: 10%; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($activeSubsystems as $sub)
                        <tr wire:key="sub-active-{{ $sub->subsystem_id }}">
                            <td>
                                <div class="admin-name-cell">
                                    <span class="name">{{ $sub->subsystem_name }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="subsystem-badge">
                                    <i class="fa-solid fa-code-branch" style="margin-right: 4px;"></i> v{{ $sub->subsystem_version }}
                                </span>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    <span class="log-date">{{ \Carbon\Carbon::parse($sub->created_at)->format('Y-m-d H:i:s') }}</span>
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($sub->created_at)->diffForHumans() }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    <span class="log-date">{{ \Carbon\Carbon::parse($sub->update_at)->format('Y-m-d H:i:s') }}</span>
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($sub->update_at)->diffForHumans() }}</span>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                @if($sub->subsystem_name === 'Admin Console' || $sub->subsystem_name === 'Profile Manager')
                                    <button type="button" class="btn-action-deactivate" style="opacity: 0.5; cursor: not-allowed;" title="Critical system modules cannot be deactivated" disabled>
                                        <i class="fa-solid fa-lock"></i> Protected
                                    </button>
                                @else
                                    <button type="button" class="btn-action-deactivate" wire:click="deactivateSubsystem({{ $sub->subsystem_id }})">
                                        <i class="fa-solid fa-power-off"></i> Deactivate
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fa-solid fa-server"></i>
                                    <h3>No Active Subsystems Found</h3>
                                    <p>All subsystem modules are currently offline/deactivated.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
