<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>{{ $title ?? 'RMS CSPC' }}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">


    @stack('styles')

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @stack('scripts')

    @livewireScripts
</body>
</html>
