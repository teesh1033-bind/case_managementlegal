<?php

/**
 * Shared FullCalendar event payloads for schedule hub pages (appointments + court).
 */

function legalpro_portal_schedule_calendar_title(int $caseId, string $label, string $fallback = 'Item'): string
{
    $text = trim($label) !== '' ? trim($label) : $fallback;
    if ($caseId <= 0) {
        return $text;
    }

    return 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) . ' · ' . $text;
}

/**
 * @param array<int, array<string, mixed>> $appointments
 * @param array{
 *   subtitle_field?: string,
 *   subtitle_fallback?: string,
 *   title_fallback?: string
 * } $options
 * @return array<int, array<string, mixed>>
 */
function legalpro_portal_build_appointment_calendar_events(array $appointments, array $options = []): array
{
    $subtitleField = (string) ($options['subtitle_field'] ?? 'client');
    $subtitleFallback = (string) ($options['subtitle_fallback'] ?? '—');
    $titleFallback = (string) ($options['title_fallback'] ?? 'Appointment');

    $events = [];
    foreach ($appointments as $row) {
        if (empty($row['starts_at'])) {
            continue;
        }

        $status = strtolower((string) ($row['status'] ?? 'pending'));
        if ($status === 'approved') {
            $status = 'accepted';
        }

        $caseId = (int) ($row['case_id'] ?? 0);
        $caseTitle = trim((string) ($row['case_title'] ?? ''));
        $notes = trim((string) ($row['notes'] ?? ''));
        $startsAt = (string) $row['starts_at'];
        $startsLabel = date('M j, Y g:i A', strtotime($startsAt));

        $titleLabel = $caseTitle !== '' ? $caseTitle : $titleFallback;
        if ($notes !== '' && $caseTitle === '') {
            $firstLine = preg_split('/\r\n|\r|\n/', $notes)[0] ?? $notes;
            $titleLabel = trim((string) $firstLine) !== '' ? trim((string) $firstLine) : $titleFallback;
        }

        $displayTitle = legalpro_portal_schedule_calendar_title($caseId, $titleLabel, $titleFallback);

        $clientName = trim(
            ((string) ($row['client_first_name'] ?? $row['first_name'] ?? '')) . ' '
            . ((string) ($row['client_last_name'] ?? $row['last_name'] ?? ''))
        );
        $lawyerName = trim((string) ($row['lawyer_name'] ?? ''));
        $subtitle = $subtitleFallback;
        if ($subtitleField === 'lawyer') {
            $subtitle = $lawyerName !== '' ? $lawyerName : $subtitleFallback;
        } elseif ($subtitleField === 'client') {
            $subtitle = $clientName !== '' ? $clientName : $subtitleFallback;
        }

        $searchHay = strtolower(implode(' ', array_filter([
            $displayTitle,
            $titleLabel,
            $clientName,
            $lawyerName,
            $status,
            $notes,
            $startsLabel,
            date('Y-m-d', strtotime($startsAt)),
            date('m/d/Y', strtotime($startsAt)),
            $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : '',
            (string) ($row['id'] ?? ''),
        ])));

        $extendedProps = [
            'client' => $clientName !== '' ? $clientName : $subtitleFallback,
            'lawyer' => $lawyerName !== '' ? $lawyerName : $subtitleFallback,
            'notes' => $notes,
            'status' => $status,
            'statusLabel' => ucfirst($status),
            'appointmentId' => (int) ($row['id'] ?? 0),
            'caseId' => $caseId,
            'case_title' => $caseTitle,
            'startsLabel' => $startsLabel,
            'agendaSub' => $subtitle,
            'searchHay' => $searchHay,
        ];
        if (isset($options['extend_props']) && is_callable($options['extend_props'])) {
            $extendedProps = (array) $options['extend_props']($row, $extendedProps);
        }

        $events[] = [
            'id' => (string) ($row['id'] ?? ''),
            'title' => $displayTitle,
            'start' => $startsAt,
            'end' => !empty($row['ends_at']) ? $row['ends_at'] : null,
            'backgroundColor' => 'transparent',
            'borderColor' => 'transparent',
            'textColor' => '#344767',
            'extendedProps' => $extendedProps,
        ];
    }

    return $events;
}

/**
 * @param array<int, array<string, mixed>> $courtDates
 * @return array<int, array<string, mixed>>
 */
function legalpro_portal_build_court_calendar_events(array $courtDates): array
{
    $events = [];
    foreach ($courtDates as $date) {
        if (empty($date['court_date'])) {
            continue;
        }

        $caseId = (int) ($date['case_id'] ?? 0);
        $status = strtolower((string) ($date['status'] ?? 'scheduled'));
        $hearingTitle = (string) ($date['title'] ?? 'Court date');
        $caseTitle = (string) ($date['case_title'] ?? '');
        $titleLabel = $caseTitle !== '' ? $caseTitle : $hearingTitle;
        $displayTitle = legalpro_portal_schedule_calendar_title($caseId, $titleLabel, 'Court date');
        $courtDateLabel = date('M j, Y g:i A', strtotime((string) $date['court_date']));
        $clientName = trim((string) ($date['client_name'] ?? ''));

        $events[] = [
            'id' => (string) ($date['id'] ?? ''),
            'title' => $displayTitle,
            'start' => $date['court_date'],
            'backgroundColor' => 'transparent',
            'borderColor' => 'transparent',
            'textColor' => '#344767',
            'extendedProps' => [
                'status' => $status,
                'description' => $date['description'] ?? '',
                'location' => $date['location'] ?? '',
                'client_name' => $clientName,
                'case_title' => $caseTitle,
                'court_title' => $hearingTitle,
                'created_by_name' => $date['created_by_name'] ?? '',
                'creator_role' => $date['creator_role'] ?? '',
                'case_id' => $caseId,
                'courtDateId' => (int) ($date['id'] ?? 0),
                'courtDateLabel' => $courtDateLabel,
                'hearingTitle' => $hearingTitle,
                'statusLabel' => ucfirst($status),
                'agendaSub' => $clientName !== '' ? $clientName : '—',
                'searchHay' => strtolower(implode(' ', array_filter([
                    $displayTitle,
                    $caseTitle,
                    $hearingTitle,
                    $clientName,
                    $date['location'] ?? '',
                    $date['description'] ?? '',
                    $status,
                    $courtDateLabel,
                    date('Y-m-d', strtotime((string) $date['court_date'])),
                    date('m/d/Y', strtotime((string) $date['court_date'])),
                    $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : '',
                    (string) ($date['id'] ?? ''),
                ]))),
            ],
        ];
    }

    return $events;
}
