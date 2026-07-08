/**
 * Shared lp-cal-studio FullCalendar chrome (month rail, agenda, legend).
 */
(function (global) {
    'use strict';

    var monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    var defaultAppointmentDisplayLabels = {
        scheduled: 'Scheduled',
        confirmed: 'Confirmed',
        rescheduled: 'Rescheduled',
        past: 'Past',
        completed: 'Completed',
        cancelled: 'Cancelled'
    };

    function appointmentStatusKey(status) {
        var value = String(status || 'pending').toLowerCase();
        return value === 'approved' ? 'accepted' : value;
    }

    function appointmentDisplayKey(props, startValue) {
        var status = appointmentStatusKey(props.status);
        var notes = String(props.notes || '');
        var start = startValue instanceof Date ? startValue : new Date(startValue);
        var isPast = !isNaN(start.getTime()) && start < new Date();

        if (status === 'rejected') {
            return 'cancelled';
        }
        if (notes.indexOf('[Rescheduled') !== -1) {
            return 'rescheduled';
        }
        if (isPast) {
            return (status === 'accepted') ? 'completed' : 'past';
        }
        if (status === 'accepted') {
            return 'confirmed';
        }
        return 'scheduled';
    }

    function appointmentDisplayLabel(displayKey, labels) {
        var map = labels || defaultAppointmentDisplayLabels;
        return map[displayKey] || map.scheduled || 'Scheduled';
    }

    function courtDateDisplayKey(props, startValue) {
        var status = String(props.status || 'scheduled').toLowerCase();
        var start = startValue instanceof Date ? startValue : new Date(startValue);
        var isPast = !isNaN(start.getTime()) && start < new Date();

        if (status === 'cancelled') {
            return 'cancelled';
        }
        if (status === 'completed') {
            return 'completed';
        }
        if (status === 'postponed') {
            return 'rescheduled';
        }
        if (isPast) {
            return 'past';
        }
        return 'scheduled';
    }

    function defaultAgendaSub(props) {
        return props.agendaSub || props.client_name || props.client || props.lawyer || '';
    }

    function mountScheduleHub(userConfig) {
        userConfig = userConfig || {};
        var mode = userConfig.mode === 'court' ? 'court' : 'appointment';
        var labels = userConfig.displayLabels || null;
        var customAgendaSub = userConfig.getAgendaSub;

        var base = {
            firstDay: 1,
            getDisplayKey: mode === 'court'
                ? courtDateDisplayKey
                : appointmentDisplayKey,
            getDisplayLabel: function (key) {
                return appointmentDisplayLabel(key, labels);
            },
            getAgendaSub: function (props) {
                if (typeof customAgendaSub === 'function') {
                    return customAgendaSub(props);
                }
                return defaultAgendaSub(props);
            }
        };

        var merged = Object.assign({}, base, userConfig);
        delete merged.mode;
        delete merged.displayLabels;
        return mount(merged);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatAgendaDateTime(dateValue) {
        var date = dateValue instanceof Date ? dateValue : new Date(dateValue);
        if (isNaN(date.getTime())) {
            return '—';
        }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var hours = date.getHours();
        var minutes = String(date.getMinutes()).padStart(2, '0');
        var ampm = hours >= 12 ? 'pm' : 'am';
        var h12 = hours % 12;
        if (h12 === 0) {
            h12 = 12;
        }
        return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear() + ' ' +
            String(h12).padStart(2, '0') + ':' + minutes + ' ' + ampm;
    }

    function eventsOnDate(events, date) {
        var y = date.getFullYear();
        var m = date.getMonth();
        var d = date.getDate();
        return events.filter(function (ev) {
            var start = new Date(ev.start);
            return !isNaN(start.getTime()) &&
                start.getFullYear() === y &&
                start.getMonth() === m &&
                start.getDate() === d;
        });
    }

    function countEventsInMonth(events, year, monthIndex) {
        return events.filter(function (ev) {
            var start = new Date(ev.start);
            return !isNaN(start.getTime()) &&
                start.getFullYear() === year &&
                start.getMonth() === monthIndex;
        }).length;
    }

    function eventsInMonth(events, year, monthIndex) {
        return events.filter(function (ev) {
            var start = new Date(ev.start);
            return !isNaN(start.getTime()) &&
                start.getFullYear() === year &&
                start.getMonth() === monthIndex;
        }).sort(function (a, b) {
            return new Date(a.start).getTime() - new Date(b.start).getTime();
        });
    }

    function resolveEl(ref) {
        if (!ref) {
            return null;
        }
        if (typeof ref === 'string') {
            return document.querySelector(ref);
        }
        return ref;
    }

    var dayActionsEl = null;
    var dayActionsBackdropEl = null;
    var dayActionsOutsideHandler = null;

    function formatDayHeading(date) {
        var d = date instanceof Date ? date : new Date(date);
        if (isNaN(d.getTime())) {
            return '';
        }
        return monthNames[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function removeDayActions() {
        if (dayActionsEl) {
            dayActionsEl.remove();
            dayActionsEl = null;
        }
        if (dayActionsBackdropEl) {
            dayActionsBackdropEl.remove();
            dayActionsBackdropEl = null;
        }
        if (dayActionsOutsideHandler) {
            document.removeEventListener('click', dayActionsOutsideHandler, true);
            document.removeEventListener('keydown', dayActionsEscapeHandler, true);
            dayActionsOutsideHandler = null;
        }
    }

    function dayActionsEscapeHandler(e) {
        if (e.key === 'Escape') {
            removeDayActions();
        }
    }

    function toEventLike(ev) {
        return {
            id: ev.id,
            title: ev.title,
            start: ev.start,
            end: ev.end,
            extendedProps: ev.extendedProps || {}
        };
    }

    function triggerEventView(ev, config) {
        if (typeof config.onEventClick === 'function') {
            config.onEventClick(toEventLike(ev), {});
        } else if (typeof config.onAgendaItemClick === 'function') {
            var props = ev.extendedProps || {};
            var id = props.itemId || props.appointmentId || props.courtDateId || ev.id;
            config.onAgendaItemClick(id, ev);
        }
    }

    function showDayActions(anchorEl, ymd, date, dayEvents, config) {
        removeDayActions();

        var backdrop = document.createElement('div');
        backdrop.className = 'lp-cal-day-actions-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.appendChild(backdrop);
        dayActionsBackdropEl = backdrop;

        var pop = document.createElement('div');
        pop.className = 'lp-cal-day-actions';
        pop.setAttribute('role', 'menu');
        pop.setAttribute('aria-label', 'Calendar day actions');

        var dateHeading = document.createElement('p');
        dateHeading.className = 'lp-cal-day-actions__date';
        dateHeading.textContent = formatDayHeading(date);
        pop.appendChild(dateHeading);

        if (dayEvents.length) {
            if (dayEvents.length === 1) {
                var viewBtn = document.createElement('button');
                viewBtn.type = 'button';
                viewBtn.className = 'lp-cal-day-actions__btn';
                viewBtn.textContent = config.viewActionLabel || 'View details';
                viewBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    removeDayActions();
                    triggerEventView(dayEvents[0], config);
                });
                pop.appendChild(viewBtn);
            } else {
                var heading = document.createElement('p');
                heading.className = 'lp-cal-day-actions__heading';
                heading.textContent = config.multiViewHeading || ('View (' + dayEvents.length + ')');
                pop.appendChild(heading);

                dayEvents.forEach(function (ev) {
                    var itemBtn = document.createElement('button');
                    itemBtn.type = 'button';
                    itemBtn.className = 'lp-cal-day-actions__btn lp-cal-day-actions__btn--item';
                    var meta = formatAgendaDateTime(ev.start);
                    var title = ev.title || 'Item';
                    itemBtn.innerHTML = '<span class="lp-cal-day-actions__item-title">' + escapeHtml(title) + '</span>' +
                        '<span class="lp-cal-day-actions__item-meta">' + escapeHtml(meta) + '</span>';
                    itemBtn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        removeDayActions();
                        triggerEventView(ev, config);
                    });
                    pop.appendChild(itemBtn);
                });
            }
        }

        if (config.schedulable !== false && typeof config.onDateClick === 'function') {
            var scheduleBtn = document.createElement('button');
            scheduleBtn.type = 'button';
            scheduleBtn.className = 'lp-cal-day-actions__btn lp-cal-day-actions__btn--schedule';
            scheduleBtn.textContent = config.scheduleActionLabel || 'Schedule new';
            scheduleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                removeDayActions();
                config.onDateClick(ymd, date, null);
            });
            pop.appendChild(scheduleBtn);
        }

        if (!pop.childNodes.length) {
            return;
        }

        document.body.appendChild(pop);
        dayActionsEl = pop;

        var rect = anchorEl.getBoundingClientRect();
        pop.style.top = (rect.bottom + 8) + 'px';
        pop.style.left = rect.left + 'px';

        requestAnimationFrame(function () {
            var popRect = pop.getBoundingClientRect();
            var left = rect.left;
            if (popRect.right > window.innerWidth - 12) {
                left = window.innerWidth - popRect.width - 12;
            }
            if (left < 12) {
                left = 12;
            }
            pop.style.left = left + 'px';

            var top = rect.bottom + 8;
            if (popRect.bottom > window.innerHeight - 12) {
                top = rect.top - popRect.height - 8;
            }
            if (top < 12) {
                top = 12;
            }
            pop.style.top = top + 'px';
        });

        backdrop.addEventListener('click', function () {
            removeDayActions();
        });

        dayActionsOutsideHandler = function (e) {
            if (!pop.contains(e.target) && !anchorEl.contains(e.target)) {
                removeDayActions();
            }
        };
        setTimeout(function () {
            document.addEventListener('click', dayActionsOutsideHandler, true);
            document.addEventListener('keydown', dayActionsEscapeHandler, true);
        }, 0);
    }

    function handleDateClick(info, events, config) {
        if (!info || !info.date) {
            return;
        }

        var ymd = global.legalproFormatCalendarDateYmd
            ? global.legalproFormatCalendarDateYmd(info.date)
            : '';
        var dayEvents = eventsOnDate(events, info.date);
        var canSchedule = config.schedulable !== false && typeof config.onDateClick === 'function';
        var canView = dayEvents.length > 0 && (
            typeof config.onEventClick === 'function' ||
            typeof config.onAgendaItemClick === 'function'
        );

        if (dayEvents.length > 0 && canSchedule && canView) {
            showDayActions(info.dayEl, ymd, info.date, dayEvents, config);
            return;
        }

        if (dayEvents.length > 0 && canView) {
            if (dayEvents.length === 1) {
                triggerEventView(dayEvents[0], config);
            } else if (typeof config.onMultiEventDayClick === 'function') {
                config.onMultiEventDayClick(ymd, info.date, dayEvents, info);
            } else {
                showDayActions(info.dayEl, ymd, info.date, dayEvents, config);
            }
            return;
        }

        if (canSchedule) {
            removeDayActions();
            config.onDateClick(ymd, info.date, info);
        }
    }

    function mount(config) {
        config = config || {};
        var events = config.events || [];
        var calendarEl = resolveEl(config.calendarEl);
        if (!calendarEl || typeof FullCalendar === 'undefined') {
            return null;
        }

        var ids = config.ids || {};
        var yearLabel = resolveEl(ids.yearLabel || '#lpCalYearLabel');
        var monthListEl = resolveEl(ids.monthList || '#lpCalMonthList');
        var monthTitleEl = resolveEl(ids.monthTitle || '#lpCalMonthTitle');
        var agendaListEl = resolveEl(ids.agendaList || '#lpCalAgendaList');
        var yearPrevBtn = resolveEl(ids.yearPrev || '#lpCalYearPrev');
        var yearNextBtn = resolveEl(ids.yearNext || '#lpCalYearNext');

        var getDisplayKey = config.getDisplayKey || function () { return 'scheduled'; };
        var getDisplayLabel = config.getDisplayLabel || function (key) { return key; };
        var agendaEmptyText = config.agendaEmptyText || 'No items this month';
        var agendaIdAttr = config.agendaIdAttr || 'data-item-id';
        var dayClassPrefix = config.dayClassPrefix || 'lp-cal-day--';
        var firstDay = typeof config.firstDay === 'number' ? config.firstDay : 1;

        var studioYear = new Date().getFullYear();
        var studioMonth = new Date().getMonth();
        var viewYear = studioYear;
        var viewMonth = studioMonth;

        function renderMonthList() {
            if (!monthListEl) {
                return;
            }
            if (yearLabel) {
                yearLabel.textContent = String(studioYear);
            }
            var html = '';
            monthNames.forEach(function (name, index) {
                var count = countEventsInMonth(events, studioYear, index);
                var isActive = studioYear === viewYear && index === viewMonth;
                html += '<li><button type="button" class="lp-cal-studio__month-item' + (isActive ? ' is-active' : '') +
                    '" data-month="' + index + '"><span class="lp-cal-studio__month-name">' + name + '</span>' +
                    '<span class="lp-cal-studio__month-count">' + count + '</span></button></li>';
            });
            monthListEl.innerHTML = html;
        }

        function renderAgenda(year, monthIndex) {
            if (!agendaListEl) {
                return;
            }
            var monthEvents = eventsInMonth(events, year, monthIndex);
            if (!monthEvents.length) {
                agendaListEl.innerHTML = '<div class="lp-cal-studio__agenda-empty">' + escapeHtml(agendaEmptyText) + '</div>';
                return;
            }
            var html = '';
            monthEvents.forEach(function (ev) {
                var props = ev.extendedProps || {};
                var displayKey = getDisplayKey(props, ev.start);
                var title = ev.title || 'Event';
                var sub = typeof config.getAgendaSub === 'function'
                    ? config.getAgendaSub(props, ev)
                    : (props.client || props.client_name || props.lawyer || '');
                var itemId = props.itemId || props.appointmentId || props.courtDateId || ev.id;
                html += '<div class="lp-cal-studio__agenda-item" role="button" tabindex="0" ' + agendaIdAttr + '="' +
                    escapeHtml(itemId) + '">' +
                    '<span class="lp-cal-studio__agenda-dot lp-cal-studio__agenda-dot--' + displayKey + '" aria-hidden="true"></span>' +
                    '<div>' +
                    '<p class="lp-cal-studio__agenda-meta">' + formatAgendaDateTime(ev.start) + '</p>' +
                    '<p class="lp-cal-studio__agenda-title">' + escapeHtml(title) + '</p>' +
                    (sub ? '<p class="lp-cal-studio__agenda-sub">' + escapeHtml(sub) + '</p>' : '') +
                    '<span class="lp-cal-studio__agenda-status lp-cal-studio__agenda-status--' + displayKey + '">' +
                    escapeHtml(getDisplayLabel(displayKey)) + '</span>' +
                    '</div>';
                if (typeof config.onAgendaDelete === 'function') {
                    html += '<button type="button" class="lp-cal-studio__agenda-delete" data-delete-id="' + escapeHtml(itemId) +
                        '" title="Delete" aria-label="Delete">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>' +
                        '</button>';
                }
                html += '</div>';
            });
            agendaListEl.innerHTML = html;
        }

        function syncChrome(cal) {
            if (!cal) {
                return;
            }
            var current = cal.getDate();
            studioYear = current.getFullYear();
            studioMonth = current.getMonth();
            viewYear = studioYear;
            viewMonth = studioMonth;
            if (monthTitleEl) {
                monthTitleEl.textContent = monthNames[studioMonth].toUpperCase();
            }
            renderMonthList();
            renderAgenda(studioYear, studioMonth);
        }

        function mountDayCell(info) {
            if (!info || !info.el || !info.date) {
                return;
            }
            var dayEvents = eventsOnDate(events, info.date);
            if (config.schedulable !== false || dayEvents.length) {
                info.el.classList.add('lp-cal-day-schedulable');
                info.el.style.cursor = 'pointer';
            }
            if (!dayEvents.length) {
                if (typeof config.onDayCellMount === 'function') {
                    config.onDayCellMount(info, dayEvents);
                }
                return;
            }
            var props = dayEvents[0].extendedProps || {};
            var displayKey = getDisplayKey(props, dayEvents[0].start);
            info.el.classList.add('lp-cal-day-has-event', dayClassPrefix + displayKey);
            info.el.setAttribute('title', dayEvents.map(function (ev) {
                var p = ev.extendedProps || {};
                return getDisplayLabel(getDisplayKey(p, ev.start)) + ': ' + (ev.title || 'Event');
            }).join('\n'));
            if (typeof config.onDayCellMount === 'function') {
                config.onDayCellMount(info, dayEvents);
            }
            if (global.legalproMountCalendarDayCell) {
                global.legalproMountCalendarDayCell(info);
            }
        }

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            height: 'auto',
            firstDay: firstDay,
            fixedWeekCount: false,
            showNonCurrentDates: false,
            headerToolbar: false,
            navLinks: false,
            dayMaxEvents: false,
            eventDisplay: 'none',
            events: events,
            dayCellContent: function (arg) {
                return { html: '<span class="lp-cal-studio__day-num">' + arg.dayNumberText + '</span>' };
            },
            dateClick: function (info) {
                handleDateClick(info, events, config);
            },
            dayCellDidMount: mountDayCell,
            eventClick: function (info) {
                if (typeof config.onEventClick === 'function') {
                    info.jsEvent.preventDefault();
                    config.onEventClick(info.event, info);
                }
            },
            datesSet: function () {
                syncChrome(calendar);
            }
        });

        calendar.render();
        syncChrome(calendar);

        if (monthListEl) {
            monthListEl.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-month]');
                if (!btn) {
                    return;
                }
                var monthIndex = parseInt(btn.getAttribute('data-month'), 10);
                calendar.gotoDate(new Date(studioYear, monthIndex, 1));
            });
        }

        if (yearPrevBtn) {
            yearPrevBtn.addEventListener('click', function () {
                studioYear -= 1;
                calendar.gotoDate(new Date(studioYear, studioMonth, 1));
            });
        }

        if (yearNextBtn) {
            yearNextBtn.addEventListener('click', function () {
                studioYear += 1;
                calendar.gotoDate(new Date(studioYear, studioMonth, 1));
            });
        }

        if (agendaListEl) {
            agendaListEl.addEventListener('click', function (e) {
                var deleteBtn = e.target.closest('[data-delete-id]');
                if (deleteBtn && typeof config.onAgendaDelete === 'function') {
                    e.stopPropagation();
                    config.onAgendaDelete(deleteBtn.getAttribute('data-delete-id'), e);
                    return;
                }
                var item = e.target.closest('[' + agendaIdAttr + ']');
                if (!item || typeof config.onAgendaItemClick !== 'function') {
                    return;
                }
                var id = item.getAttribute(agendaIdAttr);
                var match = events.find(function (ev) {
                    var props = ev.extendedProps || {};
                    var evId = String(props.itemId || props.appointmentId || props.courtDateId || ev.id);
                    return evId === String(id);
                });
                config.onAgendaItemClick(id, match || null);
            });
        }

        return calendar;
    }

    global.LegalproCalendarStudio = {
        mount: mount,
        mountScheduleHub: mountScheduleHub,
        appointmentStatusKey: appointmentStatusKey,
        appointmentDisplayKey: appointmentDisplayKey,
        appointmentDisplayLabel: appointmentDisplayLabel,
        courtDateDisplayKey: courtDateDisplayKey,
        defaultFirstDay: 1
    };

    window.addEventListener('pageshow', removeDayActions);
})(window);
