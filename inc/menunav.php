<?php
// inc/menunav.php — Admin sidebar + header utilities

require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/../lib/portal-sidebar.php';
require_once __DIR__ . '/../lib/admin-locale.php';
require_once __DIR__ . '/../lib/admin-role-access.php';

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
    ['title_key' => 'nav.dashboard', 'fallback' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'layout-dashboard', 'id' => 'dashboard'],
    ['title_key' => 'nav.clients', 'fallback' => 'Clients', 'url' => 'clients.php', 'icon' => 'users', 'id' => 'clients'],
    ['title_key' => 'nav.cases', 'fallback' => 'Cases', 'url' => 'tables.php', 'icon' => 'briefcase', 'id' => 'tables'],
    ['title_key' => 'nav.payments', 'fallback' => 'Payments', 'url' => 'payments.php', 'icon' => 'credit-card', 'id' => 'payments'],
    ['title_key' => 'nav.appointments', 'fallback' => 'Appointments', 'url' => 'appointments.php', 'icon' => 'calendar', 'id' => 'appointments'],
    ['title_key' => 'nav.court_tracking', 'fallback' => 'Court Tracking', 'url' => 'court-tracking.php', 'icon' => 'landmark', 'id' => 'court-tracking'],
    ['title_key' => 'nav.lawyers', 'fallback' => 'Lawyers', 'url' => 'lawyers.php', 'icon' => 'user-round', 'id' => 'lawyers'],
    ['title_key' => 'nav.finance', 'fallback' => 'Finance', 'url' => 'financial-summary.php', 'icon' => 'pie-chart', 'id' => 'financial-summary'],
    ['title_key' => 'nav.documents', 'fallback' => 'Documents', 'url' => 'documents.php', 'icon' => 'folder-open', 'id' => 'documents'],
    ['title_key' => 'nav.ai_assistant', 'fallback' => 'AI Assistant', 'url' => 'chatbot.php', 'icon' => 'bot', 'id' => 'chatbot'],
    ['title_key' => 'nav.settings', 'fallback' => 'Settings', 'url' => 'settings.php', 'icon' => 'settings', 'id' => 'settings'],
];

$menuItems = legalpro_filter_admin_menu_items($menuItems);

if (!empty($_SESSION['admin_id'])) {
    legalpro_admin_guard_current_page($currentPage);
}

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
        if ($itemId === 'financial-summary' && in_array($currentPage, ['financial-summary', 'invoices'], true)) {
            return true;
        }
        if ($itemId === 'settings' && strpos($currentPage, 'settings') === 0) {
            return true;
        }
        if ($itemId === 'payments' && strpos($currentPage, 'payments') === 0) {
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
<link href="../assets/css/legalpro-portal-shell.css?v=26" rel="stylesheet" />
<link href="../assets/css/legalpro-admin-portal.css?v=39" rel="stylesheet" />
<link href="../assets/css/dashboard-enhancements.css?v=18" rel="stylesheet" />
<?php echo legalpro_sidebar_stylesheet_tag(); ?>
<?php legalpro_icons_asset_links(); ?>
<?php include __DIR__ . '/portal-theme-head.php'; ?>
<?php endif; ?>

<?php
echo legalpro_render_portal_sidebar([
    'portal' => 'admin',
    'home_url' => 'dashboard.php',
    'role_label' => admin_t('sidebar.role_admin'),
    'company_name' => $companyName,
    'logo_url' => $companyLogoUrl,
    'current_page' => $currentPage,
    'items' => $menuItems,
    'footer_items' => [],
    'logout_url' => 'admin-logout.php',
    'is_active' => 'legalpro_admin_menu_is_active',
    'compact' => true,
]);
?>

<!-- LEGALPRO_I18N_SKIP -->
<?php echo $navbarUtilitiesMount; ?>

<script>window.LEGALPRO_ADMIN_I18N=<?= json_encode([
    'unreadHint' => admin_t('notifications.unread_hint'),
    'loading' => admin_t('notifications.loading'),
    'emptyTitle' => admin_t('notifications.empty_title'),
    'loadError' => admin_t('notifications.load_error'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script>window.LEGALPRO_DASHBOARD_ACTIVITY=<?= json_encode([
    'showingRange' => admin_t('activity.showing_range'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script src="../assets/js/admin-portal.js?v=7"></script>
<?php if (!defined('LEGALPRO_ADMIN_PORTAL_HEAD')): ?>
<script src="../assets/js/legalpro-admin-table-pagination.js?v=4" defer></script>
<?php endif; ?>
<?php legalpro_icons_footer_scripts(); ?>
<!-- LEGALPRO_I18N_SKIP_END -->
