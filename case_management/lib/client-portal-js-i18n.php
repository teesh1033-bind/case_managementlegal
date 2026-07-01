<?php

require_once __DIR__ . '/client-locale.php';

/**
 * Flat map of JS keys => translation keys for client portal scripts.
 */
function client_portal_js_key_map(): array
{
    return [
        'close' => 'common.close',
        'loading' => 'common.loading',
        'no_notifications' => 'notifications.empty',
        'unread_hint' => 'notifications.unread_hint',
        'prev_page' => 'js.prev_page',
        'next_page' => 'js.next_page',
        'prev_short' => 'js.prev_short',
        'next_short' => 'js.next_short',
        'page' => 'js.page',
        'current' => 'js.current',
        'showing_range' => 'js.showing_range',
        'total_suffix' => 'payments.word_total',
        'appointment' => 'js.appointment',
        'appointments' => 'js.appointments',
        'invoice' => 'js.invoice',
        'invoices' => 'js.invoices',
        'payment' => 'js.payment',
        'payments' => 'js.payments',
        'court_date' => 'js.court_date',
        'court_dates' => 'js.court_dates',
        'select_time' => 'appointments.select_time',
        'select_date_times' => 'js.select_date_times',
        'date_unavailable' => 'js.date_unavailable',
        'loading_times' => 'js.loading_times',
        'load_times_error' => 'js.load_times_error',
        'lawyer_not_working' => 'js.lawyer_not_working',
        'lawyer_weekday_hours' => 'js.lawyer_weekday_hours',
        'no_times_date' => 'js.no_times_date',
        'times_limited_hours' => 'js.times_limited_hours',
        'standard_hours' => 'js.standard_hours',
        'no_matching_times' => 'js.no_matching_times',
        'select_lawyer' => 'js.select_lawyer',
        'select_case' => 'js.select_case',
        'select_date' => 'js.select_date',
        'select_time_slot' => 'js.select_time_slot',
        'time_unavailable' => 'js.time_unavailable',
        'verify_availability_error' => 'js.verify_availability_error',
        'load_appointment_error' => 'js.load_appointment_error',
        'load_availability_error' => 'js.load_availability_error',
        'no_appt_search_match' => 'js.no_appt_search_match',
        'no_court_search_match' => 'js.no_court_search_match',
        'status_pending' => 'appointments.status.pending',
        'status_accepted' => 'js.status_accepted',
        'status_rejected' => 'appointments.status.rejected',
        'status_scheduled' => 'court.stat_scheduled',
        'status_completed' => 'court.stat_completed',
        'status_cancelled' => 'court.status_cancelled',
        'status_postponed' => 'court.status_postponed',
        'court_date_fallback' => 'activity.court_date',
        'not_specified' => 'common.not_set',
        'unknown' => 'documents.unknown',
        'no_description' => 'js.no_description',
        'no_notes' => 'js.no_notes',
        'appointment_fallback' => 'common.general_appointment',
        'tbd' => 'common.tbd',
        'pdf' => 'common.pdf',
    ];
}

function client_portal_js_dict(): array
{
    $out = [];
    foreach (client_portal_js_key_map() as $jsKey => $tKey) {
        $out[$jsKey] = client_t($tKey);
    }
    $out['locale'] = getClientPortalLocale();
    $out['date_locale'] = $out['locale'] === 'fr' ? 'fr-FR' : 'en-GB';

    return $out;
}

function client_portal_render_i18n_script(): string
{
    $json = json_encode(client_portal_js_dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    if ($json === false) {
        $json = '{}';
    }

    return '<script>window.clientPortalI18n=' . $json . ';</script>';
}

function client_portal_i18n(string $jsKey): string
{
    $map = client_portal_js_key_map();
    if (isset($map[$jsKey])) {
        return client_t($map[$jsKey]);
    }

    return client_t($jsKey);
}
