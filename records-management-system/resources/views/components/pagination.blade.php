@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}
$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? "document.querySelector('{$scrollTo}').scrollIntoView({ behavior: 'smooth', block: 'start' })"
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="rms-pagination">
            <div class="rms-pagination-info">
                <span>Showing</span>
                <strong>{{ $paginator->firstItem() }}</strong>
                <span>to</span>
                <strong>{{ $paginator->lastItem() }}</strong>
                <span>of</span>
                <strong>{{ $paginator->total() }}</strong>
                <span>results</span>
            </div>

            <div class="rms-pagination-buttons">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <span class="rms-page-btn rms-page-disabled" aria-disabled="true">
                        <i class="fa-solid fa-chevron-left" style="font-size: 10px;"></i> Previous
                    </span>
                @else
                    <button type="button" class="rms-page-btn" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">
                        <i class="fa-solid fa-chevron-left" style="font-size: 10px;"></i> Previous
                    </button>
                @endif

                {{-- Pagination Elements --}}
                @foreach ($elements as $element)
                    {{-- "Three Dots" Separator --}}
                    @if (is_string($element))
                        <span class="rms-page-btn rms-page-dots" aria-disabled="true">{{ $element }}</span>
                    @endif

                    {{-- Array Of Links --}}
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="rms-page-btn rms-page-active" wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}" aria-current="page">{{ $page }}</span>
                            @else
                                <button type="button" class="rms-page-btn" wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}">{{ $page }}</button>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <button type="button" class="rms-page-btn" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled">
                        Next <i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i>
                    </button>
                @else
                    <span class="rms-page-btn rms-page-disabled" aria-disabled="true">
                        Next <i class="fa-solid fa-chevron-right" style="font-size: 10px;"></i>
                    </span>
                @endif
            </div>
        </nav>

        <style>
            .rms-pagination {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
                width: 100%;
                font-family: 'Inter', sans-serif;
            }
            .rms-pagination-info {
                font-size: 12.5px;
                color: #64748b;
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .rms-pagination-info strong {
                color: #334155;
                font-weight: 600;
            }
            .rms-pagination-buttons {
                display: flex;
                align-items: center;
                gap: 4px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .rms-page-btn {
                font-family: 'Inter', sans-serif;
                font-size: 12px;
                font-weight: 500;
                padding: 6px 12px;
                border-radius: 6px;
                border: 1px solid #e2e8f0;
                color: #475569;
                background: #ffffff;
                cursor: pointer;
                transition: all 0.15s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
                line-height: 1.2;
                outline: none;
            }
            .rms-page-btn:hover:not(.rms-page-disabled):not(.rms-page-active):not(.rms-page-dots) {
                background: #f1f5f9;
                color: #003699;
                border-color: #cbd5e1;
            }
            .rms-page-active {
                background: #003699 !important;
                color: #ffffff !important;
                border-color: #003699 !important;
                font-weight: 600;
                cursor: default;
            }
            .rms-page-disabled {
                opacity: 0.4;
                cursor: not-allowed;
            }
            .rms-page-dots {
                border-color: transparent;
                background: transparent;
                cursor: default;
                padding: 6px 6px;
                color: #94a3b8;
            }
        </style>
    @endif
</div>
