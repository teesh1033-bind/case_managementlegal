<?php
/**
 * Client portal styles — include in <head> after argon-dashboard / app-font-montserrat.
 */
if (defined('LEGALPRO_CLIENT_PORTAL_HEAD')) {
    return;
}
define('LEGALPRO_CLIENT_PORTAL_HEAD', true);
require_once __DIR__ . '/legalpro-icons.php';
?>
<link href="../assets/css/app-font-montserrat.css?v=7" rel="stylesheet" />
<link href="../assets/css/legalpro-portal-shell.css?v=14" rel="stylesheet" />
<<<<<<< HEAD
<link href="../assets/css/legalpro-client-portal.css?v=22" rel="stylesheet" />
=======
<link href="../assets/css/legalpro-client-portal.css?v=21" rel="stylesheet" />
<?php if (!defined('LEGALPRO_SKIP_DASHBOARD_ENHANCEMENTS')): ?>
>>>>>>> 453e87407cfefd4a976b79775749e700727f26d6
<link href="../assets/css/dashboard-enhancements.css?v=10" rel="stylesheet" />
<?php endif; ?>
<link href="../assets/css/legalpro-admin-portal.css?v=14" rel="stylesheet" />
<link href="../assets/css/legalpro-sidebar-nav.css?v=1" rel="stylesheet" />
<?php legalpro_icons_asset_links(); ?>
<?php include __DIR__ . '/portal-theme-head.php'; ?>
