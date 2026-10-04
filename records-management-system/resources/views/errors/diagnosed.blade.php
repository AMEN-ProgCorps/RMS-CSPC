<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $error->kind === 'client' ? 'Client error' : 'Server error' }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #0f172a; font-family: Inter, system-ui, sans-serif; }
        main { width: min(520px, calc(100% - 32px)); background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        p { margin: 8px 0 0; color: #475569; line-height: 1.5; }
        strong { display: block; font-size: 1.15rem; }
        .ref { margin-top: 14px; font-weight: 700; color: #043899; }
    </style>
</head>
<body>
    <main>
        <strong>{{ $error->kind === 'client' ? 'Client error' : 'Server error' }}</strong>
        <p>{{ $error->message }}</p>
        @if($error->reference)
            <p class="ref">Reference {{ $error->reference }}</p>
        @endif
    </main>
</body>
</html>
