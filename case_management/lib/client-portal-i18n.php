<?php

require_once __DIR__ . '/client-locale.php';

function client_greeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) {
        return client_t('greeting.morning');
    }
    if ($hour < 17) {
        return client_t('greeting.afternoon');
    }

    return client_t('greeting.evening');
}

function client_status_label(string $status): string
{
    $key = strtolower(str_replace([' ', '-'], '_', trim($status)));
    $map = [
        'active' => 'status.active',
        'open' => 'status.active',
        'in_progress' => 'status.in_progress',
        'pending' => 'status.pending',
        'under_review' => 'status.under_review',
        'under review' => 'status.under_review',
        'closed' => 'status.closed',
    ];

    if (isset($map[$key])) {
        return client_t($map[$key]);
    }

    return ucwords(str_replace('_', ' ', $key));
}

function client_priority_label(string $priority): string
{
    $key = strtolower(trim($priority));
    $map = [
        'high' => 'priority.high',
        'urgent' => 'priority.urgent',
        'normal' => 'priority.normal',
        'low' => 'priority.low',
    ];

    if (isset($map[$key])) {
        return client_t($map[$key]);
    }

    return $priority !== '' ? ucfirst($priority) : client_t('priority.normal');
}

function client_plural(string $singularKey, string $pluralKey, int $count): string
{
    return client_t($count === 1 ? $singularKey : $pluralKey);
}

function client_format_date(?string $value, string $style = 'medium'): string
{
    if ($value === null || trim($value) === '') {
        return client_t('common.not_set');
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return client_t('common.not_set');
    }

    if (getClientPortalLocale() === 'fr') {
        $months = [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.',
            5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août',
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ];
        $d = (int) date('j', $ts);
        $m = $months[(int) date('n', $ts)] ?? date('M', $ts);
        $y = date('Y', $ts);

        if ($style === 'long') {
            $monthsLong = [
                1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
                5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
                9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
            ];
            $m = $monthsLong[(int) date('n', $ts)] ?? $m;

            return $d . ' ' . $m . ' ' . $y;
        }

        return $d . ' ' . $m . ' ' . $y;
    }

    if ($style === 'long') {
        return date('F j, Y', $ts);
    }

    return date('M j, Y', $ts);
}

function client_format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return client_t('common.not_set');
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return client_t('common.not_set');
    }

    if (getClientPortalLocale() === 'fr') {
        $months = [
            1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.',
            5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août',
            9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.',
        ];
        $d = (int) date('j', $ts);
        $m = $months[(int) date('n', $ts)] ?? date('M', $ts);
        $y = date('Y', $ts);
        $time = date('H:i', $ts);

        return $d . ' ' . $m . ' ' . $y . ' · ' . $time;
    }

    return date('M j, Y · g:i A', $ts);
}

function client_comment_role_label(string $type): string
{
    $map = [
        'client' => 'case.role_client',
        'lawyer' => 'case.role_lawyer',
        'admin' => 'case.role_admin',
        'staff' => 'case.role_staff',
    ];

    return client_t($map[$type] ?? 'case.role_system');
}

function client_modern_status_badge(string $status): string
{
    $key = strtolower(str_replace([' ', '-'], '_', trim($status)));
    $map = [
        'active' => ['cls' => 'badge-active', 'dot' => '#16a34a'],
        'open' => ['cls' => 'badge-active', 'dot' => '#16a34a'],
        'in_progress' => ['cls' => 'badge-active', 'dot' => '#16a34a'],
        'pending' => ['cls' => 'badge-pending', 'dot' => '#ca8a04'],
        'under_review' => ['cls' => 'badge-review', 'dot' => 'currentColor'],
        'closed' => ['cls' => 'badge-closed', 'dot' => '#94a3b8'],
    ];
    $cfg = $map[$key] ?? ['cls' => 'badge-closed', 'dot' => '#94a3b8'];

    return '<span class="badge ' . $cfg['cls'] . '">'
        . '<span class="badge-dot" style="background:' . $cfg['dot'] . '"></span>'
        . htmlspecialchars(client_status_label($status))
        . '</span>';
}

function client_modern_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $clsMap = [
        'high' => 'pri-high',
        'urgent' => 'pri-high',
        'normal' => 'pri-med',
    ];
    $cls = $clsMap[$key] ?? 'pri-med';

    return '<span class="' . $cls . '">' . htmlspecialchars(client_priority_label($priority)) . '</span>';
}

function client_case_nav_definitions(): array
{
    return [
        'client-case-view' => [
            'file' => 'client-case-view.php',
            'title_key' => 'case.tab.overview',
            'heading_key' => 'case.heading.overview',
            'subtitle_key' => 'case.subtitle.overview',
        ],
        'client-case-activity' => [
            'file' => 'client-case-activity.php',
            'title_key' => 'case.tab.activity',
            'heading_key' => 'case.heading.activity',
            'subtitle_key' => 'case.subtitle.activity',
        ],
        'client-case-services' => [
            'file' => 'client-case-services.php',
            'title_key' => 'case.tab.services',
            'heading_key' => 'case.heading.services',
            'subtitle_key' => 'case.subtitle.services',
        ],
        'client-case-appointments' => [
            'file' => 'client-case-appointments.php',
            'title_key' => 'case.tab.appointments',
            'heading_key' => 'case.heading.appointments',
            'subtitle_key' => 'case.subtitle.appointments',
        ],
        'client-case-documents' => [
            'file' => 'client-case-documents.php',
            'title_key' => 'case.tab.documents',
            'heading_key' => 'case.heading.documents',
            'subtitle_key' => 'case.subtitle.documents',
        ],
        'client-case-comments' => [
            'file' => 'client-case-comments.php',
            'title_key' => 'case.tab.comments',
            'heading_key' => 'case.heading.comments',
            'subtitle_key' => 'case.subtitle.comments',
        ],
    ];
}

function client_case_nav_resolved(): array
{
    $nav = [];
    foreach (client_case_nav_definitions() as $pageKey => $item) {
        $nav[$pageKey] = [
            'file' => $item['file'],
            'title' => client_t($item['title_key']),
            'heading' => client_t($item['heading_key']),
            'subtitle' => client_t($item['subtitle_key']),
        ];
    }

    return $nav;
}
