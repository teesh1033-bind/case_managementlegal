<?php

/**
 * Admin appointment list — display helpers (status, title, calendar links).
 */

function admin_appointment_display_key(array $appointment): string
{
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    if ($status === 'approved') {
        $status = 'accepted';
    }

    $notes = (string) ($appointment['notes'] ?? '');
    $startsAt = !empty($appointment['starts_at']) ? strtotime((string) $appointment['starts_at']) : false;
    $isPast = $startsAt !== false && $startsAt < time();

    if ($status === 'rejected') {
        return 'cancelled';
    }
    if (stripos($notes, '[Rescheduled') !== false) {
        return 'rescheduled';
    }
    if ($isPast) {
        return $status === 'accepted' ? 'completed' : 'past';
    }
    if ($status === 'accepted') {
        return 'confirmed';
    }

    return 'scheduled';
}

function admin_appointment_display_label(string $displayKey): string
{
    $labels = [
        'scheduled' => 'Scheduled',
        'confirmed' => 'Confirmed',
        'rescheduled' => 'Rescheduled',
        'past' => 'Past',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    return $labels[$displayKey] ?? ucfirst($displayKey);
}

function admin_appointment_status_badge(array $appointment): string
{
    $displayKey = admin_appointment_display_key($appointment);
    $label = admin_appointment_display_label($displayKey);

    if (!function_exists('legalpro_portal_status_badge')) {
        require_once __DIR__ . '/portal_list_ui.php';
    }

    return legalpro_portal_status_badge($displayKey, $label);
}

/**
 * Normalize lawyer/client appointment rows for shared admin display helpers.
 */
function legalpro_normalize_appointment_row(array $row): array
{
    if (!isset($row['client_first_name']) && isset($row['first_name'])) {
        $row['client_first_name'] = (string) $row['first_name'];
        $row['client_last_name'] = (string) ($row['last_name'] ?? '');
    }
    if (empty($row['client_first_name']) && !empty($row['client_name'])) {
        $parts = preg_split('/\s+/', trim((string) $row['client_name']), 2);
        $row['client_first_name'] = $parts[0] ?? '';
        $row['client_last_name'] = $parts[1] ?? '';
    }
    if (empty($row['lawyer_name']) && !empty($row['lawyer_first_name'])) {
        $row['lawyer_name'] = trim((string) $row['lawyer_first_name'] . ' ' . (string) ($row['lawyer_last_name'] ?? ''));
    }
    if (empty($row['case_display']) && !empty($row['case_id'])) {
        $row['case_display'] = 'C-' . str_pad((string) (int) $row['case_id'], 4, '0', STR_PAD_LEFT);
    }

    return $row;
}

/**
 * Shared appointment table row (admin / lawyer / client schedule hubs).
 *
 * @param array{
 *   person_header?: string,
 *   include_calendar?: bool,
 *   row_id_prefix?: string
 * } $options
 */
function legalpro_render_portal_appointment_table_row(
    array $appointment,
    string $actionsHtml,
    array $options = []
): string {
    $appointment = legalpro_normalize_appointment_row($appointment);
    $personHeader = (string) ($options['person_header'] ?? 'client');
    $includeCalendar = (bool) ($options['include_calendar'] ?? true);
    $rowIdPrefix = (string) ($options['row_id_prefix'] ?? 'appt-row-');

    $clientFirst = (string) ($appointment['client_first_name'] ?? '');
    $clientLast = (string) ($appointment['client_last_name'] ?? '');
    $clientName = trim($clientFirst . ' ' . $clientLast);
    if ($personHeader === 'lawyer') {
        $clientName = trim((string) ($appointment['lawyer_name'] ?? ''));
    }
    if ($clientName === '') {
        $clientName = $personHeader === 'lawyer' ? '—' : 'Unknown Client';
    }

    $title = admin_appointment_title($appointment);
    $caseLabel = admin_appointment_case_label($appointment);
    $dateTime = admin_appointment_datetime_parts($appointment);
    $displayKey = admin_appointment_display_key($appointment);
    $displayLabel = admin_appointment_display_label($displayKey);
    $statusBadge = admin_appointment_status_badge($appointment);
    $calendarLinks = $includeCalendar ? admin_appointment_calendar_links_html($appointment) : '';
    $appointmentId = (int) ($appointment['id'] ?? 0);
    $lawyerName = trim((string) ($appointment['lawyer_name'] ?? ''));
    $searchBlob = strtolower(implode(' ', array_filter([
        $title,
        $caseLabel,
        $clientName,
        $lawyerName,
        $dateTime['date'],
        $dateTime['time'],
        $displayLabel,
        (string) ($appointment['notes'] ?? ''),
    ])));

    $calendarCell = $includeCalendar
        ? '<td class="align-middle text-center">' . $calendarLinks . '</td>'
        : '';

    return '<tr id="' . htmlspecialchars($rowIdPrefix . $appointmentId, ENT_QUOTES, 'UTF-8') . '"'
        . ' class="legalpro-admin-list-row lp-appt-list-row"'
        . ' data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-display-status="' . htmlspecialchars($displayKey, ENT_QUOTES, 'UTF-8') . '">'
        . '<td class="align-middle ps-3">'
        . '<div class="lp-appt-list-datetime">'
        . '<span class="lp-appt-list-datetime__date">' . htmlspecialchars($dateTime['date']) . '</span>'
        . '<span class="lp-appt-list-datetime__time">' . htmlspecialchars($dateTime['time'] ?: '—') . '</span>'
        . '</div></td>'
        . '<td class="align-middle"><p class="lp-appt-list-title mb-0">' . htmlspecialchars($title) . '</p></td>'
        . '<td class="align-middle"><p class="lp-appt-list-client mb-0">' . htmlspecialchars($clientName) . '</p></td>'
        . '<td class="align-middle"><p class="lp-appt-list-case mb-0" title="' . htmlspecialchars($caseLabel) . '">' . htmlspecialchars($caseLabel) . '</p></td>'
        . '<td class="align-middle text-center">' . $statusBadge . '</td>'
        . $calendarCell
        . '<td class="align-middle text-end pe-3">' . $actionsHtml . '</td>'
        . '</tr>';
}

/**
 * Map court date storage status to admin appointment display keys (6 statuses).
 */
function legalpro_court_date_display_key(array $date): string
{
    $status = strtolower((string) ($date['status'] ?? 'scheduled'));
    $courtTs = !empty($date['court_date']) ? strtotime((string) $date['court_date']) : false;
    $isPast = $courtTs !== false && $courtTs < time();

    if ($status === 'cancelled') {
        return 'cancelled';
    }
    if ($status === 'completed') {
        return 'completed';
    }
    if ($status === 'postponed') {
        return 'rescheduled';
    }
    if ($isPast) {
        return 'past';
    }

    return 'scheduled';
}

/**
 * Map availability slot to admin appointment display keys (6 statuses).
 */
function legalpro_availability_display_key(array $slot): string
{
    $appointmentId = (int) ($slot['appointment_id'] ?? 0);
    $slotType = strtolower((string) ($slot['slot_type'] ?? 'available'));

    if ($appointmentId > 0) {
        return 'confirmed';
    }
    if ($slotType === 'available') {
        return 'scheduled';
    }

    return 'cancelled';
}

/**
 * Shared court date table row — same 7-column format as admin appointments.
 */
function legalpro_render_portal_court_date_table_row(
    array $date,
    string $actionsHtml,
    array $options = []
): string {
    if (!function_exists('legalpro_portal_status_badge')) {
        require_once __DIR__ . '/portal_list_ui.php';
    }

    $labelResolver = $options['label_resolver'] ?? null;
    $displayKey = legalpro_court_date_display_key($date);
    $displayLabel = $labelResolver
        ? (string) $labelResolver($displayKey, $date)
        : admin_appointment_display_label($displayKey);
    $statusBadge = legalpro_portal_status_badge($displayKey, $displayLabel);

    $courtTs = !empty($date['court_date']) ? strtotime((string) $date['court_date']) : false;
    $dateLabel = $courtTs ? date('M j, Y', $courtTs) : '—';
    $timeLabel = $courtTs ? date('g:i A', $courtTs) : '—';
    $title = (string) ($date['title'] ?? '—');
    $clientName = (string) ($date['client_name'] ?? '—');
    $caseId = (int) ($date['case_id'] ?? 0);
    $caseLabel = $caseId > 0
        ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT)
        : '—';

    $searchBlob = strtolower(implode(' ', array_filter([
        $title,
        $caseLabel,
        $clientName,
        (string) ($date['case_title'] ?? ''),
        (string) ($date['location'] ?? ''),
        $dateLabel,
        $timeLabel,
        $displayLabel,
        $displayKey,
    ])));

    $calendarHtml = '<span class="text-muted text-xs">—</span>';
    if ($courtTs !== false) {
        $calendarStart = date('Y-m-d H:i:s', $courtTs);
        $calendarEnd = date('Y-m-d H:i:s', strtotime('+1 hour', $courtTs));
        $calendarDetails = trim(implode("\n", array_filter([
            (string) ($date['description'] ?? ''),
            !empty($date['location']) ? 'Location: ' . (string) $date['location'] : '',
            !empty($date['case_title']) ? 'Case: ' . (string) $date['case_title'] : '',
            $clientName !== '—' ? 'Client: ' . $clientName : '',
        ])));
        $calendarHtml = legalpro_portal_calendar_links_html(
            $title,
            $calendarStart,
            $calendarEnd,
            $calendarDetails
        );
    }

    return '<tr id="court-' . (int) ($date['id'] ?? 0) . '" class="legalpro-admin-list-row lp-appt-list-row"'
        . ' data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-display-status="' . htmlspecialchars($displayKey, ENT_QUOTES, 'UTF-8') . '">'
        . '<td class="align-middle ps-3">'
        . '<div class="lp-appt-list-datetime">'
        . '<span class="lp-appt-list-datetime__date">' . htmlspecialchars($dateLabel) . '</span>'
        . '<span class="lp-appt-list-datetime__time">' . htmlspecialchars($timeLabel) . '</span>'
        . '</div></td>'
        . '<td class="align-middle"><p class="lp-appt-list-title mb-0">' . htmlspecialchars($title) . '</p></td>'
        . '<td class="align-middle"><p class="lp-appt-list-client mb-0">' . htmlspecialchars($clientName) . '</p></td>'
        . '<td class="align-middle"><p class="lp-appt-list-case mb-0" title="' . htmlspecialchars($caseLabel) . '">' . htmlspecialchars($caseLabel) . '</p></td>'
        . '<td class="align-middle text-center">' . $statusBadge . '</td>'
        . '<td class="align-middle text-center">' . $calendarHtml . '</td>'
        . '<td class="align-middle text-end pe-3"><div class="legalpro-admin-list-row__actions lp-appt-list-actions">' . $actionsHtml . '</div></td>'
        . '</tr>';
}

