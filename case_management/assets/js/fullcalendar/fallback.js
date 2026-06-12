// Simple Calendar Fallback for when FullCalendar CDN fails
class SimpleCalendar {
    constructor(element, options) {
        this.element = element;
        this.options = options;
        this.events = options.events || [];
        this.currentDate = new Date();
        this.init();
    }

    init() {
        this.render();
    }

    render() {
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                          'July', 'August', 'September', 'October', 'November', 'December'];

        let html = `
            <div class="simple-calendar">
                <div class="calendar-header">
                    <button class="btn btn-sm btn-outline-primary" onclick="simpleCalendar.prevMonth()">&larr;</button>
                    <h5 class="mb-0">${monthNames[month]} ${year}</h5>
                    <button class="btn btn-sm btn-outline-primary" onclick="simpleCalendar.nextMonth()">&rarr;</button>
                </div>
                <div class="calendar-grid">
                    <div class="calendar-day-header">Sun</div>
                    <div class="calendar-day-header">Mon</div>
                    <div class="calendar-day-header">Tue</div>
                    <div class="calendar-day-header">Wed</div>
                    <div class="calendar-day-header">Thu</div>
                    <div class="calendar-day-header">Fri</div>
                    <div class="calendar-day-header">Sat</div>
        `;

        // Get first day of month and last day
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startDate = new Date(firstDay);
        startDate.setDate(startDate.getDate() - firstDay.getDay());

        // Generate calendar days
        for (let i = 0; i < 42; i++) {
            const currentDate = new Date(startDate);
            currentDate.setDate(startDate.getDate() + i);

            const isCurrentMonth = currentDate.getMonth() === month;
            const isToday = currentDate.toDateString() === new Date().toDateString();
            const dayEvents = this.getEventsForDate(currentDate);

            let classes = 'calendar-day';
            if (!isCurrentMonth) classes += ' other-month';
            if (isToday) classes += ' today';
            if (dayEvents.length > 0) classes += ' has-events';

            html += `<div class="${classes}" data-date="${currentDate.toISOString().split('T')[0]}">`;
            html += `<div class="day-number">${currentDate.getDate()}</div>`;

            if (dayEvents.length > 0) {
                html += '<div class="day-events">';
                dayEvents.slice(0, 2).forEach(event => {
                    html += `<div class="day-event" style="background-color: ${event.backgroundColor}" onclick="viewCourtDate(${event.id})">${event.title.split(' - ')[1] || event.title}</div>`;
                });
                if (dayEvents.length > 2) {
                    html += `<div class="day-event-more">+${dayEvents.length - 2} more</div>`;
                }
                html += '</div>';
            }

            html += '</div>';
        }

        html += `
                </div>
            </div>
        `;

        this.element.innerHTML = html;
    }

    getEventsForDate(date) {
        const dateStr = date.toISOString().split('T')[0];
        return this.events.filter(event => {
            const eventDate = new Date(event.start).toISOString().split('T')[0];
            return eventDate === dateStr;
        });
    }

    prevMonth() {
        this.currentDate.setMonth(this.currentDate.getMonth() - 1);
        this.render();
    }

    nextMonth() {
        this.currentDate.setMonth(this.currentDate.getMonth() + 1);
        this.render();
    }
}

// Global instance
let simpleCalendar;
