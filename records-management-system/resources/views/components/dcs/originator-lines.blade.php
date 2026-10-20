{{--
DCS file: Originators stay on one line, comma-separated. Used by reports, the database, and office lists.
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
    {{ implode(', ', $lines) }}
@endif
