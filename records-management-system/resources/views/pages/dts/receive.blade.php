<?php
/**
 * Document Tracking System - Receive Transactions Volt Component
 * 
 * This component provides an interface for receiving transactions in the Document
 * Tracking System. It handles display, editing of metadata (control numbers, file
 * codes, and particulars), and list manipulation of transaction paths.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Receive Transactions')] class extends Component {
    /** @var string Holds the transaction control number */
    public string $controlNumber = 'CTRL-2023-00142';

    /** @var string Holds the file code */
    public string $fileCode = 'FC-ADM-2023-089';

    /** @var string Holds the particulars description text */
    public string $particulars = '';

    /** @var bool Flag indicating whether the Control Number is being edited */
    public bool $editingControl = false;

    /** @var bool Flag indicating whether the File Code is being edited */
    public bool $editingFileCode = false;

    /** @var bool Flag indicating whether the Particulars description is being edited */
    public bool $editingParticulars = false;

    /** 
     * @var array<int, array<string, mixed>> 
     * Holds the sequence of offices and transactions representing the route path
     */
    public array $transactionPath = [
        [
            'office' => 'office1',
            'date_in' => '2023-12-11 10:36:50',
            'date_out' => '2023-12-11 02:15:30',
            'action_needed' => 'Created',
            'notes' => '',
        ],
        [
            'office' => 'office2',
            'date_in' => '2023-12-11 10:36:50',
            'date_out' => '2023-12-11 02:15:30',
            'action_needed' => 'Forwarded',
            'notes' => '',
        ],
    ];

    /**
     * Handles contextual select actions triggered on individual path rows.
     * 
     * @param int $index The index of the path step
     * @param string $action The key of the selected action (e.g. 'delete')
     */
    public function handleRowAction(int $index, string $action): void
    {
        if ($action === '') {
            return;
        }

        match ($action) {
            'delete' => $this->removePathStep($index),
            default => null,
        };
    }

    /**
     * Deletes a step from the transaction route path array.
     * Re-indexes the array keys to preserve ordering.
     * 
     * @param int $index The index of the path step to delete
     */
    public function removePathStep(int $index): void
    {
        if (! isset($this->transactionPath[$index])) {
            return;
        }

        unset($this->transactionPath[$index]);
        $this->transactionPath = array_values($this->transactionPath);
    }

    /**
     * Switches the selected field (control, file_code, particulars) into edit mode.
     * 
     * @param string $field The field identifier to toggle editing state for
     */
    public function startEdit(string $field): void
    {
        $this->editingControl = $field === 'control';
        $this->editingFileCode = $field === 'file_code';
        $this->editingParticulars = $field === 'particulars';
    }

    /**
     * Saves changes and exits edit mode for the target field.
     * 
     * @param string $field The field identifier to toggle save state for
     */
    public function saveField(string $field): void
    {
        match ($field) {
            'control' => $this->editingControl = false,
            'file_code' => $this->editingFileCode = false,
            'particulars' => $this->editingParticulars = false,
            default => null,
        };
    }
};
?>

@push('styles')
    @vite('resources/css/dts/receive.css')
