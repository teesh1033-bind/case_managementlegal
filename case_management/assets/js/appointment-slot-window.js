(function (global) {
    var FALLBACK_START = 9 * 60;
    var FALLBACK_END = 17 * 60;

    var DEFAULT_BUSINESS_SCHEDULE = {
        monday: { enabled: true, start: '09:00:00', end: '17:00:00' },
        tuesday: { enabled: true, start: '09:00:00', end: '17:00:00' },
        wednesday: { enabled: true, start: '09:00:00', end: '17:00:00' },
        thursday: { enabled: true, start: '09:00:00', end: '17:00:00' },
        friday: { enabled: true, start: '09:00:00', end: '17:00:00' },
        saturday: { enabled: false, start: '09:00:00', end: '17:00:00' },
        sunday: { enabled: false, start: '09:00:00', end: '17:00:00' }
    };

    function timeToMinutes(timeValue) {
        if (!timeValue) {
            return -1;
        }
        var parts = String(timeValue).split(':');
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
    }

    function normalizeTimeToSeconds(timeValue) {
        var value = String(timeValue || '').trim();
        if (/^\d{2}:\d{2}$/.test(value)) {
            return value + ':00';
        }
        return value;
    }

    function isDefaultBusinessDayEnabled(dayOfWeek) {
        var day = DEFAULT_BUSINESS_SCHEDULE[String(dayOfWeek || '').toLowerCase()];
        return !!(day && day.enabled);
    }

    function isWithinDefaultBusinessHours(dayOfWeek, timeValue, durationMinutes) {
        durationMinutes = durationMinutes === 30 ? 30 : 60;
        var day = DEFAULT_BUSINESS_SCHEDULE[String(dayOfWeek || '').toLowerCase()];
        if (!day || !day.enabled) {
            return false;
        }

        var startTime = normalizeTimeToSeconds(timeValue);
        var startMinutes = timeToMinutes(startTime);
        if (startMinutes < 0) {
            return false;
        }

        var endMinutes = startMinutes + durationMinutes;
        var endTime = String(Math.floor(endMinutes / 60)).padStart(2, '0') + ':'
            + String(endMinutes % 60).padStart(2, '0') + ':00';

        return startTime >= day.start && endTime <= day.end;
    }

    function getSlotWindowMinutes(options) {
        options = options || {};
        var startMin = options.dayStartMinutes != null ? options.dayStartMinutes : FALLBACK_START;
        var endMin = options.dayEndMinutes != null ? options.dayEndMinutes : FALLBACK_END;
        var slots = options.slots || [];
        var available = slots.filter(function (slot) {
            return slot.type === 'available';
        });
        var hasPublishedSchedule = !!options.hasPublishedSchedule;
        var dayHours = options.workingHoursDay || null;
        var hasWorkingHours = !!options.hasWorkingHours;

        if (hasWorkingHours && dayHours) {
            if (!dayHours.enabled) {
                return { start: startMin, end: endMin, disabled: true };
            }
            startMin = timeToMinutes(String(dayHours.start).slice(0, 5));
            endMin = timeToMinutes(String(dayHours.end).slice(0, 5));
        } else if (!hasPublishedSchedule) {
            startMin = FALLBACK_START;
            endMin = FALLBACK_END;
        }

        if (hasPublishedSchedule && available.length > 0) {
            startMin = available.reduce(function (min, slot) {
                return Math.min(min, timeToMinutes(slot.start));
            }, startMin);
            endMin = available.reduce(function (max, slot) {
                return Math.max(max, timeToMinutes(slot.end));
            }, endMin);
        }

        return { start: startMin, end: endMin, disabled: false };
    }

    function getStandardSlotTimes(durationMinutes, windowOptions, stepMinutes) {
        durationMinutes = durationMinutes === 30 ? 30 : 60;
        stepMinutes = stepMinutes || durationMinutes;
        var window = getSlotWindowMinutes(windowOptions);
        if (window.disabled) {
            return [];
        }

        var lastStart = window.end - durationMinutes;
        if (lastStart < window.start) {
            return [];
        }

        var times = [];
        for (var t = window.start; t <= lastStart; t += stepMinutes) {
            var hours = Math.floor(t / 60);
            var minutes = t % 60;
            times.push(String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0'));
        }

        return times;
    }

    global.LegalproAppointmentSlots = {
        timeToMinutes: timeToMinutes,
        getSlotWindowMinutes: getSlotWindowMinutes,
        getStandardSlotTimes: getStandardSlotTimes,
        isWithinDefaultBusinessHours: isWithinDefaultBusinessHours,
        isDefaultBusinessDayEnabled: isDefaultBusinessDayEnabled,
        DEFAULT_BUSINESS_SCHEDULE: DEFAULT_BUSINESS_SCHEDULE,
        FALLBACK_START_MINUTES: FALLBACK_START,
        FALLBACK_END_MINUTES: FALLBACK_END
    };
})(window);
