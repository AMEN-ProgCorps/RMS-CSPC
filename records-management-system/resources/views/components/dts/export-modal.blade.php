@props([
    'show' => false,
    'category' => 'internal',
    'availableColumns' => [],
    'selectedColumns' => [],
    'format' => 'pdf',
    'selectedCount' => 0,
    'flowCount' => 0,
    'preparedBy' => '',
    'notedBy' => '',
])

@if($show)
@php
    $generalCols = collect($availableColumns)->filter(fn($c) => ($c['group'] ?? 'general') === 'general');
    $additionalCols = collect($availableColumns)->filter(fn($c) => ($c['group'] ?? '') === 'additional');
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
            
            <!-- Selected Records Banner -->
            @if($selectedCount > 0)
                <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; color: #166534; font-size: 12.5px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-circle-check" style="color: #16a34a; font-size: 14px;"></i>
                        <span>Exporting <strong>{{ $selectedCount }}</strong> selected {{ Str::plural('transaction', $selectedCount) }}</span>
                    </div>
                    <span style="font-size: 11px; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 10px; font-weight: 700;">Numbered 1 to {{ $selectedCount }}</span>
                </div>
            @else
                <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; gap: 10px; color: #991b1b; font-size: 12.5px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px; color: #dc2626; flex-shrink: 0;"></i>
                    <div>
                        <strong>No transactions selected!</strong> Please check the checkbox next to at least one transaction on the table before exporting.
                    </div>
                </div>
            @endif

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
                                Excel Spreadsheet (.xls)
                            </div>
                            <div class="dts-export-format-desc">Styled Excel workbook with institutional header, multi-tier flow grouping & auto column widths</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Customize Export Columns -->
            <div class="dts-export-section">
                <div class="dts-export-columns-header">
                    <label class="dts-export-step-label">
                        <span class="dts-export-step-num">2</span>
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

                    <!-- Section B: Dynamic Transaction Flow Steps -->
                    <div class="dts-export-flow1-section" style="background: #f0f9ff; border: 1.5px solid #bae6fd; border-radius: 10px; padding: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 12px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.4px; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-route"></i> Transaction Flow Steps
                                </span>
                                <span style="font-size: 10.5px; font-weight: 700; background: #e0f2fe; color: #0284c7; padding: 2px 8px; border-radius: 12px; border: 1px solid #7dd3fc;">
                                    {{ $flowCount }} {{ Str::plural('Flow', $flowCount) }} Added
                                </span>
                            </div>
                            <button type="button" wire:click="addExportFlow" style="font-size: 11.5px; font-weight: 700; color: #ffffff; background: #0284c7; border: none; border-radius: 6px; padding: 5px 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 1px 3px rgba(2,132,199,0.25); transition: background 0.15s ease;" onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#0284c7'">
                                <i class="fa-solid fa-plus"></i> Add Transaction Flow
                            </button>
                        </div>
                        
                        <p style="margin: 0 0 10px 0; font-size: 11px; color: #0369a1; line-height: 1.4;">
                            Click <strong>Add Transaction Flow</strong> to include destination office routing columns (Office Name, Received, Released, Notes, Elapsed Day). You can add as many flows or remove them as needed.
                        </p>

                        @if($flowCount > 0)
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                @for($f = 1; $f <= $flowCount; $f++)
                                    @php
                                        $fCols = \App\Helpers\DtsExportHelper::getFlowColumnDefinitions($f);
                                        $fKeys = array_keys($fCols);
                                        $fSelectedCount = count(array_intersect($fKeys, $selectedColumns));
                                        $fTitle = ($f === 1) ? 'Flow 1 (not the origin)' : "Flow {$f}";
                                    @endphp
                                    <div style="background: #ffffff; border: 1.5px solid #e0f2fe; border-radius: 8px; padding: 10px 12px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="font-size: 10.5px; font-weight: 800; background: #0284c7; color: #ffffff; padding: 2px 7px; border-radius: 4px;">Flow {{ $f }}</span>
                                                <span style="font-size: 12px; font-weight: 700; color: #0f172a;">{{ $fTitle }}</span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <button type="button" wire:click="toggleFlowStepColumns({{ $f }})" style="font-size: 11px; color: #0284c7; background: none; border: none; cursor: pointer; font-weight: 600; padding: 0;">
                                                    {{ $fSelectedCount === count($fKeys) ? 'Deselect All' : 'Select All' }}
                                                </button>
                                                <span style="color: #cbd5e1;">•</span>
                                                <button type="button" wire:click="removeExportFlow({{ $f }})" style="font-size: 11px; color: #dc2626; background: none; border: none; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; padding: 0;" title="Remove Flow {{ $f }}">
                                                    <i class="fa-solid fa-trash-can"></i> Remove
                                                </button>
                                            </div>
                                        </div>

                                        <div class="dts-export-checkbox-grid nested" style="background: transparent; padding: 0; border: none;">
                                            @foreach($fCols as $colKey => $colDef)
                                                <label class="dts-export-checkbox-item flow1">
                                                    <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}">
                                                    <span class="{{ in_array($colKey, $selectedColumns) ? 'selected' : '' }}">
                                                        {{ $colDef['sublabel'] }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endfor
                            </div>
                        @else
                            <div style="background: #ffffff; border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: 14px; text-align: center; color: #64748b; font-size: 12px;">
                                <i class="fa-solid fa-route" style="font-size: 18px; color: #94a3b8; margin-bottom: 4px; display: block;"></i>
                                <span>No transaction flows added yet. Click <strong>"+ Add Transaction Flow"</strong> above to include destination office routing columns.</span>
                            </div>
                        @endif
                    </div>

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

            <!-- 3. Signatories Options -->
            <div class="dts-export-signatories-section">
                <label class="dts-export-step-label">
                    <span class="dts-export-step-num">3</span>
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

        </div>

        <!-- Modal Footer -->
        <div class="dts-export-modal-footer">
            <button type="button" wire:click="closeExportModal" class="dts-export-btn-cancel">
                Cancel
            </button>
            <button type="button" wire:click="executeExport" class="dts-export-btn-submit" {{ $selectedCount === 0 ? 'disabled' : '' }} style="{{ $selectedCount === 0 ? 'opacity: 0.5; cursor: not-allowed;' : '' }}" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="executeExport" class="dts-export-btn-content">
                    @if($format === 'pdf')
                        <i class="fa-solid fa-print"></i> Generate & Print Report
                    @else
                        <i class="fa-solid fa-file-excel"></i> Download Excel (.xls)
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