/**
 * Availability slot row — same 7-column format as admin appointments.
 */
function legalpro_render_portal_availability_appointment_table_row(
    array $slot,
    string $actionsHtml,
    array $options = []
): string {
    if (!function_exists('legalpro_portal_status_badge')) {
        require_once __DIR__ . '/portal_list_ui.php';
    }

    $labelResolver = $options['label_resolver'] ?? null;
    $displayKey = legalpro_availability_display_key($slot);
    $displayLabel = $labelResolver
        ? (string) $labelResolver($displayKey, $slot)
        : admin_appointment_display_label($displayKey);

    $appointmentId = (int) ($slot['appointment_id'] ?? 0);
    $slotType = strtolower((string) ($slot['slot_type'] ?? 'available'));
    $slotDate = (string) ($slot['slot_date'] ?? '');
    $dayOfWeek = (string) ($slot['day_of_week'] ?? '');
    $startTime = (string) ($slot['start_time'] ?? '');
    $endTime = (string) ($slot['end_time'] ?? '');

    if (function_exists('lawyer_portal_format_time_range')) {
        $timeLabel = lawyer_portal_format_time_range($startTime, $endTime);
    } else {
        $timeLabel = trim($startTime . ' – ' . $endTime, ' –');
    }

    $courtTs = $slotDate !== '' ? strtotime($slotDate) : false;
    $dateLabel = $courtTs ? date('M j, Y', $courtTs) : '—';
    $dayLabel = $dayOfWeek !== '' ? ucfirst($dayOfWeek) : '';

    if ($appointmentId > 0) {
        $title = function_exists('lawyer_tf')
            ? lawyer_tf('availability.slot_appointment', 'Appointment block')
            : 'Appointment block';
    } elseif ($slotType === 'available') {
        $title = function_exists('lawyer_tf')
            ? lawyer_tf('availability.slot_available', 'Available slot')
            : 'Available slot';
    } else {
        $title = function_exists('lawyer_tf')
            ? lawyer_tf('availability.slot_unavailable', 'Unavailable')
            : 'Unavailable';
    }
    if ($dayLabel !== '') {
        $title .= ' · ' . $dayLabel;
    }

    $clientName = '—';
    $caseLabel = '—';
    $calendarHtml = '<span class="text-muted text-xs">—</span>';
    if ($appointmentId > 0) {
        $first = trim((string) ($slot['client_first_name'] ?? ''));
        $last = trim((string) ($slot['client_last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            $clientName = $full;
        }

        $caseId = (int) ($slot['case_id'] ?? 0);
        $caseTitle = trim((string) ($slot['case_title'] ?? ''));
        if ($caseId > 0) {
            $caseNo = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
            $caseLabel = $caseTitle !== '' ? ($caseNo . ' · ' . $caseTitle) : $caseNo;
        } elseif ($caseTitle !== '') {
            $caseLabel = $caseTitle;
        }

        // Build calendar links from the linked appointment when available.
        $calendarSeed = [
            'id' => $appointmentId,
            'starts_at' => (string) ($slot['appointment_starts_at'] ?? ''),
            'ends_at' => (string) ($slot['appointment_ends_at'] ?? ''),
            'notes' => (string) ($slot['appointment_notes'] ?? ''),
            'case_title' => $caseTitle,
            'case_id' => $caseId,
            'client_first_name' => $first,
            'client_last_name' => $last,
        ];
        $calendarHtml = admin_appointment_calendar_links_html($calendarSeed);
    } elseif ($slotDate !== '' && $startTime !== '') {
        $slotStart = $slotDate . ' ' . (strlen($startTime) === 5 ? $startTime . ':00' : $startTime);
        $slotEnd = $slotDate . ' ' . (strlen($endTime) === 5 ? $endTime . ':00' : $endTime);
        $slotDetails = $slotType === 'available'
            ? 'Availability slot'
            : 'Unavailable slot';
        $calendarHtml = legalpro_portal_calendar_links_html(
            $title,
            $slotStart,
            $slotEnd,
            $slotDetails
        );
    }

    $searchBlob = strtolower(implode(' ', array_filter([
        $title,
        $slotDate,
        $dayOfWeek,
        $timeLabel,
        $displayLabel,
        $displayKey,
        $startTime,
        $endTime,
    ])));

    return '<tr class="legalpro-admin-list-row lp-appt-list-row"'
        . ' data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-display-status="' . htmlspecialchars($displayKey, ENT_QUOTES, 'UTF-8') . '">'
        . '<td class="align-middle ps-3">'
        . '<div class="lp-appt-list-datetime">'
        . '<span class="lp-appt-list-datetime__date">' . htmlspecialchars($dateLabel) . '</span>'
        . '<span class="lp-appt-list-datetime__time">' . htmlspecialchars($timeLabel ?: '—') . '</span>'
        . '</div></td>'
        . '<td class="align-middle"><p class="lp-appt-list-title mb-0">' . htmlspecialchars($title) . '</p></td>'
        . '<td class="align-middle"><p class="lp-appt-list-client mb-0">' . htmlspecialchars($clientName) . '</p></td>'
        . '<td class="align-middle"><p class="lp-appt-list-case mb-0" title="' . htmlspecialchars($caseLabel) . '">' . htmlspecialchars($caseLabel) . '</p></td>'
        . '<td class="align-middle text-center">' . legalpro_portal_status_badge($displayKey, $displayLabel) . '</td>'
        . '<td class="align-middle text-center">' . $calendarHtml . '</td>'
        . '<td class="align-middle text-end pe-3"><div class="legalpro-admin-list-row__actions lp-appt-list-actions">' . $actionsHtml . '</div></td>'
        . '</tr>';
}

function admin_appointment_title(array $appointment): string
{
    $notes = trim((string) ($appointment['notes'] ?? ''));
    if ($notes !== '') {
        $firstLine = preg_split('/\r\n|\r|\n/', $notes)[0] ?? $notes;
        $firstLine = trim((string) $firstLine);
        if ($firstLine !== '') {
            return $firstLine;
        }
    }

    if (!empty($appointment['case_title'])) {
        return (string) $appointment['case_title'];
    }

    return 'Appointment';
}

function admin_appointment_case_label(array $appointment): string
{
    if (!empty($appointment['case_display'])) {
        return (string) $appointment['case_display'];
    }
    if (!empty($appointment['case_id'])) {
        return 'C-' . str_pad((string) (int) $appointment['case_id'], 4, '0', STR_PAD_LEFT);
    }

    return '—';
}

function admin_appointment_datetime_parts(array $appointment): array
{
    if (empty($appointment['starts_at'])) {
        return ['date' => 'TBD', 'time' => ''];
    }

    $ts = strtotime((string) $appointment['starts_at']);

    return [
        'date' => date('M j, Y', $ts),
        'time' => date('g:i A', $ts),
    ];
}

function admin_appointment_google_calendar_url(array $appointment): string
{
    $title = admin_appointment_title($appointment);
    $startsAt = !empty($appointment['starts_at']) ? strtotime((string) $appointment['starts_at']) : false;
    if ($startsAt === false) {
        return '#';
    }

    $endsAtRaw = $appointment['ends_at'] ?? '';
    $endsAt = $endsAtRaw !== '' ? strtotime((string) $endsAtRaw) : strtotime('+1 hour', $startsAt);
    if ($endsAt === false || $endsAt <= $startsAt) {
        $endsAt = strtotime('+1 hour', $startsAt);
    }

    $params = [
        'action' => 'TEMPLATE',
        'text' => $title,
        'dates' => gmdate('Ymd\THis\Z', $startsAt) . '/' . gmdate('Ymd\THis\Z', $endsAt),
        'details' => trim((string) ($appointment['notes'] ?? '')),
    ];

    $clientName = trim(($appointment['client_first_name'] ?? '') . ' ' . ($appointment['client_last_name'] ?? ''));
    if ($clientName !== '') {
        $params['details'] = 'Client: ' . $clientName . "\n" . $params['details'];
    }

    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}

function admin_appointment_outlook_calendar_url(array $appointment): string
{
    $title = admin_appointment_title($appointment);
    $startsAt = !empty($appointment['starts_at']) ? strtotime((string) $appointment['starts_at']) : false;
    if ($startsAt === false) {
        return '#';
    }

    $endsAtRaw = $appointment['ends_at'] ?? '';
    $endsAt = $endsAtRaw !== '' ? strtotime((string) $endsAtRaw) : strtotime('+1 hour', $startsAt);
    if ($endsAt === false || $endsAt <= $startsAt) {
        $endsAt = strtotime('+1 hour', $startsAt);
    }

    $description = trim((string) ($appointment['notes'] ?? ''));
    $clientName = trim(($appointment['client_first_name'] ?? '') . ' ' . ($appointment['client_last_name'] ?? ''));
    if ($clientName !== '') {
        $description = 'Client: ' . $clientName . ($description !== '' ? "\n" . $description : '');
    }

    $params = [
        'subject' => $title,
        'body' => $description,
        'startdt' => date('Y-m-d\TH:i:s', $startsAt),
        'enddt' => date('Y-m-d\TH:i:s', $endsAt),
        'path' => '/calendar/action/compose',
        'rru' => 'addevent',
    ];

    return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query($params);
}

function admin_appointment_calendar_icon_google(): string
{
    return '<svg class="lp-appt-list-cal-icon" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">'
        . '<path fill="currentColor" d="M12 11.5v3.5h4.9c-.2 1.2-1.4 3.5-4.9 3.5-3 0-5.4-2.5-5.4-5.5S9 7.5 12 7.5c1.7 0 2.9.7 3.6 1.3l2.5-2.4C16.9 5.2 14.7 4.5 12 4.5 7.9 4.5 4.5 7.9 4.5 12s3.4 7.5 7.5 7.5c4.3 0 7.2-3 7.2-7.3 0-.5 0-.9-.1-1.2H12z"/>'
        . '</svg>';
}

function admin_appointment_calendar_icon_outlook(): string
{
    return '<svg class="lp-appt-list-cal-icon" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">'
        . '<rect x="3" y="3" width="8" height="8" rx="1" fill="currentColor"/>'
        . '<rect x="13" y="3" width="8" height="8" rx="1" fill="currentColor" opacity="0.85"/>'
        . '<rect x="3" y="13" width="8" height="8" rx="1" fill="currentColor" opacity="0.85"/>'
        . '<rect x="13" y="13" width="8" height="8" rx="1" fill="currentColor" opacity="0.7"/>'
        . '</svg>';
}

function admin_appointment_calendar_icon_download(): string
{
    return '<svg class="lp-appt-list-cal-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
        . '<path d="M12 4v10M8 11l4 4 4-4"/>'
        . '<path d="M5 20h14"/>'
        . '</svg>';
}

function admin_appointment_calendar_links_html(array $appointment): string
{
    $appointmentId = (int) ($appointment['id'] ?? 0);
    if ($appointmentId <= 0) {
        return '<span class="text-muted text-xs">—</span>';
    }

    $googleUrl = admin_appointment_google_calendar_url($appointment);
    $outlookUrl = admin_appointment_outlook_calendar_url($appointment);
    $icsUrl = 'appointment-ics.php?id=' . $appointmentId;

    return '<div class="lp-appt-list-cal-btns" role="group" aria-label="Add to calendar">'
        . '<a href="' . htmlspecialchars($googleUrl) . '" class="lp-appt-list-cal-btn" target="_blank" rel="noopener" title="Add to Google Calendar" aria-label="Add to Google Calendar">'
        . admin_appointment_calendar_icon_google()
        . '</a>'
        . '<a href="' . htmlspecialchars($outlookUrl) . '" class="lp-appt-list-cal-btn" target="_blank" rel="noopener" title="Add to Outlook Calendar" aria-label="Add to Outlook Calendar">'
        . admin_appointment_calendar_icon_outlook()
        . '</a>'
        . '<a href="' . htmlspecialchars($icsUrl) . '" class="lp-appt-list-cal-btn" download title="Download calendar file" aria-label="Download calendar file">'
        . admin_appointment_calendar_icon_download()
        . '</a>'
        . '</div>';
}

/**
 * Shared compact calendar links group used by schedule-hub tables.
 */
function legalpro_portal_calendar_links_html(
    string $title,
    string $startsAt,
    string $endsAt,
    string $details = '',
    string $icsUrl = ''
): string {
    $startTs = strtotime($startsAt);
    if ($startTs === false) {
        return '<span class="text-muted text-xs">—</span>';
    }
    $endTs = strtotime($endsAt);
    if ($endTs === false || $endTs <= $startTs) {
        $endTs = strtotime('+1 hour', $startTs);
    }

    $googleParams = [
        'action' => 'TEMPLATE',
        'text' => $title,
        'dates' => gmdate('Ymd\THis\Z', $startTs) . '/' . gmdate('Ymd\THis\Z', $endTs),
        'details' => $details,
    ];
    $googleUrl = 'https://calendar.google.com/calendar/render?' . http_build_query($googleParams);

    $outlookParams = [
        'subject' => $title,
        'body' => $details,
        'startdt' => date('Y-m-d\TH:i:s', $startTs),
        'enddt' => date('Y-m-d\TH:i:s', $endTs),
        'path' => '/calendar/action/compose',
        'rru' => 'addevent',
    ];
    $outlookUrl = 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query($outlookParams);

    $downloadHref = $icsUrl !== ''
        ? $icsUrl
        : legalpro_portal_calendar_ics_data_url($title, $startTs, $endTs, $details);
    $downloadFilename = legalpro_portal_calendar_download_filename($title, $startTs);

    $links = '<div class="lp-appt-list-cal-btns" role="group" aria-label="Add to calendar">'
        . '<a href="' . htmlspecialchars($googleUrl) . '" class="lp-appt-list-cal-btn" target="_blank" rel="noopener" title="Add to Google Calendar" aria-label="Add to Google Calendar">'
        . admin_appointment_calendar_icon_google()
        . '</a>'
        . '<a href="' . htmlspecialchars($outlookUrl) . '" class="lp-appt-list-cal-btn" target="_blank" rel="noopener" title="Add to Outlook Calendar" aria-label="Add to Outlook Calendar">'
        . admin_appointment_calendar_icon_outlook()
        . '</a>';
    $links .= '<a href="' . htmlspecialchars($downloadHref) . '" class="lp-appt-list-cal-btn" download="' . htmlspecialchars($downloadFilename, ENT_QUOTES, 'UTF-8') . '" title="Download calendar file" aria-label="Download calendar file">'
        . admin_appointment_calendar_icon_download()
        . '</a>';

    return $links . '</div>';
}

function legalpro_portal_calendar_download_filename(string $title, int $startTs): string
{
    $base = strtolower(trim($title));
    $base = preg_replace('/[^a-z0-9]+/i', '-', $base ?? '') ?? 'event';
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'event';
    }

    return $base . '-' . date('Ymd-His', $startTs) . '.ics';
}

function legalpro_portal_calendar_ics_data_url(string $title, int $startTs, int $endTs, string $details = ''): string
{
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//LegalPro//Schedule Hub//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . md5($title . '|' . $startTs . '|' . $endTs . '|' . $details) . '@legalpro.local',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . gmdate('Ymd\THis\Z', $startTs),
        'DTEND:' . gmdate('Ymd\THis\Z', $endTs),
        'SUMMARY:' . legalpro_portal_ics_escape($title),
    ];

    $details = trim($details);
    if ($details !== '') {
        $lines[] = 'DESCRIPTION:' . legalpro_portal_ics_escape($details);
    }

    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    $ics = implode("\r\n", $lines) . "\r\n";

    return 'data:text/calendar;charset=utf-8,' . rawurlencode($ics);
}

