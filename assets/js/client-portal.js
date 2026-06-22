(function () {
    'use strict';

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
        return (panel && panel.getAttribute('data-unread-hint')) || 'New — not yet seen';
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
            list.innerHTML = tpl ? tpl.innerHTML : '<div class="legalpro-notif-panel__empty"><p>No notifications</p></div>';
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

        filterClientSearchRows('tr.ca-row[data-search]', '#caCount', 'appointment', 'appointments', ' total');
        filterClientSearchRows('.cp-invoice-row.cp-search-row', '#cpInvoiceCount', 'invoice', 'invoices', ' total');
        filterClientSearchRows('.cp-payment-row.cp-search-row', '#cpPaymentCount', 'payment', 'payments', ' total');
        filterClientSearchRows('.cct-search-row', '#cctRowCount', 'court date', 'court dates', ' total');

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
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initClientPageSearch();
        initNotificationDropdown();
        initMoreSheet();
        initActivityIcons();
    });
})();
