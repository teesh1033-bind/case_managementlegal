<?php
// inc/lawyer-menunav.php — Lawyer portal sidebar + header utilities

require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/../lib/portal-sidebar.php';

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$lawyerMenuItems = [
    ['title' => 'Dashboard', 'url' => 'lawyer-dashboard.php', 'icon' => 'layout-dashboard', 'id' => 'lawyer-dashboard'],
    ['title' => 'My Tasks', 'url' => 'tasks.php', 'icon' => 'list-checks', 'id' => 'tasks'],
    ['title' => 'My Cases', 'url' => 'lawyer-cases.php', 'icon' => 'briefcase', 'id' => 'lawyer-cases'],
    ['title' => 'My Clients', 'url' => 'lawyer-clients.php', 'icon' => 'users', 'id' => 'lawyer-clients'],
    ['title' => 'Appointments', 'url' => 'lawyer-appointments.php', 'icon' => 'calendar', 'id' => 'lawyer-appointments'],
    ['title' => 'Court Tracking', 'url' => 'lawyer-court-tracking.php', 'icon' => 'landmark', 'id' => 'lawyer-court-tracking'],
    ['title' => 'My Availability', 'url' => 'lawyer-availability.php', 'icon' => 'clock', 'id' => 'lawyer-availability'],
    ['title' => 'AI Assistant', 'url' => 'chatbot.php', 'icon' => 'bot', 'id' => 'chatbot'],
];

if (!function_exists('lawyerNavIsActive')) {
    function lawyerNavIsActive($itemId, $currentPage)
    {
        if ($itemId === 'lawyer-cases' && in_array($currentPage, ['lawyer-cases', 'lawyer-case-view'], true)) {
            return true;
        }
        if ($itemId === 'lawyer-clients' && in_array($currentPage, ['lawyer-clients', 'lawyer-client-view'], true)) {
            return true;
        }
        if ($itemId === 'chatbot' && $currentPage === 'chatbot') {
            return true;
        }

        return $itemId === $currentPage;
    }
}

$companyBranding = getCompanyBranding();
$companyName = $companyBranding['name'];
$companyLogoUrl = $companyBranding['logo_url'];

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/db.php';
}
$navbarUtilitiesMount = legalpro_navbar_utilities_mount(
    legalpro_render_lawyer_header_utilities(isset($pdo) ? $pdo : null)
);
?>

<?php
if (!defined('LEGALPRO_LAWYER_PORTAL_HEAD')) {
    ob_start();
    include __DIR__ . '/lawyer-portal-head.php';
    echo ob_get_clean();
}
?>

<?php
echo legalpro_render_portal_sidebar([
    'portal' => 'lawyer',
    'home_url' => 'lawyer-dashboard.php',
    'role_label' => 'LAWYER',
    'company_name' => $companyName,
    'logo_url' => $companyLogoUrl,
    'current_page' => $currentPage,
    'items' => $lawyerMenuItems,
    'is_active' => 'lawyerNavIsActive',
]);
?>

<?php echo $navbarUtilitiesMount; ?>
<script src="../assets/js/lawyer-portal.js?v=1"></script>
<?php legalpro_icons_footer_scripts(); ?>
