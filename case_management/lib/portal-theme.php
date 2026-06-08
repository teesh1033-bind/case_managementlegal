<?php

function getPortalThemeColorPresets(): array
{
    return [
        'primary' => [
            'label' => 'Purple',
            'primary' => '#5e72e4',
            'primary_dark' => '#825ee4',
            'sidebar_bg' => '#1e2a44',
            'sidebar_deep' => '#151d30',
            'badge_class' => 'bg-gradient-primary',
        ],
        'dark' => [
            'label' => 'Slate',
            'primary' => '#344767',
            'primary_dark' => '#2d3748',
            'sidebar_bg' => '#1a2035',
            'sidebar_deep' => '#111525',
            'badge_class' => 'bg-gradient-dark',
        ],
        'info' => [
            'label' => 'Cyan',
            'primary' => '#11cdef',
            'primary_dark' => '#1171ef',
            'sidebar_bg' => '#1a2f44',
            'sidebar_deep' => '#122333',
            'badge_class' => 'bg-gradient-info',
        ],
        'success' => [
            'label' => 'Green',
            'primary' => '#2dce89',
            'primary_dark' => '#2dcecc',
            'sidebar_bg' => '#1a352f',
            'sidebar_deep' => '#122820',
            'badge_class' => 'bg-gradient-success',
        ],
        'warning' => [
            'label' => 'Orange',
            'primary' => '#fb6340',
            'primary_dark' => '#fbb140',
            'sidebar_bg' => '#3d2a20',
            'sidebar_deep' => '#2a1c15',
            'badge_class' => 'bg-gradient-warning',
        ],
        'danger' => [
            'label' => 'Red',
            'primary' => '#f5365c',
            'primary_dark' => '#f56036',
            'sidebar_bg' => '#3d1f2a',
            'sidebar_deep' => '#2a141c',
            'badge_class' => 'bg-gradient-danger',
        ],
    ];
}

function portalThemeNormalizeHex(string $hex): ?string
{
    $hex = strtolower(trim($hex));
    if (preg_match('/^#([0-9a-f]{3})$/', $hex, $matches)) {
        return '#' . $matches[1][0] . $matches[1][0] . $matches[1][1] . $matches[1][1] . $matches[1][2] . $matches[1][2];
    }
    if (preg_match('/^#([0-9a-f]{6})$/', $hex)) {
        return $hex;
    }

    return null;
}

