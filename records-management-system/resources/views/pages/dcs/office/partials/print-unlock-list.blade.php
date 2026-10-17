<script>
(function () {
    var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-print-locked]'));
    if (!nodes.length) return;
    var type = nodes[0].getAttribute('data-intake-type');
    if (!type) return;
    function pending() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-print-locked]'));
    }
    var timer = setInterval(async function () {
        var left = pending();
        if (!left.length) {
            clearInterval(timer);
            return;
        }
        var ids = left.map(function (node) { return node.getAttribute('data-intake-id'); }).filter(Boolean).join(',');
        try {
            var res = await fetch('/dcs/api/office-intake/' + encodeURIComponent(type) + '/print-ready?ids=' + encodeURIComponent(ids), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            var data = await res.json();
            var ready = data && Array.isArray(data.ready) ? data.ready.map(String) : [];
            left.forEach(function (node) {
                if (ready.indexOf(String(node.getAttribute('data-intake-id'))) === -1) return;
                var link = document.createElement('a');
                link.href = node.getAttribute('data-print-url') || '#';
                link.target = '_blank';
                link.title = 'Print';
                link.innerHTML = '<i class="fa-solid fa-print"></i>';
                node.replaceWith(link);
            });
        } catch (e) {}
    }, 2000);
})();
</script>
