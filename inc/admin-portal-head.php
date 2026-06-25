<?php
/**
 * Admin portal styles — include in <head> after argon-dashboard / app-font-montserrat.
 */
if (defined('LEGALPRO_ADMIN_PORTAL_HEAD')) {
    return;
}
define('LEGALPRO_ADMIN_PORTAL_HEAD', true);
require_once __DIR__ . '/legalpro-icons.php';
?>
<?php include __DIR__ . '/portal-theme-head-early.php'; ?>
<?php legalpro_icons_head_scripts(); ?>
<link href="../assets/css/legalpro-portal-shell.css?v=21" rel="stylesheet" />
<link href="../assets/css/legalpro-admin-portal.css?v=35" rel="stylesheet" />
<link href="../assets/css/dashboard-enhancements.css?v=16" rel="stylesheet" />
<link href="../assets/css/legalpro-sidebar-nav.css?v=23" rel="stylesheet" />
<?php legalpro_icons_asset_links(); ?>
<?php include __DIR__ . '/portal-theme-head.php'; ?>
<script src="../assets/js/legalpro-admin-table-pagination.js?v=1" defer></script>
