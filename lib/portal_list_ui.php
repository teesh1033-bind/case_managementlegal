<?php

/**
 * Shared list card toolbar, search, status filter, and pagination (all portals).
 */

function legalpro_portal_list_status_chevron_svg(): string
{
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">'
        . '<path d="M6 9l6 6 6-6"/></svg>';
}

function legalpro_portal_list_search_svg(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">'
        . '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3-3"></path></svg>';
}

/**
 * Calendar block above list tables (all portals).
 */
function legalpro_render_portal_calendar_section(string $content, string $sectionId = ''): string
{
    $idAttr = $sectionId !== ''
        ? ' id="' . htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') . '"'
        : '';

    return '<div class="row mb-4 lp-appt-calendar-section"' . $idAttr . '>'
        . '<div class="col-12">' . $content . '</div>'
        . '</div>';
}

/**
 * Full-width list/table block below calendar (all portals).
 */
function legalpro_render_portal_list_section_open(string $sectionId = ''): string
{
    $idAttr = $sectionId !== ''
        ? ' id="' . htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') . '"'
        : '';

    return '<div class="row lp-appt-list-section"' . $idAttr . '><div class="col-12">';
}

function legalpro_render_portal_list_section_close(): string
{
    return '</div></div>';
}

/**
 * Standard list table header cell (matches admin appointments reference).
 */
function legalpro_render_portal_list_th(string $label, string $extraClass = ''): string
{
    $classAttr = trim($extraClass) !== ''
        ? ' class="' . htmlspecialchars(trim($extraClass), ENT_QUOTES, 'UTF-8') . '"'
        : '';

    return '<th' . $classAttr . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
}

/**
 * @param array<int, array{value: string, label: string}> $statusOptions empty value = "All statuses"
 */
