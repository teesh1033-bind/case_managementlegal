/**
 * Client appointment details modal — populate, open & dismiss.
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
            pending: i18n('status_pending', 'Pending'),
            accepted: i18n('status_accepted', 'Accepted'),
            rejected: i18n('status_rejected', 'Rejected')
        };
    }

    function formatAppointmentDateTime(dateStr) {
        if (!dateStr) {
            return '—';
        }
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) {
            return dateStr;
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

    function formatAppointmentDisplay(dateStr) {
        if (!dateStr) {
            return '—';
        }
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) {
            return dateStr;
        }
        return d.toLocaleDateString(dateLocale(), {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function statusClass(statusKey) {
        var key = String(statusKey || 'pending').toLowerCase();
        var map = {
            accepted: 'completed',
            pending: 'scheduled',
            rejected: 'cancelled'
        };
        var tone = map[key] || 'scheduled';
        return 'legalpro-court-detail__status legalpro-court-detail__status--' + tone;
    }

    function setText(id, value, fallback) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value || fallback || '—';
        }
    }

    function hideModal(modalEl) {
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

    function bindDismiss(modalEl) {
        if (!modalEl || modalEl._legalproDismissBound) {
            return;
        }
        modalEl._legalproDismissBound = true;
        modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                hideModal(modalEl);
            });
        });
    }

    window.legalproOpenAppointmentViewModal = function (data) {
        if (!data) {
            return;
        }

        var modalEl = document.getElementById('viewAppointmentModal');
        if (!modalEl) {
            return;
        }

        bindDismiss(modalEl);

        var statusKey = String(data.status || 'pending').toLowerCase();
        var statusEl = document.getElementById('view_apt_status');
        var notesWrap = document.getElementById('view_apt_notes_wrap');
        var startsRaw = data.starts_at_raw || data.starts_at || '';
        var labels = STATUS_LABELS();

        setText('viewAppointmentModalLabel', data.case_title, i18n('appointment_fallback', 'Appointment'));
        setText('view_apt_datetime', formatAppointmentDateTime(startsRaw));
        setText('view_apt_case', data.case_title);
        setText('view_apt_lawyer', data.lawyer_name, i18n('tbd', 'TBD'));
        setText('view_apt_starts', formatAppointmentDisplay(startsRaw));
        setText('view_apt_ends', data.ends_at_raw ? formatAppointmentDisplay(data.ends_at_raw) : (data.ends_at || '—'), '—');

        var notes = (data.notes || '').trim();
        setText('view_apt_notes', notes, i18n('no_notes', 'No notes provided.'));
        if (notesWrap) {
            notesWrap.classList.toggle('legalpro-court-detail__notes--empty', !notes);
        }

        if (statusEl) {
            statusEl.textContent = data.status_label || labels[statusKey] || statusKey;
            statusEl.className = statusClass(statusKey);
        }

        if (typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            modalEl.removeAttribute('aria-hidden');
        }

        if (typeof legalproInitIcons === 'function') {
            legalproInitIcons(modalEl);
        } else if (typeof lucide !== 'undefined') {
            lucide.createIcons({ attrs: { 'stroke-width': 1.75 }, nameAttr: 'data-lucide', root: modalEl });
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        var courtModal = document.getElementById('viewCourtDateModal');
        var aptModal = document.getElementById('viewAppointmentModal');
        if (courtModal) {
            bindDismiss(courtModal);
        }
        if (aptModal) {
            bindDismiss(aptModal);
        }
    });
})();
