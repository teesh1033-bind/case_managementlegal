(function () {
    'use strict';

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function updateNotifBadge(count) {
        var badge = qs('[data-notif-count]');
        if (!badge) {
            return;
        }
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.style.display = '';
            badge.removeAttribute('hidden');
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
        }
    }

    function getUnreadHint() {
        var panel = qs('#legalproNotifPanel');
        return (panel && panel.getAttribute('data-unread-hint')) || 'New — not yet seen';
    }

    function buildNotifItem(n) {
        var href = n.link_url || n.url || '#';
        var type = escapeHtml(n.type || 'default');
        var icon = escapeHtml(n.icon || 'bell');
        var hint = escapeHtml(getUnreadHint());
        return '<a href="' + escapeHtml(href) + '" class="legalpro-notif-item is-unread" data-notif-key="' + escapeHtml(n.key || '') + '" title="' + hint + '">'
            + '<span class="legalpro-notif-item__icon legalpro-notif-item__icon--' + type + '">'
            + '<i data-lucide="' + icon + '" class="lp-icon" aria-hidden="true"></i>'
            + '</span>'
            + '<span class="legalpro-notif-item__body">'
            + '<span class="legalpro-notif-item__title">' + escapeHtml(n.title || '') + '</span>'
            + '<span class="legalpro-notif-item__message">' + escapeHtml(n.message || '') + '</span>'
            + '<span class="legalpro-notif-item__time">' + escapeHtml(n.time || '') + '</span>'
            + '</span>'
            + '<span class="legalpro-notif-item__hover-caption" role="tooltip">' + hint + '</span>'
            + '</a>';
    }

    function renderNotifications(listEl, items) {
        if (!listEl) {
            return;
        }
        if (!items || !items.length) {
            var tpl = qs('#adminNotifEmptyTpl');
            listEl.innerHTML = tpl ? tpl.innerHTML : '<div class="legalpro-notif-panel__empty"><p>No new notifications</p></div>';
            return;
        }
        listEl.innerHTML = items.map(buildNotifItem).join('');
        if (typeof window.legalproInitIcons === 'function') {
            window.legalproInitIcons(listEl);
        }
    }

    function loadNotifications(listEl) {
        return fetch('admin-notifications-api.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                renderNotifications(listEl, data.notifications || []);
                updateNotifBadge(data.unread || 0);
            })
            .catch(function () {
                if (listEl) {
                    listEl.innerHTML = '<div class="text-muted text-sm p-3">Could not load notifications.</div>';
                }
            });
    }

    function markRead(key) {
        var body = new URLSearchParams();
        body.set('action', 'mark_read');
        body.set('key', key);
        return fetch('admin-notifications-api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function initAdminNotifications() {
        var wrap = qs('[data-admin-notif-api="1"]');
        if (!wrap) {
            return;
        }

        var panel = qs('#legalproNotifPanel', wrap);
        var listEl = qs('#adminNotifList', wrap);
        if (!panel || !listEl) {
            return;
        }

        loadNotifications(listEl);

        wrap.addEventListener('legalpro:notif-open', function () {
            loadNotifications(listEl);
        });

        panel.addEventListener('click', function (e) {
            var item = e.target.closest('.legalpro-notif-item');
            if (!item) {
                return;
            }
            var key = item.getAttribute('data-notif-key');
            if (!key) {
                return;
            }
            markRead(key).then(function (data) {
                if (data && typeof data.unread === 'number') {
                    updateNotifBadge(data.unread);
                }
            });
        });

        var markAll = qs('#adminNotifMarkAll', wrap);
        if (markAll) {
            markAll.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var body = new URLSearchParams();
                body.set('action', 'mark_all_read');
                fetch('admin-notifications-api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.ok) {
                        renderNotifications(listEl, []);
                        updateNotifBadge(0);
                    }
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', initAdminNotifications);
})();
