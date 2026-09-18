{{-- Truncate at last complete office that fits in 2 lines; See more sits after that office. --}}
@php
    $officesRaw = trim((string) ($officesText ?? ''));
    $officesList = $officesRaw === ''
        ? []
        : array_values(array_filter(array_map('trim', explode(',', $officesRaw))));
    $clampKey = $clampKey ?? ('o' . uniqid());
    $highlightOffice = trim((string) ($highlightOffice ?? ''));
@endphp
@if($officesList === [])
    —
@else
    <div
        class="db-offices-clamp"
        wire:key="ofi-clamp-{{ $clampKey }}"
        x-data="dcsOfficesClamp(@js($officesList), @js($highlightOffice))"
        x-init="init()"
    >
        <div class="db-offices-clamp-view" x-ref="view">
            <span class="db-offices-clamp-line" x-html="visibleHtml"></span><button
                type="button"
                class="db-offices-more"
                x-show="showToggle"
                x-cloak
                @click="toggle()"
                x-text="expanded ? 'See less' : 'See more'"
            ></button>
        </div>
        <div class="db-offices-clamp-measure" x-ref="measure" aria-hidden="true"></div>
    </div>
@endif
