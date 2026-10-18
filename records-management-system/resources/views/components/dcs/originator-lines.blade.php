{{--
DCS file: Splits a stored originator on commas and prints one name per line. Used by reports, the database, and office lists. The printed DRF and DCN do not use this component. They keep the comma-separated string on one line.
--}}
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
