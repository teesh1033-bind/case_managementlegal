<?php
/**
 * Dynamic portal theme overrides — include in portal <head> sections.
 */
if (defined('LEGALPRO_PORTAL_THEME_HEAD')) {
    return;
}
define('LEGALPRO_PORTAL_THEME_HEAD', true);

if (!function_exists('renderPortalThemeHead')) {
    require_once __DIR__ . '/../lib/portal-theme.php';
}

if (function_exists('renderPortalThemeHeadEarly')) {
    renderPortalThemeHeadEarly();
}

renderPortalThemeHead();
if (function_exists('renderCurrencyHeadScript')) {
    renderCurrencyHeadScript();
}
echo '<link href="../assets/css/calendar-toolbar-visible.css?v=1" rel="stylesheet" />' . "\n";
