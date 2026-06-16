<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and title attributes
new #[Layout('layouts.rdp')] #[Title('Records and Disposition Schedule')] class extends Component {
    // Component logic can be added here in the future
};
?>

@push('styles')
    @vite(['resources/css/rdp/records-and-disposition-schedule.css'])
@endpush

<div class="rms-container">
    <!-- Header Section -->
    <div class="rms-header">
        <h2>Add Record</h2>
    </div>

    <div class="record-series-stack">
        <!-- ===== RECTANGLE 254: "Item No." on the left ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Item No.</div>
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
            <div class="record-series-label">Record Series:</div>
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
            <div class="record-series-label">Title/Description:</div>
        </div>

        <!-- ===== RECTANGLE: Volume (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Active:</div>
            <div class="record-series-content">e.g., 5 boxes, 2.5 cubic meter</div>
        </div>


        <!-- ===== Final non-dropdown rows (attached) ===== -->
        <div class="record-series-rect">
            <div class="record-series-label">Storage:</div>
            <div class="record-series-content"></div>
        </div>

        <div class="record-series-rect">
            <div class="record-series-label">Total:</div>
            <div class="record-series-content"></div>
        </div>
        <!-- ===== REMARKS: attached, same thickness, wide textarea ===== -->
        <div class="record-series-rect remarks-row">
            <div class="record-series-label">Remarks:</div>
            <div class="record-series-content">
                    <div class="record-series-remarks" role="note" aria-live="polite"></div>
            </div>
        </div>
    </div>
    
    <!-- Action Buttons (right side, left-shifted) -->
    <div class="actions-row">
        <button type="button" class="btn btn-primary" data-icon="zondicons:view-show">
            <span class="action-icon" aria-hidden></span>
            VIEW
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