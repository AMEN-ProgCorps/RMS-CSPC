<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - List External Transactions')] class extends Component {
    //
};
?>

<div class="rms-container">
    <style>
        .rms-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); margin: 20px; padding: 24px; color: #333333; }
        .rms-header { margin-bottom: 24px; border-bottom: 1px solid #e9ecef; padding-bottom: 12px; }
        .rms-header h2 { font-size: 1.15rem; color: #1e40af; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
        .rms-toolbar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; flex-direction: column; }
        .rms-toolbar-top, .rms-toolbar-bottom { display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .rms-filters { display: flex; gap: 12px; }
        .rms-select { padding: 6px 32px 6px 12px; font-size: 0.85rem; color: #495057; background-color: #fff; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer; outline: none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 12px center; background-size: 12px 12px; -webkit-appearance: none; appearance: none; }
        .rms-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
        .rms-entries { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #6c757d; }
        .rms-entries select { width: 60px; padding-right: 20px; }
        .rms-actions { display: flex; align-items: center; gap: 12px; }
        .rms-search-wrapper { position: relative; }
        .rms-search-input { padding: 6px 12px 6px 32px; font-size: 0.85rem; border: 1px solid #ced4da; border-radius: 4px; width: 200px; outline: none; }
        .rms-search-wrapper::before { content: ""; position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 12px; height: 12px; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%236c757d'%3e%3cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z'/%3e%3c/svg%3e"); background-repeat: no-repeat; background-size: contain; }
        .rms-btn-print { background-color: #1d4ed8; color: #ffffff; border: none; padding: 6px 20px; font-size: 0.8rem; font-weight: 600; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 6px; text-transform: uppercase; box-shadow: 0 2px 4px rgba(29, 78, 216, 0.2); transition: background-color 0.2s ease; }
        .rms-btn-print:hover { background-color: #1e40af; }
        .btn-icon { width: 14px; height: 14px; fill: currentColor; }
        .rms-table-responsive { width: 100%; overflow-x: auto; border: 1px solid #dee2e6; border-radius: 4px; }
        .rms-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.825rem; min-width: 1200px; }
        .rms-table th { background-color: #f8f9fa; color: #212529; font-weight: 700; padding: 12px 10px; border-bottom: 2px solid #dee2e6; border-right: 1px solid #dee2e6; text-align: center; vertical-align: middle; }
        .rms-table th:last-child { border-right: none; }
        .rms-table td { padding: 12px 10px; border-bottom: 1px solid #dee2e6; border-right: 1px solid #dee2e6; color: #495057; vertical-align: middle; }
        .rms-table td:last-child { border-right: none; text-align: center; }
        .rms-no-data { text-align: center !important; color: #868e96; font-style: italic; padding: 24px !important; background-color: #fdfdfd; }
    </style>

    <div class="rms-header">
        <h2>Internal Transaction</h2>
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