function legalpro_portal_ics_escape(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\;', $value);
    $value = str_replace(',', '\,', $value);

    return $value;
}

function admin_appointment_booking_normalize_status(string $raw, string $default = 'pending'): string
{
    $status = strtolower(trim($raw));
    if ($status === 'accepted') {
        $status = 'approved';
    }
    $allowed = ['pending', 'approved', 'rejected'];

    return in_array($status, $allowed, true) ? $status : $default;
}

function admin_appointment_calendar_status_options(): array
{
    return legalpro_portal_appointment_status_options();
}

function admin_appointment_booking_display_status(array $appointment): string
{
    return admin_appointment_display_key($appointment);
}

function admin_appointment_booking_normalize_display_status(string $raw, string $default = 'scheduled'): string
{
    $status = strtolower(trim($raw));
    $allowed = array_column(admin_appointment_calendar_status_options(), 'value');

    return in_array($status, $allowed, true) ? $status : $default;
}

function admin_appointment_booking_display_to_storage(string $displayStatus, string $notes): array
{
    $displayStatus = admin_appointment_booking_normalize_display_status($displayStatus);
    $notes = trim($notes);

    switch ($displayStatus) {
        case 'confirmed':
        case 'completed':
            return ['status' => 'approved', 'notes' => $notes];
        case 'cancelled':
            return ['status' => 'rejected', 'notes' => $notes];
        case 'rescheduled':
            if (stripos($notes, '[Rescheduled') === false) {
                $marker = '[Rescheduled ' . date('Y-m-d H:i') . ']';
                $notes = $notes !== '' ? $marker . ' ' . $notes : $marker;
            }

            return ['status' => 'pending', 'notes' => $notes];
        case 'past':
        case 'scheduled':
        default:
            return ['status' => 'pending', 'notes' => $notes];
    }
}

