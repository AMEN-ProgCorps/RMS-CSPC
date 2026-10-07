<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $colors = [
            401 => '#1d4ed8',
            403 => '#be123c',
            404 => '#0f766e',
            405 => '#4338ca',
            408 => '#c2410c',
            413 => '#a16207',
            419 => '#b45309',
            422 => '#a16207',
            429 => '#c2410c',
            500 => '#dc2626',
            502 => '#9f1239',
            503 => '#b91c1c',
            504 => '#9a3412',
        ];
        $color = $colors[$error->status] ?? ($error->status >= 500 ? '#dc2626' : '#1d4ed8');
    @endphp
    <title>{{ $error->title }}</title>
    <style>
        body {
            --err: {{ $color }};
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: color-mix(in srgb, var(--err) 8%, #f8fafc);
            color: #0f172a;
            font-family: Inter, system-ui, sans-serif;
        }
        main {
            width: min(520px, calc(100% - 32px));
            background: #fff;
            border: 1px solid color-mix(in srgb, var(--err) 35%, #e2e8f0);
            border-top: 4px solid var(--err);
            border-radius: 12px;
            padding: 24px;
        }
        .status {
            margin: 0;
            color: var(--err);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        strong { display: block; margin-top: 6px; font-size: 1.15rem; }
        p { margin: 8px 0 0; color: #475569; line-height: 1.5; }
        .ref { margin-top: 14px; font-weight: 700; color: var(--err); }
        a {
            display: inline-block;
            margin-top: 16px;
            color: var(--err);
            font-weight: 600;
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main>
        <p class="status">Error {{ $error->status }}</p>
        <strong>{{ $error->title }}</strong>
        <p>{{ $error->message }}</p>
        @if($error->reference)
            <p class="ref">Reference {{ $error->reference }}</p>
        @endif
        <a href="{{ url('/') }}">Back to the system</a>
    </main>
</body>
</html>
