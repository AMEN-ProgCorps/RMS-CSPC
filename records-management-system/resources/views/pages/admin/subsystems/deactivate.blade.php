<?php
/**
 * Admin Console - Deactivated Subsystems Volt Component
 * 
 * Lists deactivated subsystems and allows administrators to reactivate them.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Deactivated Subsystems')] class extends Component {
    /** @var string Success message */
    public string $successMessage = '';

    /** @var string Error message */
    public string $errorMessage = '';

    /**
     * Clear alert messages.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Reactivates a deactivated subsystem to restore operations and access.
     * 
     * @param int $id Subsystem ID
     */
    public function activateSubsystem(int $id): void
    {
        $this->clearMessages();

        $subsystem = \DB::table('subsystems')->where('subsystem_id', $id)->first();
        if (!$subsystem) {
            $this->errorMessage = 'Subsystem not found.';
            return;
        }

        try {
            \DB::transaction(function () use ($subsystem, $id) {
                // Update status
                \DB::table('subsystems')
                    ->where('subsystem_id', $id)
                    ->update([
                        'is_active' => true,
                        'update_at' => now(),
                    ]);

                // Log to admin changes logs
                \DB::table('admin_logs')->insert([
                    'changes' => "Activated subsystem: {$subsystem->subsystem_name} (Restoring application access)",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = "Subsystem '{$subsystem->subsystem_name}' has been successfully reactivated!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to activate subsystem: ' . $e->getMessage();
        }
    }

    /**
     * Fetch deactivated subsystems.
     */
    public function with(): array
    {
        $deactivatedSubsystems = \DB::table('subsystems')
            ->where('is_active', false)
            ->orderBy('subsystem_name')
            ->get();

        return [
            'deactivatedSubsystems' => $deactivatedSubsystems,
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
        <h1>Deactivated Subsystems</h1>
        <p>Review and re-enable suspended modules. Reactivating a module restores user navigation and route access.</p>
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
                        <th style="width: 20%">Deactivated On</th>
                        <th style="width: 10%; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deactivatedSubsystems as $sub)
                        <tr wire:key="sub-deactive-{{ $sub->subsystem_id }}">
                            <td>
                                <div class="admin-name-cell">
                                    <span class="name" style="color: #64748b;">{{ $sub->subsystem_name }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="subsystem-badge" style="background-color: #f1f5f9; color: #64748b; border-color: #e2e8f0;">
                                    <i class="fa-solid fa-code-branch" style="margin-right: 4px;"></i> v{{ $sub->subsystem_version }}
                                </span>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    {{ \Carbon\Carbon::parse($sub->created_at)->format('Y-m-d H:i:s') }}
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($sub->created_at)->diffForHumans() }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    {{ \Carbon\Carbon::parse($sub->update_at)->format('Y-m-d H:i:s') }}
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($sub->update_at)->diffForHumans() }}</span>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" class="btn-action-activate" wire:click="activateSubsystem({{ $sub->subsystem_id }})">
                                    <i class="fa-solid fa-bolt"></i> Activate
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fa-solid fa-circle-check" style="color: #10b981;"></i>
                                    <h3>No Deactivated Subsystems</h3>
                                    <p>All subsystem modules are online and active.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
