<?php
// inc/client-menunav.php — Client portal sidebar + header utilities (same shell as menunav.php)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/legalpro-icons.php';
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

$clientMenuFooter = [
    ['title_key' => 'nav.settings', 'url' => 'client-settings.php', 'icon' => 'settings', 'id' => 'client-settings'],
];

if (!function_exists('clientNavIsActive')) {
    function clientNavIsActive($itemId, $currentPage)
    {
        if ($itemId === 'client-cases' && in_array($currentPage, ['client-cases', 'client-case-view'], true)) {
            return true;
        }
        if ($itemId === 'client-documents' && $currentPage === 'client-documents') {
            return true;
        }
        if ($itemId === 'chatbot' && $currentPage === 'chatbot') {
            return true;
        }
        if ($itemId === 'client-settings' && $currentPage === 'client-settings') {
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

<aside class="sidenav navbar navbar-vertical navbar-expand-xs fixed-start legalpro-admin-sidebar" id="sidenav-main">
    <div class="legalpro-sidebar-brand">
        <a href="client-dashboard.php" class="legalpro-sidebar-brand__link">
            <img src="<?php echo htmlspecialchars($companyLogoUrl); ?>" width="42" height="42" alt="<?php echo htmlspecialchars($companyName); ?> logo" class="legalpro-sidebar-brand__logo">
            <span class="legalpro-sidebar-brand__text">
                <span class="legalpro-sidebar-brand__name"><?php echo htmlspecialchars($companyName); ?></span>
                <span class="legalpro-sidebar-brand__role">CLIENT</span>
            </span>
        </a>
        <button type="button" class="legalpro-sidebar-collapse btn btn-link p-0 d-none d-xl-inline-flex" id="legalproSidebarCollapse" aria-label="Collapse sidebar">
            <?php echo legalpro_icon('chevron-left'); ?>
        </button>
        <i class="fas fa-times legalpro-sidebar-close d-xl-none" id="iconSidenav" aria-hidden="true"></i>
    </div>

    <div class="collapse show navbar-collapse w-100 legalpro-sidebar-nav-wrap" id="sidenav-collapse-main">
        <ul class="navbar-nav legalpro-sidebar-nav">
            <?php foreach ($clientMenuPrimary as $item): ?>
                <?php $active = clientNavIsActive($item['id'], $currentPage); ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
                        <span class="legalpro-sidebar-nav__icon"><?php echo legalpro_icon($item['icon']); ?></span>
                        <span class="nav-link-text legalpro-sidebar-nav__label"><?php echo htmlspecialchars(client_t($item['title_key'])); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="legalpro-sidebar-footer">
        <?php foreach ($clientMenuFooter as $item): ?>
            <?php $active = clientNavIsActive($item['id'], $currentPage); ?>
            <a class="nav-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
                <span class="legalpro-sidebar-nav__icon"><?php echo legalpro_icon($item['icon']); ?></span>
                <span class="nav-link-text legalpro-sidebar-nav__label"><?php echo htmlspecialchars(client_t($item['title_key'])); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</aside>

<?php echo $navbarUtilitiesMount; ?>
<?php echo legalpro_render_client_notification_panel(); ?>
<?php echo legalpro_client_render_bottom_nav($currentPage); ?>

<script src="../assets/js/client-portal.js?v=4"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var collapseBtn = document.getElementById('legalproSidebarCollapse');
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            document.body.classList.toggle('legalpro-sidebar-collapsed');
        });
    }
});
</script>
<script src="../assets/js/legalpro-search-clear.js?v=1"></script>
<?php legalpro_icons_footer_scripts(); ?>
