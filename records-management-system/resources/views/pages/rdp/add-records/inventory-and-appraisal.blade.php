<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and title attributes
new #[Layout('layouts.rdp')] #[Title('Inventory and Appraisal')] class extends Component {
    // Component logic can be added here in the future
};
?>

@push('styles')
    @vite(['resources/css/rdp/inventory-and-appraisal.css'])
@endpush

<div class="rms-container">
    <!-- Header Section -->
    <div class="rms-header">
        <h2>Add Record</h2>
    </div>

    <div class="record-series-stack">
        <!-- ===== RECTANGLE 254: "Record Series:" on the left ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Record Series:</div>
            <div class="record-series-content">
            </div>
        </div>

        <!-- ===== DROPDOWN IN THE MIDDLE OF THE STACK ===== -->
        <div class="record-series-rect">
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="series1">PLACEHOLDER 1</option>
                    <option value="series2">PLACEHOLDER 2</option>
                    <option value="series3">PLACEHOLDER 3</option>
                </select>
            </div>
        </div>
        <!-- ===== RECTANGLE 254: "Description:" below ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Description:</div>
            <div class="record-series-content">
            </div>
        </div>

        <!-- ===== DROPDOWN BENEATH DESCRIPTION (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="desc1">PLACEHOLDER 1</option>
                    <option value="desc2">PLACEHOLDER 2</option>
                    <option value="desc3">PLACEHOLDER 3</option>
                </select>
            </div>
        </div>
        
        <!-- ===== RECTANGLE: Period Covered / Inclusive Dates (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Period Covered/Inclusive Dates:</div>
            <div class="record-series-content">e.g., 2020-2025</div>
        </div>

        <!-- ===== RECTANGLE: Volume (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Volume:</div>
            <div class="record-series-content">e.g., 5 boxes, 2.5 cubic meter</div>
        </div>

        <!-- ===== Seven dropdown rows (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Records Medium:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="paper">Paper</option>
                    <option value="digital">Digital</option>
                    <option value="mixed">Mixed</option>
                </select>
            </div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Restriction/s:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="confidential">Confidential</option>
                    <option value="public">Public</option>
                    <option value="internal">Internal</option>
                </select>
            </div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Location of Records:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="onsite">On-site</option>
                    <option value="offsite">Off-site</option>
                    <option value="cloud">Cloud</option>
                </select>
            </div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Frequency of Use:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="daily">Daily</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Duplication:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="none">None</option>
                    <option value="partial">Partial</option>
                    <option value="full">Full</option>
                </select>
            </div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Time Value:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Utility Value:</div>
            <div class="record-series-content">
                <select class="record-series-dropdown">
                    <option value="" selected disabled></option>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
        </div>

        <!-- ===== Final non-dropdown rows (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Retention Period:</div>
            <div class="record-series-content"></div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Disposition Provision:</div>
            <div class="record-series-content"></div>
        </div>
    </div>
    
    <!-- Action Buttons (right side, left-shifted) -->
    <div class="actions-row">
        <button type="button" class="btn btn-primary" data-icon="zondicons:view-show">
            <span class="action-icon" aria-hidden></span>
            VIEW
        </button>

        <button type="button" class="btn btn-primary" data-icon="material-symbols:upload">
            <span class="action-icon" aria-hidden></span>
            UPLOAD FILE
        </button>

        <button type="button" class="btn btn-primary" data-icon="lucide:check-line">
            <span class="action-icon" aria-hidden></span>
            SAVE DRAFT
        </button>

        <button type="button" class="btn btn-primary" data-icon="gridicons:create">
            <span class="action-icon" aria-hidden></span>
            CREATE RECORD
        </button>
    </div>
</div>