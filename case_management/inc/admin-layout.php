<?php
/**
 * Shared admin portal layout helpers (Eagle-style shell, LegalPro colors).
 */

require_once __DIR__ . '/legalpro-icons.php';
require_once __DIR__ . '/../lib/portal_notifications.php';

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

    $features = __DIR__ . '/../lib/client-portal-features.php';
    if (is_file($features)) {
        require_once $features;
        return legalpro_client_notification_count_unread($pdo, $clientId);
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
    array $notifications,
    string $viewAllUrl,
    string $logoutUrl,
    string $profileUrl = '',
    string $extraMenuHtml = '',
    bool $notifPanelMode = false,
    ?int $notifBadgeCount = null
): string {
    $initials = legalpro_portal_initials($displayName);
    $notifCount = $notifBadgeCount ?? count($notifications);
    $notifBadge = '<span class="legalpro-header-notif__badge" data-notif-count' . ($notifCount > 0 ? '' : ' style="display:none"') . '>'
        . ($notifCount > 0 ? ($notifCount > 9 ? '9+' : (string) $notifCount) : '')
        . '</span>';

    $profileItem = $profileUrl !== ''
        ? '<li><a class="dropdown-item" href="' . htmlspecialchars($profileUrl) . '">' . legalpro_icon('user', 'me-2') . 'Profile</a></li>'
        : '';

    if ($notifPanelMode) {
        $notifControl = '<button type="button" class="legalpro-header-notif" id="clientNotifBell" title="Notifications" aria-expanded="false" aria-controls="clientNotifPanel">'
            . legalpro_icon('bell') . $notifBadge . '</button>';
    } else {
        $notifControl = '<div class="legalpro-header-notif-wrap">'
            . '<button type="button" class="legalpro-header-notif" id="legalproNotifToggle" aria-expanded="false" aria-controls="legalproNotifPanel" title="Notifications">'
            . legalpro_icon('bell') . $notifBadge . '</button>'
            . legalpro_render_notification_panel($notifications, $viewAllUrl)
            . '</div>';
    }

    return '
    <div class="legalpro-navbar-actions d-flex align-items-center gap-3 flex-shrink-0">
        ' . $notifControl . '
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
    var notifRoot = nav.querySelector(".legalpro-header-notif-wrap");
    var notifToggle = notifRoot ? notifRoot.querySelector("#legalproNotifToggle") : null;
    var notifPanel = notifRoot ? notifRoot.querySelector("#legalproNotifPanel") : null;

    function closeUserMenu() {
        if (!userRoot) return;
        var userToggle = userRoot.querySelector(".legalpro-header-user__toggle");
        var userMenu = userRoot.querySelector(".legalpro-header-user__menu");
        if (!userToggle || !userMenu) return;
        userMenu.classList.remove("show");
        userToggle.classList.remove("show");
        userToggle.setAttribute("aria-expanded", "false");
    }

    function openUserMenu() {
        if (!userRoot) return;
        var userToggle = userRoot.querySelector(".legalpro-header-user__toggle");
        var userMenu = userRoot.querySelector(".legalpro-header-user__menu");
        if (!userToggle || !userMenu) return;
        closeNotifPanel();
        userMenu.classList.add("show");
        userToggle.classList.add("show");
        userToggle.setAttribute("aria-expanded", "true");
    }

    function closeNotifPanel() {
        if (!notifPanel || !notifToggle) return;
        notifPanel.classList.remove("show");
        notifToggle.classList.remove("show");
        notifToggle.setAttribute("aria-expanded", "false");
    }

    function openNotifPanel() {
        if (!notifPanel || !notifToggle) return;
        closeUserMenu();
        notifPanel.classList.add("show");
        notifToggle.classList.add("show");
        notifToggle.setAttribute("aria-expanded", "true");
    }

    if (userRoot && userRoot.dataset.menuBound !== "1") {
        userRoot.dataset.menuBound = "1";
        var userToggle = userRoot.querySelector(".legalpro-header-user__toggle");
        var userMenu = userRoot.querySelector(".legalpro-header-user__menu");
        if (userToggle && userMenu) {
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
        }
    }

    if (notifToggle && notifPanel && notifRoot.dataset.menuBound !== "1") {
        notifRoot.dataset.menuBound = "1";
        notifToggle.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (notifPanel.classList.contains("show")) {
                closeNotifPanel();
            } else {
                openNotifPanel();
            }
        });
        notifPanel.addEventListener("click", function (e) {
            e.stopPropagation();
        });
    }

    document.addEventListener("click", function () {
        closeUserMenu();
        closeNotifPanel();
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            closeUserMenu();
            closeNotifPanel();
        }
    });

    if (window.location.hash) {
        var target = document.querySelector(window.location.hash);
        if (target) {
            setTimeout(function () {
                target.scrollIntoView({ behavior: "smooth", block: "center" });
                target.classList.add("legalpro-notif-target-highlight");
            }, 250);
        }
    }
});
</script>';
}