function admin_appointment_booking_status_options(string $selected = 'scheduled'): string
{
    $selected = admin_appointment_booking_normalize_display_status($selected);
    $html = '';
    foreach (admin_appointment_calendar_status_options() as $option) {
        $value = (string) ($option['value'] ?? '');
        $label = (string) ($option['label'] ?? ucfirst($value));
        if ($value === '') {
            continue;
        }
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
            . ($selected === $value ? ' selected' : '') . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html;
}

function admin_appointment_booking_status_chips_html(string $selected = 'scheduled'): string
{
    $selected = admin_appointment_booking_normalize_display_status($selected);
    $html = '<div class="lp-appt-book-status-grid" role="radiogroup" aria-label="Appointment status">';
    foreach (admin_appointment_calendar_status_options() as $option) {
        $value = (string) ($option['value'] ?? '');
        $label = (string) ($option['label'] ?? ucfirst($value));
        if ($value === '') {
            continue;
        }
        $isSelected = $selected === $value;
        $html .= '<label class="lp-appt-book-status-chip lp-appt-book-status-chip--' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            . ($isSelected ? ' is-selected' : '') . '">'
            . '<input type="radio" name="status" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
            . ($isSelected ? ' checked' : '') . ' required>'
            . '<span class="lp-appt-book-status-chip__dot" aria-hidden="true"></span>'
            . '<span class="lp-appt-book-status-chip__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</label>';
    }
    $html .= '</div>';

    return $html;
}

function admin_appointment_booking_status_chevron_svg(): string
{
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
        . '<path d="M6 9l6 6 6-6"/></svg>';
}

function admin_appointment_list_actions_html(array $appointment): string
{
    $appointmentId = (int) ($appointment['id'] ?? 0);
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    $caseDisplay = admin_appointment_case_label($appointment);
    $accentBtn = legalpro_portal_accent_action_btn_class();
    $dangerBtn = legalpro_portal_danger_action_btn_class();

    if ($status === 'accepted') {
        $editHtml = '<button type="button" class="' . $accentBtn . ' lp-appt-list-edit-btn lp-appt-list-edit-btn--locked" disabled title="Accepted appointments cannot be edited">Edit</button>';
    } elseif ($status === 'rejected') {
        $editHtml = '<a href="new_appointment.php?id=' . $appointmentId . '" class="' . $accentBtn . ' lp-appt-list-edit-btn" title="Choose another lawyer or time">Reassign</a>';
    } else {
        $editHtml = '<a href="new_appointment.php?id=' . $appointmentId . '" class="' . $accentBtn . ' lp-appt-list-edit-btn">Edit</a>';
    }

    return '<div class="lp-appt-list-actions">'
        . $editHtml
        . '<button type="button" class="' . $dangerBtn . ' lp-appt-list-btn--delete" onclick="deleteAppointment('
        . $appointmentId . ', \'' . addslashes($caseDisplay) . '\'); return false;">Delete</button>'
        . '</div>';
}
