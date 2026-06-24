<?php
// inc/client-menunav.php — Client portal sidebar + header utilities

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/../lib/portal-sidebar.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-features.php';

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$clientMenuPrimary = [
    ['title_key' => 'nav.dashboard', 'url' => 'client-dashboard.php', 'icon' => 'layout-dashboard', 'id' => 'client-dashboard'],
    ['title_key' => 'nav.my_cases', 'url' => 'client-cases.php', 'icon' => 'briefcase', 'id' => 'client-cases'],
    ['title_key' => 'nav.documents', 'url' => 'client-documents.php', 'icon' => 'file-text', 'id' => 'client-documents'],
    ['title_key' => 'nav.appointments', 'url' => 'client-appointments.php', 'icon' => 'calendar', 'id' => 'client-appointments'],
    ['title_key' => 'nav.court_tracking', 'url' => 'client-court-tracking.php', 'icon' => 'landmark', 'id' => 'client-court-tracking'],
    ['title_key' => 'nav.payments', 'url' => 'client-payments.php', 'icon' => 'credit-card', 'id' => 'client-payments'],
    ['title_key' => 'nav.ai_assistant', 'url' => 'chatbot.php', 'icon' => 'bot', 'id' => 'chatbot'],
];

$clientMenuFooter = [];

if (!function_exists('clientNavIsActive')) {
    function clientNavIsActive($itemId, $currentPage)
    {
        if ($itemId === 'client-cases' && in_array($currentPage, [
            'client-cases',
            'client-case-view',
            'client-case-activity',
            'client-case-services',
            'client-case-appointments',
            'client-case-documents',
            'client-case-comments',
        ], true)) {
            return true;
        }
        if ($itemId === 'client-documents' && $currentPage === 'client-documents') {
            return true;
        }
        if ($itemId === 'chatbot' && $currentPage === 'chatbot') {
            return true;
        }
        if ($itemId === 'client-appointments' && $currentPage === 'client-appointments') {
            return true;
        }
        if ($itemId === 'client-payments' && $currentPage === 'client-payments') {
            return true;
        }
        if ($itemId === 'client-court-tracking' && $currentPage === 'client-court-tracking') {
            return true;
        }

        return $itemId === $currentPage;
    }
}

$companyBranding = getCompanyBranding();
$companyName = $companyBranding['name'];
$companyLogoUrl = $companyBranding['logo_url'];

global $pdo;
$navbarUtilitiesMount = legalpro_navbar_utilities_mount(
    legalpro_render_client_header_utilities(isset($pdo) ? $pdo : null)
);
?>

<?php
if (!defined('LEGALPRO_CLIENT_PORTAL_HEAD')) {
    ob_start();
    include __DIR__ . '/client-portal-head.php';
    echo ob_get_clean();
}
?>

<?php
echo legalpro_render_portal_sidebar([
    'portal' => 'client',
    'home_url' => 'client-dashboard.php',
    'role_label' => 'CLIENT',
    'company_name' => $companyName,
    'logo_url' => $companyLogoUrl,
    'current_page' => $currentPage,
    'items' => $clientMenuPrimary,
    'footer_items' => $clientMenuFooter,
    'is_active' => 'clientNavIsActive',
]);
?>

<?php echo $navbarUtilitiesMount; ?>
<?php echo legalpro_client_render_bottom_nav($currentPage); ?>

<script src="../assets/js/client-portal.js?v=9"></script>
<script src="../assets/js/legalpro-search-clear.js?v=1"></script>
<?php legalpro_icons_footer_scripts(); ?>
