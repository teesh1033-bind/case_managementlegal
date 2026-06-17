(function () {
    'use strict';

    var DESKTOP_MIN = 1200;

    function portalType() {
        var aside = document.getElementById('sidenav-main');
        if (aside && aside.getAttribute('data-lp-portal')) {
            return aside.getAttribute('data-lp-portal');
        }
        var body = document.body;
        if (!body) {
            return 'admin';
        }
        if (body.classList.contains('legalpro-client-portal')) {
            return 'client';
        }
        if (body.classList.contains('legalpro-lawyer-portal')) {
            return 'lawyer';
        }
        return 'admin';
    }

    function storageKey() {
        var keys = {
            admin: 'legalproAdminSidebarCollapsed',
            client: 'legalproClientSidebarCollapsed',
            lawyer: 'legalproLawyerSidebarCollapsed'
        };
        return keys[portalType()] || 'legalproSidebarCollapsed';
    }

    function bodyEl() {
        return document.body;
    }

    function asideEl() {
        return document.getElementById('sidenav-main');
    }

    function stripPerfectScrollbar() {
        var aside = asideEl();
        if (!aside) {
            return;
        }
        aside.classList.remove('ps', 'ps--active-y', 'bg-white');
        aside.style.overflow = 'hidden';
        aside.querySelectorAll('.ps__rail-y, .ps__thumb-y').forEach(function (node) {
            node.remove();
        });
    }

    function applyStoredCollapse() {
        var body = bodyEl();
        if (!body) {
            return;
        }
        try {
            if (window.localStorage.getItem(storageKey()) === '1') {
                body.classList.add('legalpro-sidebar-collapsed');
            }
        } catch (e) {
            // ignore
        }
    }

    function ensureLayoutClasses() {
        var body = bodyEl();
        if (!body) {
            return;
        }
        body.classList.remove('g-sidenav-hidden');
        body.classList.add('g-sidenav-show');
        if (window.innerWidth >= DESKTOP_MIN) {
            body.classList.add('g-sidenav-pinned');
            body.classList.remove('nav-open');
        }
    }

    function updateCollapseButton() {
        var btn = document.getElementById('legalproSidebarCollapse');
        var body = bodyEl();
        if (!btn || !body) {
            return;
        }
        var collapsed = body.classList.contains('legalpro-sidebar-collapsed');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }

    function persistCollapse(collapsed) {
        try {
            window.localStorage.setItem(storageKey(), collapsed ? '1' : '0');
        } catch (e) {
            // ignore
        }
    }

    function bindCollapse() {
        var btn = document.getElementById('legalproSidebarCollapse');
        var body = bodyEl();
        if (!btn || !body || btn.dataset.lpBound === '1') {
            return;
        }
        btn.dataset.lpBound = '1';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var collapsed = body.classList.toggle('legalpro-sidebar-collapsed');
            persistCollapse(collapsed);
            ensureLayoutClasses();
            updateCollapseButton();
        });
    }

    function bindMobileClose() {
        var closeBtn = document.getElementById('iconSidenav');
        var body = bodyEl();
        if (!closeBtn || !body || closeBtn.dataset.lpBound === '1') {
            return;
        }
        closeBtn.dataset.lpBound = '1';
        closeBtn.addEventListener('click', function (e) {
            if (window.innerWidth >= DESKTOP_MIN) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            body.classList.remove('g-sidenav-pinned', 'nav-open');
        });
    }

    function bindMobileOpen() {
        var body = bodyEl();
        if (!body) {
            return;
        }

        function toggleDrawer(e) {
            if (window.innerWidth >= DESKTOP_MIN) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            var open = body.classList.contains('g-sidenav-pinned') || body.classList.contains('nav-open');
            if (open) {
                body.classList.remove('g-sidenav-pinned', 'nav-open');
            } else {
                body.classList.add('g-sidenav-pinned', 'nav-open');
            }
        }

        ['iconNavbarSidenav', 'iconSidenav'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || el.dataset.lpDrawerBound === '1') {
                return;
            }
            if (id === 'iconSidenav' && el.classList.contains('lp-sidebar__close-btn')) {
                return;
            }
            el.dataset.lpDrawerBound = '1';
            el.addEventListener('click', toggleDrawer, true);
        });
    }

    function blockArgonSidenav() {
        if (typeof window.navbarColorOnResize === 'function') {
            window.navbarColorOnResize = function () {};
        }
        if (typeof window.toggleSidenav === 'function') {
            window.toggleSidenav = function () {};
        }
    }

    function init() {
        applyStoredCollapse();
        stripPerfectScrollbar();
        ensureLayoutClasses();
        updateCollapseButton();
        bindCollapse();
        bindMobileClose();
        bindMobileOpen();
        blockArgonSidenav();
    }

    document.addEventListener('DOMContentLoaded', init);
    window.addEventListener('load', function () {
        stripPerfectScrollbar();
        ensureLayoutClasses();
        updateCollapseButton();
    });
    window.addEventListener('resize', ensureLayoutClasses);
})();
