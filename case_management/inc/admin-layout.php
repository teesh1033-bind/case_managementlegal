<?php
/**
 * Shared admin portal layout helpers (Eagle-style shell, LegalPro colors).
 */

require_once __DIR__ . '/legalpro-icons.php';
require_once __DIR__ . '/../lib/portal_notifications.php';
require_once __DIR__ . '/../lib/admin-locale.php';

function legalpro_admin_notification_count(?PDO $pdo = null): int
{
    return legalpro_admin_notification_unread_count($pdo);
}

function legalpro_admin_display_name(): string
{
    if (!empty($_SESSION['admin_username'])) {
        return (string) $_SESSION['admin_username'];
    }

    return 'Admin User';
}

function legalpro_admin_ui_label(string $key, string $fallback): string
{
    if (!isset($_SESSION['admin_id']) || !function_exists('admin_t')) {
        return $fallback;
    }

    $translated = admin_t($key);
    return $translated !== $key ? $translated : $fallback;
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

    return count(legalpro_fetch_lawyer_notifications($pdo, $lawyerId));
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
    ?int $notifBadgeCount = null,
    bool $adminNotifApiMode = false,
    string $prependActionsHtml = ''
): string {
    $initials = legalpro_portal_initials($displayName);
    $notifCount = $notifBadgeCount ?? count($notifications);
    $notifBadge = '<span class="legalpro-header-notif__badge" data-notif-count' . ($notifCount > 0 ? '' : ' style="display:none"') . '>'
        . ($notifCount > 0 ? ($notifCount > 9 ? '9+' : (string) $notifCount) : '')
        . '</span>';

    $profileLabel = 'Profile';
    $signOutLabel = 'Sign out';
    $notifTitle = 'Notifications';

    if (!empty($_SESSION['admin_id']) && function_exists('admin_t')) {
        $profileLabel = legalpro_admin_ui_label('header.profile', $profileLabel);
        $signOutLabel = legalpro_admin_ui_label('header.sign_out', $signOutLabel);
        $notifTitle = legalpro_admin_ui_label('notifications.title', $notifTitle);
    } elseif (function_exists('legalpro_portal_translate')) {
        $profileLabel = legalpro_portal_translate('nav.profile', $profileLabel);
        $signOutLabel = legalpro_portal_translate('nav.sign_out', $signOutLabel);
        $notifTitle = legalpro_portal_translate('notifications.title', $notifTitle);
    }

    $profileItem = $profileUrl !== ''
        ? '<li><a class="dropdown-item" href="' . htmlspecialchars($profileUrl) . '">' . legalpro_icon('user', 'me-2') . htmlspecialchars($profileLabel) . '</a></li>'
        : '';

    if ($notifPanelMode) {
        $notifControl = '<div class="legalpro-header-notif-wrap">'
            . '<button type="button" class="legalpro-header-notif" id="clientNotifBell" title="' . htmlspecialchars($notifTitle, ENT_QUOTES, 'UTF-8') . '" aria-expanded="false" aria-controls="clientNotifPanel">'
            . legalpro_icon('bell') . $notifBadge . '</button>'
            . legalpro_render_client_notification_dropdown()
            . '</div>';
    } elseif ($adminNotifApiMode) {
        $notifControl = '<div class="legalpro-header-notif-wrap" data-admin-notif-api="1">'
            . '<button type="button" class="legalpro-header-notif" id="legalproNotifToggle" aria-expanded="false" aria-controls="legalproNotifPanel" title="' . htmlspecialchars($notifTitle, ENT_QUOTES, 'UTF-8') . '">'
            . legalpro_icon('bell') . $notifBadge . '</button>'
            . legalpro_render_admin_notification_dropdown()
            . '</div>';
    } else {
        $notifControl = '<div class="legalpro-header-notif-wrap">'
            . '<button type="button" class="legalpro-header-notif" id="legalproNotifToggle" aria-expanded="false" aria-controls="legalproNotifPanel" title="' . htmlspecialchars($notifTitle, ENT_QUOTES, 'UTF-8') . '">'
            . legalpro_icon('bell') . $notifBadge . '</button>'
            . legalpro_render_notification_panel($notifications, $viewAllUrl)
            . '</div>';
    }

    return '
    <div class="legalpro-navbar-actions d-flex align-items-center gap-3 flex-shrink-0">
        ' . $prependActionsHtml . '
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
                <li><a class="dropdown-item text-danger" href="' . htmlspecialchars($logoutUrl) . '">' . legalpro_icon('log-out', 'me-2') . htmlspecialchars($signOutLabel) . '</a></li>
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
    var nav = document.querySelector("main .navbar-main .container-fluid")
        || document.querySelector(".navbar-main .container-fluid");
    if (!mount || !nav) {
        return;
    }
    var actions = mount.querySelector(".legalpro-navbar-actions");
    if (!actions) {
        mount.remove();
        return;
    }
    nav.classList.add("d-flex", "align-items-center", "justify-content-between", "flex-wrap", "gap-2", "w-100");

    var isLawyerPortal = document.body.classList.contains("legalpro-lawyer-portal");
    var isLawyerSettingsPage = document.body.classList.contains("lawyer-settings-page");
    var navCollapse = nav.querySelector("#navbar") || nav.querySelector(".navbar-collapse");
    if (isLawyerPortal && !isLawyerSettingsPage && navCollapse && !navCollapse.querySelector(".legalpro-navbar-search")) {
        var params = new URLSearchParams(window.location.search);
        var lawyerI18n = window.legalproLawyerI18n || {};
        var searchPlaceholder = lawyerI18n.search || "Search...";
        var searchAria = lawyerI18n.searchAria || searchPlaceholder;
        var searchReset = lawyerI18n.searchReset || "Reset";
        var searchForm = document.createElement("form");
        searchForm.className = "ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search";
        searchForm.method = "get";
        searchForm.action = window.location.pathname.split("/").pop() || "";
        searchForm.setAttribute("role", "search");
        searchForm.innerHTML = ""
            + "<div class=\"input-group\">"
            + "<span class=\"input-group-text text-body\"><i class=\"fas fa-search\" aria-hidden=\"true\"></i></span>"
            + "<input type=\"search\" name=\"q\" id=\"lawyerNavbarSearchInput\" class=\"form-control\" placeholder=\"" + searchPlaceholder.replace(/"/g, "&quot;") + "\" autocomplete=\"off\" maxlength=\"200\" aria-label=\"" + searchAria.replace(/"/g, "&quot;") + "\">"
            + "<button type=\"button\" class=\"lp-lawyer-search-reset-btn\" data-lawyer-search-reset=\"lawyerNavbarSearchInput\" data-clear-url-param=\"q\" aria-label=\"" + searchReset.replace(/"/g, "&quot;") + "\">" + searchReset.replace(/</g, "&lt;") + "</button>"
            + "</div>";

        var searchInput = searchForm.querySelector("input[name=\"q\"]");
        if (searchInput) {
            searchInput.value = (params.get("q") || "").trim();
        }

        var navList = navCollapse.querySelector(".navbar-nav");
        if (navList && navList.parentNode === navCollapse) {
            navCollapse.insertBefore(searchForm, navList);
        } else {
            navCollapse.prepend(searchForm);
        }

        var searchRows = Array.prototype.slice.call(document.querySelectorAll("[data-search]"));
        function applyLawyerSearch(term) {
            if (!searchRows.length) {
                return;
            }
            var q = String(term || "").trim().toLowerCase();
            searchRows.forEach(function (row) {
                if (!q) {
                    row.style.display = "";
                    return;
                }
                var hay = (row.getAttribute("data-search") || row.textContent || "").toLowerCase();
                row.style.display = hay.indexOf(q) !== -1 ? "" : "none";
            });
        }

        if (searchInput) {
            applyLawyerSearch(searchInput.value);
            searchInput.addEventListener("input", function () {
                applyLawyerSearch(searchInput.value);
            });
        }
    }

    var existingActions = nav.querySelector(".legalpro-navbar-actions");
    if (existingActions) {
        existingActions.remove();
    }
    nav.appendChild(actions);
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
                notifRoot.dispatchEvent(new CustomEvent("legalpro:notif-open"));
            }
        });
        notifPanel.addEventListener("click", function (e) {
            e.stopPropagation();
        });
    }

    document.addEventListener("click", function (e) {
        if (e.target && e.target.closest && e.target.closest(".legalpro-notif-item, .legalpro-notif-panel__view-all")) {
            return;
        }
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
    $unreadCount = $pdo instanceof PDO ? legalpro_admin_notification_unread_count($pdo) : 0;

    return legalpro_render_portal_header_utilities(
        legalpro_admin_display_name(),
        legalpro_admin_ui_label('header.administrator', 'Administrator'),
        [],
        'admin-notifications.php',
        'admin-logout.php',
        'profile.php',
        '<li><a class="dropdown-item" href="settings.php">' . legalpro_icon('settings', 'me-2') . htmlspecialchars(legalpro_admin_ui_label('nav.settings', 'Settings')) . '</a></li>',
        false,
        $unreadCount,
        true,
        legalpro_render_admin_theme_toggle()
    );
}

function legalpro_render_admin_theme_toggle(): string
{
    if (!isset($_SESSION['admin_id'])) {
        return '';
    }

    if (!function_exists('getPortalTheme')) {
        require_once __DIR__ . '/../lib/portal-theme.php';
    }

    $currentMode = (string) (getPortalTheme()['mode'] ?? 'light');
    $isDark = $currentMode === 'dark';
    $iconName = $isDark ? 'sun' : 'moon';
    $switchLight = legalpro_admin_ui_label('theme.switch_light', 'Switch to light mode');
    $switchDark = legalpro_admin_ui_label('theme.switch_dark', 'Switch to dark mode');
    $label = $isDark ? $switchLight : $switchDark;

    return '<button type="button" class="legalpro-header-theme-toggle" id="adminThemeToggle"'
        . ' data-theme-mode="' . htmlspecialchars($currentMode, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-light="' . htmlspecialchars($switchLight, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-dark="' . htmlspecialchars($switchDark, ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . legalpro_icon($iconName)
        . '</button>';
}

function legalpro_render_admin_notification_dropdown(): string
{
    $unreadHint = htmlspecialchars(legalpro_notification_unread_hint(), ENT_QUOTES, 'UTF-8');

    return '<div class="legalpro-notif-panel legalpro-admin-notif-dropdown" id="legalproNotifPanel" role="menu" aria-label="' . htmlspecialchars(legalpro_admin_ui_label('header.notifications', 'Notifications'), ENT_QUOTES, 'UTF-8') . '" data-unread-hint="' . $unreadHint . '">'
        . '<div class="legalpro-notif-panel__head">'
        . '<h6 class="legalpro-notif-panel__title">' . htmlspecialchars(legalpro_admin_ui_label('notifications.title', 'Notifications')) . '</h6>'
        . '<button type="button" class="btn btn-link btn-sm p-0 text-primary legalpro-admin-notif-dropdown__mark-all" id="adminNotifMarkAll">' . htmlspecialchars(legalpro_admin_ui_label('notifications.mark_all_read', 'Mark all read')) . '</button>'
        . '</div>'
        . '<div class="legalpro-notif-panel__body" id="adminNotifList">'
        . '<div class="text-muted text-sm p-3">' . htmlspecialchars(legalpro_admin_ui_label('notifications.loading', 'Loading…')) . '</div>'
        . '</div>'
        . '<div class="legalpro-notif-panel__foot">'
        . '<a href="admin-notifications.php" class="legalpro-notif-panel__view-all">' . htmlspecialchars(legalpro_admin_ui_label('notifications.view_all', 'View all')) . '</a>'
        . '</div>'
        . '</div>'
        . '<template id="adminNotifEmptyTpl">'
        . '<div class="legalpro-notif-panel__empty">'
        . legalpro_icon('bell', 'legalpro-notif-panel__empty-icon')
        . '<p>' . htmlspecialchars(legalpro_admin_ui_label('notifications.empty_title', 'No new notifications')) . '</p>'
        . '<span>' . htmlspecialchars(legalpro_admin_ui_label('notifications.empty_sub', 'You are all caught up.')) . '</span>'
        . '</div>'
        . '</template>';
}

function legalpro_render_lawyer_theme_toggle(): string
{
    if (!isset($_SESSION['lawyer_id'])) {
        return '';
    }

    if (!function_exists('getLawyerPortalThemeMode')) {
        require_once __DIR__ . '/../lib/portal-theme.php';
    }
    if (!function_exists('legalpro_portal_translate')) {
        require_once __DIR__ . '/../lib/lawyer-locale.php';
    }

    $currentMode = getLawyerPortalThemeMode((int) $_SESSION['lawyer_id']);
    $isDark = $currentMode === 'dark';
    $iconName = $isDark ? 'sun' : 'moon';
    $labelKey = $isDark ? 'theme.switch_light' : 'theme.switch_dark';
    $label = legalpro_portal_translate($labelKey, $isDark ? 'Switch to light mode' : 'Switch to dark mode');
    $switchLight = legalpro_portal_translate('theme.switch_light', 'Switch to light mode');
    $switchDark = legalpro_portal_translate('theme.switch_dark', 'Switch to dark mode');

    return '<button type="button" class="legalpro-header-theme-toggle" id="lawyerThemeToggle"'
        . ' data-theme-mode="' . htmlspecialchars($currentMode, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-light="' . htmlspecialchars($switchLight, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-dark="' . htmlspecialchars($switchDark, ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . legalpro_icon($iconName)
        . '</button>';
}

function legalpro_render_lawyer_header_utilities(?PDO $pdo = null): string
{
    if (!function_exists('legalpro_portal_translate')) {
        require_once __DIR__ . '/../lib/lawyer-locale.php';
    }

    $lawyerId = isset($_SESSION['lawyer_id']) ? (int) $_SESSION['lawyer_id'] : 0;
    $displayName = isset($_SESSION['lawyer_name']) ? (string) $_SESSION['lawyer_name'] : 'Lawyer';
    $notifications = ($pdo instanceof PDO && $lawyerId > 0)
        ? legalpro_fetch_lawyer_notifications($pdo, $lawyerId)
        : [];

    $lawyerLabel = legalpro_portal_translate('header.lawyer', 'Lawyer');
    $settingsLabel = legalpro_portal_translate('nav.settings', 'Settings');

    return legalpro_render_portal_header_utilities(
        $displayName,
        $lawyerLabel,
        $notifications,
        'lawyer-notifications.php',
        'lawyer-logout.php',
        'lawyer-profile.php',
        '<li><a class="dropdown-item" href="lawyer-settings.php">' . legalpro_icon('settings', 'me-2') . htmlspecialchars($settingsLabel) . '</a></li>',
        false,
        null,
        false,
        legalpro_render_lawyer_theme_toggle()
    );
}

function legalpro_render_client_theme_toggle(): string
{
    if (!isset($_SESSION['client_id'])) {
        return '';
    }

    if (!function_exists('getClientPortalThemeMode')) {
        require_once __DIR__ . '/../lib/portal-theme.php';
    }

    $currentMode = getClientPortalThemeMode((int) $_SESSION['client_id']);
    $isDark = $currentMode === 'dark';
    $iconName = $isDark ? 'sun' : 'moon';
    $labelKey = $isDark ? 'theme.switch_light' : 'theme.switch_dark';
    $label = function_exists('client_t') ? client_t($labelKey) : ($isDark ? 'Switch to light mode' : 'Switch to dark mode');
    if ($label === $labelKey) {
        $label = $isDark ? 'Switch to light mode' : 'Switch to dark mode';
    }

    $switchLight = function_exists('client_t') ? client_t('theme.switch_light') : 'Switch to light mode';
    if ($switchLight === 'theme.switch_light') {
        $switchLight = 'Switch to light mode';
    }
    $switchDark = function_exists('client_t') ? client_t('theme.switch_dark') : 'Switch to dark mode';
    if ($switchDark === 'theme.switch_dark') {
        $switchDark = 'Switch to dark mode';
    }

    return '<button type="button" class="legalpro-header-theme-toggle" id="clientThemeToggle"'
        . ' data-theme-mode="' . htmlspecialchars($currentMode, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-light="' . htmlspecialchars($switchLight, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-label-dark="' . htmlspecialchars($switchDark, ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . legalpro_icon($iconName)
        . '</button>';
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
        'client-notifications.php',
        'client-logout.php',
        'client-profile.php',
        '<li><a class="dropdown-item" href="client-settings.php">' . legalpro_icon('settings', 'me-2') . htmlspecialchars($settingsLabel) . '</a></li>',
        true,
        legalpro_client_notification_count($pdo, $clientId),
        false,
        legalpro_render_client_theme_toggle()
    );
}

function legalpro_render_client_notification_dropdown(): string
{
    $markAll = function_exists('client_t') ? client_t('notifications.mark_all_read') : 'Mark all read';
    $title = function_exists('client_t') ? client_t('notifications.title') : 'Notifications';
    $empty = function_exists('client_t') ? client_t('notifications.empty') : 'No notifications yet';
    $viewAll = function_exists('client_t') ? client_t('notifications.view_all') : 'View all';
    $unreadHint = htmlspecialchars(
        function_exists('client_t') ? client_t('notifications.unread_hint') : legalpro_notification_unread_hint(),
        ENT_QUOTES,
        'UTF-8'
    );

    return '<div class="legalpro-notif-panel legalpro-client-notif-dropdown" id="clientNotifPanel" role="menu" aria-label="' . htmlspecialchars($title) . '" data-unread-hint="' . $unreadHint . '">'
        . '<div class="legalpro-notif-panel__head">'
        . '<h6 class="legalpro-notif-panel__title">' . htmlspecialchars($title) . '</h6>'
        . '<button type="button" class="btn btn-link btn-sm p-0 text-primary legalpro-client-notif-dropdown__mark-all" id="clientNotifMarkAll">'
        . htmlspecialchars($markAll) . '</button>'
        . '</div>'
        . '<div class="legalpro-notif-panel__body" id="clientNotifList">'
        . '<div class="text-muted text-sm p-3">Loading…</div>'
        . '</div>'
        . '<div class="legalpro-notif-panel__foot">'
        . '<a href="client-notifications.php" class="legalpro-notif-panel__view-all">' . htmlspecialchars($viewAll) . '</a>'
        . '</div>'
        . '</div>'
        . '<template id="clientNotifEmptyTpl">'
        . '<div class="legalpro-notif-panel__empty">'
        . legalpro_icon('bell', 'legalpro-notif-panel__empty-icon')
        . '<p>' . htmlspecialchars($empty) . '</p>'
        . '</div>'
        . '</template>';
}

/** @deprecated Panel is embedded in the header bell dropdown — kept for backward compatibility. */
function legalpro_render_client_notification_panel(): string
{
    return '';
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

    return function_exists('formatCurrency') ? formatCurrency($value) : number_format($value, 2);
}

function legalpro_case_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $map = [
        'normal' => ['key' => 'badges.priority.normal', 'label' => 'Normal', 'class' => 'lp-pill--priority-medium'],
        'high' => ['key' => 'badges.priority.high', 'label' => 'High', 'class' => 'lp-pill--priority-high'],
        'urgent' => ['key' => 'badges.priority.urgent', 'label' => 'Urgent', 'class' => 'lp-pill--priority-urgent'],
    ];

    if (isset($map[$key])) {
        $meta = $map[$key];

        return '<span class="lp-pill ' . $meta['class'] . '">' . htmlspecialchars(admin_badge_t($meta['key'], $meta['label'])) . '</span>';
    }

    $class = 'lp-pill--priority-medium';
    $label = $priority !== '' ? $priority : admin_badge_t('badges.priority.normal', 'Normal');

    return '<span class="lp-pill ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

function legalpro_case_status_badge(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'open' => ['key' => 'badges.status.active', 'label' => 'Active', 'class' => 'lp-pill--status-active'],
        'active' => ['key' => 'badges.status.active', 'label' => 'Active', 'class' => 'lp-pill--status-active'],
        'pending' => ['key' => 'badges.status.pending', 'label' => 'Pending', 'class' => 'lp-pill--status-pending'],
        'in_progress' => ['key' => 'badges.status.active', 'label' => 'Active', 'class' => 'lp-pill--status-active'],
        'under_review' => ['key' => 'badges.status.under_review', 'label' => 'Under Review', 'class' => 'lp-pill--status-waiting'],
        'waiting_for_client' => ['key' => 'badges.status.waiting_for_client', 'label' => 'Waiting For Client', 'class' => 'lp-pill--status-waiting'],
        'on_hold' => ['key' => 'badges.status.on_hold', 'label' => 'On Hold', 'class' => 'lp-pill--status-waiting'],
        'closed' => ['key' => 'badges.status.closed', 'label' => 'Closed', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    $meta = $map[$key];

    return '<span class="lp-pill ' . $meta['class'] . '">' . htmlspecialchars(admin_badge_t($meta['key'], $meta['label'])) . '</span>';
}

function legalpro_comment_role_badge(string $commentType): string
{
    $key = strtolower(trim($commentType));
    $map = [
        'client' => ['key' => 'badges.role.client', 'label' => 'Client', 'class' => 'lp-pill--status-default'],
        'lawyer' => ['key' => 'badges.role.lawyer', 'label' => 'Lawyer', 'class' => 'lp-pill--status-active'],
        'admin' => ['key' => 'badges.role.admin', 'label' => 'Admin', 'class' => 'lp-pill--status-pending'],
        'staff' => ['key' => 'badges.role.staff', 'label' => 'Staff', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    $meta = $map[$key];

    return '<span class="lp-pill ' . $meta['class'] . '">' . htmlspecialchars(admin_badge_t($meta['key'], $meta['label'])) . '</span>';
}

function legalpro_task_status_badge(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'active' => ['key' => 'badges.status.active', 'label' => 'Active', 'class' => 'lp-pill--status-progress'],
        'pending' => ['key' => 'badges.status.pending', 'label' => 'Pending', 'class' => 'lp-pill--status-pending'],
        'under_review' => ['key' => 'badges.status.under_review', 'label' => 'Under review', 'class' => 'lp-pill--status-progress'],
        'closed' => ['key' => 'badges.status.closed', 'label' => 'Closed', 'class' => 'lp-pill--status-closed'],
        'in_progress' => ['key' => 'badges.status.in_progress', 'label' => 'In Progress', 'class' => 'lp-pill--status-progress'],
        'completed' => ['key' => 'badges.status.completed', 'label' => 'Completed', 'class' => 'lp-pill--status-active'],
        'cancelled' => ['key' => 'badges.status.cancelled', 'label' => 'Cancelled', 'class' => 'lp-pill--status-declined'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    $meta = $map[$key];

    return '<span class="lp-pill ' . $meta['class'] . '">' . htmlspecialchars(admin_badge_t($meta['key'], $meta['label'])) . '</span>';
}

function legalpro_task_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $map = [
        'normal' => ['key' => 'badges.priority.normal', 'label' => 'Normal', 'class' => 'lp-pill--priority-medium'],
        'high' => ['key' => 'badges.priority.high', 'label' => 'High', 'class' => 'lp-pill--priority-high'],
        'urgent' => ['key' => 'badges.priority.urgent', 'label' => 'Urgent', 'class' => 'lp-pill--priority-urgent'],
        'low' => ['key' => 'badges.priority.low', 'label' => 'Low', 'class' => 'lp-pill--status-closed'],
        'medium' => ['key' => 'badges.priority.medium', 'label' => 'Medium', 'class' => 'lp-pill--priority-medium'],
    ];

    if (!isset($map[$key])) {
        $label = $priority !== '' ? ucwords($priority) : admin_badge_t('badges.priority.medium', 'Medium');

        return '<span class="lp-pill lp-pill--priority-medium">' . htmlspecialchars($label) . '</span>';
    }

    $meta = $map[$key];

    return '<span class="lp-pill ' . $meta['class'] . '">' . htmlspecialchars(admin_badge_t($meta['key'], $meta['label'])) . '</span>';
}

function legalpro_court_date_status_meta(string $status): array
{
    $key = strtolower(trim($status));
    $map = [
        'scheduled' => ['key' => 'badges.status.scheduled', 'label' => 'Scheduled', 'pill' => 'lp-pill--status-progress'],
        'completed' => ['key' => 'badges.status.completed', 'label' => 'Completed', 'pill' => 'lp-pill--status-closed'],
        'cancelled' => ['key' => 'badges.status.cancelled', 'label' => 'Cancelled', 'pill' => 'lp-pill--status-declined'],
        'postponed' => ['key' => 'badges.status.postponed', 'label' => 'Postponed', 'pill' => 'lp-pill--status-pending'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return ['label' => $label, 'pill' => 'lp-pill--status-default'];
    }

    $meta = $map[$key];

    return ['label' => admin_badge_t($meta['key'], $meta['label']), 'pill' => $meta['pill']];
}

function legalpro_court_date_status_badge(string $status): string
{
    $meta = legalpro_court_date_status_meta($status);

    return '<span class="lp-pill ' . $meta['pill'] . '">' . htmlspecialchars($meta['label']) . '</span>';
}

function legalpro_lawyer_active_status_badge(bool $isActive): string
{
    if ($isActive) {
        return '<span class="lp-pill lp-pill--status-active">' . htmlspecialchars(admin_badge_t('badges.status.active', 'Active')) . '</span>';
    }

    return '<span class="lp-pill lp-pill--status-closed">' . htmlspecialchars(admin_badge_t('badges.inactive', 'Inactive')) . '</span>';
}

function legalpro_case_fee_due(float $estimatedFees, float $invoicedTotal): float
{
    return max($estimatedFees, $invoicedTotal);
}

function legalpro_case_payment_status_label(float $totalDue, float $paid): string
{
    if ($totalDue <= 0.01 && $paid <= 0.01) {
        return admin_badge_t('badges.status.no_fees', 'No fees');
    }
    if ($paid >= $totalDue - 0.01) {
        return admin_badge_t('badges.status.paid', 'Paid');
    }
    if ($paid > 0.01) {
        return admin_badge_t('badges.status.partial', 'Partial');
    }

    return admin_badge_t('badges.status.outstanding', 'Outstanding');
}

function legalpro_case_payment_status_badge(float $totalDue, float $paid): string
{
    $label = legalpro_case_payment_status_label($totalDue, $paid);
    $map = [
        admin_badge_t('badges.status.no_fees', 'No fees') => 'lp-pill--status-default',
        admin_badge_t('badges.status.paid', 'Paid') => 'lp-pill--status-active',
        admin_badge_t('badges.status.partial', 'Partial') => 'lp-pill--status-progress',
        admin_badge_t('badges.status.outstanding', 'Outstanding') => 'lp-pill--status-pending',
        'No fees' => 'lp-pill--status-default',
        'Paid' => 'lp-pill--status-active',
        'Partial' => 'lp-pill--status-progress',
        'Outstanding' => 'lp-pill--status-pending',
    ];
    $class = $map[$label] ?? 'lp-pill--status-default';

    return '<span class="lp-pill ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

function legalpro_invoice_status_badge(string $status): string
{
    $key = strtolower(trim($status));
    $map = [
        'draft' => ['key' => 'badges.status.draft', 'label' => 'Draft', 'class' => 'lp-pill--status-default'],
        'sent' => ['key' => 'badges.status.sent', 'label' => 'Sent', 'class' => 'lp-pill--status-progress'],
        'paid' => ['key' => 'badges.status.paid', 'label' => 'Paid', 'class' => 'lp-pill--status-active'],
        'overdue' => ['key' => 'badges.status.overdue', 'label' => 'Overdue', 'class' => 'lp-pill--status-declined'],
        'cancelled' => ['key' => 'badges.status.cancelled', 'label' => 'Cancelled', 'class' => 'lp-pill--status-closed'],
    ];

    if (!isset($map[$key])) {
        $label = ucwords(str_replace('_', ' ', $key));

        return '<span class="lp-pill lp-pill--status-default">' . htmlspecialchars($label) . '</span>';
    }

    $meta = $map[$key];

    return '<span class="lp-pill ' . $meta['class'] . '">' . htmlspecialchars(admin_badge_t($meta['key'], $meta['label'])) . '</span>';
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
        case 'html':
        case 'htm':
            return ['icon' => 'file-text', 'accent' => 'info'];
        default:
            return ['icon' => 'file', 'accent' => 'dark'];
    }
}

function legalpro_document_file_icon_wrap(string $filename, string $extraClass = ''): string
{
    $meta = legalpro_document_file_icon_meta($filename);
    $class = 'dashboard-stat-icon-wrap dashboard-stat-icon-wrap--' . $meta['accent']
        . ' document-item-icon legalpro-doc-icon flex-shrink-0 me-3';
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
        'scheduled' => ['label_key' => 'badge.court_scheduled', 'pill' => 'ca-status-pill--scheduled'],
        'completed' => ['label_key' => 'badge.court_completed', 'pill' => 'ca-status-pill--done'],
        'cancelled' => ['label_key' => 'badge.court_cancelled', 'pill' => 'ca-status-pill--declined'],
        'postponed' => ['label_key' => 'badge.court_postponed', 'pill' => 'ca-status-pill--pending'],
    ];

    if (!isset($map[$key])) {
        $label = function_exists('client_status_label') ? client_status_label($status) : ucwords(str_replace('_', ' ', $key));

        return '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars($label) . '</span>';
    }

    $label = function_exists('client_t') ? client_t($map[$key]['label_key']) : $map[$key]['label_key'];

    return '<span class="ca-status-pill ' . $map[$key]['pill'] . '">' . htmlspecialchars($label) . '</span>';
}

function client_case_status_badge(string $status): string
{
    $key = strtolower(str_replace(' ', '_', trim($status)));
    $map = [
        'open' => ['label_key' => 'status.active', 'pill' => 'ca-status-pill--scheduled'],
        'in_progress' => ['label_key' => 'status.in_progress', 'pill' => 'ca-status-pill--scheduled'],
        'closed' => ['label_key' => 'status.closed', 'pill' => 'ca-status-pill--done'],
        'pending' => ['label_key' => 'status.pending', 'pill' => 'ca-status-pill--pending'],
    ];

    if (!isset($map[$key])) {
        $label = function_exists('client_status_label') ? client_status_label($status) : ucwords(str_replace('_', ' ', $key));

        return '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars($label) . '</span>';
    }

    $label = function_exists('client_t') ? client_t($map[$key]['label_key']) : $map[$key]['label_key'];

    return '<span class="ca-status-pill ' . $map[$key]['pill'] . '">' . htmlspecialchars($label) . '</span>';
}

function client_case_priority_badge(string $priority): string
{
    $key = strtolower(trim($priority));
    $label = function_exists('client_priority_label')
        ? client_priority_label($priority)
        : ($priority !== '' ? $priority : 'Normal');

    if ($key === 'high' || $key === 'urgent') {
        $pill = 'ca-status-pill--declined';
    } elseif ($key === 'low') {
        $pill = 'ca-status-pill--muted';
    } else {
        $pill = 'ca-status-pill--pending';
    }

    return '<span class="ca-status-pill ' . $pill . '">' . htmlspecialchars($label) . '</span>';
}

function lawyer_appointment_status_badge(array $appointment): string
{
    $status = strtolower((string) ($appointment['status'] ?? ''));
    $startsAt = !empty($appointment['starts_at']) ? strtotime($appointment['starts_at']) : 0;
    $now = time();

    if ($status === 'pending') {
        $label = function_exists('lawyer_tf')
            ? lawyer_tf('appointments.status_pending_approval', 'Pending approval')
            : 'Pending approval';

        return '<span class="ca-status-pill ca-status-pill--pending">' . htmlspecialchars($label) . '</span>';
    }
    if ($status === 'rejected') {
        $label = function_exists('lawyer_appointment_status_label')
            ? lawyer_appointment_status_label('rejected')
            : 'Rejected';

        return '<span class="ca-status-pill ca-status-pill--declined">' . htmlspecialchars($label) . '</span>';
    }
    if ($status === 'accepted') {
        if ($startsAt > 0 && $startsAt < $now) {
            $label = function_exists('lawyer_appointment_status_label')
                ? lawyer_appointment_status_label('completed')
                : 'Completed';

            return '<span class="ca-status-pill ca-status-pill--done">' . htmlspecialchars($label) . '</span>';
        }
        if ($startsAt > 0 && date('Y-m-d', $startsAt) === date('Y-m-d')) {
            $label = function_exists('lawyer_appointment_status_label')
                ? lawyer_appointment_status_label('today')
                : 'Today';

            return '<span class="ca-status-pill ca-status-pill--scheduled">' . htmlspecialchars($label) . '</span>';
        }

        $label = function_exists('lawyer_appointment_status_label')
            ? lawyer_appointment_status_label('scheduled')
            : 'Scheduled';

        return '<span class="ca-status-pill ca-status-pill--scheduled">' . htmlspecialchars($label) . '</span>';
    }

    $label = function_exists('lawyer_appointment_status_label')
        ? lawyer_appointment_status_label($status)
        : ucwords(str_replace('_', ' ', $status));

    return '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars($label) . '</span>';
}

function client_appointment_status_badge(array $appointment): string
{
    $status = strtolower((string) ($appointment['status'] ?? ''));
    if ($status === 'approved') {
        $status = 'accepted';
    }
    $startsAt = !empty($appointment['starts_at']) ? strtotime($appointment['starts_at']) : 0;
    $now = time();

    if ($status === 'pending') {
        $label = function_exists('client_t') ? client_t('badge.appt_awaiting') : 'Awaiting confirmation';

        return '<span class="ca-status-pill ca-status-pill--pending">' . htmlspecialchars($label) . '</span>';
    }
    if ($status === 'rejected') {
        $label = function_exists('client_t') ? client_t('badge.appt_declined') : 'Declined';

        return '<span class="ca-status-pill ca-status-pill--declined">' . htmlspecialchars($label) . '</span>';
    }
    if ($status === 'accepted') {
        if ($startsAt > 0 && $startsAt < $now) {
            $label = function_exists('client_t') ? client_t('badge.appt_completed') : 'Completed';

            return '<span class="ca-status-pill ca-status-pill--done">' . htmlspecialchars($label) . '</span>';
        }
        if ($startsAt > 0 && date('Y-m-d', $startsAt) === date('Y-m-d')) {
            $label = function_exists('client_t') ? client_t('badge.appt_today') : 'Today';

            return '<span class="ca-status-pill ca-status-pill--scheduled">' . htmlspecialchars($label) . '</span>';
        }

        $label = function_exists('client_t') ? client_t('badge.appt_confirmed') : 'Confirmed';

        return '<span class="ca-status-pill ca-status-pill--scheduled">' . htmlspecialchars($label) . '</span>';
    }

    $label = function_exists('client_status_label') ? client_status_label($status) : ucwords(str_replace('_', ' ', $status));

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
function legalpro_render_admin_table_pagination_nav(string $ariaLabel = 'Table pagination'): string
{
    return '<nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="'
        . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '" hidden>'
        . '<p class="lp-admin-pagination__info" data-lp-range></p>'
        . '<div class="lp-admin-pagination__controls" data-lp-pages></div>'
        . '</nav>';
}

function legalpro_admin_table_pagination_open(int $perPage = 10, string $rowSelector = '.legalpro-admin-list-row'): string
{
    return '<div class="lp-admin-table-paginate" data-lp-admin-paginate'
        . ' data-lp-per-page="' . (int) $perPage . '"'
        . ' data-lp-row="' . htmlspecialchars($rowSelector, ENT_QUOTES, 'UTF-8') . '">';
}

function legalpro_admin_table_pagination_close(string $ariaLabel = 'Table pagination'): string
{
    return legalpro_render_admin_table_pagination_nav($ariaLabel) . '</div>';
}

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
        . 'row.classList.toggle("lp-admin-row-filtered",!match);'
        . 'if(match){visible++;}'
        . '});'
        . 'if(emptyNote){emptyNote.classList.toggle("d-none",visible>0||rows.length===0);}'
        . 'var paginateWrap=tbody.closest("[data-lp-admin-paginate]");'
        . 'if(paginateWrap&&window.LegalproAdminTablePagination){'
        . 'window.LegalproAdminTablePagination.refresh(paginateWrap);'
        . '}'
        . '}'
        . 'if(searchInput){searchInput.addEventListener("input",applyAdminListSearch);}'
        . '})();</script>';
}

function legalpro_admin_featured_search_svg(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" aria-hidden="true">'
        . '<circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3-3"></path></svg>';
}

function legalpro_render_admin_featured_cal_search(
    string $inputId,
    string $resultsId,
    string $label,
    string $placeholder
): string {
    return '<div class="admin-cal-search-wrap admin-cal-search-wrap--featured">'
        . '<label class="admin-cal-search-label" for="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label) . '</label>'
        . '<div class="admin-cal-search-field">'
        . '<span class="admin-cal-search-icon" aria-hidden="true">' . legalpro_admin_featured_search_svg() . '</span>'
        . '<input type="search" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" class="admin-cal-search-input"'
        . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" autocomplete="off"'
        . ' aria-label="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">'
        . '</div>'
        . '<div class="admin-cal-search-results" id="' . htmlspecialchars($resultsId, ENT_QUOTES, 'UTF-8') . '" hidden></div>'
        . '</div>';
}

function legalpro_render_admin_featured_list_search(
    string $inputId,
    string $label,
    string $placeholder
): string {
    return '<div class="px-3 pt-3 pb-2">'
        . '<div class="admin-cal-search-wrap admin-cal-search-wrap--featured admin-cal-search-wrap--list">'
        . '<label class="admin-cal-search-label" for="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label) . '</label>'
        . '<div class="admin-cal-search-field">'
        . '<span class="admin-cal-search-icon" aria-hidden="true">' . legalpro_admin_featured_search_svg() . '</span>'
        . '<input type="search" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" class="admin-cal-search-input"'
        . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" autocomplete="off"'
        . ' aria-label="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">'
        . '</div>'
        . '</div>'
        . '</div>';
}
