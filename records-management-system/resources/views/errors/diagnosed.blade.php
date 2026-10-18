<!DOCTYPE html>
<html lang="en" data-rms-error="1">
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

        $retryUrl = '/'.ltrim(request()->path(), '/');
        $query = request()->getQueryString();
        if (is_string($query) && $query !== '') {
            $retryUrl .= '?'.$query;
        }
        $referer = (string) request()->headers->get('referer', '');
        $refererHost = parse_url($referer, PHP_URL_HOST);
        $refererPath = parse_url($referer, PHP_URL_PATH);
        $refererQuery = parse_url($referer, PHP_URL_QUERY);
        $refererUrl = is_string($refererPath) ? $refererPath : '';
        if ($refererUrl !== '' && is_string($refererQuery) && $refererQuery !== '') {
            $refererUrl .= '?'.$refererQuery;
        }
        $sameHost = $referer !== '' && $refererHost === request()->getHost();
        $backUrl = ($sameHost && $refererUrl !== '' && $refererUrl !== '/' && $refererUrl !== $retryUrl)
            ? $refererUrl
            : '';
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
        .detail {
            margin-top: 14px;
            padding: 10px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #334155;
            font-size: 0.85rem;
            line-height: 1.45;
            word-break: break-word;
        }
        .detail strong { display: inline; margin: 0; font-size: inherit; }
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
        @if($error->file)
            <p class="detail"><strong>File:</strong> {{ $error->file }}@if($error->line) on line {{ $error->line }}@endif</p>
        @endif
        @if($error->reference)
            <p class="ref">Reference {{ $error->reference }}</p>
        @endif
        <a href="{{ $backUrl !== '' ? $backUrl : '#' }}" id="rmsErrorBack" data-back="{{ $backUrl }}" data-retry="{{ $retryUrl }}">Back to the system</a>
    </main>
    <script>
        (function () {
            var link = document.getElementById('rmsErrorBack');
            var retry = (link && link.getAttribute('data-retry')) || (location.pathname + location.search);
            if (retry.charAt(0) !== '/' || retry.indexOf('//') === 0) {
                retry = location.pathname + location.search;
            }
            function storedPage(key) {
                try { return sessionStorage.getItem(key) || ''; } catch (e) { return ''; }
            }
            function usable(url) {
                return !!url && url.charAt(0) === '/' && url.indexOf('//') !== 0 && url !== '/' && url !== retry;
            }
            var back = storedPage('rmsLastGoodPage');
            if (!usable(back)) back = storedPage('rmsPreviousPage');
            if (!usable(back)) back = (link && link.getAttribute('data-back')) || '';
            if (!usable(back)) back = '';
            if (link) {
                link.setAttribute('href', back || '#');
                link.addEventListener('click', function (event) {
                    if (back) return;
                    event.preventDefault();
                    if (history.length > 1) history.back();
                });
            }

            var checking = false;
            setInterval(function () {
                if (checking || document.visibilityState === 'hidden') return;
                checking = true;
                fetch(retry, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                    .then(function (response) {
                        return response.text().then(function (html) {
                            var path = '';
                            try { path = new URL(response.url, location.origin).pathname; } catch (e) { path = ''; }
                            if (path === '/login' || path === '/portal' || path === '/') return;
                            var stillError = response.status >= 400 || (html && html.indexOf('data-rms-error="1"') !== -1);
                            if (!stillError && response.ok) location.replace(retry);
                        });
                    })
                    .catch(function () {})
                    .then(function () { checking = false; });
            }, 4000);
        })();
    </script>
</body>
</html>