function legalpro_render_admin_header_utilities(?PDO $pdo = null): string
{
    $notifications = $pdo instanceof PDO ? legalpro_fetch_admin_notifications($pdo) : [];

    return legalpro_render_portal_header_utilities(
        legalpro_admin_display_name(),
        'Administrator',
        $notifications,
        'appointments.php',
        'admin-logout.php',
        'profile.php',
        '<li><a class="dropdown-item" href="settings.php">' . legalpro_icon('settings', 'me-2') . 'Settings</a></li>'
    );
}

function legalpro_render_lawyer_header_utilities(?PDO $pdo = null): string
{
    $lawyerId = isset($_SESSION['lawyer_id']) ? (int) $_SESSION['lawyer_id'] : 0;
    $displayName = isset($_SESSION['lawyer_name']) ? (string) $_SESSION['lawyer_name'] : 'Lawyer';
    $notifications = ($pdo instanceof PDO && $lawyerId > 0)
        ? legalpro_fetch_lawyer_notifications($pdo, $lawyerId)
        : [];

    return legalpro_render_portal_header_utilities(
        $displayName,
        'Lawyer',
        $notifications,
        'lawyer-appointments.php',
        'lawyer-logout.php',
        'lawyer-profile.php',
        '<li><a class="dropdown-item" href="lawyer-settings.php">' . legalpro_icon('settings', 'me-2') . 'Settings</a></li>'
    );
}

function legalpro_render_client_header_utilities(?PDO $pdo = null): string
{
    $clientId = isset($_SESSION['client_id']) ? (int) $_SESSION['client_id'] : 0;
    $displayName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';
    $notifications = ($pdo instanceof PDO && $clientId > 0)
        ? legalpro_fetch_client_notifications($pdo, $clientId)
        : [];

    $clientLabel = function_exists('client_t') ? client_t('header.client') : 'Client';
    $settingsLabel = function_exists('client_t') ? client_t('nav.settings') : 'Settings';

    return legalpro_render_portal_header_utilities(
        $displayName,
        $clientLabel,
        $notifications,
        'client-appointments.php',
        'client-logout.php',
        'client-profile.php',
        '<li><a class="dropdown-item" href="client-settings.php">' . legalpro_icon('settings', 'me-2') . htmlspecialchars($settingsLabel) . '</a></li>',
        true,
        legalpro_client_notification_count($pdo, $clientId)
    );
}

