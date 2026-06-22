(function (window) {
    'use strict';

    window.LegalProCalendar = window.LegalProCalendar || {};

    function parseDayStart(dateInput) {
        if (dateInput instanceof Date) {
            var copy = new Date(dateInput);
            copy.setHours(0, 0, 0, 0);
            return copy;
        }
        return new Date(String(dateInput) + 'T00:00:00');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    LegalProCalendar.getEventsForDay = function (calendar, dateInput) {
        var dayStart = parseDayStart(dateInput);
        var dayEnd = new Date(dayStart);
        dayEnd.setDate(dayEnd.getDate() + 1);

        return calendar.getEvents().filter(function (ev) {
            if (!ev.start) {
                return false;
            }
            var evEnd = ev.end ? new Date(ev.end) : new Date(ev.start);
            return ev.start < dayEnd && evEnd >= dayStart;
        }).sort(function (a, b) {
            return (a.start || 0) - (b.start || 0);
        });
    };

    LegalProCalendar.formatEventTime = function (event) {
        if (!event.start) {
            return '';
        }
        return event.start.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });
    };

    function removePicker() {
        var existing = document.querySelector('.legalpro-cal-day-picker');
        if (existing) {
            existing.remove();
        }
    }

    LegalProCalendar.showDayEventPicker = function (events, anchorEvent, onSelect) {
        removePicker();
        if (!events.length) {
            return;
        }
        if (events.length === 1) {
            onSelect(events[0]);
            return;
        }

        var picker = document.createElement('div');
        picker.className = 'legalpro-cal-day-picker';
        picker.setAttribute('role', 'menu');

        var html = '<div class="legalpro-cal-day-picker__head">Select an event</div><ul class="legalpro-cal-day-picker__list">';
        events.forEach(function (ev, index) {
            var time = LegalProCalendar.formatEventTime(ev);
            var title = ev.title || 'Event';
            html += '<li><button type="button" class="legalpro-cal-day-picker__item" data-index="' + index + '">';
            html += '<span class="legalpro-cal-day-picker__time">' + escapeHtml(time) + '</span>';
            html += '<span class="legalpro-cal-day-picker__title">' + escapeHtml(title) + '</span>';
            html += '</button></li>';
        });
        html += '</ul>';
        picker.innerHTML = html;

        document.body.appendChild(picker);

        var rect = picker.getBoundingClientRect();
        var left = Math.min(anchorEvent.clientX, window.innerWidth - rect.width - 12);
        var top = Math.min(anchorEvent.clientY, window.innerHeight - rect.height - 12);
        picker.style.left = Math.max(12, left) + 'px';
        picker.style.top = Math.max(12, top) + 'px';

        picker.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-index]');
            if (!btn) {
                return;
            }
            var idx = parseInt(btn.getAttribute('data-index'), 10);
            removePicker();
            onSelect(events[idx]);
        });

        setTimeout(function () {
            function outsideClick(e) {
                if (!picker.contains(e.target)) {
                    removePicker();
                    document.removeEventListener('click', outsideClick);
                }
            }
            document.addEventListener('click', outsideClick);
        }, 0);
    };

    LegalProCalendar.markDaysWithEvents = function (calendar) {
        var root = calendar.el;
        if (!root) {
            return;
        }
        root.querySelectorAll('.fc-daygrid-day').forEach(function (cell) {
            cell.classList.remove('fc-day-has-events');
            var dateStr = cell.getAttribute('data-date');
            if (!dateStr) {
                return;
            }
            if (LegalProCalendar.getEventsForDay(calendar, dateStr).length) {
                cell.classList.add('fc-day-has-events');
            }
        });
    };

    LegalProCalendar.attachDayCellClicks = function (calendarEl, calendar, onEventSelect) {
        if (!calendarEl || calendarEl._legalproDayClickBound) {
            return;
        }
        calendarEl._legalproDayClickBound = true;

        calendarEl.addEventListener('click', function (e) {
            if (e.target.closest('.fc-event') || e.target.closest('.fc-daygrid-more-link')) {
                return;
            }

            var dayCell = e.target.closest('.fc-daygrid-day');
            if (!dayCell) {
                return;
            }

            var dateStr = dayCell.getAttribute('data-date');
            if (!dateStr) {
                return;
            }

            var events = LegalProCalendar.getEventsForDay(calendar, dateStr);
            if (!events.length) {
                return;
            }

            e.preventDefault();
            e.stopPropagation();
            LegalProCalendar.showDayEventPicker(events, e, onEventSelect);
        }, true);
    };

    LegalProCalendar.enhance = function (calendarEl, calendar, onEventSelect) {
        if (!calendarEl || !calendar || typeof onEventSelect !== 'function') {
            return;
        }

        LegalProCalendar.attachDayCellClicks(calendarEl, calendar, onEventSelect);
        calendar.on('datesSet', function () {
            LegalProCalendar.markDaysWithEvents(calendar);
        });
        LegalProCalendar.markDaysWithEvents(calendar);
    };
}(window));
