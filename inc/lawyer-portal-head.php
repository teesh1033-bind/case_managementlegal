<?php
/**
 * Lawyer portal styles — include in <head> after argon-dashboard / app-font-montserrat.
 */
if (defined('LEGALPRO_LAWYER_PORTAL_HEAD')) {
    return;
}
define('LEGALPRO_LAWYER_PORTAL_HEAD', true);
require_once __DIR__ . '/legalpro-icons.php';
?>
<link href="../assets/css/legalpro-portal-shell.css?v=12" rel="stylesheet" />
<link href="../assets/css/legalpro-lawyer-portal.css?v=11" rel="stylesheet" />
<link href="../assets/css/legalpro-admin-portal.css?v=19" rel="stylesheet" />
<link href="../assets/css/dashboard-enhancements.css?v=6" rel="stylesheet" />
<link href="../assets/css/legalpro-sidebar-nav.css?v=1" rel="stylesheet" />
<?php legalpro_icons_asset_links(); ?>
<?php include __DIR__ . '/portal-theme-head.php'; ?>
<?php include __DIR__ . '/lawyer-portal-badges-css.php'; ?>
