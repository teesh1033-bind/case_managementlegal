<?php
// inc/menunav.php — Admin sidebar + header utilities (LegalPro colors, modern shell)

require_once __DIR__ . '/admin-layout.php';

$companyBranding = getCompanyBranding();
$companyName = $companyBranding['name'];
$companyLogoUrl = $companyBranding['logo_url'];

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPage = str_replace('.php', '', $currentPage);

$menuItems = [
    ['title' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'ni ni-tv-2', 'id' => 'dashboard'],
    ['title' => 'Clients', 'url' => 'clients.php', 'icon' => 'ni ni-circle-08', 'id' => 'clients'],
    ['title' => 'Cases', 'url' => 'tables.php', 'icon' => 'ni ni-collection', 'id' => 'tables'],
    ['title' => 'Payments', 'url' => 'payments.php', 'icon' => 'ni ni-money-coins', 'id' => 'payments'],
    ['title' => 'Appointments', 'url' => 'appointments.php', 'icon' => 'ni ni-calendar-grid-58', 'id' => 'appointments'],
    ['title' => 'Court Tracking', 'url' => 'court-tracking.php', 'icon' => 'ni ni-map-big', 'id' => 'court-tracking'],
    ['title' => 'Lawyers', 'url' => 'lawyers.php', 'icon' => 'ni ni-single-02', 'id' => 'lawyers'],
    ['title' => 'Invoices', 'url' => 'invoices.php', 'icon' => 'ni ni-paper-diploma', 'id' => 'invoices'],
    ['title' => 'Financial Summary', 'url' => 'financial-summary.php', 'icon' => 'ni ni-chart-pie-35', 'id' => 'financial-summary'],
    ['title' => 'Documents', 'url' => 'documents.php', 'icon' => 'ni ni-folder-17', 'id' => 'documents'],
    ['title' => 'AI Assistant', 'url' => 'chatbot.php', 'icon' => 'ni ni-chat-round', 'id' => 'chatbot'],
    ['title' => 'Settings', 'url' => 'settings.php', 'icon' => 'ni ni-settings', 'id' => 'settings'],
];

function isActive($itemId, $currentPage)
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

    return $itemId === $currentPage;
}

global $pdo;
$navbarUtilitiesMount = legalpro_navbar_utilities_mount(
    legalpro_render_admin_header_utilities(isset($pdo) ? $pdo : null)
);
?>

<link href="../assets/css/legalpro-admin-portal.css?v=13" rel="stylesheet" />
<link href="../assets/css/legalpro-sidebar-nav.css?v=1" rel="stylesheet" />

<aside class="sidenav navbar navbar-vertical navbar-expand-xs legalpro-admin-sidebar" id="sidenav-main">
    <div class="legalpro-sidebar-brand">
        <a href="dashboard.php" class="legalpro-sidebar-brand__link">
            <img src="<?php echo htmlspecialchars($companyLogoUrl); ?>" width="42" height="42" alt="<?php echo htmlspecialchars($companyName); ?> logo" class="legalpro-sidebar-brand__logo">
            <span class="legalpro-sidebar-brand__text">
                <span class="legalpro-sidebar-brand__name"><?php echo htmlspecialchars($companyName); ?></span>
                <span class="legalpro-sidebar-brand__role">ADMIN</span>
            </span>
        </a>
        <button type="button" class="legalpro-sidebar-collapse btn btn-link p-0 d-none d-xl-inline-flex" id="legalproSidebarCollapse" aria-label="Collapse sidebar">
            <i class="ni ni-bold-left"></i>
        </button>
        <i class="fas fa-times legalpro-sidebar-close d-xl-none" id="iconSidenav" aria-hidden="true"></i>
    </div>

    <div class="collapse navbar-collapse w-100 legalpro-sidebar-nav-wrap" id="sidenav-collapse-main">
        <ul class="navbar-nav legalpro-sidebar-nav">
            <?php foreach ($menuItems as $item): ?>
                <?php $active = isActive($item['id'], $currentPage); ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $active ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
                        <span class="legalpro-sidebar-nav__icon"><i class="<?php echo htmlspecialchars($item['icon']); ?>"></i></span>
                        <span class="nav-link-text legalpro-sidebar-nav__label"><?php echo htmlspecialchars($item['title']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>

<?php echo $navbarUtilitiesMount; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var body = document.body;
    var collapseBtn = document.getElementById('legalproSidebarCollapse');
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            body.classList.toggle('legalpro-sidebar-collapsed');
        });
    }
});
</script>
