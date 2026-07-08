<?php
/**
 * Shared calendar studio CSS + table pagination JS (all portals).
 */
if (defined('LEGALPRO_PORTAL_CALENDAR_ASSETS')) {
    return;
}
define('LEGALPRO_PORTAL_CALENDAR_ASSETS', true);

echo '<link href="../assets/css/legalpro-appointments-calendar.css?v=31" rel="stylesheet" />' . "\n";
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" />' . "\n";
echo '<script src="../assets/js/legalpro-calendar-studio.js?v=5" defer></script>' . "\n";

if (!defined('LEGALPRO_ADMIN_PORTAL_HEAD')) {
    echo '<script src="../assets/js/legalpro-admin-table-pagination.js?v=4" defer></script>' . "\n";
}
