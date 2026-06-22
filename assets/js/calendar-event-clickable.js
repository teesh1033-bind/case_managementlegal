/**
 * FullCalendar — full-width clickable event cards + clickable day cells (all portals).
 */
(function () {
    'use strict';

    function getEventsOnDate(calendar, date) {
        if (!calendar || !date) {
            return [];
        }

        return calendar.getEvents().filter(function (ev) {
            if (!ev.start) {
                return false;
            }
            var start = ev.start;
            return start.getFullYear() === date.getFullYear()
                && start.getMonth() === date.getMonth()
                && start.getDate() === date.getDate();
        }).sort(function (a, b) {
            var aTime = a.start ? a.start.getTime() : 0;
            var bTime = b.start ? b.start.getTime() : 0;
            return aTime - bTime;
        });
    }

    function mountCalendarEventClickable(info) {
        if (!info || !info.el) {
            return;
        }

        var el = info.el;
        el.classList.add('legalpro-cal-event--clickable');
        el.style.cursor = 'pointer';
        el.style.display = 'block';
        el.style.width = '100%';
        el.style.minHeight = '1.55rem';

        var main = el.querySelector('.fc-event-main');
        if (main) {
            main.style.display = 'block';
            main.style.width = '100%';
            main.style.minHeight = '1.55rem';
            main.style.cursor = 'pointer';
        }

        var card = el.querySelector('.dashboard-cal-event');
        if (card) {
            card.style.width = '100%';
            card.style.boxSizing = 'border-box';
            card.style.minHeight = '1.55rem';
            card.style.cursor = 'pointer';
        }
    }

    function mountCalendarDayCell(info) {
        if (!info || !info.el || !info.view || !info.date) {
            return;
        }

        var events = getEventsOnDate(info.view.calendar, info.date);
        if (events.length > 0) {
            info.el.classList.add('lp-cal-day-has-events');
            info.el.setAttribute('data-event-count', String(events.length));
        }
    }

    function handleCalendarDateClick(info, openEvent) {
        if (!info || !info.view || !info.date || typeof openEvent !== 'function') {
            return;
        }

        var events = getEventsOnDate(info.view.calendar, info.date);
        if (events.length > 0) {
            openEvent(events[0], events, info);
        }
    }

    window.legalproGetCalendarEventsOnDate = getEventsOnDate;
    window.legalproMountCalendarEventClickable = mountCalendarEventClickable;
    window.legalproMountCalendarDayCell = mountCalendarDayCell;
    window.legalproHandleCalendarDateClick = handleCalendarDateClick;
})();
