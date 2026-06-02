<?php
// inc/client-menunav.php — Client portal sidebar (original colored icons)
// Usage from pages/: include __DIR__ . '/../inc/client-menunav.php';

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$clientMenuItems = [
    ['title' => 'Dashboard', 'url' => 'client-dashboard.php', 'icon' => 'ni ni-tv-2', 'id' => 'client-dashboard'],
    ['title' => 'My Profile', 'url' => 'client-profile.php', 'icon' => 'ni ni-single-02', 'id' => 'client-profile'],
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
        return $itemId === $currentPage;
    }
}

$clientDisplayName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';
$companyBranding = getCompanyBranding();
$companyName = $companyBranding['name'];
$companyLogoUrl = $companyBranding['logo_url'];
?>

<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl fixed-start ms-4 legalpro-client-sidenav" id="sidenav-main">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0" href="client-dashboard.php">
            <img src="<?php echo htmlspecialchars($companyLogoUrl); ?>" width="26" height="26" class="navbar-brand-img h-100" alt="<?php echo htmlspecialchars($companyName); ?> logo" style="object-fit: contain;">
            <span class="ms-1 font-weight-bold"><?php echo htmlspecialchars($companyName); ?></span>
        </a>
    </div>
    <hr class="horizontal dark mt-0 mb-0">
    <div class="collapse navbar-collapse w-auto legalpro-client-sidenav__nav" id="sidenav-collapse-main">
        <ul class="navbar-nav">
            <?php foreach ($clientMenuItems as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo clientNavIsActive($item['id'], $currentPage) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center legalpro-client-nav-icon">
                            <i class="<?php echo htmlspecialchars($item['icon']); ?> text-dark text-xs opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1"><?php echo htmlspecialchars($item['title']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="sidenav-footer legalpro-client-sidenav__footer">
        <p class="text-xs text-muted mb-1">Logged in as</p>
        <p class="text-sm font-weight-bold mb-2"><?php echo htmlspecialchars($clientDisplayName); ?></p>
        <a href="client-logout.php" class="btn btn-sm btn-outline-danger mb-0">Logout</a>
    </div>
</aside>
