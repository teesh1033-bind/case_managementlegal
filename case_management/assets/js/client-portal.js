(function () {
    'use strict';

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

    function renderNotifications(items) {
        var list = qs('#clientNotifList');
        if (!list) return;

        if (!items || !items.length) {
            var tpl = qs('#clientNotifEmptyTpl');
            list.innerHTML = tpl ? tpl.innerHTML : '<div class="p-4 text-center text-muted text-sm">No notifications</div>';
            return;
        }

        list.innerHTML = items.map(function (n) {
            var unread = n.is_read ? '' : ' is-unread';
            var href = n.link_url || '#';
            return '<a href="' + href + '" class="legalpro-client-notif-item' + unread + '" data-notif-id="' + n.id + '">'
                + '<span class="legalpro-client-notif-item__body">'
                + '<strong class="legalpro-client-notif-item__title">' + escapeHtml(n.title) + '</strong>'
                + '<span class="legalpro-client-notif-item__text">' + escapeHtml(n.body || '') + '</span>'
                + '</span>'
                + '<time class="legalpro-client-notif-item__time" datetime="' + escapeHtml(n.time_iso || '') + '" title="' + escapeHtml(n.time_label || n.time_ago || '') + '">'
                + escapeHtml(n.time_label || n.time_ago || '') + '</time>'
                + '</a>';
        }).join('');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadNotifications() {
        return fetch('client-notifications-api.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                renderNotifications(data.notifications || []);
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

    function initNotificationPanel() {
        var bell = qs('#clientNotifBell');
        var panel = qs('#clientNotifPanel');
        if (!bell || !panel) return;

        function openPanel() {
            panel.hidden = false;
            bell.setAttribute('aria-expanded', 'true');
            document.body.classList.add('client-notif-open');
            loadNotifications();
        }

        function closePanel() {
            panel.hidden = true;
            bell.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('client-notif-open');
        }

        bell.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (panel.hidden) openPanel();
            else closePanel();
        });

        qsa('[data-notif-close]', panel).forEach(function (el) {
            el.addEventListener('click', closePanel);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) closePanel();
        });

        panel.addEventListener('click', function (e) {
            var item = e.target.closest('.legalpro-client-notif-item');
            if (!item) return;
            var id = item.getAttribute('data-notif-id');
            if (id) {
                markRead(id).then(function (data) {
                    if (data && typeof data.unread === 'number') updateNotifBadge(data.unread);
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
                        qsa('.legalpro-client-notif-item', panel).forEach(function (el) {
                            el.classList.remove('is-unread');
                        });
                        updateNotifBadge(0);
                    }
                });
            });
        }
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
                landmark: '⚖️',
                'message-circle': '💬',
                briefcase: '💼',
                bell: '🔔'
            };
            el.textContent = map[name] || '•';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNotificationPanel();
        initMoreSheet();
        initActivityIcons();
    });
})();
