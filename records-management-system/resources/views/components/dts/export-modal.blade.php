@props([
    'show' => false,
    'category' => 'internal',
    'availableColumns' => [],
    'selectedColumns' => [],
    'format' => 'pdf',
    'scope' => 'all',
    'selectedCount' => 0,
    'totalCount' => 0,
    'preparedBy' => '',
    'notedBy' => '',
])

@if($show)
@php
    $generalCols = collect($availableColumns)->filter(fn($c) => ($c['group'] ?? 'general') === 'general');
    $flow1Cols = collect($availableColumns)->filter(fn($c) => ($c['group'] ?? '') === 'flow1');
    $additionalCols = collect($availableColumns)->filter(fn($c) => ($c['group'] ?? '') === 'additional');
    
    $flow1Keys = $flow1Cols->keys()->toArray();
    $selectedFlow1Count = count(array_intersect($flow1Keys, $selectedColumns));
@endphp
<div class="dts-export-modal-backdrop" onclick="event.stopPropagation()">
    <div class="dts-export-modal-card" onclick="event.stopPropagation()">
        
        <!-- Modal Header -->
        <div class="dts-export-modal-header">
            <div class="dts-export-modal-header-left">
                <div class="dts-export-modal-header-icon">
                    <i class="fa-solid fa-file-export"></i>
                </div>
                <div>
                    <h3 class="dts-export-modal-header-title">Export Transactions List</h3>
                    <div class="dts-export-modal-header-sub">
                        <i class="fa-solid fa-folder-open"></i>
                        <span>{{ \App\Helpers\DtsExportHelper::getCategoryTitle($category) }}</span>
                    </div>
                </div>
            </div>
            <button type="button" class="dts-export-modal-close-btn" wire:click="closeExportModal" title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div class="dts-export-modal-body">
            
            <!-- 1. Format Selection -->
            <div class="dts-export-section">
                <label class="dts-export-step-label">
                    <span class="dts-export-step-num">1</span>
                    Select Export Format
                </label>
                <div class="dts-export-format-grid">
                    <!-- PDF Option -->
                    <label class="dts-export-format-card {{ $format === 'pdf' ? 'active' : '' }}">
                        <input type="radio" name="exportFormat" value="pdf" wire:model.live="exportFormat">
                        <div class="dts-export-format-info">
                            <div class="dts-export-format-title">
                                <span class="dts-format-badge-pdf">
                                    <i class="fa-solid fa-file-pdf"></i>
                                </span>
                                PDF / Print Report
                            </div>
                            <div class="dts-export-format-desc">Official printable letterhead with signatories & structured flow headers</div>
                        </div>
                    </label>

                    <!-- Excel Option -->
                    <label class="dts-export-format-card {{ $format === 'excel' ? 'active' : '' }}">
                        <input type="radio" name="exportFormat" value="excel" wire:model.live="exportFormat">
                        <div class="dts-export-format-info">
                            <div class="dts-export-format-title">
                                <span class="dts-format-badge-excel">
                                    <i class="fa-solid fa-file-excel"></i>
                                </span>
                                Excel Spreadsheet
                            </div>
                            <div class="dts-export-format-desc">Universal .csv format ready for MS Excel analysis & archiving</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Scope Selection -->
            <div class="dts-export-section">
                <label class="dts-export-step-label">
                    <span class="dts-export-step-num">2</span>
                    Select Records Scope
                </label>
                <div class="dts-export-scope-grid">
                    <!-- All Filtered Records -->
                    <label class="dts-export-scope-card {{ $scope === 'all' ? 'active' : '' }}">
                        <div class="dts-export-scope-left">
                            <input type="radio" name="exportScope" value="all" wire:model.live="exportScope">
                            <span class="dts-export-scope-name">All Filtered Records</span>
                        </div>
                        <span class="dts-export-count-pill primary">
                            {{ $totalCount }} records
                        </span>
                    </label>

                    <!-- Selected Records Only -->
                    <label class="dts-export-scope-card {{ $scope === 'selected' ? 'active' : '' }} {{ $selectedCount === 0 ? 'disabled' : '' }}">
                        <div class="dts-export-scope-left">
                            <input type="radio" name="exportScope" value="selected" wire:model.live="exportScope" {{ $selectedCount === 0 ? 'disabled' : '' }}>
                            <div>
                                <span class="dts-export-scope-name">Selected Records Only</span>
                                @if($selectedCount === 0)
                                    <div class="dts-export-scope-warning">(No table rows selected)</div>
                                @endif
                            </div>
                        </div>
                        <span class="dts-export-count-pill success">
                            {{ $selectedCount }} selected
                        </span>
                    </label>
                </div>
            </div>

            <!-- 3. Customize Export Columns -->
            <div class="dts-export-section">
                <div class="dts-export-columns-header">
                    <label class="dts-export-step-label">
                        <span class="dts-export-step-num">3</span>
                        Customize Included Columns
                    </label>
                    <div class="dts-export-columns-actions">
                        <button type="button" wire:click="selectAllExportColumns" class="dts-export-action-btn primary">Select All</button>
                        <span class="dts-export-action-bullet">•</span>
                        <button type="button" wire:click="resetDefaultExportColumns" class="dts-export-action-btn">Reset Defaults</button>
                        <span class="dts-export-action-bullet">•</span>
                        <button type="button" wire:click="deselectAllExportColumns" class="dts-export-action-btn muted">Clear All</button>
                    </div>
                </div>

                <div class="dts-export-columns-groups">
                    
                    <!-- Section A: General Transaction Information (Default Included) -->
                    <div class="dts-export-col-section">
                        <div class="dts-export-col-title">
                            <i class="fa-solid fa-circle-info"></i> General Transaction Information
                        </div>
                        <div class="dts-export-checkbox-grid">
                            @foreach($generalCols as $colKey => $colDef)
                                <label class="dts-export-checkbox-item">
                                    <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}">
                                    <span class="{{ in_array($colKey, $selectedColumns) ? 'selected' : '' }}" title="{{ $colDef['label'] }}">
                                        {{ $colDef['label'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <!-- Section B: Flow 1 Routing Steps (Not Origin) -->
                    @if($flow1Cols->isNotEmpty())
                        <div class="dts-export-flow1-section">
                            <div class="dts-export-flow1-header">
                                <div class="dts-export-flow1-title-group">
                                    <span class="dts-export-flow1-title">
                                        <i class="fa-solid fa-route"></i> Flow 1 (not the origin)
                                    </span>
                                    <span class="dts-export-flow1-badge">Forwarded Step 1</span>
                                </div>
                                <button type="button" wire:click="toggleFlow1ExportColumns" class="dts-export-flow1-btn">
                                    @if($selectedFlow1Count === count($flow1Keys))
                                        <i class="fa-solid fa-check-double"></i> Exclude Flow 1
                                    @else
                                        <i class="fa-solid fa-plus"></i> Include Flow 1 Columns
                                    @endif
                                </button>
                            </div>
                            
                            <p class="dts-export-flow1-desc">
                                Adds dedicated columns for the 1st destination office: <strong>Office Name</strong>, <strong>Received (Date/Time & Person)</strong>, <strong>Released (Date/Time & Person)</strong>, and <strong>Elapsed Day (Duration)</strong>.
                            </p>

                            <div class="dts-export-checkbox-grid nested">
                                @foreach($flow1Cols as $colKey => $colDef)
                                    <label class="dts-export-checkbox-item flow1">
                                        <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}">
                                        <span class="{{ in_array($colKey, $selectedColumns) ? 'selected' : '' }}">
                                            {{ $colDef['sublabel'] ?? $colDef['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Section C: Additional Metadata -->
                    @if($additionalCols->isNotEmpty())
                        <div class="dts-export-col-section additional">
                            <div class="dts-export-col-title">
                                <i class="fa-solid fa-list-check"></i> Secondary Fields & Activity
                            </div>
                            <div class="dts-export-checkbox-grid">
                                @foreach($additionalCols as $colKey => $colDef)
                                    <label class="dts-export-checkbox-item">
                                        <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}">
                                        <span class="{{ in_array($colKey, $selectedColumns) ? 'selected' : '' }}" title="{{ $colDef['label'] }}">
                                            {{ $colDef['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                </div>
            </div>

            <!-- 4. Signatories Options (for PDF / Printable Report) -->
            @if($format === 'pdf')
                <div class="dts-export-signatories-section">
                    <label class="dts-export-step-label">
                        <span class="dts-export-step-num">4</span>
                        Report Signatories Configuration
                    </label>
                    <div class="dts-export-signatories-grid">
                        <div class="dts-export-signatory-col">
                            <span class="dts-export-signatory-label">Prepared by (Name):</span>
                            <input type="text" wire:model="exportPreparedBy" placeholder="e.g. John Doe" class="dts-export-input">
                        </div>
                        <div class="dts-export-signatory-col">
                            <span class="dts-export-signatory-label">Noted / Approved by:</span>
                            <input type="text" wire:model="exportNotedBy" placeholder="e.g. Head of Office / Dean" class="dts-export-input">
                        </div>
                    </div>
                </div>
            @endif

        </div>

        <!-- Modal Footer -->
        <div class="dts-export-modal-footer">
            <button type="button" wire:click="closeExportModal" class="dts-export-btn-cancel">
                Cancel
            </button>
            <button type="button" wire:click="executeExport" class="dts-export-btn-submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="executeExport" class="dts-export-btn-content">
                    @if($format === 'pdf')
                        <i class="fa-solid fa-print"></i> Generate & Print Report
                    @else
                        <i class="fa-solid fa-file-excel"></i> Download Excel (.csv)
                    @endif
                </span>
                <span wire:loading wire:target="executeExport" class="dts-export-btn-content">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> Preparing Export...
                </span>
            </button>
        </div>

    </div>
</div>
@endif
