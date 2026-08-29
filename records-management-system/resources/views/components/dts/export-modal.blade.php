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
<div class="dts-export-modal-backdrop" style="position: fixed; inset: 0; z-index: 999999; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 16px;">
    <div class="dts-export-modal-card" style="background: #ffffff; width: 100%; max-width: 620px; max-height: 90vh; border-radius: 14px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; font-family: 'Inter', system-ui, sans-serif;" onclick="event.stopPropagation()">
        
        <!-- Modal Header -->
        <div style="padding: 16px 22px; background: #f8fafc; border-bottom: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 34px; height: 34px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 15px;">
                    <i class="fa-solid fa-file-export"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">Export Transactions List</h3>
                    <span style="font-size: 11.5px; color: #64748b;">{{ \App\Helpers\DtsExportHelper::getCategoryTitle($category) }}</span>
                </div>
            </div>
            <button type="button" wire:click="closeExportModal" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='transparent'">&times;</button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div style="padding: 20px 22px; overflow-y: auto; display: flex; flex-direction: column; gap: 18px;">
            
            <!-- 1. Format Selection -->
            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px;">
                    1. Select Export Format
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- PDF Option -->
                    <label style="display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 8px; border: 1.5px solid {{ $format === 'pdf' ? '#1e40af' : '#cbd5e1' }}; background: {{ $format === 'pdf' ? '#f0f6ff' : '#ffffff' }}; cursor: pointer; transition: all 0.15s ease;">
                        <input type="radio" name="exportFormat" value="pdf" wire:model.live="exportFormat" style="accent-color: #1e40af; width: 16px; height: 16px; cursor: pointer;">
                        <div>
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-file-pdf" style="color: #dc2626;"></i> PDF / Print Report
                            </div>
                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">Institutional letterhead preview</div>
                        </div>
                    </label>

                    <!-- Excel Option -->
                    <label style="display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 8px; border: 1.5px solid {{ $format === 'excel' ? '#1e40af' : '#cbd5e1' }}; background: {{ $format === 'excel' ? '#f0f6ff' : '#ffffff' }}; cursor: pointer; transition: all 0.15s ease;">
                        <input type="radio" name="exportFormat" value="excel" wire:model.live="exportFormat" style="accent-color: #1e40af; width: 16px; height: 16px; cursor: pointer;">
                        <div>
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-file-excel" style="color: #16a34a;"></i> Excel Spreadsheet
                            </div>
                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">.csv format for MS Excel</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Scope Selection -->
            <div>
                <label style="display: block; font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px;">
                    2. Select Records Scope
                </label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <!-- All Filtered Records -->
                    <label style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 8px; border: 1.5px solid {{ $scope === 'all' ? '#1e40af' : '#e2e8f0' }}; background: {{ $scope === 'all' ? '#f0f6ff' : '#ffffff' }}; cursor: pointer;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="radio" name="exportScope" value="all" wire:model.live="exportScope" style="accent-color: #1e40af; width: 16px; height: 16px; cursor: pointer;">
                            <span style="font-size: 12.5px; font-weight: 600; color: #1e293b;">All Filtered Records</span>
                        </div>
                        <span style="font-size: 11.5px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 8px; border-radius: 12px;">{{ $totalCount }} records</span>
                    </label>

                    <!-- Selected Records Only -->
                    <label style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 8px; border: 1.5px solid {{ $scope === 'selected' ? '#1e40af' : '#e2e8f0' }}; background: {{ $scope === 'selected' ? '#f0f6ff' : '#ffffff' }}; opacity: {{ $selectedCount > 0 ? '1' : '0.6' }}; cursor: {{ $selectedCount > 0 ? 'pointer' : 'not-allowed' }};">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="radio" name="exportScope" value="selected" wire:model.live="exportScope" {{ $selectedCount === 0 ? 'disabled' : '' }} style="accent-color: #1e40af; width: 16px; height: 16px; cursor: {{ $selectedCount > 0 ? 'pointer' : 'not-allowed' }};">
                            <div>
                                <span style="font-size: 12.5px; font-weight: 600; color: #1e293b;">Selected Records Only</span>
                                @if($selectedCount === 0)
                                    <span style="font-size: 10.5px; color: #dc2626; margin-left: 6px;">(No checkboxes selected on table)</span>
                                @endif
                            </div>
                        </div>
                        <span style="font-size: 11.5px; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 2px 8px; border-radius: 12px;">{{ $selectedCount }} selected</span>
                    </label>
                </div>
            </div>

            <!-- 3. Customize Export Columns -->
            <div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <label style="font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.4px; margin: 0;">
                        3. Customize Included Columns
                    </label>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" wire:click="selectAllExportColumns" style="font-size: 11px; color: #1e40af; font-weight: 600; background: none; border: none; cursor: pointer; padding: 0;">Select All</button>
                        <span style="color: #cbd5e1;">|</span>
                        <button type="button" wire:click="resetDefaultExportColumns" style="font-size: 11px; color: #64748b; font-weight: 600; background: none; border: none; cursor: pointer; padding: 0;">Reset Defaults</button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 8px; max-height: 160px; overflow-y: auto;">
                    @foreach($availableColumns as $colKey => $colDef)
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: #334155; cursor: pointer; user-select: none;">
                            <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}" style="accent-color: #1e40af; width: 14px; height: 14px; cursor: pointer;">
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ $colDef['label'] }}">{{ $colDef['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- 4. Signatories Options (for PDF / Printable Report) -->
            @if($format === 'pdf')
                <div style="border-top: 1px solid #f1f5f9; padding-top: 14px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px;">
                        4. Report Signatories
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <span style="display: block; font-size: 11px; color: #64748b; font-weight: 600; margin-bottom: 4px;">Prepared by (Name):</span>
                            <input type="text" wire:model="exportPreparedBy" placeholder="e.g. John Doe" class="rms-select" style="width: 100%; height: 32px; background-image: none; font-size: 12px; padding: 4px 8px;">
                        </div>
                        <div>
                            <span style="display: block; font-size: 11px; color: #64748b; font-weight: 600; margin-bottom: 4px;">Noted / Approved by:</span>
                            <input type="text" wire:model="exportNotedBy" placeholder="e.g. Office Head" class="rms-select" style="width: 100%; height: 32px; background-image: none; font-size: 12px; padding: 4px 8px;">
                        </div>
                    </div>
                </div>
            @endif

        </div>

        <!-- Modal Footer -->
        <div style="padding: 14px 22px; background: #f8fafc; border-top: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 10px;">
            <button type="button" wire:click="closeExportModal" style="padding: 8px 16px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #475569; font-size: 12.5px; font-weight: 600; cursor: pointer;">
                Cancel
            </button>
            <button type="button" wire:click="executeExport" style="padding: 8px 20px; border-radius: 8px; border: none; background: #1e40af; color: #ffffff; font-size: 12.5px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 6px rgba(30, 64, 175, 0.25);" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="executeExport">
                    @if($format === 'pdf')
                        <i class="fa-solid fa-print"></i> Generate & Print Report
                    @else
                        <i class="fa-solid fa-download"></i> Download Excel (.csv)
                    @endif
                </span>
                <span wire:loading wire:target="executeExport" style="display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Preparing Export...
                </span>
            </button>
        </div>

    </div>
</div>
@endif
