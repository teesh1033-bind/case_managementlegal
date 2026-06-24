<?php
// inc/menunav.php — Admin sidebar + header utilities

require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/../lib/portal-sidebar.php';

if (defined('LEGALPRO_ADMIN_MENUNAV_LOADED')) {
    return;
}
define('LEGALPRO_ADMIN_MENUNAV_LOADED', true);

$companyBranding = getCompanyBranding();
$companyName = $companyBranding['name'];
$companyLogoUrl = $companyBranding['logo_url'];

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPage = str_replace('.php', '', $currentPage);

$menuItems = [
    ['title' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'layout-dashboard', 'id' => 'dashboard'],
    ['title' => 'Clients', 'url' => 'clients.php', 'icon' => 'users', 'id' => 'clients'],
    ['title' => 'Cases', 'url' => 'tables.php', 'icon' => 'briefcase', 'id' => 'tables'],
    ['title' => 'Payments', 'url' => 'payments.php', 'icon' => 'credit-card', 'id' => 'payments'],
    ['title' => 'Appointments', 'url' => 'appointments.php', 'icon' => 'calendar', 'id' => 'appointments'],
    ['title' => 'Court Tracking', 'url' => 'court-tracking.php', 'icon' => 'landmark', 'id' => 'court-tracking'],
    ['title' => 'Lawyers', 'url' => 'lawyers.php', 'icon' => 'user-round', 'id' => 'lawyers'],
    ['title' => 'Invoices', 'url' => 'invoices.php', 'icon' => 'file-text', 'id' => 'invoices'],
    ['title' => 'Finance', 'url' => 'financial-summary.php', 'icon' => 'pie-chart', 'id' => 'financial-summary'],
    ['title' => 'Documents', 'url' => 'documents.php', 'icon' => 'folder-open', 'id' => 'documents'],
    ['title' => 'AI Assistant', 'url' => 'chatbot.php', 'icon' => 'bot', 'id' => 'chatbot'],
];

if (!function_exists('legalpro_admin_menu_is_active')) {
    function legalpro_admin_menu_is_active($itemId, $currentPage)
    {
        if ($itemId === 'tables' && in_array($currentPage, ['tables', 'case-detail', 'case-view', 'case-edit', 'case-new'], true)) {
            return true;
        }
        if ($itemId === 'clients' && in_array($currentPage, ['clients', 'client-detail'], true)) {
            return true;
        }
        if ($itemId === 'appointments' && in_array($currentPage, ['appointments', 'new_appointment'], true)) {
            return true;
        }
        if ($itemId === 'court-tracking' && $currentPage === 'court-tracking') {
            return true;
        }
        if ($itemId === 'documents' && in_array($currentPage, ['documents', 'document-upload', 'document-templates', 'document-generate', 'document-browse'], true)) {
            return true;
        }

        return $itemId === $currentPage;
    }
}

global $pdo;
$navbarUtilitiesMount = legalpro_navbar_utilities_mount(
    legalpro_render_admin_header_utilities(isset($pdo) ? $pdo : null)
);
?>

<?php if (!defined('LEGALPRO_ADMIN_PORTAL_HEAD')): ?>
<?php include __DIR__ . '/portal-theme-head-early.php'; ?>
<?php legalpro_icons_head_scripts(); ?>
<link href="../assets/css/legalpro-portal-shell.css?v=21" rel="stylesheet" />
<link href="../assets/css/legalpro-admin-portal.css?v=34" rel="stylesheet" />
<link href="../assets/css/dashboard-enhancements.css?v=16" rel="stylesheet" />
<?php echo legalpro_sidebar_stylesheet_tag(); ?>
<?php legalpro_icons_asset_links(); ?>
<?php include __DIR__ . '/portal-theme-head.php'; ?>
<?php endif; ?>

<?php
echo legalpro_render_portal_sidebar([
    'portal' => 'admin',
    'home_url' => 'dashboard.php',
    'role_label' => 'ADMIN',
    'company_name' => $companyName,
    'logo_url' => $companyLogoUrl,
    'current_page' => $currentPage,
    'items' => $menuItems,
    'is_active' => 'legalpro_admin_menu_is_active',
    'compact' => true,
]);
?>

<?php echo $navbarUtilitiesMount; ?>

<script src="../assets/js/admin-portal.js?v=2"></script>
<script src="../assets/js/legalpro-admin-table-pagination.js?v=1" defer></script>
<?php legalpro_icons_footer_scripts(); ?>
