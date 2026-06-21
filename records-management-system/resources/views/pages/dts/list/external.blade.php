<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - List External Transactions')] class extends Component {
    //
};
?>
@push('styles')
    @vite('resources/css/dts/list_transaction.css')
@endpush

<div class="rms-container">
    <div class="rms-header">
        <h2>External Transaction</h2>
    </div>

    <div class="rms-toolbar">
        <div class="rms-toolbar-top">
            <div class="rms-filters">
                <select class="rms-select"><option value="all">All Priority</option><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select>
                <select class="rms-select"><option value="all">All Status</option><option value="pending">Pending</option><option value="in_process">In Process</option><option value="completed">Completed</option></select>
            </div>
            <button type="button" class="rms-btn-print">
                <svg class="btn-icon" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 9h6v2a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-2zm7-5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg> Print
            </button>
        </div>
        <div class="rms-toolbar-bottom">
            <div class="rms-entries">
                Show 
                <select class="rms-select">
                    <option value="10">10</option><option value="25">25</option><option value="50">50</option>
                </select> 
                Entries
            </div>
            <div class="rms-actions">
                <div class="rms-search-wrapper"><input type="text" class="rms-search-input" placeholder="Search..."></div>
            </div>
        </div>
    </div>

    <div class="rms-table-responsive">
        <table class="rms-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Item No.</th>
                    <th>Control Number</th>
                    <th>Barcode</th>
                    <th>Source</th>
                    <th>Subject</th>
                    <th>Type of Document</th>
                    <th>Date Created</th>
                    <th>Current Location</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Remarks</th>
                    <th>Received by</th>
                    <th style="width: 60px;">View</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="13" class="rms-no-data">No transactions found.</td></tr>
            </tbody>
        </table>
    </div>
</div>