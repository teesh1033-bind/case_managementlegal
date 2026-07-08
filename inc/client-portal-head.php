<?php
/**
 * Client portal styles — include in <head> after argon-dashboard / app-font-montserrat.
 */
if (defined('LEGALPRO_CLIENT_PORTAL_HEAD')) {
    return;
}
define('LEGALPRO_CLIENT_PORTAL_HEAD', true);
require_once __DIR__ . '/legalpro-icons.php';
require_once __DIR__ . '/../lib/client-portal-js-i18n.php';
?>

<?php include __DIR__ . '/portal-theme-head-early.php'; ?>
<?php legalpro_icons_head_scripts(); ?>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=7" rel="stylesheet" />

<link href="../assets/css/legalpro-portal-shell.css?v=26" rel="stylesheet" />

<link href="../assets/css/legalpro-client-portal.css?v=34" rel="stylesheet" />

<?php if (!defined('LEGALPRO_SKIP_DASHBOARD_ENHANCEMENTS')): ?>
<link href="../assets/css/dashboard-enhancements.css?v=17" rel="stylesheet" />
<?php endif; ?>

<link href="../assets/css/legalpro-admin-portal.css?v=40" rel="stylesheet" />

<link href="../assets/css/legalpro-sidebar-nav.css?v=24" rel="stylesheet" />

<?php legalpro_icons_asset_links(); ?>
<?php include __DIR__ . '/portal-theme-head.php'; ?>
<?php include __DIR__ . '/portal-calendar-assets.php'; ?>
<?php echo client_portal_render_i18n_script(); ?>
