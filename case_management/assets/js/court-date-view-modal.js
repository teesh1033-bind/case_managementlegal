/**
 * Court date details modal — populate & open (admin, lawyer, client).
 */
(function () {
    'use strict';

    function i18n(key, fallback) {
        var dict = window.clientPortalI18n || {};
        return dict[key] || fallback || key;
    }

    function dateLocale() {
        return (window.clientPortalI18n && window.clientPortalI18n.date_locale) || 'en-GB';
    }

    function STATUS_LABELS() {
        return {
            scheduled: i18n('status_scheduled', 'Scheduled'),
            completed: i18n('status_completed', 'Completed'),
            cancelled: i18n('status_cancelled', 'Cancelled'),
            postponed: i18n('status_postponed', 'Postponed')
        };
    }

    function formatCourtDateDisplay(dateStr) {
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) {
            return dateStr || '—';
        }
        var datePart = d.toLocaleDateString(dateLocale(), {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
        var timePart = d.toLocaleTimeString(dateLocale(), {
            hour: '2-digit',
            minute: '2-digit'
        });
        return datePart + ' · ' + timePart;
    }

    function statusClass(statusKey, pillMode) {
        var key = String(statusKey || 'scheduled').toLowerCase();
        if (pillMode === 'lp') {
            var lp = {
                scheduled: 'lp-pill lp-pill--status-progress',
                completed: 'lp-pill lp-pill--status-closed',
                cancelled: 'lp-pill lp-pill--status-declined',
                postponed: 'lp-pill lp-pill--status-pending'
            };
            return lp[key] || 'lp-pill lp-pill--status-default';
        }
        return 'legalpro-court-detail__status legalpro-court-detail__status--' + (['scheduled', 'completed', 'cancelled', 'postponed'].indexOf(key) !== -1 ? key : 'scheduled');
    }

    function setText(id, value, fallback) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value || fallback || '—';
        }
    }

    window.legalproOpenCourtDateViewModal = function (eventData, options) {
        options = options || {};
        if (!eventData) {
            return;
        }

        var statusKey = String(eventData.status || 'scheduled').toLowerCase();
        var statusEl = document.getElementById('view_status');
        var titleEl = document.getElementById('viewCourtDateModalLabel');
        var descWrap = document.getElementById('view_description_wrap');
        var labels = STATUS_LABELS();

        if (titleEl) {
            titleEl.textContent = eventData.title || i18n('court_date_fallback', 'Court date');
        }
        setText('view_datetime', formatCourtDateDisplay(eventData.court_date));
        setText('view_case_title', eventData.case_title);
        setText('view_client_name', eventData.client_name);
        setText('view_location', eventData.location, i18n('not_specified', 'Not specified'));
        setText('view_created_by', eventData.created_by_name, i18n('unknown', 'Unknown'));

        var roleEl = document.getElementById('view_creator_role');
        if (roleEl) {
            var role = eventData.creator_role
                ? String(eventData.creator_role).charAt(0).toUpperCase() + String(eventData.creator_role).slice(1)
                : '';
            roleEl.textContent = role ? role : '';
            roleEl.style.display = role ? '' : 'none';
        }

        var description = (eventData.description || '').trim();
        setText('view_description', description, i18n('no_description', 'No description provided.'));
        if (descWrap) {
            descWrap.classList.toggle('legalpro-court-detail__notes--empty', !description);
        }

        if (statusEl) {
            statusEl.textContent = labels[statusKey] || (statusKey.charAt(0).toUpperCase() + statusKey.slice(1));
            statusEl.className = statusClass(statusKey, options.pillMode || 'detail');
        }

        bindCourtDateModalDismiss();

        var modalEl = document.getElementById('viewCourtDateModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            if (typeof legalproInitIcons === 'function') {
                legalproInitIcons(modalEl);
            } else if (typeof lucide !== 'undefined') {
                lucide.createIcons({ attrs: { 'stroke-width': 1.75 }, nameAttr: 'data-lucide', root: modalEl });
            }
        }
    };

    function hideCourtDateModal() {
        var modalEl = document.getElementById('viewCourtDateModal');
        if (!modalEl) {
            return;
        }
        if (typeof bootstrap === 'undefined') {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            modalEl.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            document.querySelectorAll('.modal-backdrop').forEach(function (el) {
                el.remove();
            });
            return;
        }
        var inst = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
        if (inst) {
            inst.hide();
        }
    }

    function bindCourtDateModalDismiss() {
        var modalEl = document.getElementById('viewCourtDateModal');
        if (!modalEl || modalEl._legalproDismissBound) {
            return;
        }
        modalEl._legalproDismissBound = true;
        modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                hideCourtDateModal();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', bindCourtDateModalDismiss);
})();
