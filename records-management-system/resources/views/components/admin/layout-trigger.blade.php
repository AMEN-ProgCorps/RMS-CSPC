@props(['mode' => 'horizontal'])

<div class="view-layout-trigger" style="display: inline-flex; align-items: center; background: #e2e8f0; padding: 3px; border-radius: 8px; gap: 2px;">
    <button type="button" 
            wire:click="setLayoutMode('default')" 
            class="layout-trigger-btn {{ $mode === 'default' ? 'active' : '' }}"
            title="Switch to Default (Classic Split Directory) Layout"
            style="padding: 5px 12px; font-size: 12px; font-weight: 700; border-radius: 6px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s ease; {{ $mode === 'default' ? 'background: #ffffff; color: #0284c7; box-shadow: 0 1px 3px rgba(0,0,0,0.12);' : 'background: transparent; color: #64748b;' }}">
        <i class="fa-solid fa-table-columns"></i>
        <span>Default</span>
    </button>
    <button type="button" 
            wire:click="setLayoutMode('horizontal')" 
            class="layout-trigger-btn {{ $mode === 'horizontal' ? 'active' : '' }}"
            title="Switch to Horizontal (4-Column Miller Browser) Layout"
            style="padding: 5px 12px; font-size: 12px; font-weight: 700; border-radius: 6px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s ease; {{ $mode === 'horizontal' ? 'background: #ffffff; color: #0284c7; box-shadow: 0 1px 3px rgba(0,0,0,0.12);' : 'background: transparent; color: #64748b;' }}">
        <i class="fa-solid fa-arrows-left-right"></i>
        <span>Horizontal</span>
    </button>
</div>
