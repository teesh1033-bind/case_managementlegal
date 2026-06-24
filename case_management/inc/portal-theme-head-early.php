<?php
/**
 * Early dark-mode bootstrap — include at the start of portal <head> (before CSS links).
 */
if (defined('LEGALPRO_PORTAL_THEME_HEAD_EARLY')) {
    return;
}
define('LEGALPRO_PORTAL_THEME_HEAD_EARLY', true);

if (!function_exists('renderPortalThemeHeadEarly')) {
    require_once __DIR__ . '/../lib/portal-theme.php';
}

renderPortalThemeHeadEarly();
