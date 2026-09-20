{{-- Truncate free-form text to 2 lines; See more / See less matches distribution offices. --}}
@php
    $textRaw = trim((string) ($text ?? ''));
    $clampKey = $clampKey ?? ('t' . uniqid());
    $emptyLabel = $emptyLabel ?? '—';
@endphp
@if($textRaw === '')
    {{ $emptyLabel }}
@else
    <div
        class="db-offices-clamp"
        wire:key="txt-clamp-{{ $clampKey }}"
        x-data="dcsTextClamp(@js($textRaw))"
        x-init="init()"
    >
        <div class="db-offices-clamp-view" x-ref="view">
            <span class="db-offices-clamp-line" x-text="visibleText"></span><button
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
