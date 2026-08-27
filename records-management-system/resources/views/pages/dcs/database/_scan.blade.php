@if(!empty($url))
    <a href="{{ $url }}" class="db-pdf-link" target="_blank" rel="noopener" title="Open scanned PDF" aria-label="Open scanned PDF">
        <i class="fa-solid fa-file-pdf"></i>
    </a>
@else
    <span class="db-na">—</span>
@endif
