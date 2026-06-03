<?php
// inc/client-menunav.php — Client portal sidebar + header utilities

require_once __DIR__ . '/admin-layout.php';

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$clientMenuItems = [
    ['title' => 'Dashboard', 'url' => 'client-dashboard.php', 'icon' => 'ni ni-tv-2', 'id' => 'client-dashboard'],
    ['title' => 'My Cases', 'url' => 'client-cases.php', 'icon' => 'ni ni-collection', 'id' => 'client-cases'],
    ['title' => 'Appointments', 'url' => 'client-appointments.php', 'icon' => 'ni ni-time-alarm', 'id' => 'client-appointments'],
    ['title' => 'Court Tracking', 'url' => 'client-court-tracking.php', 'icon' => 'ni ni-calendar-grid-58', 'id' => 'client-court-tracking'],
    ['title' => 'Payments', 'url' => 'client-payments.php', 'icon' => 'ni ni-money-coins', 'id' => 'client-payments'],
    ['title' => 'AI Assistant', 'url' => 'chatbot.php', 'icon' => 'ni ni-chat-round', 'id' => 'chatbot'],
];

if (!function_exists('clientNavIsActive')) {
    function clientNavIsActive($itemId, $currentPage)
    {
        if ($itemId === 'client-cases' && in_array($currentPage, ['client-cases', 'client-case-view'], true)) {
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
$navbarUtilitiesMount = legalpro_navbar_utilities_mount(
    legalpro_render_client_header_utilities(isset($pdo) ? $pdo : null)
);
?>

<link href="../assets/css/legalpro-client-portal.css?v=11" rel="stylesheet" />
<link href="../assets/css/legalpro-portal-shell.css?v=8" rel="stylesheet" />

<aside class="sidenav navbar navbar-vertical navbar-expand-xs legalpro-portal-sidebar legalpro-client-sidebar" id="sidenav-main">
    <div class="legalpro-sidebar-brand">
        <a href="client-dashboard.php" class="legalpro-sidebar-brand__link">
            <img src="<?php echo htmlspecialchars($companyLogoUrl); ?>" width="42" height="42" alt="<?php echo htmlspecialchars($companyName); ?> logo" class="legalpro-sidebar-brand__logo">
            <span class="legalpro-sidebar-brand__text">
                <span class="legalpro-sidebar-brand__name"><?php echo htmlspecialchars($companyName); ?></span>
                <span class="legalpro-sidebar-brand__role">CLIENT</span>
            </span>
        </a>
        <button type="button" class="legalpro-sidebar-collapse btn btn-link p-0 d-none d-xl-inline-flex" id="legalproSidebarCollapse" aria-label="Collapse sidebar">
            <i class="ni ni-bold-left"></i>
        </button>
        <i class="fas fa-times legalpro-sidebar-close d-xl-none" id="iconSidenav" aria-hidden="true"></i>
    </div>

    <div class="collapse navbar-collapse w-auto legalpro-sidebar-nav-wrap" id="sidenav-collapse-main">
        <ul class="navbar-nav legalpro-sidebar-nav">
            <?php foreach ($clientMenuItems as $item): ?>
                <?php $active = clientNavIsActive($item['id'], $currentPage); ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
                        <span class="legalpro-sidebar-nav__icon"><i class="<?php echo htmlspecialchars($item['icon']); ?>"></i></span>
                        <span class="nav-link-text legalpro-sidebar-nav__label"><?php echo htmlspecialchars($item['title']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="legalpro-sidebar-footer">
        <a href="client-logout.php" class="legalpro-sidebar-signout">
            <i class="ni ni-button-power"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>

<?php echo $navbarUtilitiesMount; ?>

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