@endpush
<div class="receive-page">
    <form class="receive-card" method="post" action="#" onsubmit="return false;">
        <h1 class="receive-title">Receive Transactions</h1>
        
        <div class="receive-fields">
            <!-- Control Number field -->
            <div class="receive-field-row">
                <span class="receive-field-label">Control #:</span>
                @if ($editingControl)
                    <div style="display: flex; gap: 8px; width: 100%;">
                        <input type="text" class="receive-field-input" wire:model="controlNumber">
                        <div class="receive-field-actions">
                            <button type="button" wire:click="saveField('control')">Save</button>
                        </div>
                    </div>
                @else
                    <input type="text" class="receive-field-input" value="{{ $controlNumber }}" readonly>
                    <div class="receive-field-actions">
                        <button type="button" wire:click="startEdit('control')">Update</button>
                        <span>|</span>
                        <button type="button" wire:click="startEdit('control')">Edit</button>
                    </div>
                @endif
            </div>

            <!-- File Code field -->
            <div class="receive-field-row">
                <span class="receive-field-label">File Code:</span>
                @if ($editingFileCode)
                    <div style="display: flex; gap: 8px; width: 100%;">
                        <input type="text" class="receive-field-input" wire:model="fileCode">
                        <div class="receive-field-actions">
                            <button type="button" wire:click="saveField('file_code')">Save</button>
                        </div>
                    </div>
                @else
                    <input type="text" class="receive-field-input" value="{{ $fileCode }}" readonly>
                    <div class="receive-field-actions">
                        <button type="button" wire:click="startEdit('file_code')">Update</button>
                        <span>|</span>
                        <button type="button" wire:click="startEdit('file_code')">Edit</button>
                    </div>
                @endif
            </div>

            <!-- Particulars field -->
            <div class="receive-field-row receive-field-row--particulars">
                <span class="receive-field-label">Particulars:</span>
                @if ($editingParticulars)
                    <div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">
                        <textarea class="receive-field-input" wire:model="particulars" style="min-height: 72px; resize: vertical;"></textarea>
                        <div class="receive-field-actions" style="justify-content: flex-end;">
                            <button type="button" wire:click="saveField('particulars')">Save</button>
                        </div>
                    </div>
                @else
                    <div class="receive-particulars-display" wire:click="startEdit('particulars')" style="cursor: pointer;">
                        {{ $particulars ?: 'Click to add particulars...' }}
                    </div>
                @endif
            </div>
        </div>

        <hr class="receive-divider">

        <!-- Transaction Path Section -->
        <h2 class="receive-title" style="font-size: 16px; margin-top: 10px;">Transaction Path</h2>

        <!-- Table Section -->
        <div class="receive-table-wrap">
            <table class="receive-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Office</th>
                        <th>Date In</th>
                        <th>Date Out</th>
                        <th>Action Need</th>
                        <th>Notes</th>
                        <th>Info</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactionPath as $index => $step)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td class="office-cell">{{ $step['office'] }}</td>
                            <td>{{ $step['date_in'] }}</td>
                            <td>{{ $step['date_out'] }}</td>
                            <td>{{ $step['action_needed'] }}</td>
                            <td>{{ $step['notes'] }}</td>
                            <td>
                                <button type="button" class="receive-info-btn">
                                    <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px; stroke: currentColor;">
                                        <circle cx="12" cy="12" r="10" stroke-width="1.5" fill="none"/>
                                        <circle cx="12" cy="12" r="2" fill="currentColor"/>
                                        <circle cx="17" cy="12" r="2" fill="currentColor"/>
                                        <circle cx="7" cy="12" r="2" fill="currentColor"/>
                                    </svg>
                                </button>
                            </td>
                            <td class="action-cell">
                                <select class="receive-row-action-select" wire:change="handleRowAction({{ $index }}, $event.target.value)">
                                    <option value="">Select Action</option>
                                    <option value="delete">Delete</option>
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding: 24px; color: #888; font-style: italic;">No transaction paths listed.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Buttons Section -->
        <div class="receive-actions">
            <!-- VIEW LISTED PATH -->
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                VIEW LISTED PATH
            </button>

            <!-- COMPLETED -->
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                COMPLETED
            </button>

            <!-- EDIT -->
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 3l4 4-7 7H10v-4l7-7z"/>
                    <path d="M4 20h16"/>
                </svg>
                EDIT
            </button>

            <!-- DELETE -->
            <button type="button" class="receive-action-btn receive-action-btn--danger">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
                DELETE
            </button>

            <!-- ADD CF -->
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                ADD CF
            </button>

            <!-- BARCODE -->
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 5h2v14H3zM7 5h2v14H7zM11 5h2v14h-2zM15 5h2v14h-2zM19 5h2v14h-2z"/>
                </svg>
                BARCODE
            </button>
        </div>
    </form>
</div>