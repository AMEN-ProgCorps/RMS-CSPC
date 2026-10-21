@props([
    'show' => false,
    'title' => 'Office Hub [HUB] Recipients',
    'subtitle' => 'Select offices to broadcast this transaction to simultaneously via Office Hub',
    'search' => '',
    'selected' => [],
    'offices' => [],
    'wireClose' => 'closeHubModal',
    'wireToggle' => 'toggleHubModalOffice',
    'wireSelectAll' => 'selectAllHubModalOffices',
    'wireClear' => 'clearHubModalOffices',
    'wireApply' => 'applyHubModal',
    'wireSearch' => 'hubModalSearch',
])

@if($show)
@php
    $searchLower = strtolower(trim($search));
    $isAll = in_array('ALL', $selected);

    $filtered = collect($offices)->filter(function ($office) use ($searchLower) {
        $code = is_array($office) ? ($office['office_code'] ?? '') : ($office->office_code ?? '');
        $name = is_array($office) ? ($office['office_name'] ?? '') : ($office->office_name ?? '');

        if (in_array($code, ['ORIGIN', '[H]', '[HUB]', 'ALL'])) {
            return false;
        }

        if (empty($searchLower)) {
            return true;
        }

        return str_contains(strtolower($code), $searchLower) || str_contains(strtolower($name), $searchLower);
    })->values();

    $chosenCount = $isAll ? count($filtered) : count($selected);
@endphp
<div class="ia-modal-overlay" style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 100000;" onclick="event.stopPropagation()">
    <div class="ia-modal-card" style="background: #ffffff; width: 640px; max-width: 95vw; border-radius: 14px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25); display: flex; flex-direction: column; max-height: 85vh; overflow: hidden;" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="ia-modal-header" style="padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; background: #ffffff;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: #2563eb; color: #ffffff; width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <h3 class="ia-modal-title" style="margin: 0 0 3px 0; font-size: 17px; font-weight: 700; color: #0f172a;">
                        {{ $title }}
                    </h3>
                    <p style="margin: 0; font-size: 12px; color: #64748b; font-weight: 500;">
                        {{ $subtitle }}
                    </p>
                </div>
            </div>
            <button type="button" wire:click="{{ $wireClose }}" class="ia-modal-close" style="font-size: 26px; color: #94a3b8; background: none; border: none; cursor: pointer; line-height: 1; padding: 4px; transition: color 0.15s ease;" onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <!-- Body -->
        <div class="ia-modal-body" style="padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; overflow-y: auto; flex: 1;">
            <!-- Search & Actions Bar -->
            <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                <div style="position: relative; flex: 1; min-width: 220px;">
                    <input type="text" 
                           class="ia-input" 
                           wire:model.live="{{ $wireSearch }}" 
                           placeholder="Filter by office code or name..." 
                           style="width: 100%; height: 38px; padding-left: 36px; padding-right: 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 13px; outline: none; background: #ffffff; box-sizing: border-box; transition: border-color 0.15s ease;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" 
                         style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none;">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </div>
                <div style="display: flex; gap: 6px;">
                    <button type="button" wire:click="{{ $wireSelectAll }}" 
                            style="height: 38px; padding: 0 12px; border-radius: 8px; border: 1px solid #bfdbfe; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s ease;">
                        Select All Units
                    </button>
                    <button type="button" wire:click="{{ $wireClear }}" 
                            style="height: 38px; padding: 0 12px; border-radius: 8px; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s ease;">
                        Deselect All
                    </button>
                </div>
            </div>

            <!-- Selected Count Banner -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 12.5px; color: #1e40af; font-weight: 600;">
                <span>Selected in Hub: <strong>{{ $isAll ? 'All Active Offices (' . $chosenCount . ')' : $chosenCount . ' ' . Str::plural('office', $chosenCount) }}</strong></span>
                @if($chosenCount > 0)
                    <span style="font-size: 11.5px; color: #2563eb; font-weight: 700;">✓ Ready to broadcast</span>
                @else
                    <span style="font-size: 11.5px; color: #94a3b8; font-weight: 500;">No offices selected</span>
                @endif
            </div>

            <!-- Offices List -->
            <div style="display: flex; flex-direction: column; gap: 6px; border: 1px solid #e2e8f0; border-radius: 8px; max-height: 290px; overflow-y: auto; padding: 6px; background: #f8fafc;">
                @forelse($filtered as $off)
                    @php
                        $code = is_array($off) ? ($off['office_code'] ?? '') : ($off->office_code ?? '');
                        $name = is_array($off) ? ($off['office_name'] ?? '') : ($off->office_name ?? '');
                        $isSelected = $isAll || in_array($code, $selected);
                    @endphp
                    <div wire:click="{{ $wireToggle }}('{{ $code }}')"
                         class="ia-collective-item {{ $isSelected ? 'is-selected' : '' }}"
                         style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease; background: {{ $isSelected ? '#eff6ff' : '#ffffff' }}; border: 1px solid {{ $isSelected ? '#bfdbfe' : '#e2e8f0' }}; user-select: none;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" 
                                   {{ $isSelected ? 'checked' : '' }} 
                                   style="cursor: pointer; pointer-events: none; width: 16px; height: 16px; accent-color: #2563eb;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span class="ia-collective-code-badge" style="font-weight: 800; font-size: 12px; color: #0f172a; background: #f1f5f9; padding: 2px 7px; border-radius: 4px;">{{ $code }}</span>
                                <span class="ia-collective-name" style="font-size: 13px; color: #334155; font-weight: 600;">{{ $name }}</span>
                            </div>
                        </div>
                        <span style="font-size: 11.5px; font-weight: 700; color: {{ $isSelected ? '#2563eb' : '#94a3b8' }};">
                            {{ $isSelected ? '✓ Selected' : '+ Select' }}
                        </span>
                    </div>
                @empty
                    <div style="text-align: center; padding: 24px; font-size: 12.5px; color: #64748b;">
                        No matching active offices found.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Footer -->
        <div class="ia-modal-footer" style="padding: 14px 22px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-bottom-left-radius: 14px; border-bottom-right-radius: 14px;">
            <span style="font-size: 12px; color: #64748b; font-weight: 500;">
                <strong>{{ $isAll ? 'All Offices' : count($selected) }}</strong> chosen
            </span>
            <div style="display: flex; gap: 8px;">
                <button type="button" wire:click="{{ $wireClose }}" style="height: 36px; padding: 0 14px; font-size: 12.5px; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff; color: #475569; font-weight: 600; cursor: pointer; transition: all 0.15s ease;">
                    Cancel
                </button>
                <button type="button" wire:click="{{ $wireApply }}" style="height: 36px; padding: 0 18px; font-size: 12.5px; background: #2563eb; color: #ffffff; border: 1px solid #2563eb; border-radius: 6px; font-weight: 700; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(37,99,235,0.2);">
                    Apply to Hub
                </button>
            </div>
        </div>
    </div>
</div>
@endif
