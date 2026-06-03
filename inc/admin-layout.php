<?php
/**
 * Shared admin portal layout helpers (Eagle-style shell, LegalPro colors).
 */

require_once __DIR__ . '/legalpro-icons.php';

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
        ? '<li><a class="dropdown-item" href="' . htmlspecialchars($profileUrl) . '">' . legalpro_icon('user', 'me-2') . 'Profile</a></li>'
        : '';

    return '
    <div class="legalpro-navbar-actions d-flex align-items-center gap-3 flex-shrink-0">
        <a href="' . htmlspecialchars($notifUrl) . '" class="legalpro-header-notif" title="Notifications">
            ' . legalpro_icon('bell') . '
            ' . $notifBadge . '
        </a>
        <div class="legalpro-header-user dropdown">
            <button type="button" class="legalpro-header-user__toggle" aria-expanded="false" aria-haspopup="true" aria-controls="legalproHeaderUserMenuList">
                <span class="legalpro-header-user__avatar">' . htmlspecialchars($initials) . '</span>
                <span class="legalpro-header-user__meta d-none d-md-block">
                    <span class="legalpro-header-user__name">' . htmlspecialchars($displayName) . '</span>
                    <span class="legalpro-header-user__role">' . htmlspecialchars($roleLabel) . '</span>
                </span>
                ' . legalpro_icon('chevron-down', 'legalpro-header-user__caret') . '
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 legalpro-header-user__menu" id="legalproHeaderUserMenuList">
                ' . $profileItem . '
                ' . $extraMenuHtml . '
                <li><a class="dropdown-item text-danger" href="' . htmlspecialchars($logoutUrl) . '">' . legalpro_icon('log-out', 'me-2') . 'Sign out</a></li>
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
        '<li><a class="dropdown-item" href="settings.php">' . legalpro_icon('settings', 'me-2') . 'Settings</a></li>'
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

function legalpro_format_case_number(int $caseId, ?string $createdAt = null): string
{
    $year = $createdAt ? date('Y', strtotime($createdAt)) : date('Y');

    return 'CASE-' . $year . '-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
}

function legalpro_format_case_fee($amount): string
{
    $value = is_numeric($amount) ? (float) $amount : 0.0;

    return '£ ' . number_format($value, 2);
}

function legalpro_case_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $label = $priority !== '' ? $priority : 'Normal';

    if ($key === 'high' || $key === 'urgent') {
        $class = $key === 'urgent' ? 'lp-pill--priority-urgent' : 'lp-pill--priority-high';
    } else {
        $class = 'lp-pill--priority-medium';
        if ($key === 'normal') {
            $label = 'Medium';
        }
    }

    return '<span class="lp-pill ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

function legalpro_case_status_badge(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'open' => ['label' => 'Pending', 'class' => 'lp-pill--status-pending'],
        'pending' => ['label' => 'Pending', 'class' => 'lp-pill--status-pending'],
        'in_progress' => ['label' => 'In Progress', 'class' => 'lp-pill--status-progress'],
        'waiting_for_client' => ['label' => 'Waiting For Client', 'class' => 'lp-pill--status-waiting'],
        'closed' => ['label' => 'Closed', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="lp-pill ' . $map[$key]['class'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function legalpro_court_date_status_meta(string $status): array
{
    $key = strtolower(trim($status));
    $map = [
        'scheduled' => ['label' => 'Scheduled', 'pill' => 'lp-pill--status-progress'],
        'completed' => ['label' => 'Completed', 'pill' => 'lp-pill--status-closed'],
        'cancelled' => ['label' => 'Cancelled', 'pill' => 'lp-pill--status-declined'],
        'postponed' => ['label' => 'Postponed', 'pill' => 'lp-pill--status-pending'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return ['label' => $label, 'pill' => 'lp-pill--status-default'];
    }

    return $map[$key];
}

function legalpro_court_date_status_badge(string $status): string
{
    $meta = legalpro_court_date_status_meta($status);

    return '<span class="lp-pill ' . $meta['pill'] . '">' . htmlspecialchars($meta['label']) . '</span>';
}

function client_court_date_status_badge(string $status): string
{
    $key = strtolower(trim($status));
    $map = [
        'scheduled' => ['label' => 'Scheduled', 'pill' => 'ca-status-pill--scheduled'],
        'completed' => ['label' => 'Completed', 'pill' => 'ca-status-pill--done'],
        'cancelled' => ['label' => 'Cancelled', 'pill' => 'ca-status-pill--declined'],
        'postponed' => ['label' => 'Postponed', 'pill' => 'ca-status-pill--pending'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="ca-status-pill ' . $map[$key]['pill'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function client_case_status_badge(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'open' => ['label' => 'Open', 'pill' => 'ca-status-pill--scheduled'],
        'in_progress' => ['label' => 'In Progress', 'pill' => 'ca-status-pill--scheduled'],
        'closed' => ['label' => 'Closed', 'pill' => 'ca-status-pill--done'],
        'pending' => ['label' => 'Pending', 'pill' => 'ca-status-pill--pending'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="ca-status-pill ' . $map[$key]['pill'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function client_case_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $label = $priority !== '' ? $priority : 'Normal';

    if ($key === 'high' || $key === 'urgent') {
        $pill = 'ca-status-pill--declined';
    } elseif ($key === 'low') {
        $pill = 'ca-status-pill--muted';
    } else {
        $pill = 'ca-status-pill--pending';
        if ($key === 'normal') {
            $label = 'Normal';
        }
    }

    return '<span class="ca-status-pill ' . $pill . '">' . htmlspecialchars($label) . '</span>';
}
