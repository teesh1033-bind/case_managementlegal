(function () {
    'use strict';
    var COLLAPSE_KEY = 'legalproClientSidebarCollapsed';

    function isClientPortal() {
        return document.body && document.body.classList.contains('legalpro-client-portal');
    }

    function ensureDesktopPinned() {
        var body = document.body;
        if (!body || !isClientPortal()) {
            return;
        }

        body.classList.remove('g-sidenav-hidden');
        if (window.innerWidth >= 1200) {
            body.classList.add('g-sidenav-pinned');
            body.classList.remove('nav-open');
        }
    }

    function fixClientSidenavLayout() {
        if (!isClientPortal()) {
            return;
        }

        var body = document.body;
        var sidenav = document.getElementById('sidenav-main');
        var navWrap = document.getElementById('sidenav-collapse-main');

        ensureDesktopPinned();

        if (sidenav) {
            sidenav.classList.remove('ps', 'ps--active-y');
            sidenav.style.overflow = 'hidden';
            sidenav.querySelectorAll('.ps__rail-y, .ps__thumb-y').forEach(function (node) {
                node.remove();
            });
        }

        if (!navWrap) {
            return;
        }

        navWrap.classList.add('show');
        navWrap.style.removeProperty('display');

        if (body.classList.contains('legalpro-sidebar-collapsed')) {
            navWrap.style.removeProperty('height');
            navWrap.style.removeProperty('max-height');
            navWrap.style.removeProperty('min-height');
            navWrap.style.removeProperty('overflow-y');
            return;
        }

        navWrap.style.height = 'auto';
        navWrap.style.maxHeight = 'none';
        navWrap.style.minHeight = '0';
        navWrap.style.overflowY = 'auto';
    }

    function updateCollapseButton(collapsed) {
        var btn = document.getElementById('legalproSidebarCollapse');
        if (!btn) {
            return;
        }
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }

    function getStoredCollapsed() {
        try {
            return window.localStorage.getItem(COLLAPSE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function setStoredCollapsed(collapsed) {
        try {
            window.localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch (e) {
            // ignore storage errors (private mode / blocked storage)
        }
    }

    function applyStoredCollapsedState() {
        var body = document.body;
        if (!body || !isClientPortal()) {
            return;
        }

        if (getStoredCollapsed()) {
            body.classList.add('legalpro-sidebar-collapsed');
        } else {
            body.classList.remove('legalpro-sidebar-collapsed');
        }
    }

    function initCollapseToggle() {
        var body = document.body;
        if (!body || !isClientPortal()) {
            return;
        }

        var collapseBtn = document.getElementById('legalproSidebarCollapse');
        if (!collapseBtn || collapseBtn.dataset.lpSidebarBound === '1') {
            return;
        }
        collapseBtn.dataset.lpSidebarBound = '1';

        updateCollapseButton(body.classList.contains('legalpro-sidebar-collapsed'));

        collapseBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            body.classList.toggle('legalpro-sidebar-collapsed');
            setStoredCollapsed(body.classList.contains('legalpro-sidebar-collapsed'));
            ensureDesktopPinned();
            updateCollapseButton(body.classList.contains('legalpro-sidebar-collapsed'));
            fixClientSidenavLayout();
        });
    }

    function initMobileDrawerToggle() {
        if (!isClientPortal()) {
            return;
        }

        var body = document.body;

        function toggleMobileDrawer(e) {
            if (window.innerWidth >= 1200) {
                return;
            }

            e.preventDefault();
            e.stopImmediatePropagation();

            var isOpen = body.classList.contains('g-sidenav-pinned') || body.classList.contains('nav-open');
            if (isOpen) {
                body.classList.remove('g-sidenav-pinned', 'nav-open');
            } else {
                body.classList.add('g-sidenav-pinned', 'nav-open');
            }
        }

        ['iconNavbarSidenav', 'iconSidenav'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || el.dataset.lpMobileDrawerBound === '1') {
                return;
            }
            el.dataset.lpMobileDrawerBound = '1';
            el.addEventListener('click', toggleMobileDrawer, true);
        });
    }

    function init() {
        if (!isClientPortal()) {
            return;
        }
        applyStoredCollapsedState();
        ensureDesktopPinned();
        fixClientSidenavLayout();
        initCollapseToggle();
        initMobileDrawerToggle();
    }

    document.addEventListener('DOMContentLoaded', init);
    window.addEventListener('load', fixClientSidenavLayout);
    window.addEventListener('resize', function () {
        ensureDesktopPinned();
        fixClientSidenavLayout();
    });
})();
