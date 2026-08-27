@php
    $value = $pct === null ? null : (float) $pct;
    $tone = 'empty';
    if ($value !== null) {
        $tone = $value >= 100 ? 'full' : ($value >= 70 ? 'good' : ($value >= 40 ? 'mid' : 'low'));
    }
    $label = $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';
@endphp
<span class="mon-pct mon-pct-{{ $tone }}">{{ $label }}</span>
