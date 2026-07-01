<?php

require_once __DIR__ . '/lawyer-locale.php';

/**
 * Translate with English fallback when the key is missing.
 */
function lawyer_tf(string $key, string $fallback = '', array $replace = []): string
{
    $value = lawyer_t($key, $replace);
    if ($value === $key) {
        $text = $fallback !== '' ? $fallback : $key;
        foreach ($replace as $search => $replaceValue) {
            $text = str_replace(':' . $search, (string) $replaceValue, $text);
        }
        return $text;
    }

    return $value;
}

/**
 * Standard lawyer portal breadcrumb navbar (matches client portal pattern).
 *
 * @param array<int, array{label: string, url?: string}> $crumbs Optional intermediate crumbs after portal home.
 */
function legalpro_render_lawyer_breadcrumb_navbar(
    string $pageTitle,
    array $crumbs = [],
    array $options = []
): string {
    $titleTag = (string) ($options['title_tag'] ?? 'h6');
    if (!in_array($titleTag, ['h5', 'h6'], true)) {
        $titleTag = 'h6';
    }

    $parentLabel = htmlspecialchars(
        (string) ($options['parent_label'] ?? lawyer_tf('nav.lawyer_portal', 'Lawyer Portal')),
        ENT_QUOTES,
        'UTF-8'
    );
    $parentUrl = htmlspecialchars(
        (string) ($options['parent_url'] ?? 'lawyer-dashboard.php'),
        ENT_QUOTES,
        'UTF-8'
    );
    $parentLinkClass = htmlspecialchars(
        (string) ($options['parent_link_class'] ?? 'opacity-5'),
        ENT_QUOTES,
        'UTF-8'
    );
    $pageTitleEsc = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');

    $crumbHtml = '<li class="breadcrumb-item text-sm">'
        . '<a class="' . $parentLinkClass . ' text-white" href="' . $parentUrl . '">' . $parentLabel . '</a>'
        . '</li>';

    foreach ($crumbs as $crumb) {
        $label = htmlspecialchars((string) ($crumb['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($label === '') {
            continue;
        }
        $url = trim((string) ($crumb['url'] ?? ''));
        if ($url !== '') {
            $crumbHtml .= '<li class="breadcrumb-item text-sm">'
                . '<a class="opacity-5 text-white" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>'
                . '</li>';
        } else {
            $crumbHtml .= '<li class="breadcrumb-item text-sm text-white active" aria-current="page">' . $label . '</li>';
        }
    }

    $crumbHtml .= '<li class="breadcrumb-item text-sm text-white active" aria-current="page">' . $pageTitleEsc . '</li>';

    return '
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">'
                        . $crumbHtml .
                    '</ol>
                    <' . $titleTag . ' class="font-weight-bolder text-white mb-0">' . $pageTitleEsc . '</' . $titleTag . '>
                </nav>
            </div>
        </nav>';
}

function lawyer_status_label(string $status): string
{
    $raw = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'open' => 'active',
        'in_progress' => 'under_review',
        'active' => 'active',
        'pending' => 'pending',
        'under_review' => 'under_review',
        'completed' => 'closed',
        'cancelled' => 'closed',
        'closed' => 'closed',
    ];
    $key = $map[$raw] ?? 'pending';
    $fallbacks = [
        'active' => 'Active',
        'pending' => 'Pending',
        'under_review' => 'Under review',
        'closed' => 'Closed',
    ];

    return lawyer_tf('status.' . $key, $fallbacks[$key] ?? ucwords(str_replace('_', ' ', $key)));
}

function lawyer_priority_label(string $priority): string
{
    $raw = strtolower(trim($priority));
    $map = [
        'low' => 'normal',
        'medium' => 'normal',
        'normal' => 'normal',
        'high' => 'high',
        'urgent' => 'urgent',
    ];
    $key = $map[$raw] ?? 'normal';
    $fallbacks = [
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    return lawyer_tf('priority.' . $key, $fallbacks[$key] ?? 'Normal');
}

function lawyer_portal_js_date_locale(): string
{
    return getLawyerPortalLocale() === 'fr' ? 'fr-FR' : 'en-US';
}

function lawyer_portal_fc_locale(): string
{
    return getLawyerPortalLocale() === 'fr' ? 'fr' : 'en';
}

function lawyer_portal_format_time(string $timeValue): string
{
    $ts = strtotime($timeValue);
    if ($ts === false) {
        return $timeValue;
    }

    if (getLawyerPortalLocale() === 'fr') {
        return date('H:i', $ts);
    }

    return date('g:i A', $ts);
}

function lawyer_portal_format_time_range(string $startTime, string $endTime): string
{
    return lawyer_portal_format_time($startTime) . ' - ' . lawyer_portal_format_time($endTime);
}

function lawyer_appointment_status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'approved') {
        $status = 'accepted';
    }

    $map = [
        'pending' => ['appointments.status_pending', 'Pending'],
        'accepted' => ['appointments.status_accepted', 'Accepted'],
        'rejected' => ['appointments.status_rejected', 'Rejected'],
        'scheduled' => ['appointments.status_scheduled', 'Scheduled'],
        'completed' => ['appointments.status_completed', 'Completed'],
        'today' => ['appointments.status_today', 'Today'],
        'locked' => ['appointments.status_locked', 'Locked'],
    ];

    if (isset($map[$status])) {
        return lawyer_tf($map[$status][0], $map[$status][1]);
    }

    return ucwords(str_replace('_', ' ', $status));
}

/** @return array<int, string> */
function lawyer_portal_day_names(): array
{
    return [
        lawyer_tf('calendar.sunday', 'Sunday'),
        lawyer_tf('calendar.monday', 'Monday'),
        lawyer_tf('calendar.tuesday', 'Tuesday'),
        lawyer_tf('calendar.wednesday', 'Wednesday'),
        lawyer_tf('calendar.thursday', 'Thursday'),
        lawyer_tf('calendar.friday', 'Friday'),
        lawyer_tf('calendar.saturday', 'Saturday'),
    ];
}

/** @return array<string, string> */
function lawyer_portal_fc_button_text(): array
{
    return [
        'today' => lawyer_tf('calendar.today', 'Today'),
        'month' => lawyer_tf('calendar.month', 'Month'),
        'week' => lawyer_tf('calendar.week', 'Week'),
        'list' => lawyer_tf('calendar.list', 'List'),
    ];
}

function lawyer_portal_format_datetime(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }

    if (getLawyerPortalLocale() === 'fr') {
        $months = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $month = $months[(int) date('n', $ts)] ?? date('M', $ts);

        return date('j', $ts) . ' ' . $month . ' ' . date('Y', $ts) . ' ' . lawyer_portal_format_time(date('H:i', $ts));
    }

    return date('M j, Y g:i A', $ts);
}

function lawyer_portal_fc_locale_script(): string
{
    if (lawyer_portal_fc_locale() !== 'fr') {
        return '';
    }

    return '<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.11/locales/fr.global.min.js"></script>';
}
