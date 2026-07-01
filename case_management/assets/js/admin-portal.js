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
        var i18n = window.LEGALPRO_ADMIN_I18N || {};
        var panel = qs('#legalproNotifPanel');
        return (panel && panel.getAttribute('data-unread-hint')) || i18n.unreadHint || 'New — not yet seen';
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
        var i18n = window.LEGALPRO_ADMIN_I18N || {};
        if (!items || !items.length) {
            var tpl = qs('#adminNotifEmptyTpl');
            listEl.innerHTML = tpl ? tpl.innerHTML : '<div class="legalpro-notif-panel__empty"><p>' + escapeHtml(i18n.emptyTitle || 'No new notifications') + '</p></div>';
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
                    var i18n = window.LEGALPRO_ADMIN_I18N || {};
                    listEl.innerHTML = '<div class="text-muted text-sm p-3">' + escapeHtml(i18n.loadError || 'Could not load notifications.') + '</div>';
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

    function initThemeToggle() {
        var btn = qs('#adminThemeToggle');
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

            fetch('admin-theme-api.php', {
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

            e.stopPropagation();

            var key = item.getAttribute('data-notif-key');
            var href = item.getAttribute('href') || '';
            var navigate = function () {
                if (href && href !== '#') {
                    window.location.assign(href);
                }
            };

            if (!key) {
                navigate();
                return;
            }

            e.preventDefault();
            markRead(key)
                .then(function (data) {
                    if (data && typeof data.unread === 'number') {
                        updateNotifBadge(data.unread);
                    }
                })
                .finally(navigate);
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

    document.addEventListener('DOMContentLoaded', function () {
        initThemeToggle();
        initAdminNotifications();
    });
})();
