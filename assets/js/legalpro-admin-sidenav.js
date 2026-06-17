(function () {
    'use strict';

    function isAdminPortal() {
        return document.body && document.body.classList.contains('legalpro-admin-portal');
    }

    function destroySidenavPerfectScrollbar() {
        var sidenav = document.getElementById('sidenav-main');
        if (!sidenav) {
            return;
        }

        sidenav.classList.remove('ps', 'ps--active-y', 'ps--active-x');
        sidenav.style.overflow = 'hidden';
        sidenav.querySelectorAll('.ps__rail-x, .ps__rail-y, .ps__thumb-x, .ps__thumb-y').forEach(function (node) {
            node.remove();
        });
    }

    window.legalproAdminSidenavFix = function legalproAdminSidenavFix() {
        if (!isAdminPortal()) {
            return;
        }

        var body = document.body;
        var sidenav = document.getElementById('sidenav-main');
        var navWrap = document.getElementById('sidenav-collapse-main');
        var isCollapsed = body.classList.contains('legalpro-sidebar-collapsed');

        body.classList.remove('g-sidenav-hidden');
        body.classList.add('g-sidenav-pinned');
        body.classList.add('g-sidenav-show');

        destroySidenavPerfectScrollbar();

        if (navWrap) {
            navWrap.classList.add('show');
            navWrap.style.removeProperty('display');
            navWrap.style.removeProperty('height');
            navWrap.style.removeProperty('max-height');
            navWrap.style.minHeight = '0';
            navWrap.style.width = '100%';
            navWrap.style.maxWidth = '100%';
            navWrap.style.overflowY = isCollapsed ? '' : 'auto';
            navWrap.style.overflowX = 'hidden';
        }

        if (sidenav) {
            sidenav.style.maxWidth = '';
            sidenav.style.width = '';
        }
    };

    function scheduleFix() {
        window.requestAnimationFrame(function () {
            window.legalproAdminSidenavFix();
        });
    }

    document.addEventListener('DOMContentLoaded', scheduleFix);
    window.addEventListener('load', scheduleFix);
    if (document.readyState !== 'loading') {
        scheduleFix();
    }

    document.addEventListener('click', function (event) {
        if (!isAdminPortal()) {
            return;
        }
        if (event.target.closest('.legalpro-sidebar-nav-group__toggle')) {
            scheduleFix();
            window.setTimeout(scheduleFix, 50);
        }
    });
})();
