<?php
/**
 * Client court tracking — calendar styles bundle.
 *
 * Load order matters: FullCalendar CDN first, then dashboard-enhancements.css
 * (overrides FC defaults for #courtTrackingCalendar), then theme dark overrides.
 *
 * Usage in client-court-tracking.php (after client-portal-head.php):
 *   define('LEGALPRO_SKIP_DASHBOARD_ENHANCEMENTS', true);
 *   include __DIR__ . '/../inc/client-portal-head.php';
 *   include __DIR__ . '/../inc/client-court-tracking-calendar-css.php';
 */
if (defined('LEGALPRO_CLIENT_COURT_TRACKING_CALENDAR_CSS')) {
    return;
}
define('LEGALPRO_CLIENT_COURT_TRACKING_CALENDAR_CSS', true);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" />
<link href="../assets/css/dashboard-enhancements.css?v=12" rel="stylesheet" />
<link href="../assets/css/calendar-toolbar-visible.css?v=1" rel="stylesheet" />
<?php include __DIR__ . '/portal-theme-calendar-dark.php'; ?>
