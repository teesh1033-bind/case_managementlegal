<?php
// inc/menunav.php
// Clean navigation menu for LegalPro Case Manager
// Usage: include __DIR__ . '/../inc/menunav.php';

// Get current page to highlight active menu item
$currentPage = basename($_SERVER['PHP_SELF']);
$currentPage = str_replace('.php', '', $currentPage);

// Navigation menu items
$menuItems = [
    [
        'title' => 'Dashboard',
        'url' => 'dashboard.php',
        'icon' => 'ni ni-tv-2',
        'id' => 'dashboard'
    ],
    [
        'title' => 'Cases',
        'url' => 'tables.php',
        'icon' => 'ni ni-collection',
        'id' => 'tables'
    ],
    [
        'title' => 'New Case',
        'url' => 'case-new.php',
        'icon' => 'ni ni-fat-add',
        'id' => 'case-new'
    ],
    [
        'title' => 'Clients',
        'url' => 'clients.php',
        'icon' => 'ni ni-circle-08',
        'id' => 'clients'
    ],
    [
        'title' => 'Appointments',
        'url' => 'appointments.php',
        'icon' => 'ni ni-time-alarm',
        'id' => 'appointments'
    ],
    [
        'title' => 'Court Tracking',
        'url' => 'court-tracking.php',
        'icon' => 'ni ni-calendar-grid-58',
        'id' => 'court-tracking'
    ],
    [
        'title' => 'Lawyers',
        'url' => 'lawyers.php',
        'icon' => 'ni ni-single-02',
        'id' => 'lawyers'
    ],
    [
        'title' => 'Payments',
        'url' => 'payments.php',
        'icon' => 'ni ni-money-coins',
        'id' => 'payments'
    ],
    [
        'title' => 'Invoices',
        'url' => 'invoices.php',
        'icon' => 'ni ni-collection',
        'id' => 'invoices'
    ],
    [
        'title' => 'Financial Summary',
        'url' => 'financial-summary.php',
        'icon' => 'ni ni-chart-pie-35',
        'id' => 'financial-summary'
    ],
    [
        'title' => 'Documents',
        'url' => 'documents.php',
        'icon' => 'ni ni-folder-17',
        'id' => 'documents'
    ],
    [
        'title' => 'Settings',
        'url' => 'settings.php',
        'icon' => 'ni ni-settings',
        'id' => 'settings'
    ],
    [
        'title' => 'Chatbot',
        'url' => 'chatbot.php',
        'icon' => 'ni ni-chat-round',
        'id' => 'chatbot'
    ]
];

// Function to check if menu item is active
function isActive($itemId, $currentPage) {
// Handle special cases
    if ($itemId === 'tables' && ($currentPage === 'tables' || $currentPage === 'case-detail')) {
        return true;
    }
    if ($itemId === 'clients' && ($currentPage === 'clients' || $currentPage === 'client-detail')) {
        return true;
    }
    if ($itemId === 'billing' && $currentPage === 'billing') {
        return true;
    }
    return $itemId === $currentPage;
}
?>

<link href="../assets/css/legalpro-admin-portal.css?v=4" rel="stylesheet" />
<style>
    .legalpro-nav .nav-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 1.25rem;
        margin: 0.15rem 0.75rem;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 600;
        color: #344767;
        transition: all 0.15s ease;
    }
    .legalpro-nav .nav-link .legalpro-nav-icon {
        min-width: 36px;
        width: 36px;
        height: 36px;
        background-color: rgba(94, 114, 228, 0.08);
        color: #5e72e4;
        transition: all 0.15s ease;
    }
    .legalpro-nav .nav-link .legalpro-nav-icon i {
        color: inherit !important;
        opacity: 0.9;
}
    .legalpro-nav .nav-link.active,
    .legalpro-nav .nav-link:hover {
        background: linear-gradient(135deg, #5e72e4, #825ee4);
        color: #fff;
        box-shadow: 0 10px 20px rgba(94, 114, 228, 0.25);
    }
    .legalpro-nav .nav-link.active .legalpro-nav-icon,
    .legalpro-nav .nav-link:hover .legalpro-nav-icon {
        background-color: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    .legalpro-nav .nav-link.active .legalpro-nav-text,
    .legalpro-nav .nav-link:hover .legalpro-nav-text {
        color: #fff !important;
    }
    .legalpro-nav .nav-scroll {
        flex: 1;
        overflow-y: auto;
        padding-right: 0.25rem;
        margin-right: -0.25rem;
    }
    .legalpro-nav .nav-scroll::-webkit-scrollbar {
        width: 4px;
    }
    .legalpro-nav .nav-scroll::-webkit-scrollbar-thumb {
        background: rgba(52, 71, 103, 0.3);
        border-radius: 4px;
}
</style>

<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl fixed-start ms-4 legalpro-nav" id="sidenav-main" style="height: calc(100vh - 2rem); top: 1rem; display: flex; flex-direction: column; overflow: hidden;">
    <div class="sidenav-header" style="flex-shrink: 0; padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: center; position: relative; width: 100%;">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0" href="dashboard.php" style="display: flex; align-items: center; justify-content: center; margin: 0 auto; width: 100%;">
            <img src="../assets/img/logo-ct-dark.png" width="24" height="24" class="navbar-brand-img" alt="LegalPro logo">
            <span class="ms-2 font-weight-bold" style="font-size: 0.875rem;">LegalPro</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0 mb-0" style="flex-shrink: 0; margin: 0;">
    <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main" style="flex: 1; display: flex; flex-direction: column; overflow: hidden;">
        <div class="nav-scroll">
            <ul class="navbar-nav" style="flex: 1; display: flex; flex-direction: column; justify-content: flex-start; padding: 0.25rem 0 0.5rem; margin: 0;">
            <?php foreach ($menuItems as $item): ?>
                    <li class="nav-item" style="flex-shrink: 0; margin: 0;">
                        <a class="nav-link <?php echo isActive($item['id'], $currentPage) ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
                            <div class="d-flex align-items-center">
                                <div class="icon icon-shape border-radius-md text-center me-2 d-flex align-items-center justify-content-center legalpro-nav-icon">
                                    <i class="<?php echo htmlspecialchars($item['icon']); ?> text-dark text-xs opacity-10"></i>
                                </div>
                                <span class="nav-link-text legalpro-nav-text" style="font-size: 0.95rem;"><?php echo htmlspecialchars($item['title']); ?></span>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
                </div>
    <div class="sidenav-footer" style="flex-shrink: 0; padding: 0.75rem 1rem; border-top: 1px solid rgba(0,0,0,0.1);">
        <div class="text-center">
            <p class="text-xs text-muted mb-1" style="font-size: 0.7rem;">Logged in as</p>
            <p class="text-sm font-weight-bold mb-2"><?php echo isset($_SESSION['admin_username']) ? htmlspecialchars($_SESSION['admin_username']) : 'Admin'; ?></p>
            <a href="admin-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
            </div>
    </div>
</aside>