function legalpro_render_client_notification_panel(): string
{
    $markAll = function_exists('client_t') ? client_t('notifications.mark_all_read') : 'Mark all read';
    $title = function_exists('client_t') ? client_t('notifications.title') : 'Notifications';
    $empty = function_exists('client_t') ? client_t('notifications.empty') : 'No notifications yet';
    $digest = function_exists('client_t') ? client_t('notifications.digest_settings') : 'Email digest settings';

    return '
<div class="legalpro-client-notif-panel" id="clientNotifPanel" hidden aria-label="' . htmlspecialchars($title) . '">
    <div class="legalpro-client-notif-panel__backdrop" data-notif-close="1"></div>
    <div class="legalpro-client-notif-panel__sheet" role="dialog" aria-modal="true">
        <div class="legalpro-client-notif-panel__hdr">
            <h6 class="mb-0">' . htmlspecialchars($title) . '</h6>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-link btn-sm p-0 text-primary" id="clientNotifMarkAll">' . htmlspecialchars($markAll) . '</button>
                <button type="button" class="btn btn-link p-0 text-secondary" data-notif-close="1" aria-label="Close">&times;</button>
            </div>
        </div>
        <div class="legalpro-client-notif-panel__list" id="clientNotifList">
            <div class="legalpro-client-notif-panel__loading text-muted text-sm p-3">Loading…</div>
        </div>
        <div class="legalpro-client-notif-panel__footer">
            <a href="client-settings.php#email-digest" class="text-xs text-muted">' . htmlspecialchars($digest) . '</a>
        </div>
    </div>
</div>
<template id="clientNotifEmptyTpl">
    <div class="legalpro-client-notif-panel__empty text-center p-4 text-muted text-sm">' . htmlspecialchars($empty) . '</div>
</template>';
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
        'on_hold' => ['label' => 'On Hold', 'class' => 'lp-pill--status-waiting'],
        'closed' => ['label' => 'Closed', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="lp-pill ' . $map[$key]['class'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function legalpro_comment_role_badge(string $commentType): string
{
    $key = strtolower(trim($commentType));
    $map = [
        'client' => ['label' => 'Client', 'class' => 'lp-pill--status-progress'],
        'lawyer' => ['label' => 'Lawyer', 'class' => 'lp-pill--status-active'],
        'admin' => ['label' => 'Admin', 'class' => 'lp-pill--status-pending'],
        'staff' => ['label' => 'Staff', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="lp-pill ' . $map[$key]['class'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function legalpro_task_status_badge(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'pending' => ['label' => 'Pending', 'class' => 'lp-pill--status-pending'],
        'in_progress' => ['label' => 'In Progress', 'class' => 'lp-pill--status-progress'],
        'completed' => ['label' => 'Completed', 'class' => 'lp-pill--status-active'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'lp-pill--status-declined'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="lp-pill ' . $map[$key]['class'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function legalpro_task_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $map = [
        'low' => ['label' => 'Low', 'class' => 'lp-pill--status-closed'],
        'medium' => ['label' => 'Medium', 'class' => 'lp-pill--priority-medium'],
        'high' => ['label' => 'High', 'class' => 'lp-pill--priority-high'],
        'urgent' => ['label' => 'Urgent', 'class' => 'lp-pill--priority-urgent'],
    ];

    if (!isset($map[$key])) {
        $label = $priority !== '' ? ucwords($priority) : 'Medium';

        return '<span class="lp-pill lp-pill--priority-medium">' . htmlspecialchars($label) . '</span>';
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

function legalpro_lawyer_active_status_badge(bool $isActive): string
{
    if ($isActive) {
        return '<span class="lp-pill lp-pill--status-active">Active</span>';
    }

    return '<span class="lp-pill lp-pill--status-closed">Inactive</span>';
}

function legalpro_invoice_status_badge(string $status): string
{
    $key = strtolower(trim($status));
    $map = [
        'draft' => ['label' => 'Draft', 'class' => 'lp-pill--status-default'],
        'sent' => ['label' => 'Sent', 'class' => 'lp-pill--status-progress'],
        'paid' => ['label' => 'Paid', 'class' => 'lp-pill--status-active'],
        'overdue' => ['label' => 'Overdue', 'class' => 'lp-pill--status-declined'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="lp-pill ' . $map[$key]['class'] . '">' . htmlspecialchars($map[$key]['label']) . '</span>';
}

function legalpro_document_file_icon_meta(string $filename): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'pdf':
            return ['icon' => 'file-text', 'accent' => 'danger'];
        case 'doc':
        case 'docx':
            return ['icon' => 'file-text', 'accent' => 'info'];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'webp':
            return ['icon' => 'image', 'accent' => 'success'];
        case 'xls':
        case 'xlsx':
        case 'csv':
            return ['icon' => 'file-spreadsheet', 'accent' => 'success'];
        case 'txt':
            return ['icon' => 'file-text', 'accent' => 'dark'];
        default:
            return ['icon' => 'file', 'accent' => 'dark'];
    }
}

function legalpro_document_file_icon_wrap(string $filename, string $extraClass = ''): string
{
    $meta = legalpro_document_file_icon_meta($filename);
    $class = 'dashboard-stat-icon-wrap dashboard-stat-icon-wrap--' . $meta['accent']
        . ' document-item-icon flex-shrink-0 me-3';
    if (trim($extraClass) !== '') {
        $class .= ' ' . trim($extraClass);
    }

    return '<div class="' . $class . '">' . legalpro_icon($meta['icon']) . '</div>';
}

function legalpro_document_count_badge(int $count, bool $compact = false): string
{
    if ($compact) {
        $class = $count > 0 ? 'lp-pill--status-progress' : 'lp-pill--status-default';
        $label = (string) max(0, $count);

        return '<span class="lp-pill ' . $class . ' me-2">' . htmlspecialchars($label) . '</span>';
    }

    if ($count > 0) {
        $label = $count === 1 ? '1 file' : $count . ' files';

        return '<span class="lp-pill lp-pill--status-progress">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="lp-pill lp-pill--status-default">No files</span>';
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

function lawyer_appointment_status_badge(array $appointment): string
{
    $status = strtolower((string) ($appointment['status'] ?? ''));
    $startsAt = !empty($appointment['starts_at']) ? strtotime($appointment['starts_at']) : 0;
    $now = time();

    if ($status === 'pending') {
        return '<span class="ca-status-pill ca-status-pill--pending">Pending approval</span>';
    }
    if ($status === 'rejected') {
        return '<span class="ca-status-pill ca-status-pill--declined">Rejected</span>';
    }
    if ($status === 'accepted') {
        if ($startsAt > 0 && $startsAt < $now) {
            return '<span class="ca-status-pill ca-status-pill--done">Completed</span>';
        }
        if ($startsAt > 0 && date('Y-m-d', $startsAt) === date('Y-m-d')) {
            return '<span class="ca-status-pill ca-status-pill--scheduled">Today</span>';
        }

        return '<span class="ca-status-pill ca-status-pill--scheduled">Scheduled</span>';
    }

    $label = ucwords(str_replace('_', ' ', $status));

    return '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars($label) . '</span>';
}

/**
 * Cases-style search field for admin list tables.
 */
function legalpro_render_admin_list_search(string $inputId, string $placeholder = 'Search...'): string
{
    return '<div class="legalpro-admin-list-filters">'
        . '<div class="legalpro-admin-list-search">'
        . legalpro_icon('search')
        . '<input type="search" class="form-control" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '"'
        . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"'
        . ' autocomplete="off" aria-label="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">'
        . '</div>'
        . '</div>';
}

/**
 * Client-side filter for rows with class + data-search (matches cases table behavior).
 */
function legalpro_admin_list_search_script(
    string $inputId,
    string $tbodyId,
    string $emptyRowId = '',
    string $rowClass = 'legalpro-admin-list-row'
): string {
    $inputIdJs = json_encode($inputId);
    $tbodyIdJs = json_encode($tbodyId);
    $emptyRowIdJs = json_encode($emptyRowId);
    $rowSelectorJs = json_encode('.' . $rowClass . '[data-search]');

    return '<script>(function(){var searchInput=document.getElementById(' . $inputIdJs . ');'
        . 'var tbody=document.getElementById(' . $tbodyIdJs . ');'
        . 'var emptyNote=' . ($emptyRowId !== '' ? 'document.getElementById(' . $emptyRowIdJs . ')' : 'null') . ';'
        . 'if(!tbody){return;}'
        . 'function applyAdminListSearch(){'
        . 'var q=(searchInput&&searchInput.value?searchInput.value:"").trim().toLowerCase();'
        . 'var rows=tbody.querySelectorAll(' . $rowSelectorJs . ');'
        . 'var visible=0;'
        . 'rows.forEach(function(row){'
        . 'var match=!q||row.getAttribute("data-search").indexOf(q)!==-1;'
        . 'row.style.display=match?"":"none";'
        . 'if(match){visible++;}'
        . '});'
        . 'if(emptyNote){emptyNote.classList.toggle("d-none",visible>0||rows.length===0);}'
        . '}'
        . 'if(searchInput){searchInput.addEventListener("input",applyAdminListSearch);}'
        . '})();</script>';
}
