@php
    $raw = trim((string) ($name ?? ''));
    $lines = $raw === ''
        ? []
        : array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', $raw) ?: []), fn ($line) => $line !== ''));
@endphp
@if($lines === [])
    {{ $empty ?? '—' }}
@else
    @foreach($lines as $index => $line)
        @if($index > 0)<br>@endif{{ $line }}
    @endforeach
@endif
