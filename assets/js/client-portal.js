(function () {
    'use strict';

    function i18n(key, replace) {
        var dict = window.clientPortalI18n || {};
        var text = dict[key] || key;
        if (replace) {
            Object.keys(replace).forEach(function (k) {
                text = String(text).split(':' + k).join(String(replace[k]));
            });
        }
        return text;
    }

    function dateLocale() {
        return (window.clientPortalI18n && window.clientPortalI18n.date_locale) || 'en-GB';
    }

    var PREVIEW_LIMIT = 5;
    var cachedNotifications = [];

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function updateNotifBadge(count) {
        var badge = qs('[data-notif-count]');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.style.display = '';
            badge.removeAttribute('hidden');
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getUnreadHint() {
        var panel = qs('#clientNotifPanel');
        return (panel && panel.getAttribute('data-unread-hint')) || i18n('unread_hint');
    }

    function buildNotifItem(n) {
        var unread = n.is_read ? '' : ' is-unread';
        var href = n.link_url || '#';
        var hint = '';
        var caption = '';
        if (!n.is_read) {
            hint = escapeHtml(getUnreadHint());
            caption = '<span class="legalpro-notif-item__hover-caption" role="tooltip">' + hint + '</span>';
        }
        return '<a href="' + href + '" class="legalpro-notif-item' + unread + '" data-notif-id="' + n.id + '"'
            + (hint ? ' title="' + hint + '"' : '')
            + '>'
            + '<span class="legalpro-notif-item__body">'
            + '<span class="legalpro-notif-item__title">' + escapeHtml(n.title) + '</span>'
            + '<span class="legalpro-notif-item__message">' + escapeHtml(n.body || '') + '</span>'
            + '<span class="legalpro-notif-item__time">' + escapeHtml(n.time_label || n.time_ago || '') + '</span>'
            + '</span>'
            + caption
            + '</a>';
    }

    function renderNotifications(items) {
        var list = qs('#clientNotifList');
        if (!list) return;

        var allItems = items || [];
        var visible = allItems.slice(0, PREVIEW_LIMIT);

        if (!visible.length) {
            var tpl = qs('#clientNotifEmptyTpl');
            list.innerHTML = tpl ? tpl.innerHTML : '<div class="legalpro-notif-panel__empty"><p>' + escapeHtml(i18n('no_notifications')) + '</p></div>';
            return;
        }

        list.innerHTML = visible.map(buildNotifItem).join('');
    }

    function loadNotifications() {
        return fetch('client-notifications-api.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                cachedNotifications = data.notifications || [];
                renderNotifications(cachedNotifications);
                updateNotifBadge(data.unread || 0);
            })
            .catch(function () {});
    }

    function markRead(id) {
        var body = new URLSearchParams();
        body.set('action', 'mark_read');
        body.set('id', String(id));
        return fetch('client-notifications-api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function initNotificationDropdown() {
        var bell = qs('#clientNotifBell');
        var panel = qs('#clientNotifPanel');
        var wrap = bell ? bell.closest('.legalpro-header-notif-wrap') : null;
        if (!bell || !panel) return;

        function openPanel() {
            panel.classList.add('show');
            bell.classList.add('show');
            bell.setAttribute('aria-expanded', 'true');
            loadNotifications();
        }

        function closePanel() {
            panel.classList.remove('show');
            bell.classList.remove('show');
            bell.setAttribute('aria-expanded', 'false');
            renderNotifications(cachedNotifications);
        }

        bell.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (panel.classList.contains('show')) closePanel();
            else openPanel();
        });

        panel.addEventListener('click', function (e) {
            e.stopPropagation();

            var item = e.target.closest('.legalpro-notif-item');
            if (!item) return;
            var id = item.getAttribute('data-notif-id');
            if (id) {
                markRead(id).then(function (data) {
                    if (data && typeof data.unread === 'number') updateNotifBadge(data.unread);
                    item.classList.remove('is-unread');
                });
            }
        });

        var markAll = qs('#clientNotifMarkAll');
        if (markAll) {
            markAll.addEventListener('click', function (e) {
                e.preventDefault();
                var body = new URLSearchParams();
                body.set('action', 'mark_all_read');
                fetch('client-notifications-api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.ok) {
                        qsa('.legalpro-notif-item', panel).forEach(function (el) {
                            el.classList.remove('is-unread');
                        });
                        updateNotifBadge(0);
                    }
                });
            });
        }

        document.addEventListener('click', function () {
            if (panel.classList.contains('show')) closePanel();
        });

        if (wrap) {
            wrap.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('show')) closePanel();
        });
    }

    function initMoreSheet() {
        var trigger = qs('[data-more-trigger]');
        var sheet = qs('#clientMoreSheet');
        if (!trigger || !sheet) return;

        function openSheet() {
            sheet.hidden = false;
            document.body.classList.add('client-more-open');
        }

        function closeSheet() {
            sheet.hidden = true;
            document.body.classList.remove('client-more-open');
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            openSheet();
        });

        qsa('[data-more-close]', sheet).forEach(function (el) {
            el.addEventListener('click', closeSheet);
        });
    }

    function initActivityFeedPagination() {
        var wrap = qs('.cd-activity-feed-wrap');
        if (!wrap) {
            return;
        }

        var perPage = parseInt(wrap.getAttribute('data-activity-per-page') || '6', 10);
        var items = qsa('.cp-activity-feed .cp-activity-item', wrap);
        if (!items.length || items.length <= perPage) {
            return;
        }

        var nav = qs('.cd-activity-pagination', wrap);
        var rangeEl = qs('[data-activity-range]', wrap);
        var pagesEl = qs('[data-activity-pages]', wrap);
        if (!nav || !rangeEl || !pagesEl) {
            return;
        }

        var currentPage = 1;
        var totalPages = Math.ceil(items.length / perPage);

        function pageButton(label, page, options) {
            options = options || {};
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cd-activity-pagination__btn';
            if (options.nav) {
                btn.className += ' cd-activity-pagination__btn--nav';
            }
            if (options.active) {
                btn.className += ' cd-activity-pagination__btn--active';
            }
            btn.textContent = label;
            btn.setAttribute('aria-label', options.ariaLabel || (i18n('page') + ' ' + label));
            if (options.disabled) {
                btn.disabled = true;
            } else if (page) {
                btn.addEventListener('click', function () {
                    showPage(page);
                });
            }
            return btn;
        }

        function ellipsis() {
            var span = document.createElement('span');
            span.className = 'cd-activity-pagination__ellipsis';
            span.textContent = '…';
            span.setAttribute('aria-hidden', 'true');
            return span;
        }

        function visiblePages() {
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

        function renderControls() {
            pagesEl.innerHTML = '';

            var prev = pageButton(i18n('prev_short'), currentPage - 1, {
                nav: true,
                disabled: currentPage === 1,
                ariaLabel: i18n('prev_page')
            });
            pagesEl.appendChild(prev);

            visiblePages().forEach(function (page) {
                if (page === 'gap') {
                    pagesEl.appendChild(ellipsis());
                    return;
                }
                pagesEl.appendChild(pageButton(String(page), page, {
                    active: page === currentPage,
                    ariaLabel: 'Page ' + page + (page === currentPage ? ', current' : '')
                }));
            });

            var next = pageButton(i18n('next_short'), currentPage + 1, {
                nav: true,
                disabled: currentPage === totalPages,
                ariaLabel: i18n('next_page')
            });
            pagesEl.appendChild(next);
        }

        function showPage(page) {
            currentPage = Math.max(1, Math.min(totalPages, page));
            items.forEach(function (item, index) {
                var itemPage = Math.floor(index / perPage) + 1;
                item.classList.toggle('cp-activity-item--hidden', itemPage !== currentPage);
            });

            var start = (currentPage - 1) * perPage + 1;
            var end = Math.min(currentPage * perPage, items.length);
            rangeEl.textContent = i18n('showing_range', { start: start, end: end, total: items.length });

            renderControls();
        }

        showPage(1);
    }

    function initActivityIcons() {
        qsa('.cp-activity-item__icon[data-icon]').forEach(function (el) {
            var name = el.getAttribute('data-icon');
            var map = {
                'file-text': '📄',
                calendar: '📅',
                'calendar-check': '✅',
                'calendar-clock': '⏳',
                receipt: '🧾',
                'credit-card': '💳',
                landmark: '⚖️',
                activity: '📌',
                'message-circle': '💬',
                briefcase: '💼',
                bell: '🔔'
            };
            el.textContent = map[name] || '•';
        });
    }

    function getClientPageSearchQuery() {
        return (new URLSearchParams(window.location.search).get('q') || '').trim();
    }

    function filterClientSearchRows(selector, countSelector, singular, plural, suffix) {
        var qLower = getClientPageSearchQuery().toLowerCase();
        var rows = qsa(selector);
        if (!rows.length) {
            return;
        }
        var visible = 0;
        rows.forEach(function (row) {
            if (!qLower) {
                row.style.display = '';
                visible++;
                return;
            }
            var hay = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
            var show = hay.indexOf(qLower) !== -1;
            row.style.display = show ? '' : 'none';
            if (show) {
                visible++;
            }
        });
        if (countSelector) {
            var countEl = qs(countSelector);
            if (countEl) {
                countEl.textContent = visible + ' ' + (visible === 1 ? singular : plural) + (suffix || '');
            }
        }
    }

    function initClientPageSearch() {
        if (!document.body.classList.contains('legalpro-client-portal')) {
            return;
        }

        var q = getClientPageSearchQuery();
        var ccSearch = qs('#ccSearch');
        if (ccSearch && q) {
            ccSearch.value = q;
        }

        if (typeof window.ccFilter === 'function') {
            window.ccFilter();
        } else {
            filterClientSearchRows('[data-search]', null, '', '', '');
        }

        filterClientSearchRows('tr.ca-row[data-search]', '#caCount', i18n('appointment'), i18n('appointments'), ' ' + i18n('total_suffix'));
        filterClientSearchRows('.cp-invoice-row.cp-search-row', '#cpInvoiceCount', i18n('invoice'), i18n('invoices'), ' ' + i18n('total_suffix'));
        filterClientSearchRows('.cp-payment-row.cp-search-row', '#cpPaymentCount', i18n('payment'), i18n('payments'), ' ' + i18n('total_suffix'));
        filterClientSearchRows('.cct-search-row', '#cctRowCount', i18n('court_date'), i18n('court_dates'), ' ' + i18n('total_suffix'));
        legalproResetClientTablePaginations();

        var navInput = qs('.legalpro-navbar-search input[name="q"]');
        if (navInput) {
            navInput.addEventListener('input', function () {
                var term = navInput.value.trim().toLowerCase();
                qsa('[data-search]').forEach(function (row) {
                    if (!term) {
                        row.style.display = '';
                        return;
                    }
                    var hay = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                    row.style.display = hay.indexOf(term) !== -1 ? '' : 'none';
                });
                if (typeof window.ccFilter === 'function') {
                    window.ccFilter();
                }
                legalproResetClientTablePaginations();
            });
        }
    }

    function legalproResetClientTablePaginations() {
        [
            'ccShowPage',
            'cpInvoiceShowPage',
            'cpPaymentShowPage',
            'cpQuotationShowPage',
            'cdocShowPage',
            'crRequestShowPage'
        ].forEach(function (name) {
            if (typeof window[name] === 'function') {
                window[name](1);
            }
        });
    }

    function legalproInitClientTablePagination(wrap) {
        if (!wrap || wrap.getAttribute('data-portal-pagination-ready') === '1') {
            return null;
        }

        var nav = wrap.querySelector('[data-portal-pagination]');
        var rowSelector = wrap.getAttribute('data-portal-row');
        if (!nav || !rowSelector) {
            return null;
        }

        var perPage = parseInt(wrap.getAttribute('data-portal-per-page') || '10', 10);
        var hiddenClass = wrap.getAttribute('data-portal-hidden-class') || 'cp-portal-row--off-page';
        var rows = qsa(rowSelector, wrap);
        var rangeEl = nav.querySelector('[data-portal-range]');
        var pagesEl = nav.querySelector('[data-portal-pages]');

        if (!rows.length || rows.length <= perPage) {
            nav.hidden = true;
            wrap.setAttribute('data-portal-pagination-ready', '1');
            return null;
        }

        nav.hidden = false;
        var currentPage = 1;
        var totalPages = Math.ceil(rows.length / perPage);
        var btnClass = 'cp-portal-pagination__btn';

        function pageButton(label, page, options) {
            options = options || {};
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = btnClass;
            if (options.nav) {
                btn.className += ' cp-portal-pagination__btn--nav';
            }
            if (options.active) {
                btn.className += ' cp-portal-pagination__btn--active';
            }
            btn.textContent = label;
            btn.setAttribute('aria-label', options.ariaLabel || (i18n('page') + ' ' + label));
            if (options.disabled) {
                btn.disabled = true;
            } else if (page) {
                btn.addEventListener('click', function () {
                    showPage(page);
                });
            }
            return btn;
        }

        function ellipsis() {
            var span = document.createElement('span');
            span.className = 'cp-portal-pagination__ellipsis';
            span.textContent = '…';
            span.setAttribute('aria-hidden', 'true');
            return span;
        }

        function visiblePages() {
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

        function renderControls() {
            if (!pagesEl) {
                return;
            }
            pagesEl.innerHTML = '';
            pagesEl.appendChild(pageButton(i18n('prev_short'), currentPage - 1, {
                nav: true,
                disabled: currentPage === 1,
                ariaLabel: i18n('prev_page')
            }));
            visiblePages().forEach(function (page) {
                if (page === 'gap') {
                    pagesEl.appendChild(ellipsis());
                    return;
                }
                pagesEl.appendChild(pageButton(String(page), page, {
                    active: page === currentPage,
                    ariaLabel: i18n('page') + ' ' + page + (page === currentPage ? ', ' + i18n('current') : '')
                }));
            });
            pagesEl.appendChild(pageButton(i18n('next_short'), currentPage + 1, {
                nav: true,
                disabled: currentPage === totalPages,
                ariaLabel: i18n('next_page')
            }));
        }

        function showPage(page) {
            currentPage = Math.max(1, Math.min(totalPages, page));
            rows.forEach(function (row, index) {
                var rowPage = Math.floor(index / perPage) + 1;
                row.classList.toggle(hiddenClass, rowPage !== currentPage);
            });

            var start = (currentPage - 1) * perPage + 1;
            var end = Math.min(currentPage * perPage, rows.length);
            if (rangeEl) {
                rangeEl.textContent = i18n('showing_range', { start: start, end: end, total: rows.length });
            }
            renderControls();
        }

        var globalName = wrap.getAttribute('data-portal-show-page-global');
        if (globalName) {
            window[globalName] = showPage;
        }

        wrap.setAttribute('data-portal-pagination-ready', '1');
        showPage(1);
        return showPage;
    }

    function initAllClientTablePaginations() {
        qsa('[data-portal-table-wrap]').forEach(function (wrap) {
            legalproInitClientTablePagination(wrap);
        });
    }

    function initThemeToggle() {
        var btn = qs('#clientThemeToggle');
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            if (btn.disabled) {
                return;
            }

            var current = btn.getAttribute('data-theme-mode') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            btn.disabled = true;

            fetch('client-theme-api.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ theme_mode: next })
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.ok) {
                        window.location.reload();
                        return;
                    }
                    btn.disabled = false;
                })
                .catch(function () {
                    btn.disabled = false;
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initClientPageSearch();
        initThemeToggle();
        initNotificationDropdown();
        initMoreSheet();
        initActivityIcons();
        initActivityFeedPagination();
        initAllClientTablePaginations();
    });

    window.legalproInitClientTablePagination = legalproInitClientTablePagination;
    window.legalproResetClientTablePaginations = legalproResetClientTablePaginations;
})();
