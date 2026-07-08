<?php

/**
 * Shared lp-cal-studio calendar shell (all portals).
 *
 * @param array{
 *   calendar_id?: string,
 *   id_prefix?: string,
 *   legend?: array<int, array{key: string, label: string}>,
 *   add_href?: string,
 *   add_title?: string,
 *   add_aria?: string,
 *   add_modal?: string,
 *   agenda_title?: string
 * } $config
 */
function legalpro_render_calendar_studio_shell(array $config = []): string
{
    $calendarId = (string) ($config['calendar_id'] ?? 'portalCalendar');
    $prefix = (string) ($config['id_prefix'] ?? 'lpCal');
    $agendaTitle = (string) ($config['agenda_title'] ?? 'Agenda');
    $legend = $config['legend'] ?? [];

    $addHtml = '';
    if (!empty($config['add_modal'])) {
        $modal = htmlspecialchars((string) $config['add_modal'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string) ($config['add_title'] ?? 'Add'), ENT_QUOTES, 'UTF-8');
        $aria = htmlspecialchars((string) ($config['add_aria'] ?? $title), ENT_QUOTES, 'UTF-8');
        $addHtml = '<button type="button" class="lp-cal-studio__add-btn" data-bs-toggle="modal" data-bs-target="'
            . $modal . '" title="' . $title . '" aria-label="' . $aria . '">+</button>';
    } elseif (!empty($config['add_href'])) {
        $href = htmlspecialchars((string) $config['add_href'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string) ($config['add_title'] ?? 'Add'), ENT_QUOTES, 'UTF-8');
        $aria = htmlspecialchars((string) ($config['add_aria'] ?? $title), ENT_QUOTES, 'UTF-8');
        $addHtml = '<a href="' . $href . '" class="lp-cal-studio__add-btn" title="' . $title . '" aria-label="' . $aria . '">+</a>';
    } elseif (!empty($config['add_onclick'])) {
        $onclick = htmlspecialchars((string) $config['add_onclick'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string) ($config['add_title'] ?? 'Add'), ENT_QUOTES, 'UTF-8');
        $aria = htmlspecialchars((string) ($config['add_aria'] ?? $title), ENT_QUOTES, 'UTF-8');
        $addHtml = '<button type="button" class="lp-cal-studio__add-btn" onclick="' . $onclick
            . '" title="' . $title . '" aria-label="' . $aria . '">+</button>';
    }

    $legendHtml = '';
    foreach ($legend as $item) {
        $key = htmlspecialchars((string) ($item['key'] ?? ''), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($key === '' || $label === '') {
            continue;
        }
        $legendHtml .= '<span class="lp-cal-studio__legend-item lp-cal-studio__legend-item--' . $key
            . '"><i></i> ' . $label . '</span>';
    }

    $yearLabelId = htmlspecialchars($prefix . 'YearLabel', ENT_QUOTES, 'UTF-8');
    $yearPrevId = htmlspecialchars($prefix . 'YearPrev', ENT_QUOTES, 'UTF-8');
    $yearNextId = htmlspecialchars($prefix . 'YearNext', ENT_QUOTES, 'UTF-8');
    $monthListId = htmlspecialchars($prefix . 'MonthList', ENT_QUOTES, 'UTF-8');
    $monthTitleId = htmlspecialchars($prefix . 'MonthTitle', ENT_QUOTES, 'UTF-8');
    $agendaListId = htmlspecialchars($prefix . 'AgendaList', ENT_QUOTES, 'UTF-8');
    $calendarIdAttr = htmlspecialchars($calendarId, ENT_QUOTES, 'UTF-8');

    return '<div class="dashboard-calendar-hub lp-cal-studio-wrap">'
        . '<div class="lp-cal-studio">'
        . '<aside class="lp-cal-studio__months" aria-label="Month navigation">'
        . '<div class="lp-cal-studio__year">'
        . '<button type="button" class="lp-cal-studio__year-btn" id="' . $yearPrevId . '" aria-label="Previous year">&lsaquo;</button>'
        . '<span id="' . $yearLabelId . '">' . date('Y') . '</span>'
        . '<button type="button" class="lp-cal-studio__year-btn" id="' . $yearNextId . '" aria-label="Next year">&rsaquo;</button>'
        . '</div>'
        . '<ul class="lp-cal-studio__month-list" id="' . $monthListId . '"></ul>'
        . '</aside>'
        . '<div class="lp-cal-studio__main">'
        . '<div class="lp-cal-studio__main-head">'
        . '<h3 class="lp-cal-studio__month-title" id="' . $monthTitleId . '">JANUARY</h3>'
        . $addHtml
        . '</div>'
        . '<div id="' . $calendarIdAttr . '" class="lp-cal-studio__calendar"></div>'
        . ($legendHtml !== ''
            ? '<div class="lp-cal-studio__legend" aria-label="Status legend">' . $legendHtml . '</div>'
            : '')
        . '</div>'
        . '<aside class="lp-cal-studio__agenda" aria-label="Month agenda">'
        . '<div class="lp-cal-studio__agenda-head"><span>' . htmlspecialchars($agendaTitle, ENT_QUOTES, 'UTF-8') . '</span></div>'
        . '<div class="lp-cal-studio__agenda-list" id="' . $agendaListId . '"></div>'
        . '</aside>'
        . '</div>'
        . '</div>';
}
