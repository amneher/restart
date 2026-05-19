(function () {
    'use strict';

    if (typeof rrNavState === 'undefined') return;

    var state = rrNavState;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    // ── Header "My Account" nav item ──────────────────────────────────────────

    function updateHeaderNav() {
        // The block nav renders each link as .wp-block-navigation-item__content.
        // Match by href — more reliable than text content which could be localised.
        var allLinks = document.querySelectorAll(
            '.site-header .wp-block-navigation-item__content'
        );
        var accountLink = null;
        allLinks.forEach(function (a) {
            try {
                var path = new URL(a.href, window.location.origin).pathname;
                if (path === '/my-account/' || path === '/my-account') {
                    accountLink = a;
                }
            } catch (_) {}
        });
        if (!accountLink) return;

        if (!state.isLoggedIn) {
            // Replace with a plain login link — no submenu needed.
            accountLink.textContent = 'Login or Register';
            accountLink.href = state.loginUrl;
            return;
        }

        // Build submenu items.
        var items = [];
        if (state.registryUrl) {
            items.push({ label: 'My Registry',      url: state.registryUrl });
        } else {
            items.push({ label: 'Start a Registry', url: state.startRegistryUrl });
        }
        items.push({ label: 'My Account', url: state.myAccountUrl });
        items.push({ label: 'Logout',     url: state.logoutUrl });
        items.push({ label: 'Contact',    url: '#contact' });

        var ul = document.createElement('ul');
        ul.className = 'rr-submenu';
        ul.setAttribute('role', 'menu');

        items.forEach(function (item) {
            var li = document.createElement('li');
            li.setAttribute('role', 'none');
            var a = document.createElement('a');
            a.href = item.url;
            a.textContent = item.label;
            a.setAttribute('role', 'menuitem');
            li.appendChild(a);
            ul.appendChild(li);
        });

        var li = accountLink.closest('.wp-block-navigation-item');
        if (!li) return;
        li.classList.add('rr-has-submenu');
        li.appendChild(ul);

        // Toggle on click; prevent default navigation on the parent link.
        accountLink.addEventListener('click', function (e) {
            e.preventDefault();
            var open = li.classList.toggle('rr-submenu-open');
            accountLink.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        accountLink.setAttribute('aria-haspopup', 'true');
        accountLink.setAttribute('aria-expanded', 'false');

        // Close when clicking outside.
        document.addEventListener('click', function (e) {
            if (!li.contains(e.target)) {
                li.classList.remove('rr-submenu-open');
                accountLink.setAttribute('aria-expanded', 'false');
            }
        });

        // Close on Escape.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                li.classList.remove('rr-submenu-open');
                accountLink.setAttribute('aria-expanded', 'false');
                accountLink.focus();
            }
        });
    }

    // ── Footer "Start a Registry" link ────────────────────────────────────────

    function updateFooterNav() {
        if (!state.registryUrl) return;

        document.querySelectorAll(
            '.site-footer .wp-block-navigation-item__content'
        ).forEach(function (a) {
            try {
                var path = new URL(a.href, window.location.origin).pathname;
                if (path === '/start-a-registry/' || path === '/start-a-registry') {
                    a.textContent = 'My Registry';
                    a.href = state.registryUrl;
                }
            } catch (_) {}
        });
    }

    ready(function () {
        updateHeaderNav();
        updateFooterNav();
    });
}());
