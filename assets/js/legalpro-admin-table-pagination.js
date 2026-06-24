/**
 * Client-side pagination for admin portal data tables.
 */
(function (global) {
    'use strict';

    var instances = new WeakMap();

    function pageButton(label, page, options) {
        options = options || {};
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lp-admin-pagination__btn';
        if (options.nav) {
            btn.className += ' lp-admin-pagination__btn--nav';
        }
        if (options.active) {
            btn.className += ' lp-admin-pagination__btn--active';
        }
        btn.textContent = label;
        btn.setAttribute('aria-label', options.ariaLabel || ('Page ' + label));
        if (options.disabled) {
            btn.disabled = true;
        } else if (page) {
            btn.addEventListener('click', function () {
                options.onPage(page);
            });
        }
        return btn;
    }

    function ellipsis() {
        var span = document.createElement('span');
        span.className = 'lp-admin-pagination__ellipsis';
        span.textContent = '…';
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    function visiblePages(currentPage, totalPages) {
        if (totalPages <= 7) {
            var all = [];
            for (var p = 1; p <= totalPages; p++) {
                all.push(p);
            }
            return all;
        }
        var pages = [1];
        var start = Math.max(2, currentPage - 1);
        var end = Math.min(totalPages - 1, currentPage + 1);
        if (start > 2) {
            pages.push('gap');
        }
        for (var i = start; i <= end; i++) {
            pages.push(i);
        }
        if (end < totalPages - 1) {
            pages.push('gap');
        }
        pages.push(totalPages);
        return pages;
    }

    function getRows(wrap) {
        var selector = wrap.getAttribute('data-lp-row') || '.legalpro-admin-list-row';
        var tbody = wrap.querySelector('tbody');
        if (!tbody) {
            return [];
        }
        return Array.prototype.slice.call(tbody.querySelectorAll(selector)).filter(function (row) {
            if (row.id && row.id.indexOf('FilterEmpty') !== -1) {
                return false;
            }
            if (row.classList.contains('lp-admin-pagination-skip')) {
                return false;
            }
            return true;
        });
    }

    function getActiveRows(rows) {
        return rows.filter(function (row) {
            return !row.classList.contains('lp-admin-row-filtered');
        });
    }

    function renderControls(state) {
        var pagesEl = state.pagesEl;
        if (!pagesEl) {
            return;
        }
        pagesEl.innerHTML = '';
        var onPage = function (page) {
            showPage(state, page);
        };
        pagesEl.appendChild(pageButton('‹ Prev', state.currentPage - 1, {
            nav: true,
            disabled: state.currentPage === 1,
            ariaLabel: 'Previous page',
            onPage: onPage
        }));
        visiblePages(state.currentPage, state.totalPages).forEach(function (page) {
            if (page === 'gap') {
                pagesEl.appendChild(ellipsis());
                return;
            }
            pagesEl.appendChild(pageButton(String(page), page, {
                active: page === state.currentPage,
                ariaLabel: 'Page ' + page + (page === state.currentPage ? ', current' : ''),
                onPage: onPage
            }));
        });
        pagesEl.appendChild(pageButton('Next ›', state.currentPage + 1, {
            nav: true,
            disabled: state.currentPage === state.totalPages,
            ariaLabel: 'Next page',
            onPage: onPage
        }));
    }

    function applyVisibility(state) {
        var activeRows = getActiveRows(state.allRows);
        state.totalPages = Math.max(1, Math.ceil(activeRows.length / state.perPage) || 1);
        if (state.currentPage > state.totalPages) {
            state.currentPage = state.totalPages;
        }

        state.allRows.forEach(function (row) {
            row.classList.add('lp-admin-row-page-hidden');
        });

        var start = (state.currentPage - 1) * state.perPage;
        var end = start + state.perPage;
        activeRows.forEach(function (row, index) {
            if (index >= start && index < end) {
                row.classList.remove('lp-admin-row-page-hidden');
            }
        });

        if (state.rangeEl) {
            if (!activeRows.length) {
                state.rangeEl.textContent = 'No rows to display';
            } else {
                var showStart = start + 1;
                var showEnd = Math.min(end, activeRows.length);
                state.rangeEl.textContent = 'Showing ' + showStart + '–' + showEnd + ' of ' + activeRows.length;
            }
        }

        if (state.nav) {
            state.nav.hidden = activeRows.length <= state.perPage;
        }

        renderControls(state);
    }

    function showPage(state, page) {
        state.currentPage = Math.max(1, Math.min(state.totalPages, page));
        applyVisibility(state);
    }

    function initWrap(wrap) {
        if (!wrap || instances.has(wrap)) {
            return;
        }

        var perPage = parseInt(wrap.getAttribute('data-lp-per-page') || '10', 10);
        if (!perPage || perPage < 1) {
            perPage = 10;
        }

        var nav = wrap.querySelector('[data-lp-pagination-nav]');
        var state = {
            wrap: wrap,
            perPage: perPage,
            nav: nav,
            rangeEl: nav ? nav.querySelector('[data-lp-range]') : null,
            pagesEl: nav ? nav.querySelector('[data-lp-pages]') : null,
            allRows: getRows(wrap),
            currentPage: 1,
            totalPages: 1
        };

        instances.set(wrap, state);
        applyVisibility(state);
    }

    function refreshWrap(wrap) {
        if (!wrap) {
            return;
        }
        var state = instances.get(wrap);
        if (!state) {
            initWrap(wrap);
            return;
        }
        state.allRows = getRows(wrap);
        applyVisibility(state);
    }

    function initAll(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-lp-admin-paginate]').forEach(initWrap);
    }

    function refreshAll(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-lp-admin-paginate]').forEach(refreshWrap);
    }

    global.LegalproAdminTablePagination = {
        init: initAll,
        refresh: refreshWrap,
        refreshAll: refreshAll
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAll();
        });
    } else {
        initAll();
    }
})(window);