function legalpro_render_portal_list_toolbar(
    string $searchInputId,
    string $searchPlaceholder,
    string $searchLabel = 'Search',
    string $statusFilterId = '',
    array $statusOptions = [],
    string $allStatusesLabel = 'All statuses'
): string {
    $searchHtml = '<div class="admin-cal-search-wrap lp-appt-list-search-wrap">'
        . '<label class="visually-hidden" for="' . htmlspecialchars($searchInputId, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($searchLabel) . '</label>'
        . '<div class="admin-cal-search-field">'
        . '<span class="admin-cal-search-icon" aria-hidden="true">' . legalpro_portal_list_search_svg() . '</span>'
        . '<input type="search" id="' . htmlspecialchars($searchInputId, ENT_QUOTES, 'UTF-8') . '" class="admin-cal-search-input"'
        . ' placeholder="' . htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8') . '" autocomplete="off"'
        . ' aria-label="' . htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8') . '">'
        . '</div></div>';

    if ($statusFilterId === '' || empty($statusOptions)) {
        return '<div class="lp-appt-list-card__filters"><div class="lp-appt-list-toolbar">' . $searchHtml . '</div></div>';
    }

    $optionsHtml = '<option value="">' . htmlspecialchars($allStatusesLabel) . '</option>';
    foreach ($statusOptions as $opt) {
        $val = (string) ($opt['value'] ?? '');
        $label = (string) ($opt['label'] ?? $val);
        if ($val === '' && $label === '') {
            continue;
        }
        $optionsHtml .= '<option value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label) . '</option>';
    }

    $statusHtml = '<div class="lp-appt-list-status-wrap">'
        . '<label class="visually-hidden" for="' . htmlspecialchars($statusFilterId, ENT_QUOTES, 'UTF-8') . '">Filter by status</label>'
        . '<div class="lp-appt-list-status-field">'
        . '<select id="' . htmlspecialchars($statusFilterId, ENT_QUOTES, 'UTF-8') . '" class="lp-appt-list-status-select" aria-label="Filter by status">'
        . $optionsHtml
        . '</select>'
        . '<span class="lp-appt-list-status-chevron" aria-hidden="true">' . legalpro_portal_list_status_chevron_svg() . '</span>'
        . '</div></div>';

    return '<div class="lp-appt-list-card__filters">'
        . '<div class="lp-appt-list-toolbar">' . $searchHtml . $statusHtml . '</div>'
        . '</div>';
}

function legalpro_render_portal_list_card_open(string $title, string $subtitle = ''): string
{
    $subtitleHtml = trim($subtitle) !== ''
        ? '<p class="mb-0">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    return '<div class="lp-appt-list-card card">'
        . '<div class="lp-appt-list-card__head"><div>'
        . '<h6 class="mb-0">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h6>'
        . $subtitleHtml
        . '</div></div>';
}

function legalpro_render_portal_list_pagination_open(
    int $perPage = 10,
    string $rowSelector = '.legalpro-admin-list-row'
): string {
    return '<div class="card-body px-0 pt-0 pb-2">'
        . '<div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="' . (int) $perPage . '"'
        . ' data-lp-row="' . htmlspecialchars($rowSelector, ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="table-responsive lp-appt-list-table-wrap">';
}

function legalpro_render_portal_list_pagination_close(string $paginationAria = 'Table pagination'): string
{
    if (!function_exists('legalpro_render_admin_table_pagination_nav')) {
        require_once __DIR__ . '/../inc/admin-layout.php';
    }

    return legalpro_render_admin_table_pagination_nav($paginationAria) . '</div></div></div>';
}

function legalpro_render_portal_list_empty_row(
    string $emptyRowId,
    int $colspan,
    string $message = 'No items match your filters.'
): string {
    return '<tr id="' . htmlspecialchars($emptyRowId, ENT_QUOTES, 'UTF-8') . '" class="d-none">'
        . '<td colspan="' . (int) $colspan . '" class="text-center text-muted text-sm py-4">'
        . htmlspecialchars($message) . '</td></tr>';
}

/**
 * @deprecated Use legalpro_render_portal_list_pagination_open/close.
 */
function legalpro_render_portal_list_table_open(
    string $tbodyId,
    int $perPage = 10,
    string $rowSelector = '.legalpro-admin-list-row',
    string $paginationAria = 'Table pagination'
): string {
    return legalpro_render_portal_list_pagination_open($perPage, $rowSelector)
        . '<table class="table align-items-center mb-0 lp-appt-list-table"><tbody id="'
        . htmlspecialchars($tbodyId, ENT_QUOTES, 'UTF-8') . '">';
}

function legalpro_render_portal_list_table_close(
    string $emptyRowId = '',
    int $emptyColspan = 7,
    string $emptyMessage = 'No items match your filters.',
    string $paginationAria = 'Table pagination'
): string {
    $emptyHtml = $emptyRowId !== ''
        ? legalpro_render_portal_list_empty_row($emptyRowId, $emptyColspan, $emptyMessage)
        : '';

    if (!function_exists('legalpro_render_admin_table_pagination_nav')) {
        require_once __DIR__ . '/../inc/admin-layout.php';
    }

    return $emptyHtml . '</tbody></table></div>'
        . legalpro_render_admin_table_pagination_nav($paginationAria)
        . '</div></div></div>';
}

/**
 * Client-side search + optional status filter with shared pagination refresh.
 */
function legalpro_portal_list_filter_script(
    string $searchInputId,
    string $tbodyId,
    string $emptyRowId = '',
    string $rowSelector = '.legalpro-admin-list-row',
    string $statusFilterId = ''
): string {
    $searchIdJs = json_encode($searchInputId);
    $tbodyIdJs = json_encode($tbodyId);
    $emptyIdJs = json_encode($emptyRowId);
    $rowSelJs = json_encode($rowSelector . '[data-search]');
    $statusIdJs = json_encode($statusFilterId);
    $hasStatus = $statusFilterId !== '' ? 'true' : 'false';

    return '<script>(function(){'
        . 'var searchInput=document.getElementById(' . $searchIdJs . ');'
        . 'var statusFilter=' . ($hasStatus === 'true' ? 'document.getElementById(' . $statusIdJs . ')' : 'null') . ';'
        . 'var tbody=document.getElementById(' . $tbodyIdJs . ');'
        . 'var emptyNote=' . ($emptyRowId !== '' ? 'document.getElementById(' . $emptyIdJs . ')' : 'null') . ';'
        . 'if(!tbody){return;}'
        . 'function applyPortalListFilters(){'
        . 'var q=(searchInput&&searchInput.value?searchInput.value:"").trim().toLowerCase();'
        . 'var statusValue=statusFilter?statusFilter.value:"";'
        . 'var rows=tbody.querySelectorAll(' . $rowSelJs . ');'
        . 'var visible=0;'
        . 'rows.forEach(function(row){'
        . 'var textMatch=!q||(row.getAttribute("data-search")||"").toLowerCase().indexOf(q)!==-1;'
        . 'var statusMatch=!statusValue||row.getAttribute("data-display-status")===statusValue;'
        . 'var match=textMatch&&statusMatch;'
        . 'row.classList.toggle("lp-admin-row-filtered",!match);'
        . 'if(match){visible++;}'
        . '});'
        . 'if(emptyNote){emptyNote.classList.toggle("d-none",visible>0||rows.length===0);}'
        . 'var paginateWrap=tbody.closest("[data-lp-admin-paginate]");'
        . 'if(paginateWrap&&window.LegalproAdminTablePagination){window.LegalproAdminTablePagination.refresh(paginateWrap);}'
        . '}'
        . 'if(searchInput){searchInput.addEventListener("input",applyPortalListFilters);}'
        . 'if(statusFilter){statusFilter.addEventListener("change",applyPortalListFilters);}'
        . 'document.querySelectorAll(".lp-appt-list-status-field").forEach(function(field){'
        . 'var select=field.querySelector("select");'
        . 'if(!select){return;}'
        . 'field.addEventListener("mousedown",function(e){'
        . 'if(e.target===select){return;}'
        . 'e.preventDefault();'
        . 'if(typeof select.showPicker==="function"){try{select.showPicker();return;}catch(err){}}'
        . 'select.focus();'
        . '});'
        . '});'
        . '})();</script>';
}

function legalpro_portal_appointment_status_options(): array
{
    return [
        ['value' => 'scheduled', 'label' => 'Scheduled'],
        ['value' => 'confirmed', 'label' => 'Confirmed'],
        ['value' => 'rescheduled', 'label' => 'Rescheduled'],
        ['value' => 'past', 'label' => 'Past'],
        ['value' => 'completed', 'label' => 'Completed'],
        ['value' => 'cancelled', 'label' => 'Cancelled'],
    ];
}

function legalpro_portal_lawyer_appointment_status_options(): array
{
    return [
        ['value' => 'pending', 'label' => 'Pending'],
        ['value' => 'accepted', 'label' => 'Accepted'],
        ['value' => 'rejected', 'label' => 'Rejected'],
    ];
}

function legalpro_portal_court_status_options(): array
{
    return [
        ['value' => 'scheduled', 'label' => 'Scheduled'],
        ['value' => 'completed', 'label' => 'Completed'],
        ['value' => 'postponed', 'label' => 'Postponed'],
        ['value' => 'cancelled', 'label' => 'Cancelled'],
    ];
}

function legalpro_portal_availability_status_options(): array
{
    return legalpro_portal_appointment_status_options();
}

/**
 * Admin appointments calendar legend (6 display statuses).
 *
 * @param callable|null $labelResolver fn(string $key, string $defaultLabel): string
 */
function legalpro_portal_appointment_calendar_legend(?callable $labelResolver = null): array
{
    $legend = [];
    foreach (legalpro_portal_appointment_status_options() as $option) {
        $key = (string) ($option['value'] ?? '');
        $label = (string) ($option['label'] ?? ucfirst($key));
        if ($labelResolver) {
            $label = (string) $labelResolver($key, $label);
        }
        $legend[] = ['key' => $key, 'label' => $label];
    }

    return $legend;
}

/**
 * Standard admin-appointments table header row (7 columns).
 */
function legalpro_render_portal_appointment_table_headers(array $labels = []): string
{
    $defaults = [
        'datetime' => 'Date & Time',
        'title' => 'Title',
        'person' => 'Client',
        'case' => 'Case',
        'status' => 'Status',
        'calendar' => 'Calendar',
        'actions' => 'Actions',
    ];
    $labels = array_merge($defaults, $labels);

    return legalpro_render_portal_list_th($labels['datetime'], 'ps-3')
        . legalpro_render_portal_list_th($labels['title'])
        . legalpro_render_portal_list_th($labels['person'])
        . legalpro_render_portal_list_th($labels['case'])
        . legalpro_render_portal_list_th($labels['status'], 'text-center')
        . legalpro_render_portal_list_th($labels['calendar'], 'text-center')
        . legalpro_render_portal_list_th($labels['actions'], 'text-end pe-3');
}

/**
 * Unified status pill (all schedule hub tables).
 */
function legalpro_portal_status_badge(string $displayKey, string $label): string
{
    $key = preg_replace('/[^a-z0-9_-]/', '', strtolower($displayKey)) ?: 'scheduled';

    return '<span class="lp-appt-list-status lp-appt-list-status--' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label)
        . '</span>';
}

function legalpro_portal_court_date_status_badge(string $status, ?callable $labelResolver = null): string
{
    $key = strtolower(trim($status));
    if (!in_array($key, ['scheduled', 'completed', 'postponed', 'cancelled'], true)) {
        $key = 'scheduled';
    }

    $defaultLabels = [
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'postponed' => 'Postponed',
        'cancelled' => 'Cancelled',
    ];

    $label = $labelResolver
        ? (string) $labelResolver($key, $status)
        : ($defaultLabels[$key] ?? ucwords(str_replace('_', ' ', $key)));

    return legalpro_portal_status_badge($key, $label);
}

function legalpro_portal_availability_status_badge(string $statusKey, string $label): string
{
    $key = strtolower(trim($statusKey));
    if (!in_array($key, ['available', 'unavailable', 'appointment'], true)) {
        $key = 'unavailable';
    }

    return legalpro_portal_status_badge($key, $label);
}

function legalpro_render_portal_list_card_close(): string
{
    return '</div>';
}

/**
 * Schedule hub calendar block (studio shell + section wrapper).
 */
function legalpro_render_portal_schedule_hub_calendar(array $config, string $hubId = ''): string
{
    if (!function_exists('legalpro_render_calendar_studio_shell')) {
        require_once __DIR__ . '/../inc/portal-calendar-studio.php';
    }

    $config = array_merge([
        'id_prefix' => 'lpCal',
        'agenda_title' => 'Agenda',
        'legend' => legalpro_portal_appointment_calendar_legend(),
    ], $config);

    return legalpro_render_portal_calendar_section(
        legalpro_render_calendar_studio_shell($config),
        $hubId
    );
}

/**
 * Schedule hub list block — identical card, toolbar, table, and pagination shell.
 *
 * @param array{
 *   header_labels?: array<string, string>,
 *   empty_colspan?: int,
 *   empty_message?: string,
 *   pagination_aria?: string,
 *   all_statuses_label?: string
 * } $options
 */
function legalpro_render_portal_schedule_hub_list_section(
    string $sectionId,
    string $title,
    string $subtitle,
    string $searchInputId,
    string $searchPlaceholder,
    string $searchLabel,
    string $statusFilterId,
    array $statusOptions,
    string $tbodyId,
    string $tbodyHtml,
    string $emptyRowId,
    array $options = []
): string {
    $headerLabels = $options['header_labels'] ?? [];
    $emptyColspan = (int) ($options['empty_colspan'] ?? 7);
    $emptyMessage = (string) ($options['empty_message'] ?? 'No items match your filters.');
    $paginationAria = (string) ($options['pagination_aria'] ?? 'Table pagination');
    $allStatusesLabel = (string) ($options['all_statuses_label'] ?? 'All statuses');

    return legalpro_render_portal_list_section_open($sectionId)
        . legalpro_render_portal_list_card_open($title, $subtitle)
        . legalpro_render_portal_list_toolbar(
            $searchInputId,
            $searchPlaceholder,
            $searchLabel,
            $statusFilterId,
            $statusOptions,
            $allStatusesLabel
        )
        . legalpro_render_portal_list_pagination_open()
        . '<table class="table align-items-center mb-0 lp-appt-list-table"><thead><tr>'
        . legalpro_render_portal_appointment_table_headers($headerLabels)
        . '</tr></thead><tbody id="' . htmlspecialchars($tbodyId, ENT_QUOTES, 'UTF-8') . '">'
        . $tbodyHtml
        . legalpro_render_portal_list_empty_row($emptyRowId, $emptyColspan, $emptyMessage)
        . '</tbody></table>'
        . legalpro_render_portal_list_pagination_close($paginationAria)
        . legalpro_render_portal_list_card_close()
        . legalpro_render_portal_list_section_close();
}