function portalThemeRgbFromHex(string $hex): array
{
    $hex = ltrim(portalThemeNormalizeHex($hex) ?? '#5e72e4', '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function portalThemeHexFromRgb(int $r, int $g, int $b): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

function portalThemeMixHex(string $hex1, string $hex2, float $ratio): string
{
    $ratio = max(0.0, min(1.0, $ratio));
    $rgb1 = portalThemeRgbFromHex($hex1);
    $rgb2 = portalThemeRgbFromHex($hex2);

    return portalThemeHexFromRgb(
        (int) round($rgb1[0] + (($rgb2[0] - $rgb1[0]) * $ratio)),
        (int) round($rgb1[1] + (($rgb2[1] - $rgb1[1]) * $ratio)),
        (int) round($rgb1[2] + (($rgb2[2] - $rgb1[2]) * $ratio))
    );
}

function portalThemeBuildCustomPreset(string $primaryHex): array
{
    $primary = portalThemeNormalizeHex($primaryHex) ?? '#5e72e4';
    $rgb = portalThemeRgbFromHex($primary);
    $primaryDark = portalThemeHexFromRgb(
        min(255, $rgb[0] + 36),
        min(255, max(0, $rgb[1] - 1)),
        min(255, $rgb[2])
    );

    return [
        'label' => 'Custom',
        'primary' => $primary,
        'primary_dark' => $primaryDark,
        'sidebar_bg' => portalThemeMixHex($primary, '#1a2035', 0.82),
        'sidebar_deep' => portalThemeMixHex($primary, '#111525', 0.88),
        'badge_class' => 'bg-gradient-primary',
    ];
}

function getPortalThemeColorOptions(): array
{
    return array_merge(getPortalThemeColorPresets(), [
        'custom' => portalThemeBuildCustomPreset((string) getSetting('portal_theme_custom_primary', '#5e72e4')),
    ]);
}

function getPortalTheme(): array
{
    $presets = getPortalThemeColorOptions();
    $mode = strtolower(trim((string) getSetting('portal_theme_mode', 'light')));
    $color = strtolower(trim((string) getSetting('portal_theme_color', 'primary')));

    if (!in_array($mode, ['light', 'dark'], true)) {
        $mode = 'light';
    }
    if (!isset($presets[$color])) {
        $color = 'primary';
    }

    return [
        'mode' => $mode,
        'color' => $color,
        'preset' => $presets[$color],
        'custom_primary' => portalThemeNormalizeHex((string) getSetting('portal_theme_custom_primary', '#5e72e4')) ?? '#5e72e4',
    ];
}

function savePortalTheme(string $mode, string $color, ?string $customPrimary = null): array
{
    $presets = getPortalThemeColorOptions();
    $mode = strtolower(trim($mode));
    $color = strtolower(trim($color));

    if (!in_array($mode, ['light', 'dark'], true)) {
        return ['ok' => false, 'message' => 'Invalid theme mode selected.'];
    }
    if (!isset($presets[$color])) {
        return ['ok' => false, 'message' => 'Invalid theme color selected.'];
    }

    if ($color === 'custom') {
        $normalized = portalThemeNormalizeHex((string) $customPrimary);
        if ($normalized === null) {
            return ['ok' => false, 'message' => 'Please choose a valid custom color.'];
        }
        setSetting('portal_theme_custom_primary', $normalized);
    }

    setSetting('portal_theme_mode', $mode);
    setSetting('portal_theme_color', $color);

    return ['ok' => true, 'message' => 'Appearance settings updated successfully.'];
}

function renderPortalThemeDarkCss(string $primary, string $rgb): string
{
    $soft12 = portalThemeHexToRgba($primary, 0.12);
    $soft20 = portalThemeHexToRgba($primary, 0.2);

    $bodies = 'body.legalpro-dark-mode,'
        . 'body.legalpro-dark-mode.legalpro-admin-portal,'
        . 'body.legalpro-dark-mode.legalpro-client-portal,'
        . 'body.legalpro-dark-mode.legalpro-lawyer-portal,'
        . 'body.legalpro-dark-mode.client-dashboard-page,'
        . 'body.legalpro-dark-mode.client-cases-page,'
        . 'body.legalpro-dark-mode.client-appointments-page,'
        . 'body.legalpro-dark-mode.client-court-tracking-page,'
        . 'body.legalpro-dark-mode.client-payments-page,'
        . 'body.legalpro-dark-mode.client-portal-page,'
        . 'body.legalpro-dark-mode.client-profile-page,'
        . 'body.legalpro-dark-mode.lawyer-dashboard-page,'
        . 'body.legalpro-dark-mode.lawyer-cases-page,'
        . 'body.legalpro-dark-mode.lawyer-clients-page,'
        . 'body.legalpro-dark-mode.lawyer-appointments-page,'
        . 'body.legalpro-dark-mode.lawyer-availability-page,'
        . 'body.legalpro-dark-mode.lawyer-case-view-page,'
        . 'body.legalpro-dark-mode.lawyer-client-view-page,'
        . 'body.legalpro-dark-mode.lawyer-court-tracking-page,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page,'
        . 'body.legalpro-dark-mode.lawyer-profile-page,'
        . 'body.legalpro-dark-mode.admin-court-tracking-page';

    $css = 'html.legalpro-theme-dark { background: #2a3040; }';

    $css .= ':root {'
        . '--lp-dark-bg: #2a3040;'
        . '--lp-dark-surface: #343b4f;'
        . '--lp-dark-surface-raised: #3d455c;'
        . '--lp-dark-surface-hover: #464f68;'
        . '--lp-dark-border: rgba(255, 255, 255, 0.1);'
        . '--lp-dark-border-strong: rgba(255, 255, 255, 0.16);'
        . '--lp-dark-text: #f8f9fc;'
        . '--lp-dark-text-secondary: #e2e8f2;'
        . '--lp-dark-text-muted: #c5cede;'
        . '--lp-dark-text-subtle: #9aa8bc;'
        . '--lp-dark-input-bg: #2f3547;'
        . '--lp-admin-content-bg: #2a3040;'
        . '--lp-portal-content-bg: #2a3040;'
        . '--client-portal-content-bg: #2a3040;'
        . '--lawyer-portal-content-bg: #2a3040;'
        . '--lp-task-card-bg: #3d455c;'
        . '--lp-task-card-border: rgba(255, 255, 255, 0.1);'
        . '--lp-task-card-body-bg: #3d455c;'
        . '}';

    $css .= $bodies . ' {'
        . 'background: linear-gradient(180deg, #2e3446 0%, #2a3040 45%, #272c3c 100%) !important;'
        . 'background-color: var(--lp-dark-bg) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.bg-gray-100 { background: var(--lp-dark-bg) !important; }';

    $css .= 'body.legalpro-dark-mode .main-content .card,'
        . 'body.legalpro-dark-mode .card,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub,'
        . 'body.legalpro-dark-mode .dashboard-stat-card,'
        . 'body.legalpro-dark-mode .dashboard-glance__item,'
        . 'body.legalpro-dark-mode .modal-content,'
        . 'body.legalpro-dark-mode .swal2-popup {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.18) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .card .card-header,'
        . 'body.legalpro-dark-mode .card-header {'
        . 'background: transparent !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .card h1, body.legalpro-dark-mode .card h2,'
        . 'body.legalpro-dark-mode .card h3, body.legalpro-dark-mode .card h4,'
        . 'body.legalpro-dark-mode .card h5, body.legalpro-dark-mode .card h6,'
        . 'body.legalpro-dark-mode .card .h1, body.legalpro-dark-mode .card .h2,'
        . 'body.legalpro-dark-mode .card .h3, body.legalpro-dark-mode .card .h4,'
        . 'body.legalpro-dark-mode .card .h5, body.legalpro-dark-mode .card .h6,'
        . 'body.legalpro-dark-mode h1, body.legalpro-dark-mode h2,'
        . 'body.legalpro-dark-mode h3, body.legalpro-dark-mode h4,'
        . 'body.legalpro-dark-mode h5, body.legalpro-dark-mode h6,'
        . 'body.legalpro-dark-mode .h1, body.legalpro-dark-mode .h2,'
        . 'body.legalpro-dark-mode .h3, body.legalpro-dark-mode .h4,'
        . 'body.legalpro-dark-mode .h5, body.legalpro-dark-mode .h6,'
        . 'body.legalpro-dark-mode .text-dark,'
        . 'body.legalpro-dark-mode .font-weight-bolder,'
        . 'body.legalpro-dark-mode .font-weight-bold,'
        . 'body.legalpro-dark-mode .legalpro-page-toolbar__title,'
        . 'body.legalpro-dark-mode .cc-comment-author,'
        . 'body.legalpro-dark-mode .dashboard-cal-event__text {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-muted,'
        . 'body.legalpro-dark-mode .text-secondary,'
        . 'body.legalpro-dark-mode .text-xs.text-muted,'
        . 'body.legalpro-dark-mode .text-sm.text-muted,'
        . 'body.legalpro-dark-mode .legalpro-page-toolbar__subtitle,'
        . 'body.legalpro-dark-mode .cc-comment-time,'
        . 'body.legalpro-dark-mode .footer .copyright,'
        . 'body.legalpro-dark-mode .form-control-label,'
        . 'body.legalpro-dark-mode label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-body,'
        . 'body.legalpro-dark-mode .card p,'
        . 'body.legalpro-dark-mode .card li,'
        . 'body.legalpro-dark-mode .card span:not(.badge):not(.ca-status-pill):not(.lp-pill),'
        . 'body.legalpro-dark-mode .cc-comment-text,'
        . 'body.legalpro-dark-mode .table td,'
        . 'body.legalpro-dark-mode .table tbody td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main,'
        . 'body.legalpro-dark-mode #navbarBlur,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 1px 0 var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main h6,'
        . 'body.legalpro-dark-mode .navbar-main .font-weight-bolder,'
        . 'body.legalpro-dark-mode .navbar-main .text-white,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar h6,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar .font-weight-bolder {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main .dashboard-welcome-sub,'
        . 'body.legalpro-dark-mode .legalpro-page-navbar .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .form-control,'
        . 'body.legalpro-dark-mode .form-select,'
        . 'body.legalpro-dark-mode textarea.form-control,'
        . 'body.legalpro-dark-mode input.form-control,'
        . 'body.legalpro-dark-mode .input-group-text {'
        . 'background-color: var(--lp-dark-input-bg) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .form-control::placeholder,'
        . 'body.legalpro-dark-mode textarea::placeholder {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table thead th,'
        . 'body.legalpro-dark-mode .table thead td {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table thead .text-secondary,'
        . 'body.legalpro-dark-mode .table thead .text-uppercase {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody td .font-weight-bold,'
        . 'body.legalpro-dark-mode .table tbody td .text-sm.font-weight-bold {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table > :not(caption) > * > * {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .list-group-item {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-comment-item-inner {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-comment-item--yours .cc-comment-item-inner {'
        . 'background: ' . $soft12 . ' !important;'
        . 'border-color: ' . $soft20 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dropdown-menu {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 12px 40px rgba(0, 0, 0, 0.45) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dropdown-item {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dropdown-item:hover,'
        . 'body.legalpro-dark-mode .dropdown-item:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-header,'
        . 'body.legalpro-dark-mode .modal-footer {'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-close {'
        . 'filter: invert(1) grayscale(100%) brightness(200%);'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-white,'
        . 'body.legalpro-dark-mode .btn-light {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-secondary,'
        . 'body.legalpro-dark-mode .btn-outline-secondary {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-dark,'
        . 'body.legalpro-dark-mode .btn-outline-dark {'
        . 'color: #fff !important;'
        . 'background-color: #252b3d !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-primary,'
        . 'body.legalpro-dark-mode .btn-danger,'
        . 'body.legalpro-dark-mode .btn-success,'
        . 'body.legalpro-dark-mode .btn-info,'
        . 'body.legalpro-dark-mode .btn-warning,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-primary,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-danger,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-success,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-info,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-warning,'
        . 'body.legalpro-dark-mode .btn.bg-gradient-dark {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-primary,'
        . 'body.legalpro-dark-mode .btn-outline-danger,'
        . 'body.legalpro-dark-mode .btn-outline-success,'
        . 'body.legalpro-dark-mode .btn-outline-info,'
        . 'body.legalpro-dark-mode .btn-outline-warning {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . 'body.legalpro-dark-mode #dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . 'body.legalpro-dark-mode #courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-quick-actions .btn-outline-white {'
        . 'background: transparent !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.45) . ' !important;'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode hr,'
        . 'body.legalpro-dark-mode hr.horizontal,'
        . 'body.legalpro-dark-mode .border-top,'
        . 'body.legalpro-dark-mode .border-bottom {'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-sidebar-nav .nav-link:not(.active) {'
        . 'color: rgba(255, 255, 255, 0.72) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-sidebar-nav .nav-link:hover:not(.active) {'
        . 'color: #fff !important;'
        . 'background: rgba(255, 255, 255, 0.06) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-scrollgrid,'
        . 'body.legalpro-dark-mode .fc-theme-standard td,'
        . 'body.legalpro-dark-mode .fc-theme-standard th {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-daygrid-day-number,'
        . 'body.legalpro-dark-mode .fc .fc-col-header-cell-cushion,'
        . 'body.legalpro-dark-mode .fc .fc-list-day-text,'
        . 'body.legalpro-dark-mode .fc .fc-list-day-side-text {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-day-other .fc-daygrid-day-number {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-list-event-title,'
        . 'body.legalpro-dark-mode .fc .fc-list-event-time,'
        . 'body.legalpro-dark-mode .fc .fc-list-event-graphic + td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .navbar-main .breadcrumb-item,'
        . 'body.legalpro-dark-mode .navbar-main .breadcrumb-item a,'
        . 'body.legalpro-dark-mode .navbar-main .breadcrumb-item.active {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-body,'
        . 'body.legalpro-dark-mode .modal-body p,'
        . 'body.legalpro-dark-mode .modal-body span:not(.badge):not(.lp-pill):not(.ca-status-pill) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .modal-body strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-day-today {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.1) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert {'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-success {'
        . 'background: rgba(45, 206, 137, 0.16) !important;'
        . 'color: #b8f5d8 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-danger {'
        . 'background: rgba(245, 54, 92, 0.16) !important;'
        . 'color: #ffc9d4 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-warning {'
        . 'background: rgba(251, 140, 64, 0.16) !important;'
        . 'color: #ffe0b8 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .alert-info {'
        . 'background: rgba(17, 205, 239, 0.16) !important;'
        . 'color: #b8efff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .settings-theme-mode__option {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .settings-theme-swatch__label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ps__thumb-y {'
        . 'background: #4a5568 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode a:not(.btn):not(.nav-link):not(.dropdown-item):not(.badge) {'
        . 'color: ' . $primary . ';'
        . '}';

    $css .= 'body.legalpro-dark-mode .text-primary,'
        . 'body.legalpro-dark-mode a.text-primary {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .bg-white,'
        . 'body.legalpro-dark-mode .dashboard-upcoming-panel,'
        . 'body.legalpro-dark-mode .legalpro-header-search .form-control {'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-stat-card,'
        . 'body.legalpro-dark-mode .dashboard-glance__item {'
        . 'border: none !important;'
        . 'outline: none !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 4px 18px rgba(0, 0, 0, 0.22) !important;'
        . 'overflow: hidden;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-stat-card .card-body {'
        . 'border: none !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-glance {'
        . 'background: transparent !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-glance__value {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .dashboard-glance__label {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .chat-message-bot .chat-bubble {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .timeline-content .text-dark,'
        . 'body.legalpro-dark-mode .timeline-content h6 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $primaryOnDark = portalThemeMixHex($primary, '#ffffff', 0.55);
    $dangerSoft = 'rgba(245, 54, 92, 0.16)';
    $dangerBorder = 'rgba(245, 54, 92, 0.28)';
    $infoSoft = portalThemeHexToRgba($primary, 0.14);
    $infoBorder = portalThemeHexToRgba($primary, 0.28);

    $css .= 'body.legalpro-dark-mode tr.table-danger > td,'
        . 'body.legalpro-dark-mode tr.table-danger > th {'
        . 'background-color: ' . $dangerSoft . ' !important;'
        . '--bs-table-bg: ' . $dangerSoft . ';'
        . '--bs-table-color: var(--lp-dark-text-secondary);'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: ' . $dangerBorder . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-danger h6,'
        . 'body.legalpro-dark-mode tr.table-danger .text-sm,'
        . 'body.legalpro-dark-mode tr.table-danger p,'
        . 'body.legalpro-dark-mode tr.table-danger span:not(.ca-status-pill):not(.badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-danger .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-info > td,'
        . 'body.legalpro-dark-mode tr.table-info > th {'
        . 'background-color: ' . $infoSoft . ' !important;'
        . '--bs-table-bg: ' . $infoSoft . ';'
        . '--bs-table-color: var(--lp-dark-text-secondary);'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: ' . $infoBorder . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-info h6,'
        . 'body.legalpro-dark-mode tr.table-info .text-sm,'
        . 'body.legalpro-dark-mode tr.table-info p {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode tr.table-info .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--scheduled {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.22) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--pending {'
        . 'background: rgba(251, 140, 0, 0.2) !important;'
        . 'color: #ffc978 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--declined {'
        . 'background: rgba(245, 54, 92, 0.22) !important;'
        . 'color: #ff9eb5 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-active {'
        . 'background: rgba(45, 206, 137, 0.2) !important;'
        . 'color: #8ce8c0 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .lp-pill--status-closed,'
        . 'body.legalpro-dark-mode .ca-status-pill--done {'
        . 'background: rgba(255, 255, 255, 0.08) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .ca-status-pill--muted {'
        . 'background: rgba(255, 255, 255, 0.06) !important;'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-primary {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.5) . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-primary:hover,'
        . 'body.legalpro-dark-mode .btn-outline-primary:focus {'
        . 'background: ' . portalThemeHexToRgba($primary, 0.18) . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-dark {'
        . 'color: var(--lp-dark-text) !important;'
        . 'border-color: var(--lp-dark-border-strong) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .btn-outline-dark:hover,'
        . 'body.legalpro-dark-mode .btn-outline-dark:focus {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.45) . ' !important;'
        . 'color: ' . $primaryOnDark . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-appointments-page .table thead th {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'opacity: 1 !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-appointments-page .table tbody td {'
        . 'vertical-align: middle;'
        . '}';

    $css .= 'body.legalpro-dark-mode .fc .fc-list-event:hover td {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $clientCardSurfaces = 'body.legalpro-dark-mode.legalpro-client-portal .main-content .card,'
        . 'body.legalpro-dark-mode.client-dashboard-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-cases-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-appointments-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-court-tracking-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-payments-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-portal-page .main-content .card,'
        . 'body.legalpro-dark-mode.client-profile-page .main-content .card,'
        . 'body.legalpro-dark-mode .cd-hero,'
        . 'body.legalpro-dark-mode .cc-hero,'
        . 'body.legalpro-dark-mode .ca-hero,'
        . 'body.legalpro-dark-mode .cp-hero,'
        . 'body.legalpro-dark-mode .cct-hero,'
        . 'body.legalpro-dark-mode .cd-panel,'
        . 'body.legalpro-dark-mode .cc-panel,'
        . 'body.legalpro-dark-mode .ca-panel,'
        . 'body.legalpro-dark-mode .cp-panel,'
        . 'body.legalpro-dark-mode .cct-panel,'
        . 'body.legalpro-dark-mode .cc-comments-panel,'
        . 'body.legalpro-dark-mode .cd-stat-card,'
        . 'body.legalpro-dark-mode .dashboard-calendar-hub';

    $css .= $clientCardSurfaces . ' {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.16) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-hero-stat,'
        . 'body.legalpro-dark-mode .ca-hero-pill,'
        . 'body.legalpro-dark-mode .cp-hero-pill,'
        . 'body.legalpro-dark-mode .cct-hero-pill,'
        . 'body.legalpro-dark-mode .cd-list-item {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero-title,'
        . 'body.legalpro-dark-mode .cc-hero-title,'
        . 'body.legalpro-dark-mode .ca-hero-title,'
        . 'body.legalpro-dark-mode .cp-hero-title,'
        . 'body.legalpro-dark-mode .cct-hero-title,'
        . 'body.legalpro-dark-mode .cc-hero-stat-value,'
        . 'body.legalpro-dark-mode .ca-hero-pill-value,'
        . 'body.legalpro-dark-mode .cp-hero-pill-value,'
        . 'body.legalpro-dark-mode .cct-hero-pill-value,'
        . 'body.legalpro-dark-mode .cd-panel .card-header h6,'
        . 'body.legalpro-dark-mode .cc-panel .card-header h5,'
        . 'body.legalpro-dark-mode .ca-panel .card-header h5,'
        . 'body.legalpro-dark-mode .cp-panel .card-header h5,'
        . 'body.legalpro-dark-mode .cct-panel .card-header h5 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-hero-text,'
        . 'body.legalpro-dark-mode .cc-hero-text,'
        . 'body.legalpro-dark-mode .ca-hero-text,'
        . 'body.legalpro-dark-mode .cp-hero-text,'
        . 'body.legalpro-dark-mode .cp-hero-meta,'
        . 'body.legalpro-dark-mode .cct-hero-text,'
        . 'body.legalpro-dark-mode .cc-hero-stat-label,'
        . 'body.legalpro-dark-mode .ca-hero-pill-label,'
        . 'body.legalpro-dark-mode .cp-hero-pill-label,'
        . 'body.legalpro-dark-mode .cct-hero-pill-label,'
        . 'body.legalpro-dark-mode .cd-panel .cd-panel-sub {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cd-list-item:hover {'
        . 'background: ' . $soft12 . ' !important;'
        . 'border-color: ' . $soft20 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-panel .table thead th,'
        . 'body.legalpro-dark-mode .ca-panel .table thead th,'
        . 'body.legalpro-dark-mode .cp-panel .table thead th,'
        . 'body.legalpro-dark-mode .cct-panel .table thead th {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-panel .card-header,'
        . 'body.legalpro-dark-mode .ca-panel .card-header,'
        . 'body.legalpro-dark-mode .cp-panel .card-header,'
        . 'body.legalpro-dark-mode .cct-panel .card-header,'
        . 'body.legalpro-dark-mode .cd-panel .card-header,'
        . 'body.legalpro-dark-mode .cc-comments-panel .card-header {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.legalpro-client-portal .navbar-main,'
        . 'body.legalpro-dark-mode.client-dashboard-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-cases-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-appointments-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-court-tracking-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-payments-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-portal-page .navbar-main,'
        . 'body.legalpro-dark-mode.client-profile-page .navbar-main {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'box-shadow: 0 1px 0 var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__toggle {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border: 1px solid var(--lp-dark-border) !important;'
        . 'box-shadow: none !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__toggle:hover,'
        . 'body.legalpro-dark-mode .legalpro-header-user__toggle:focus,'
        . 'body.legalpro-dark-mode .legalpro-header-user__toggle.show {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__name {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__role {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__caret {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .legalpro-header-user__menu .dropdown-item:hover,'
        . 'body.legalpro-dark-mode .legalpro-header-user__menu .dropdown-item:focus {'
        . 'background-color: var(--lp-dark-surface-hover) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .cc-pill {'
        . 'background: ' . $soft12 . ' !important;'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-hub__head {'
        . 'background: linear-gradient(135deg, ' . $soft12 . ' 0%, var(--lp-dark-surface-raised) 100%) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-hub__title,'
        . 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-title__main {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-hub__count,'
        . 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-title__sub,'
        . 'body.legalpro-dark-mode.admin-cases-page .legalpro-case-client {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-table tbody td {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-cases-page .legalpro-cases-table tbody tr:hover {'
        . 'background: ' . $soft12 . ' !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .card h6,'
        . 'body.legalpro-dark-mode.admin-court-tracking-page .dashboard-calendar-hub__title {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .table tbody td,'
        . 'body.legalpro-dark-mode.admin-court-tracking-page .table tbody td span:not(.lp-pill):not(.badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .court-actions .btn {'
        . 'color: #fff !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.admin-court-tracking-page .court-actions .btn-secondary {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $lawyerCardSurfaces = 'body.legalpro-dark-mode.legalpro-lawyer-portal .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-dashboard-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-cases-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-clients-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-appointments-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-availability-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-case-view-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-client-view-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-court-tracking-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page .main-content .card,'
        . 'body.legalpro-dark-mode.lawyer-profile-page .main-content .card';

    $css .= $lawyerCardSurfaces . ' {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'box-shadow: 0 4px 20px rgba(15, 20, 35, 0.16) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed,'
        . 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed .card-body {'
        . 'background: var(--lp-task-card-body-bg) !important;'
        . 'background-color: var(--lp-task-card-bg) !important;'
        . 'border-color: var(--lp-task-card-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed h6 {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed .text-sm:not(.badge) {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode.lawyer-tasks-page .task-card-themed .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .card-header .nav-tabs {'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs .nav-link {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'border-color: transparent !important;'
        . 'background: transparent !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs .nav-link:hover,'
        . 'body.legalpro-dark-mode .nav-tabs .nav-link:focus {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . 'border-color: var(--lp-dark-border) var(--lp-dark-border) transparent !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .nav-tabs .nav-link.active,'
        . 'body.legalpro-dark-mode .nav-tabs .nav-item.show .nav-link {'
        . 'color: var(--lp-dark-text) !important;'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) var(--lp-dark-border) var(--lp-dark-surface-raised) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table-striped > tbody > tr:nth-of-type(odd) > * {'
        . 'background-color: var(--lp-dark-surface-raised) !important;'
        . '--bs-table-accent-bg: var(--lp-dark-surface-raised) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table-striped > tbody > tr:nth-of-type(even) > * {'
        . 'background-color: var(--lp-dark-surface) !important;'
        . '--bs-table-accent-bg: var(--lp-dark-surface) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody tr.table-active > td,'
        . 'body.legalpro-dark-mode .table tbody tr.table-active > th {'
        . 'background-color: ' . $soft12 . ' !important;'
        . '--bs-table-accent-bg: ' . $soft12 . ' !important;'
        . 'color: var(--lp-dark-text) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .table tbody tr.table-active strong {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .tab-content .table thead th {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . 'font-weight: 600;'
        . '}';

    $css .= 'body.legalpro-dark-mode .tab-content .table tbody td,'
        . 'body.legalpro-dark-mode .tab-content .table tbody td strong {'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .tab-content .text-center.text-muted {'
        . 'color: var(--lp-dark-text-subtle) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-hero {'
        . 'background: linear-gradient(140deg, ' . $soft12 . ' 0%, var(--lp-dark-surface-raised) 100%) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text-secondary) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-hero h6,'
        . 'body.legalpro-dark-mode .availability-hero .font-weight-bolder {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-hero .text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-calendar {'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'background: var(--lp-dark-surface) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-toolbar {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-bottom-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-header > div {'
        . 'background: var(--lp-dark-surface-raised) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day-name {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day-date {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-week-label {'
        . 'color: var(--lp-dark-text) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day {'
        . 'background: var(--lp-dark-surface) !important;'
        . 'border-color: var(--lp-dark-border) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day:hover {'
        . 'background: var(--lp-dark-surface-hover) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-fallback-day .text-muted,'
        . 'body.legalpro-dark-mode .availability-fallback-day .text-sm.text-muted {'
        . 'color: var(--lp-dark-text-muted) !important;'
        . '}';

    $css .= 'body.legalpro-dark-mode .availability-week-nav .btn-outline-primary {'
        . 'color: ' . $primaryOnDark . ' !important;'
        . 'border-color: ' . portalThemeHexToRgba($primary, 0.5) . ' !important;'
        . '}';

    return $css;
}

function portalThemePrimaryRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return '94, 114, 228';
    }

    return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
}

function renderPortalThemeCss(): string
{
    $theme = getPortalTheme();
    $preset = $theme['preset'];
    $primary = $preset['primary'];
    $primaryDark = $preset['primary_dark'];
    $sidebarBg = $preset['sidebar_bg'];
    $sidebarDeep = $preset['sidebar_deep'];
    $rgb = portalThemePrimaryRgb($primary);
    $soft14 = portalThemeHexToRgba($primary, 0.14);
    $soft12 = portalThemeHexToRgba($primary, 0.12);
    $soft08 = portalThemeHexToRgba($primary, 0.08);
    $soft35 = portalThemeHexToRgba($primary, 0.35);
    $gradient = 'linear-gradient(135deg, ' . $primary . ' 0%, ' . $primaryDark . ' 100%)';
    $gradient310 = 'linear-gradient(310deg, ' . $primary . ' 0%, ' . $primaryDark . ' 100%)';
    $lawyerGradient = 'linear-gradient(140deg, ' . $sidebarDeep . ' 0%, ' . $primary . ' 44%, ' . $primaryDark . ' 100%)';
    $sidebarGradient = 'linear-gradient(180deg, ' . $sidebarBg . ' 0%, ' . $sidebarDeep . ' 100%)';

    $css = ':root {'
        . '--bs-primary: ' . $primary . ';'
        . '--bs-primary-rgb: ' . $rgb . ';'
        . '--bs-link-color: ' . $primary . ';'
        . '--bs-link-color-rgb: ' . $rgb . ';'
        . '--bs-focus-ring-color: rgba(' . $rgb . ', 0.25);'
        . '--lp-admin-primary: ' . $primary . ';'
        . '--lp-admin-primary-dark: ' . $primaryDark . ';'
        . '--lp-admin-gradient: ' . $gradient . ';'
        . '--lp-admin-sidebar-bg: ' . $sidebarBg . ';'
        . '--lp-admin-sidebar-bg-deep: ' . $sidebarDeep . ';'
        . '--lp-portal-primary: ' . $primary . ';'
        . '--lp-portal-primary-dark: ' . $primaryDark . ';'
        . '--lp-portal-sidebar-bg: ' . $sidebarBg . ';'
        . '--lp-portal-sidebar-bg-deep: ' . $sidebarDeep . ';'
        . '--lp-portal-gradient: ' . $gradient . ';'
        . '--client-portal-gradient: ' . $gradient . ';'
        . '--lawyer-portal-gradient: ' . $lawyerGradient . ';'
        . '--lawyer-portal-bg-fallback: ' . $sidebarDeep . ';'
        . '--lp-cases-accent-soft: ' . $soft12 . ';'
        . '--lp-cases-accent-border: ' . $soft35 . ';'
        . '--legalpro-theme-primary: ' . $primary . ';'
        . '--legalpro-theme-primary-dark: ' . $primaryDark . ';'
        . '--legalpro-theme-primary-rgb: ' . $rgb . ';'
        . '--legalpro-theme-gradient: ' . $gradient . ';'
        . '--legalpro-theme-gradient-310: ' . $gradient310 . ';'
        . '}';

    $primarySelectors = '.bg-gradient-primary,'
        . '.btn.bg-gradient-primary,'
        . '.badge.bg-gradient-primary,'
        . '.lp-card-header-primary,'
        . '.modal-header.bg-gradient-primary,'
        . '.icon-shape.bg-gradient-primary';

    $css .= $primarySelectors . ' {'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.btn-primary,'
        . '.btn.btn-primary {'
        . '--bs-btn-bg: ' . $primary . ';'
        . '--bs-btn-border-color: ' . $primary . ';'
        . '--bs-btn-hover-bg: ' . $primaryDark . ';'
        . '--bs-btn-hover-border-color: ' . $primaryDark . ';'
        . '--bs-btn-active-bg: ' . $primaryDark . ';'
        . '--bs-btn-active-border-color: ' . $primaryDark . ';'
        . '--bs-btn-disabled-bg: ' . $primary . ';'
        . '--bs-btn-disabled-border-color: ' . $primary . ';'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.btn-outline-primary {'
        . '--bs-btn-color: ' . $primary . ';'
        . '--bs-btn-border-color: ' . $primary . ';'
        . '--bs-btn-hover-bg: ' . $primary . ';'
        . '--bs-btn-hover-border-color: ' . $primary . ';'
        . '--bs-btn-active-bg: ' . $primary . ';'
        . '--bs-btn-active-border-color: ' . $primary . ';'
        . 'color: ' . $primary . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.text-primary,'
        . 'a.text-primary,'
        . '.text-xs.text-primary,'
        . 'h6.text-primary,'
        . 'p.text-primary {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= '.border-primary { border-color: ' . $primary . ' !important; }';

    $css .= '#sidenav-main.legalpro-admin-sidebar,'
        . '.legalpro-admin-sidebar {'
        . 'background: ' . $sidebarGradient . ' !important;'
        . '}';

    $css .= '#sidenav-main.legalpro-admin-sidebar .legalpro-sidebar-nav .nav-link.active,'
        . '.legalpro-admin-sidebar .legalpro-sidebar-nav .nav-link.active,'
        . '#sidenav-main .legalpro-sidebar-nav .nav-link.active {'
        . 'background: ' . $gradient . ' !important;'
        . 'color: #fff !important;'
        . 'box-shadow: 0 8px 18px ' . portalThemeHexToRgba($primary, 0.35) . ' !important;'
        . '}';

    $css .= '.dashboard-stat-icon-wrap--primary {'
        . 'background: ' . $soft12 . ' !important;'
        . '}';

    $css .= '.dashboard-stat-icon-wrap--primary .lp-icon svg,'
        . '.lp-icon--primary svg {'
        . 'stroke: ' . $primary . ' !important;'
        . '}';

    $css .= '.ca-status-pill--scheduled,'
        . '.lp-pill--status-progress {'
        . 'background: ' . $soft14 . ' !important;'
        . 'color: ' . $primary . ' !important;'
        . '}';

    $css .= '.form-control:focus,'
        . '.form-select:focus,'
        . 'textarea.form-control:focus {'
        . 'border-color: ' . $primary . ' !important;'
        . 'box-shadow: 0 0 0 0.2rem rgba(' . $rgb . ', 0.15) !important;'
        . '}';

    $css .= '.page-item.active .page-link,'
        . '.pagination .page-item.active .page-link {'
        . 'background-color: ' . $primary . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.progress-bar,'
        . '.progress .progress-bar {'
        . 'background-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.settings-theme-mode__option:has(input:checked),'
        . '.settings-theme-swatch.active .settings-theme-swatch__dot,'
        . '.settings-theme-swatch:has(input:checked) .settings-theme-swatch__dot {'
        . 'border-color: ' . $primary . ' !important;'
        . '}';

    $css .= '.settings-theme-mode__option:has(input:checked) {'
        . 'background: ' . $soft08 . ' !important;'
        . '}';

    $css .= '.settings-theme-swatch.active .settings-theme-swatch__dot,'
        . '.settings-theme-swatch:has(input:checked) .settings-theme-swatch__dot {'
        . 'box-shadow: 0 0 0 3px ' . portalThemeHexToRgba($primary, 0.25) . ' !important;'
        . '}';

    $css .= '.cc-case-row:hover td,'
        . '.cct-row:hover td,'
        . '.cp-row:hover td {'
        . 'background-color: ' . $soft08 . ' !important;'
        . '}';

    $css .= '.chat-message-user .chat-bubble {'
        . 'background: ' . $primary . ' !important;'
        . '}';

    $fcToolbar = '.fc .fc-toolbar.fc-header-toolbar,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar';

    $fcToolbarBtn = '.fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button';

    $fcToolbarBtnHover = '.fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button:active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:hover,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:focus,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button:active';

    $fcToolbarBtnActive = '.fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled).fc-button-active,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled):active,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled).fc-button-active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled):active,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled).fc-button-active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button-primary:not(:disabled):active,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-button.fc-button-active';

    $css .= $fcToolbar . ' {'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . '}';

    $css .= '.fc .fc-toolbar-title,'
        . '#dashboardCalendar .fc .fc-toolbar-title,'
        . '#courtTrackingCalendar .fc .fc-toolbar-title {'
        . 'color: #fff !important;'
        . '}';

    $css .= $fcToolbarBtn . ' {'
        . 'background-color: ' . $primary . ' !important;'
        . 'background-image: ' . $gradient310 . ' !important;'
        . 'border-color: ' . $primary . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $fcToolbarBtnHover . ' {'
        . 'background-color: ' . $primaryDark . ' !important;'
        . 'background-image: none !important;'
        . 'border-color: ' . $primaryDark . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= $fcToolbarBtnActive . ' {'
        . 'background-color: ' . $primaryDark . ' !important;'
        . 'background-image: none !important;'
        . 'border-color: #fff !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= '.fc .fc-toolbar.fc-header-toolbar .fc-icon,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-icon-chevron-left,'
        . '.fc .fc-toolbar.fc-header-toolbar .fc-icon-chevron-right,'
        . '#dashboardCalendar .fc .fc-toolbar.fc-header-toolbar .fc-icon,'
        . '#courtTrackingCalendar .fc .fc-toolbar.fc-header-toolbar .fc-icon {'
        . 'color: #fff !important;'
        . '}';

    $css .= '.simple-calendar .calendar-header {'
        . 'background: ' . $gradient . ' !important;'
        . 'color: #fff !important;'
        . '}';

    $css .= '#dashboardCalendar .fc-daygrid-more-link,'
        . '#courtTrackingCalendar .fc-daygrid-more-link {'
        . 'color: ' . $primary . ' !important;'
        . '}';

    if ($theme['mode'] === 'dark') {
        $css .= renderPortalThemeDarkCss($primary, $rgb);
    }

    return $css;
}

function portalThemeHexToRgba(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return 'rgba(94, 114, 228, ' . $alpha . ')';
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return 'rgba(' . $r . ', ' . $g . ', ' . $b . ', ' . $alpha . ')';
}

function renderPortalThemeHead(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    $theme = getPortalTheme();
    $css = renderPortalThemeCss();
    $isDark = $theme['mode'] === 'dark';

    echo '<style id="legalpro-portal-theme">' . $css . '</style>';
    if ($isDark) {
        echo '<script>(function(){var d=document;d.documentElement.classList.add("legalpro-theme-dark");var apply=function(){if(d.body)d.body.classList.add("legalpro-dark-mode");};if(d.body)apply();else d.addEventListener("DOMContentLoaded",apply);})();</script>';
    }
}

function renderPortalThemeSettingsHtml(): string
{
    $theme = getPortalTheme();
    $presets = getPortalThemeColorPresets();
    $currentMode = $theme['mode'];
    $currentColor = $theme['color'];
    $customPrimary = $theme['custom_primary'];

    $lightChecked = $currentMode === 'light' ? ' checked' : '';
    $darkChecked = $currentMode === 'dark' ? ' checked' : '';

    $swatches = '';
    foreach ($presets as $key => $preset) {
        $active = $currentColor === $key ? ' active' : '';
        $swatchGradient = 'linear-gradient(135deg, ' . $preset['primary'] . ' 0%, ' . $preset['primary_dark'] . ' 100%)';
        $swatches .= '<label class="settings-theme-swatch' . $active . '" title="' . htmlspecialchars($preset['label']) . '">'
            . '<input type="radio" name="theme_color" value="' . htmlspecialchars($key) . '"' . ($currentColor === $key ? ' checked' : '') . '>'
            . '<span class="settings-theme-swatch__dot" style="background: ' . htmlspecialchars($swatchGradient) . ';"></span>'
            . '<span class="settings-theme-swatch__label">' . htmlspecialchars($preset['label']) . '</span>'
            . '</label>';
    }

    $customPreset = portalThemeBuildCustomPreset($customPrimary);
    $customGradient = 'linear-gradient(135deg, ' . $customPreset['primary'] . ' 0%, ' . $customPreset['primary_dark'] . ' 100%)';
    $customActive = $currentColor === 'custom' ? ' active' : '';
    $customChecked = $currentColor === 'custom' ? ' checked' : '';
    $customPickerStyle = $currentColor === 'custom' ? '' : ' style="display:none;"';

    $swatches .= '<label class="settings-theme-swatch settings-theme-swatch--custom' . $customActive . '" title="Custom">'
        . '<input type="radio" name="theme_color" value="custom"' . $customChecked . '>'
        . '<span class="settings-theme-swatch__dot settings-theme-swatch__dot--custom" style="background: ' . htmlspecialchars($customGradient) . ';"></span>'
        . '<span class="settings-theme-swatch__label">Custom</span>'
        . '</label>';

    return '<div class="card mb-4">'
        . '<div class="card-header pb-0"><h6>Appearance</h6></div>'
        . '<div class="card-body">'
        . '<p class="text-sm text-muted mb-4">Choose the default theme and accent color for the admin, lawyer, and client portals.</p>'
        . '<form method="post" class="settings-theme-form">'
        . '<input type="hidden" name="form_type" value="portal_theme">'
        . '<div class="mb-4">'
        . '<label class="form-control-label d-block mb-2">Theme mode</label>'
        . '<div class="settings-theme-mode">'
        . '<label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="light"' . $lightChecked . '> Light</label>'
        . '<label class="settings-theme-mode__option"><input type="radio" name="theme_mode" value="dark"' . $darkChecked . '> Dark</label>'
        . '</div>'
        . '</div>'
        . '<div class="mb-4">'
        . '<label class="form-control-label d-block mb-2">Accent color</label>'
        . '<div class="settings-theme-swatches">' . $swatches . '</div>'
        . '</div>'
        . '<div class="settings-theme-custom-picker mb-4"' . $customPickerStyle . '>'
        . '<label class="form-control-label d-block mb-2">Custom color</label>'
        . '<div class="d-flex align-items-center gap-3 flex-wrap">'
        . '<input type="color" class="form-control form-control-color settings-theme-color-input" name="custom_primary" value="' . htmlspecialchars($customPrimary) . '" title="Pick a custom accent color">'
        . '<span class="text-sm text-muted">Pick any color for buttons, links, and sidebar highlights.</span>'
        . '</div>'
        . '</div>'
        . '<button type="submit" class="btn btn-dark">Save Appearance</button>'
        . '</form>'
        . '<script>(function(){var form=document.querySelector(".settings-theme-form");if(!form)return;var customInput=form.querySelector(\'input[name="theme_color"][value="custom"]\');var pickerWrap=form.querySelector(".settings-theme-custom-picker");var picker=form.querySelector(\'input[name="custom_primary"]\');var customDot=form.querySelector(".settings-theme-swatch--custom .settings-theme-swatch__dot");var sync=function(){if(pickerWrap)pickerWrap.style.display=customInput&&customInput.checked?"block":"none";};form.querySelectorAll(\'input[name="theme_color"]\').forEach(function(radio){radio.addEventListener("change",sync);});if(picker){picker.addEventListener("input",function(){if(customDot)customDot.style.background=picker.value;});}sync();})();</script>'
        . '</div>'
        . '</div>';
}
