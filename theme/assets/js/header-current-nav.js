(function () {
    function markCurrent() {
        var here = window.location.pathname.replace(/\/+$/, '') || '/';
        var links = document.querySelectorAll('.site-header a[href]');
        links.forEach(function (a) {
            var href;
            try { href = new URL(a.href, window.location.origin).pathname; }
            catch (_) { return; }
            href = href.replace(/\/+$/, '') || '/';
            // Exact match for /my-account/, /registry/, etc. Also mark category
            // landing pages active when viewing a child article (e.g. articles/<slug>).
            var match = (href === here) ||
                        (href !== '/' && here.indexOf(href + '/') === 0);
            if (match) {
                a.classList.add('is-current');
                a.setAttribute('aria-current', 'page');
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', markCurrent);
    } else {
        markCurrent();
    }
}());
