/* responsive.js – sidebar drawer + scrollable tables (pairs with css/responsive.css) */
(function () {
    'use strict';

    var BREAKPOINT = 992; // Bootstrap "lg"

    function init() {
        // 1. Any table that is not already scrollable gets its own scroll box,
        //    so wide tables never stretch the whole page when zoomed in.
        var tables = document.querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i];
            if (!t.closest('.table-responsive')) {
                var wrap = document.createElement('div');
                wrap.className = 'table-responsive';
                t.parentNode.insertBefore(wrap, t);
                wrap.appendChild(t);
            }
        }

        // 2. Sidebar drawer (only on pages that have a sidebar)
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) { return; }

        if (!document.querySelector('.topbar, .navbar-custom, .navbar')) {
            document.body.classList.add('no-topbar');
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'nav-toggle';
        btn.setAttribute('aria-label', 'Toggle navigation menu');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span class="nav-toggle-bars"></span>';

        var backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';

        document.body.appendChild(backdrop);
        document.body.appendChild(btn);

        function setOpen(open) {
            document.body.classList.toggle('sidebar-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        btn.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('sidebar-open'));
        });
        backdrop.addEventListener('click', function () { setOpen(false); });
        sidebar.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('a')) { setOpen(false); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setOpen(false); }
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth >= BREAKPOINT) { setOpen(false); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
