{{-- Truncated office list: See more only when text exceeds 2 lines; link sits after last complete office on row 2. --}}
@php
    $officesRaw = trim((string) ($officesText ?? ''));
    $officesList = $officesRaw === ''
        ? []
        : array_values(array_filter(array_map('trim', explode(',', $officesRaw))));
    $clampKey = $clampKey ?? ('o' . uniqid());
@endphp
@if($officesList === [])
    —
@else
    <div
        class="db-offices-clamp"
        x-data="dcsOfficesClamp(@js($officesList))"
        x-init="init()"
    >
        <div class="db-offices-clamp-view" x-ref="view">
            <template x-if="expanded">
                <span class="db-offices-clamp-line">
                    <span x-text="fullText"></span><button type="button" class="db-offices-more" @click="collapse()">See less</button>
                </span>
            </template>
            <template x-if="!expanded">
                <span class="db-offices-clamp-line">
                    <span x-text="collapsedText"></span><button type="button" class="db-offices-more" x-show="needsMore" x-cloak @click="expand()">See more</button>
                </span>
            </template>
        </div>
        <div class="db-offices-clamp-measure" x-ref="measure" aria-hidden="true"></div>
    </div>
@endif
