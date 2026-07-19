<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - Action Options')] class extends Component {
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
        .addon-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: rgba(0, 0, 0, 0.02) 0px 8px 30px;
            border: 1px solid #eef2f6;
            padding: 24px;
        }
        .addon-input {
            width: 100%;
            height: 40px;
            padding: 0 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13.5px;
            color: #334155;
            outline: none;
            background: #ffffff;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .addon-input:focus {
            border-color: #003699;
            box-shadow: 0 0 0 3px rgba(0, 54, 153, 0.1);
        }
        .addon-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 8px;
        }
        .addon-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            border-bottom: 1.5px solid #e2e8f0;
        }
        .addon-table td {
            padding: 14px 16px;
            font-size: 13.5px;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .addon-table tr:hover td {
            background: #f8fafc;
        }
        .addon-btn-primary {
            background: #003699;
            color: #ffffff;
            border: none;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .addon-btn-primary:hover {
            background: #002d80;
            transform: translateY(-1px);
        }
        .addon-btn-edit {
            background: #eff6ff;
            color: #1e40af;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .addon-btn-edit:hover {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .addon-btn-delete {
            background: #fef2f2;
            color: #991b1b;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .addon-btn-delete:hover {
            background: #fee2e2;
            color: #dc2626;
        }
        .addon-btn-save {
            background: #10b981;
            color: #ffffff;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .addon-btn-save:hover {
            background: #059669;
        }
        .addon-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .addon-btn-cancel:hover {
            background: #e2e8f0;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="logs-header-section" style="margin-bottom: 24px;">
        <div class="logs-title-area">
            <h1 class="logs-title-main" style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-puzzle-piece" style="color: #003699;"></i>
                Action Options
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
        <div class="addon-card" style="padding: 20px;">
            <!-- Search Area -->
            <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                <div style="position: relative; flex: 1;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13.5px;"></i>
                    <input type="text" class="addon-input" style="padding-left: 40px;" wire:model.live="search" placeholder="Search options...">
                </div>
            </div>

            <!-- Table -->
            <div style="overflow-x: auto;">
                <table class="addon-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th>Option Name</th>
                            <th>Date Added</th>
                            <th style="text-align: right; width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($options as $index => $option)
                            <tr>
                                <td style="color: #64748b; font-weight: 600;">{{ $index + 1 }}</td>
                                <td style="font-weight: 600; color: #334155;">
                                    @if($editingOptionId === $option->id)
                                        <input type="text" class="addon-input" style="height: 34px; padding: 0 10px; font-size: 13px;" wire:model="editingOptionName">
                                    @else
                                        {{ $option->option_name }}
                                    @endif
                                </td>
                                <td style="color: #64748b;">{{ \Carbon\Carbon::parse($option->created_at)->format('M d, Y H:i') }}</td>
                                <td style="text-align: right; white-space: nowrap;">
                                    @if($editingOptionId === $option->id)
                                        <div style="display: inline-flex; gap: 6px;">
                                            <button type="button" class="addon-btn-save" wire:click="updateOption">Save</button>
                                            <button type="button" class="addon-btn-cancel" wire:click="cancelEdit">Cancel</button>
                                        </div>
                                    @else
                                        <button type="button" class="addon-btn-edit" wire:click="startEdit({{ $option->id }}, '{{ addslashes($option->option_name) }}')" title="Edit">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <button type="button" class="addon-btn-delete" wire:click="deleteOption({{ $option->id }})" wire:confirm="Are you sure you want to delete this option? It will be removed from the dropdown list." title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 48px; color: #94a3b8; font-style: italic;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 32px; display: block; margin-bottom: 12px; color: #cbd5e1;"></i>
                                    No options found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Side: Add New Option Form -->
        <div class="addon-card" style="display: flex; flex-direction: column; gap: 16px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">Add Option</h3>
            <form wire:submit.prevent="addOption" style="display: flex; flex-direction: column; gap: 12px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">Option Name</label>
                    <input type="text" class="addon-input" wire:model="optionName" placeholder="e.g. For Approval">
                </div>
                <button type="submit" class="addon-btn-primary" style="width: 100%;">
                    <i class="fa-solid fa-plus"></i> Add New Option
                </button>
            </form>
        </div>
    </div>
</div>
