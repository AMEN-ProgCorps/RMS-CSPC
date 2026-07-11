<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Addon')] class extends Component {
    public string $search = '';
    public string $optionName = '';
    public ?int $editingOptionId = null;
    public string $editingOptionName = '';

    public string $successMessage = '';
    public string $errorMessage = '';

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function addOption(): void
    {
        $this->clearMessages();

        $name = trim($this->optionName);
        if (empty($name)) {
            $this->errorMessage = 'Option name is required.';
            return;
        }

        try {
            $exists = DB::table('dts_action_options')->where('option_name', $name)->exists();
            if ($exists) {
                $this->errorMessage = 'This option already exists.';
                return;
            }

            DB::table('dts_action_options')->insert([
                'option_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log admin action
            DB::table('admin_logs')->insert([
                'changes' => "Added DTS action option: {$name}",
                'admin_id' => auth()->id(),
                'what_system' => 3, // DTS
                'when_changes' => now(),
            ]);

            $this->optionName = '';
            $this->successMessage = 'Option added successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to add option: ' . $e->getMessage();
        }
    }

    public function startEdit(int $id, string $name): void
    {
        $this->clearMessages();
        $this->editingOptionId = $id;
        $this->editingOptionName = $name;
    }

    public function cancelEdit(): void
    {
        $this->editingOptionId = null;
        $this->editingOptionName = '';
    }

    public function updateOption(): void
    {
        $this->clearMessages();

        $newName = trim($this->editingOptionName);
        if (empty($newName)) {
            $this->errorMessage = 'Option name is required.';
            return;
        }

        try {
            $exists = DB::table('dts_action_options')
                ->where('option_name', $newName)
                ->where('id', '!=', $this->editingOptionId)
                ->exists();

            if ($exists) {
                $this->errorMessage = 'Another option with this name already exists.';
                return;
            }

            $oldName = DB::table('dts_action_options')->where('id', $this->editingOptionId)->value('option_name');

            DB::table('dts_action_options')
                ->where('id', $this->editingOptionId)
                ->update([
                    'option_name' => $newName,
                    'updated_at' => now(),
                ]);

            // Log admin action
            DB::table('admin_logs')->insert([
                'changes' => "Updated DTS action option: '{$oldName}' to '{$newName}'",
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->cancelEdit();
            $this->successMessage = 'Option updated successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to update option: ' . $e->getMessage();
        }
    }

    public function deleteOption(int $id): void
    {
        $this->clearMessages();

        try {
            $name = DB::table('dts_action_options')->where('id', $id)->value('option_name');

            DB::table('dts_action_options')->where('id', $id)->delete();

            // Log admin action
            DB::table('admin_logs')->insert([
                'changes' => "Deleted DTS action option: {$name}",
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->successMessage = 'Option deleted successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete option: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $query = DB::table('dts_action_options');
        if (!empty($this->search)) {
            $query->where('option_name', 'like', '%' . $this->search . '%');
        }

        $options = $query->orderBy('option_name', 'asc')->get();

        return [
            'options' => $options,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css', 'resources/css/admin/accounts_offices.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .addon-container {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
            align-items: start;
        }
        @media(max-width: 900px) {
            .addon-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="logs-header-section" style="margin-bottom: 24px;">
        <div class="logs-title-area">
            <h1 class="logs-title-main" style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-puzzle-piece" style="color: #003699;"></i>
                DTS Action Options (Add-on)
            </h1>
            <p class="logs-subtitle-sub">Manage custom dropdown options for "Action Needed" steps in transaction details.</p>
        </div>
    </div>

    <!-- Alert Toast Messages -->
    @if ($successMessage)
        <div style="padding: 12px 16px; background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; color: #065f46; font-size: 13.5px; font-weight: 500; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-check"></i>
            {{ $successMessage }}
        </div>
    @endif
    @if ($errorMessage)
        <div style="padding: 12px 16px; background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; color: #991b1b; font-size: 13.5px; font-weight: 500; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-xmark"></i>
            {{ $errorMessage }}
        </div>
    @endif

    <div class="addon-container">
        <!-- Left Side: Options List -->
        <div class="admin-offices-container" style="padding: 20px; background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <!-- Search Area -->
            <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                <div style="position: relative; flex: 1;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                    <input type="text" wire:model.live="search" placeholder="Search options..." style="width: 100%; padding: 10px 12px 10px 38px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13.5px;">
                </div>
            </div>

            <!-- Table -->
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13.5px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; color: #475569; font-weight: 600;">
                            <th style="padding: 12px;">#</th>
                            <th style="padding: 12px;">Option Name</th>
                            <th style="padding: 12px;">Date Added</th>
                            <th style="padding: 12px; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($options as $index => $option)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px; color: #64748b;">{{ $index + 1 }}</td>
                                <td style="padding: 12px; font-weight: 500; color: #1e293b;">
                                    @if($editingOptionId === $option->id)
                                        <input type="text" wire:model="editingOptionName" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                                    @else
                                        {{ $option->option_name }}
                                    @endif
                                </td>
                                <td style="padding: 12px; color: #64748b;">{{ \Carbon\Carbon::parse($option->created_at)->format('M d, Y H:i') }}</td>
                                <td style="padding: 12px; text-align: right;">
                                    @if($editingOptionId === $option->id)
                                        <button type="button" wire:click="updateOption" style="background: #10b981; color: white; border: none; padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">Save</button>
                                        <button type="button" wire:click="cancelEdit" style="background: #64748b; color: white; border: none; padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; margin-left: 4px;">Cancel</button>
                                    @else
                                        <button type="button" wire:click="startEdit({{ $option->id }}, '{{ addslashes($option->option_name) }}')" style="background: #003699; color: white; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; cursor: pointer;" title="Edit"><i class="fa-solid fa-pen"></i></button>
                                        <button type="button" wire:click="deleteOption({{ $option->id }})" wire:confirm="Are you sure you want to delete this option? It will be removed from the dropdown list." style="background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; margin-left: 4px;" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 32px; color: #94a3b8; font-style: italic;">No options found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Side: Add New Option Form -->
        <div style="padding: 20px; background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column; gap: 16px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">Add Option</h3>
            <form wire:submit.prevent="addOption" style="display: flex; flex-direction: column; gap: 12px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">Option Name</label>
                    <input type="text" wire:model="optionName" placeholder="e.g. For Approval" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px;">
                </div>
                <button type="submit" style="background: #003699; color: white; border: none; padding: 10px; border-radius: 6px; font-size: 13.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                    <i class="fa-solid fa-plus"></i> Add New Option
                </button>
            </form>
        </div>
    </div>
</div>
