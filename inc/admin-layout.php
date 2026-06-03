<?php
/**
 * Shared admin portal layout helpers (Eagle-style shell, LegalPro colors).
 */

function legalpro_admin_notification_count(?PDO $pdo = null): int
{
    if ($pdo === null) {
        return 0;
    }

    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE LOWER(COALESCE(status, 'pending')) = 'pending'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function legalpro_admin_display_name(): string
{
    if (!empty($_SESSION['admin_username'])) {
        return (string) $_SESSION['admin_username'];
    }

    return 'Admin User';
}

function legalpro_admin_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials !== '' ? $initials : 'AU';
}

function legalpro_portal_initials(string $name, string $fallback = 'U'): string
{
    return legalpro_admin_initials($name !== '' ? $name : $fallback);
}

function legalpro_lawyer_notification_count(?PDO $pdo = null, ?int $lawyerId = null): int
{
    if ($pdo === null || $lawyerId === null || $lawyerId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments
            WHERE lawyer_id = ? AND LOWER(COALESCE(status, 'pending')) = 'pending'
        ");
        $stmt->execute([$lawyerId]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function legalpro_client_notification_count(?PDO $pdo = null, ?int $clientId = null): int
{
    if ($pdo === null || $clientId === null || $clientId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM appointments
            WHERE client_id = ? AND LOWER(COALESCE(status, 'pending')) = 'pending'
        ");
        $stmt->execute([$clientId]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Fixed top-right utilities (notifications + profile).
 */
function legalpro_render_portal_header_utilities(
    string $displayName,
    string $roleLabel,
    string $notifUrl,
    int $notifCount,
    string $logoutUrl,
    string $profileUrl = '',
    string $extraMenuHtml = ''
): string {
    $initials = legalpro_portal_initials($displayName);
    $notifBadge = $notifCount > 0
        ? '<span class="legalpro-header-notif__badge">' . ($notifCount > 9 ? '9+' : (string) $notifCount) . '</span>'
        : '';

    $profileItem = $profileUrl !== ''
        ? '<li><a class="dropdown-item" href="' . htmlspecialchars($profileUrl) . '"><i class="ni ni-single-02 me-2"></i>Profile</a></li>'
        : '';

    return '
    <div class="legalpro-navbar-actions d-flex align-items-center gap-3 flex-shrink-0">
        <a href="' . htmlspecialchars($notifUrl) . '" class="legalpro-header-notif" title="Notifications">
            <i class="ni ni-bell-55"></i>
            ' . $notifBadge . '
        </a>
        <div class="legalpro-header-user dropdown">
            <button type="button" class="legalpro-header-user__toggle" aria-expanded="false" aria-haspopup="true" aria-controls="legalproHeaderUserMenuList">
                <span class="legalpro-header-user__avatar">' . htmlspecialchars($initials) . '</span>
                <span class="legalpro-header-user__meta d-none d-md-block">
                    <span class="legalpro-header-user__name">' . htmlspecialchars($displayName) . '</span>
                    <span class="legalpro-header-user__role">' . htmlspecialchars($roleLabel) . '</span>
                </span>
                <i class="ni ni-bold-down legalpro-header-user__caret" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 legalpro-header-user__menu" id="legalproHeaderUserMenuList">
                ' . $profileItem . '
                ' . $extraMenuHtml . '
                ' . ($profileItem !== '' || $extraMenuHtml !== '' ? '<li><hr class="dropdown-divider"></li>' : '') . '
                <li><a class="dropdown-item text-danger" href="' . htmlspecialchars($logoutUrl) . '"><i class="ni ni-button-power me-2"></i>Sign out</a></li>
            </ul>
        </div>
    </div>';
}

/**
 * Mount navbar actions into the page top bar (injected after menunav on each portal).
 */
function legalpro_navbar_utilities_mount(string $utilitiesHtml): string
{
    if ($utilitiesHtml === '') {
        return '';
    }

    return '
<div id="legalproNavbarUtilitiesMount" hidden>' . $utilitiesHtml . '</div>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var mount = document.getElementById("legalproNavbarUtilitiesMount");
    var nav = document.querySelector("main .navbar-main .container-fluid");
    if (!mount || !nav) {
        return;
    }
    var actions = mount.querySelector(".legalpro-navbar-actions");
    if (!actions) {
        mount.remove();
        return;
    }
    nav.classList.add("d-flex", "align-items-center", "justify-content-between", "flex-wrap", "gap-2", "w-100");
    if (!nav.querySelector(".legalpro-navbar-actions")) {
        nav.appendChild(actions);
    }
    mount.remove();

    var userRoot = nav.querySelector(".legalpro-header-user");
    if (!userRoot || userRoot.dataset.menuBound === "1") {
        return;
    }
    userRoot.dataset.menuBound = "1";

    var userToggle = userRoot.querySelector(".legalpro-header-user__toggle");
    var userMenu = userRoot.querySelector(".legalpro-header-user__menu");
    if (!userToggle || !userMenu) {
        return;
    }

    function closeUserMenu() {
        userMenu.classList.remove("show");
        userToggle.classList.remove("show");
        userToggle.setAttribute("aria-expanded", "false");
    }

    function openUserMenu() {
        userMenu.classList.add("show");
        userToggle.classList.add("show");
        userToggle.setAttribute("aria-expanded", "true");
    }

    userToggle.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (userMenu.classList.contains("show")) {
            closeUserMenu();
        } else {
            openUserMenu();
        }
    });

    userMenu.addEventListener("click", function (e) {
        e.stopPropagation();
    });

    document.addEventListener("click", function () {
        closeUserMenu();
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            closeUserMenu();
        }
    });
});
</script>';
}

function legalpro_render_admin_header_utilities(?PDO $pdo = null): string
{
    return legalpro_render_portal_header_utilities(
        legalpro_admin_display_name(),
        'Administrator',
        'appointments.php',
        legalpro_admin_notification_count($pdo),
        'admin-logout.php',
        'profile.php',
        '<li><a class="dropdown-item" href="settings.php"><i class="ni ni-settings me-2"></i>Settings</a></li>'
    );
}

function legalpro_render_lawyer_header_utilities(?PDO $pdo = null): string
{
    $lawyerId = isset($_SESSION['lawyer_id']) ? (int) $_SESSION['lawyer_id'] : 0;
    $displayName = isset($_SESSION['lawyer_name']) ? (string) $_SESSION['lawyer_name'] : 'Lawyer';

    return legalpro_render_portal_header_utilities(
        $displayName,
        'Lawyer',
        'lawyer-appointments.php',
        legalpro_lawyer_notification_count($pdo, $lawyerId),
        'lawyer-logout.php',
        'lawyer-profile.php'
    );
}

function legalpro_render_client_header_utilities(?PDO $pdo = null): string
{
    $clientId = isset($_SESSION['client_id']) ? (int) $_SESSION['client_id'] : 0;
    $displayName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';

    return legalpro_render_portal_header_utilities(
        $displayName,
        'Client',
        'client-appointments.php',
        legalpro_client_notification_count($pdo, $clientId),
        'client-logout.php',
        'client-profile.php'
    );
}

/**
 * Optional page toolbar below the main navbar title.
 */
function legalpro_render_page_toolbar(string $title, string $subtitle = '', string $actionsHtml = ''): string
{
    $subtitleHtml = $subtitle !== ''
        ? '<p class="legalpro-page-toolbar__subtitle mb-0">' . htmlspecialchars($subtitle) . '</p>'
        : '';

    $actions = $actionsHtml !== ''
        ? '<div class="legalpro-page-toolbar__actions">' . $actionsHtml . '</div>'
        : '';

    return '
    <div class="legalpro-page-toolbar mb-4">
        <div class="legalpro-page-toolbar__text">
            <h5 class="legalpro-page-toolbar__title mb-1">' . htmlspecialchars($title) . '</h5>
            ' . $subtitleHtml . '
        </div>
        ' . $actions . '
    </div>';
}
