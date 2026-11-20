{{--
  Shared DCS list pagination — same markup on every list page.
  Required: $from, $to, $total, $page, $lastPage
  Optional: $goToMethod (default goToPage), $loadingTarget (adds wire:loading), $wrapperClass
--}}
@php
    $page = max(1, (int) ($page ?? 1));
    $lastPage = max(1, (int) ($lastPage ?? 1));
    $from = (int) ($from ?? 0);
    $to = (int) ($to ?? 0);
    $total = (int) ($total ?? 0);
    $goToMethod = $goToMethod ?? 'goToPage';
    $loadingTarget = $loadingTarget ?? null;
    $wrapperClass = trim('dcs-pagination ' . ($wrapperClass ?? ''));
    $start = max(1, $page - 2);
    $end = min($lastPage, $page + 2);
@endphp
@if($total > 0)
<div class="{{ $wrapperClass }}">
    <div class="dcs-pagination-info">Showing {{ $from }}–{{ $to }} of {{ $total }}</div>
    <div class="dcs-pagination-links">
        @if($page > 1)
            <button
                type="button"
                class="dcs-pg"
                wire:click="{{ $goToMethod }}({{ $page - 1 }})"
                @if($loadingTarget) wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}" @endif
            >Prev</button>
        @endif
        @for($p = $start; $p <= $end; $p++)
            <button
                type="button"
                class="dcs-pg {{ $p === $page ? 'is-active' : '' }}"
                wire:click="{{ $goToMethod }}({{ $p }})"
                @if($p === $page) disabled @endif
                @if($loadingTarget) wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}" @endif
            >{{ $p }}</button>
        @endfor
        @if($page < $lastPage)
            <button
                type="button"
                class="dcs-pg"
                wire:click="{{ $goToMethod }}({{ $page + 1 }})"
                @if($loadingTarget) wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}" @endif
            >Next</button>
        @endif
    </div>
</div>
@endif
