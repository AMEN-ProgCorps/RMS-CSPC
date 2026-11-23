{{--
  DCS PARTIAL: Office intake list search
  FILE: resources/views/pages/dcs/office/partials/ofi-list-search.blade.php
--}}
@php
    $placeholder = $placeholder ?? 'Search by document no. or title…';
    $wireTarget = $wireTarget ?? 'search';
@endphp
<div class="ofi-list-search">
    <label class="ofi-list-search-field" for="ofiListSearch">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input
            type="text"
            id="ofiListSearch"
            class="ofi-list-search-input"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            aria-label="{{ $placeholder }}"
        >
        @if(trim((string) ($search ?? '')) !== '')
            <button
                type="button"
                class="ofi-list-search-clear"
                wire:click="$set('search', '')"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                title="Clear search"
                aria-label="Clear search"
            >
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        @endif
    </label>
</div>
