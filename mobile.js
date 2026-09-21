/* mobile.js — responsive behaviour shared by every screen (user, staff & admin)
   Loaded with `defer` on all pages alongside mobile.css. Everything here is
   defensive: it only activates when a #sidebar drawer or bare <table> exists.
*/
(function () {
    'use strict';

    var DESKTOP_BP = 1024; // Tailwind `lg` breakpoint — sidebar is inline above this

    /* ---------- toggleSidebar fallback (some pages never defined it) ---------- */
    if (typeof window.toggleSidebar !== 'function') {
        window.toggleSidebar = function () {
            var sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
        };
    }

    function isDesktop() {
        return window.innerWidth >= DESKTOP_BP;
    }

    /* ---------- Sidebar backdrop + state sync ---------- */
    function initSidebar() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        var backdrop = document.getElementById('sidebarBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'sidebarBackdrop';
            backdrop.setAttribute('aria-hidden', 'true');
            document.body.appendChild(backdrop);
        }

        function syncState() {
            var open = !isDesktop() && !sidebar.classList.contains('-translate-x-full');
            backdrop.classList.toggle('visible', open);
            document.body.classList.toggle('sidebar-open', open);
            sidebar.setAttribute('aria-hidden', String(!open && !isDesktop()));
        }

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            syncState();
        }

        // Watch the class attribute — works with every page's own toggleSidebar()
        new MutationObserver(syncState).observe(sidebar, {
            attributes: true,
            attributeFilter: ['class']
        });

        backdrop.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Close the drawer as soon as a navigation link inside it is tapped
        sidebar.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (link && !isDesktop()) closeSidebar();
        });

        // Rotating/resizing to desktop clears any stale open state
        window.addEventListener('resize', function () {
            if (isDesktop() && document.body.classList.contains('sidebar-open')) {
                document.body.classList.remove('sidebar-open');
                backdrop.classList.remove('visible');
            }
        });

        syncState();
    }

    /* ---------- Auto-wrap bare tables so they scroll horizontally ---------- */
    function wrapTables() {
        document.querySelectorAll('table').forEach(function (table) {
            var p = table.parentElement;
            if (!p) return;
            var cls = p.className || '';
            if (cls.indexOf('overflow-x') !== -1 || cls.indexOf('mobile-table-wrap') !== -1) return;
            var wrap = document.createElement('div');
            wrap.className = 'mobile-table-wrap';
            p.insertBefore(wrap, table);
            wrap.appendChild(table);
        });
    }

    /* ---------- Collapse wide Tailwind grids that lack responsive variants ---------- */
    function fixWideGrids() {
        if (window.innerWidth > 640) return;
        document.querySelectorAll('[class*="grid-cols-"]').forEach(function (el) {
            var cls = el.className || '';
            if (/(sm|md|lg|xl):grid-cols/.test(cls)) return;
            var m = cls.match(/(?:^|\s)grid-cols-(\d+)/);
            if (m && parseInt(m[1], 10) >= 4) {
                el.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))';
            }
        });
    }

    function init() {
        initSidebar();
        wrapTables();
        fixWideGrids();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
