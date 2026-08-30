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
<div class="dts-export-modal-backdrop" style="position: fixed; inset: 0; z-index: 999999; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(5px); display: flex; align-items: center; justify-content: center; padding: 16px;">
    <div class="dts-export-modal-card" style="background: #ffffff; width: 100%; max-width: 680px; max-height: 92vh; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35); display: flex; flex-direction: column; overflow: hidden; font-family: 'Inter', system-ui, sans-serif; border: 1px solid #e2e8f0;" onclick="event.stopPropagation()">
        
        <!-- Modal Header -->
        <div style="padding: 18px 24px; background: linear-gradient(135deg, #003699 0%, #0052cc 100%); color: #ffffff; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; font-size: 17px; color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.25);">
                    <i class="fa-solid fa-file-export"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: #ffffff; letter-spacing: -0.2px;">Export Transactions List</h3>
                    <div style="font-size: 12px; color: rgba(255, 255, 255, 0.85); margin-top: 1px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-folder-open" style="font-size: 10px;"></i>
                        <span>{{ \App\Helpers\DtsExportHelper::getCategoryTitle($category) }}</span>
                    </div>
                </div>
            </div>
            <button type="button" wire:click="closeExportModal" style="background: rgba(255, 255, 255, 0.15); border: none; font-size: 18px; color: #ffffff; cursor: pointer; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; transition: all 0.15s ease;" onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.15)'">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div style="padding: 22px 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px;">
            
            <!-- 1. Format Selection -->
            <div>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #003699; color: #fff; font-size: 10px;">1</span>
                    Select Export Format
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <!-- PDF Option -->
                    <label style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 12px; border: 2px solid {{ $format === 'pdf' ? '#003699' : '#e2e8f0' }}; background: {{ $format === 'pdf' ? '#eff6ff' : '#ffffff' }}; cursor: pointer; transition: all 0.2s ease; box-shadow: {{ $format === 'pdf' ? '0 4px 12px rgba(0, 54, 153, 0.08)' : 'none' }};">
                        <input type="radio" name="exportFormat" value="pdf" wire:model.live="exportFormat" style="accent-color: #003699; width: 16px; height: 16px; cursor: pointer; margin-top: 2px;">
                        <div style="flex: 1;">
                            <div style="font-size: 13.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 7px;">
                                <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; background: #fee2e2; color: #dc2626; font-size: 11px;">
                                    <i class="fa-solid fa-file-pdf"></i>
                                </span>
                                PDF / Print Report
                            </div>
                            <div style="font-size: 11px; color: #64748b; margin-top: 4px; line-height: 1.35;">Official printable letterhead with signatories & structured flow headers</div>
                        </div>
                    </label>

                    <!-- Excel Option -->
                    <label style="display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 12px; border: 2px solid {{ $format === 'excel' ? '#003699' : '#e2e8f0' }}; background: {{ $format === 'excel' ? '#eff6ff' : '#ffffff' }}; cursor: pointer; transition: all 0.2s ease; box-shadow: {{ $format === 'excel' ? '0 4px 12px rgba(0, 54, 153, 0.08)' : 'none' }};">
                        <input type="radio" name="exportFormat" value="excel" wire:model.live="exportFormat" style="accent-color: #003699; width: 16px; height: 16px; cursor: pointer; margin-top: 2px;">
                        <div style="flex: 1;">
                            <div style="font-size: 13.5px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 7px;">
                                <span style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; background: #dcfce7; color: #16a34a; font-size: 11px;">
                                    <i class="fa-solid fa-file-excel"></i>
                                </span>
                                Excel Spreadsheet
                            </div>
                            <div style="font-size: 11px; color: #64748b; margin-top: 4px; line-height: 1.35;">Universal .csv format ready for MS Excel analysis & archiving</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- 2. Scope Selection -->
            <div>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #003699; color: #fff; font-size: 10px;">2</span>
                    Select Records Scope
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- All Filtered Records -->
                    <label style="display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-radius: 10px; border: 1.5px solid {{ $scope === 'all' ? '#003699' : '#e2e8f0' }}; background: {{ $scope === 'all' ? '#f0f6ff' : '#ffffff' }}; cursor: pointer; transition: all 0.15s ease;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="radio" name="exportScope" value="all" wire:model.live="exportScope" style="accent-color: #003699; width: 16px; height: 16px; cursor: pointer;">
                            <span style="font-size: 12.5px; font-weight: 600; color: #1e293b;">All Filtered Records</span>
                        </div>
                        <span style="font-size: 11px; font-weight: 700; color: #0369a1; background: #e0f2fe; padding: 2px 8px; border-radius: 12px; border: 1px solid #bae6fd;">
                            {{ $totalCount }} records
                        </span>
                    </label>

                    <!-- Selected Records Only -->
                    <label style="display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-radius: 10px; border: 1.5px solid {{ $scope === 'selected' ? '#003699' : '#e2e8f0' }}; background: {{ $scope === 'selected' ? '#f0f6ff' : '#ffffff' }}; opacity: {{ $selectedCount > 0 ? '1' : '0.6' }}; cursor: {{ $selectedCount > 0 ? 'pointer' : 'not-allowed' }}; transition: all 0.15s ease;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="radio" name="exportScope" value="selected" wire:model.live="exportScope" {{ $selectedCount === 0 ? 'disabled' : '' }} style="accent-color: #003699; width: 16px; height: 16px; cursor: {{ $selectedCount > 0 ? 'pointer' : 'not-allowed' }};">
                            <div>
                                <span style="font-size: 12.5px; font-weight: 600; color: #1e293b;">Selected Records Only</span>
                                @if($selectedCount === 0)
                                    <div style="font-size: 10px; color: #dc2626;">(No table rows selected)</div>
                                @endif
                            </div>
                        </div>
                        <span style="font-size: 11px; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 12px; border: 1px solid #bbf7d0;">
                            {{ $selectedCount }} selected
                        </span>
                    </label>
                </div>
            </div>

            <!-- 3. Customize Export Columns -->
            <div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #003699; color: #fff; font-size: 10px;">3</span>
                        Customize Included Columns
                    </label>
                    <div style="display: flex; align-items: center; gap: 10px; font-size: 11px;">
                        <button type="button" wire:click="selectAllExportColumns" style="color: #003699; font-weight: 700; background: none; border: none; cursor: pointer; padding: 0;">Select All</button>
                        <span style="color: #cbd5e1;">•</span>
                        <button type="button" wire:click="resetDefaultExportColumns" style="color: #64748b; font-weight: 600; background: none; border: none; cursor: pointer; padding: 0;">Reset Defaults</button>
                        <span style="color: #cbd5e1;">•</span>
                        <button type="button" wire:click="deselectAllExportColumns" style="color: #94a3b8; font-weight: 600; background: none; border: none; cursor: pointer; padding: 0;">Clear All</button>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 14px;">
                    
                    <!-- Section A: General Transaction Information (Default Included) -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px;">
                        <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; display: flex; align-items: center; gap: 5px;">
                            <i class="fa-solid fa-circle-info" style="color: #003699;"></i> General Transaction Information
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;">
                            @foreach($generalCols as $colKey => $colDef)
                                <label style="display: flex; align-items: center; gap: 7px; font-size: 12px; color: #334155; cursor: pointer; user-select: none; padding: 3px 0;">
                                    <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}" style="accent-color: #003699; width: 15px; height: 15px; cursor: pointer; border-radius: 4px;">
                                    <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: {{ in_array($colKey, $selectedColumns) ? '600' : '400' }};" title="{{ $colDef['label'] }}">
                                        {{ $colDef['label'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <!-- Section B: Flow 1 Routing Steps (Not Origin) — Default: NOT INCLUDED -->
                    @if($flow1Cols->isNotEmpty())
                        <div style="background: #f0f9ff; border: 1.5px solid #bae6fd; border-radius: 10px; padding: 14px; position: relative;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 11.5px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.4px; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-route" style="font-size: 13px;"></i> Flow 1 (not the origin)
                                    </span>
                                    <span style="font-size: 10px; font-weight: 700; background: #e0f2fe; color: #0284c7; padding: 1px 7px; border-radius: 12px; border: 1px solid #7dd3fc;">
                                        Forwarded Step 1
                                    </span>
                                </div>
                                <button type="button" wire:click="toggleFlow1ExportColumns" style="font-size: 11px; font-weight: 700; color: #0284c7; background: #ffffff; border: 1px solid #7dd3fc; border-radius: 6px; padding: 2px 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                    @if($selectedFlow1Count === count($flow1Keys))
                                        <i class="fa-solid fa-check-double"></i> Exclude Flow 1
                                    @else
                                        <i class="fa-solid fa-plus"></i> Include Flow 1 Columns
                                    @endif
                                </button>
                            </div>
                            
                            <p style="margin: 0 0 10px 0; font-size: 11px; color: #0369a1; line-height: 1.4;">
                                Adds dedicated columns for the 1st destination office: <strong>Office Name</strong>, <strong>Received (Date/Time & Person)</strong>, <strong>Released (Date/Time & Person)</strong>, and <strong>Elapsed Day (Duration)</strong>.
                            </p>

                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; background: #ffffff; padding: 10px 12px; border-radius: 8px; border: 1px solid #e0f2fe;">
                                @foreach($flow1Cols as $colKey => $colDef)
                                    <label style="display: flex; align-items: center; gap: 7px; font-size: 12px; color: #0f172a; cursor: pointer; user-select: none; padding: 2px 0;">
                                        <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}" style="accent-color: #0284c7; width: 15px; height: 15px; cursor: pointer; border-radius: 4px;">
                                        <span style="font-weight: {{ in_array($colKey, $selectedColumns) ? '700' : '500' }}; color: {{ in_array($colKey, $selectedColumns) ? '#0284c7' : '#334155' }};">
                                            {{ $colDef['sublabel'] ?? $colDef['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Section C: Additional Metadata -->
                    @if($additionalCols->isNotEmpty())
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px;">
                            <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; display: flex; align-items: center; gap: 5px;">
                                <i class="fa-solid fa-list-check" style="color: #64748b;"></i> Secondary Fields & Activity
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;">
                                @foreach($additionalCols as $colKey => $colDef)
                                    <label style="display: flex; align-items: center; gap: 7px; font-size: 12px; color: #334155; cursor: pointer; user-select: none; padding: 3px 0;">
                                        <input type="checkbox" wire:model.live="exportColumns" value="{{ $colKey }}" style="accent-color: #003699; width: 15px; height: 15px; cursor: pointer; border-radius: 4px;">
                                        <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: {{ in_array($colKey, $selectedColumns) ? '600' : '400' }};" title="{{ $colDef['label'] }}">
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
                <div style="border-top: 1px solid #f1f5f9; padding-top: 16px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #003699; color: #fff; font-size: 10px;">4</span>
                        Report Signatories Configuration
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <span style="display: block; font-size: 11.5px; color: #475569; font-weight: 600; margin-bottom: 4px;">Prepared by (Name):</span>
                            <input type="text" wire:model="exportPreparedBy" placeholder="e.g. John Doe" class="rms-select" style="width: 100%; height: 36px; background-image: none; font-size: 12.5px; padding: 6px 10px; border-radius: 8px; border: 1.5px solid #cbd5e1;">
                        </div>
                        <div>
                            <span style="display: block; font-size: 11.5px; color: #475569; font-weight: 600; margin-bottom: 4px;">Noted / Approved by:</span>
                            <input type="text" wire:model="exportNotedBy" placeholder="e.g. Head of Office / Dean" class="rms-select" style="width: 100%; height: 36px; background-image: none; font-size: 12.5px; padding: 6px 10px; border-radius: 8px; border: 1.5px solid #cbd5e1;">
                        </div>
                    </div>
                </div>
            @endif

        </div>

        <!-- Modal Footer -->
        <div style="padding: 16px 24px; background: #f8fafc; border-top: 1.5px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 12px;">
            <button type="button" wire:click="closeExportModal" style="padding: 9px 18px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s ease;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'">
                Cancel
            </button>
            <button type="button" wire:click="executeExport" style="padding: 9px 24px; border-radius: 8px; border: none; background: #003699; color: #ffffff; font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0, 54, 153, 0.25); transition: all 0.15s ease;" onmouseover="this.style.background='#002873'" onmouseout="this.style.background='#003699'" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="executeExport" style="display: inline-flex; align-items: center; gap: 7px;">
                    @if($format === 'pdf')
                        <i class="fa-solid fa-print"></i> Generate & Print Report
                    @else
                        <i class="fa-solid fa-file-excel"></i> Download Excel (.csv)
                    @endif
                </span>
                <span wire:loading wire:target="executeExport" style="display: inline-flex; align-items: center; gap: 7px;">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> Preparing Export...
                </span>
            </button>
        </div>

    </div>
</div>
@endif
