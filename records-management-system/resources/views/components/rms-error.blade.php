@once
    <script src="{{ asset('js/rms-error.js') }}?v={{ file_exists(public_path('js/rms-error.js')) ? filemtime(public_path('js/rms-error.js')) : time() }}"></script>
@endonce
