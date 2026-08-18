<script>
    document.addEventListener('DOMContentLoaded', () => {
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                fetch('{{ url('/api/session/ping') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                }).catch(() => {});
            }
        }, 30000);

        window.addEventListener('pagehide', () => {
            const data = new FormData();
            data.append('_token', '{{ csrf_token() }}');
            navigator.sendBeacon('{{ url('/api/session/tab-closed') }}', data);
        });
    });
</script>
