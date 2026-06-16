<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Receive Transactions')] class extends Component {
    public string $controlNumber = 'CTRL-2023-00142';

    public string $fileCode = 'FC-ADM-2023-089';

    public string $particulars = '';

    public bool $editingControl = false;

    public bool $editingFileCode = false;

    public bool $editingParticulars = false;

    /** @var array<int, array<string, mixed>> */
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

    public function removePathStep(int $index): void
    {
        if (! isset($this->transactionPath[$index])) {
            return;
        }

        unset($this->transactionPath[$index]);
        $this->transactionPath = array_values($this->transactionPath);
    }

    public function startEdit(string $field): void
    {
        $this->editingControl = $field === 'control';
        $this->editingFileCode = $field === 'file_code';
        $this->editingParticulars = $field === 'particulars';
    }

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
    @vite(['resources/css/dts/receive.css'])
@endpush
<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Receive Transactions</h2>
    </div>

    <!-- Internal Transaction Form -->
    <form class="rms-form" method="post" action="#" onsubmit="return false;">
        
        <!-- Control Number and File Code side by side in one row -->
        <div class="two-columns-row">
            <!-- Control Number field (LEFT) -->
            <div class="form-col medium-input">
                <label for="unit-college" class="input-label">Control #:</label>
                <div style="display:flex; align-items:center; gap:8px; width: 100%;">
                    <input type="text" class="text-input unit-college-input" placeholder="" id="unit-college">
                    <a href="#" class="muted-link">Update | Edit</a>
                </div>
            </div>

            <!-- File Code field (RIGHT) -->
            <div class="form-col medium-input">
                <label for="file-code" class="input-label">File Code:</label>
                <div style="display:flex; align-items:center; gap:8px; width: 100%;">
                    <input type="text" class="text-input unit-college-input" placeholder="" id="file-code">
                    <a href="#" class="muted-link">Update | Edit</a>
                </div>
            </div>
        </div>

        <!-- PARTICULARS SECTION: Heading + Line below it with generous spacing -->
        <div class="particulars-section">
            <div class="particulars-heading">Particulars:</div>
            <!-- Extra spacing container for the line -->
            <div class="line-container">
                <div class="separator-line"></div>
            </div>
        </div>

        <!-- TRANSACTION PATH SECTION: Heading only (table name) -->
        <div class="transaction-path-section">
            <div class="transaction-path-heading">Transaction Path</div>
        </div>

        <!-- TABLE SECTION with empty rows and icon inside Info column + blank column -->
        <div class="rms-table-responsive">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Office</th>
                        <th>Date In</th>
                        <th>Date Out</th>
                        <th>Action Need</th>
                        <th>Notes</th>
                        <th>Info</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Row 1 - Empty fields, icon in Info column, blank column -->
                    <tr>
                        <td>1</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>
                            <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="#1e3a8a" stroke-width="1.5" fill="white"/>
                                <circle cx="12" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="17" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="7" cy="12" r="2" fill="#1e3a8a"/>
                            </svg>
                        </td>
                        <td></td>
                    </tr>
                    <!-- Row 2 - Empty fields, icon in Info column, blank column -->
                    <tr>
                        <td>2</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>
                            <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="#1e3a8a" stroke-width="1.5" fill="white"/>
                                <circle cx="12" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="17" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="7" cy="12" r="2" fill="#1e3a8a"/>
                            </svg>
                        </td>
                        <td></td>
                    </tr>
                    <!-- Row 3 - Empty fields, icon in Info column, blank column -->
                    <tr>
                        <td>3</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>
                            <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="#1e3a8a" stroke-width="1.5" fill="white"/>
                                <circle cx="12" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="17" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="7" cy="12" r="2" fill="#1e3a8a"/>
                            </svg>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
             </table>
        </div>

        <!-- BUTTONS SECTION - arranged from LEFT to RIGHT, aligned to the RIGHT side -->
        <div class="actions-row">
            <!-- VIEW LISTED PATH -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                VIEW LISTED PATH
            </button>

            <!-- COMPLETED -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                COMPLETED
            </button>

            <!-- EDIT -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 3l4 4-7 7H10v-4l7-7z"/>
                    <path d="M4 20h16"/>
                </svg>
                EDIT
            </button>

            <!-- DELETE -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
                DELETE
            </button>

            <!-- ADD CF -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                ADD CF
            </button>

            <!-- BARCODE -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 5h2v14H3zM7 5h2v14H7zM11 5h2v14h-2zM15 5h2v14h-2zM19 5h2v14h-2z"/>
                </svg>
                BARCODE
            </button>
        </div>
    </form>

</div>