<?php

/**
 * Unified portal navigation — modern sidebar renderer for admin, client, and lawyer portals.
 */

require_once dirname(__DIR__) . '/inc/legalpro-icons.php';

function legalpro_portal_nav_asset_links(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    echo '<link href="../assets/css/legalpro-portal-shell.css?v=22" rel="stylesheet" />' . "\n";
    echo '<link href="../assets/css/legalpro-admin-portal.css?v=31" rel="stylesheet" />' . "\n";
    echo '<link href="../assets/css/dashboard-enhancements.css?v=15" rel="stylesheet" />' . "\n";
    echo '<link href="../assets/css/legalpro-sidebar-nav.css?v=20" rel="stylesheet" />' . "\n";
    echo '<link href="../assets/css/legalpro-modern-nav.css?v=1" rel="stylesheet" />' . "\n";
    if (function_exists('legalpro_icons_asset_links')) {
        legalpro_icons_asset_links();
    }
}

function legalpro_portal_nav_resolve_title(array $item): string
{
    if (!empty($item['title'])) {
        return (string) $item['title'];
    }
    if (!empty($item['title_key']) && function_exists('client_t')) {
        $t = client_t((string) $item['title_key']);
        if ($t !== $item['title_key']) {
            return $t;
        }
    }
    if (!empty($item['title_key'])) {
        return (string) ($item['fallback'] ?? $item['title_key']);
    }

    return '';
}

function legalpro_portal_nav_item_is_active(array $item, string $currentPage, ?callable $isActiveFn): bool
{
    if ($isActiveFn) {
        return (bool) $isActiveFn($item['id'] ?? '', $currentPage, $item);
    }

    return ($item['id'] ?? '') === $currentPage;
}

function legalpro_portal_nav_group_is_open(array $item, string $currentPage, ?callable $isActiveFn): bool
{
    if (empty($item['children'])) {
        return false;
    }
    foreach ($item['children'] as $child) {
        if (legalpro_portal_nav_item_is_active($child, $currentPage, $isActiveFn)) {
            return true;
        }
    }

    return false;
}

function legalpro_portal_nav_render_link(array $item, string $currentPage, ?callable $isActiveFn, bool $isChild = false): string
{
    $title = legalpro_portal_nav_resolve_title($item);
    $url = htmlspecialchars((string) ($item['url'] ?? '#'));
    $active = legalpro_portal_nav_item_is_active($item, $currentPage, $isActiveFn);
    $icon = !empty($item['icon']) ? legalpro_icon((string) $item['icon']) : '';
    $badge = '';
    if (!empty($item['badge'])) {
        $badge = '<span class="legalpro-nav-badge">' . htmlspecialchars((string) $item['badge']) . '</span>';
    }

    if ($isChild) {
        return '<li class="nav-item">'
            . '<a class="nav-link legalpro-sidebar-nav-group__link' . ($active ? ' active' : '') . '" href="' . $url . '" title="' . htmlspecialchars($title) . '">'
            . '<span class="legalpro-sidebar-nav-group__dot" aria-hidden="true"></span>'
            . '<span class="nav-link-text legalpro-sidebar-nav__label">' . htmlspecialchars($title) . '</span>'
            . $badge
            . '</a></li>';
    }

    return '<li class="nav-item">'
        . '<a class="nav-link legalpro-nav-link' . ($active ? ' active' : '') . '" href="' . $url . '" title="' . htmlspecialchars($title) . '" aria-label="' . htmlspecialchars($title) . '">'
        . '<span class="legalpro-sidebar-nav__icon">' . $icon . '</span>'
        . '<span class="nav-link-text legalpro-sidebar-nav__label">' . htmlspecialchars($title) . '</span>'
        . $badge
        . '</a></li>';
}

function legalpro_portal_nav_render_group(array $item, string $currentPage, ?callable $isActiveFn): string
{
    $title = legalpro_portal_nav_resolve_title($item);
    $groupOpen = legalpro_portal_nav_group_is_open($item, $currentPage, $isActiveFn);
    $groupActive = legalpro_portal_nav_item_is_active($item, $currentPage, $isActiveFn) || $groupOpen;
    $childrenHtml = '';
    foreach ($item['children'] as $child) {
        $childrenHtml .= legalpro_portal_nav_render_link($child, $currentPage, $isActiveFn, true);
    }

    return '<li class="nav-item legalpro-sidebar-nav-group' . ($groupOpen ? ' is-open' : '') . ($groupActive ? ' is-active' : '') . '">'
        . '<button type="button" class="nav-link legalpro-sidebar-nav-group__toggle legalpro-nav-link' . ($groupActive ? ' active' : '') . '"'
        . ' aria-expanded="' . ($groupOpen ? 'true' : 'false') . '"'
        . ' aria-controls="nav-group-' . htmlspecialchars((string) $item['id']) . '">'
        . '<span class="legalpro-sidebar-nav__icon">' . legalpro_icon((string) ($item['icon'] ?? 'folder')) . '</span>'
        . '<span class="nav-link-text legalpro-sidebar-nav__label">' . htmlspecialchars($title) . '</span>'
        . '<span class="legalpro-sidebar-nav-group__chevron" aria-hidden="true">' . legalpro_icon('chevron-down') . '</span>'
        . '</button>'
        . '<ul class="legalpro-sidebar-nav-group__items" id="nav-group-' . htmlspecialchars((string) $item['id']) . '">' . $childrenHtml . '</ul>'
        . '</li>';
}

function legalpro_portal_nav_render_items(array $items, string $currentPage, ?callable $isActiveFn): string
{
    $html = '';
    foreach ($items as $item) {
        if (!empty($item['children'])) {
            $html .= legalpro_portal_nav_render_group($item, $currentPage, $isActiveFn);
        } else {
            $html .= legalpro_portal_nav_render_link($item, $currentPage, $isActiveFn);
        }
    }

    return $html;
}

function legalpro_portal_nav_render_sections(array $sections, string $currentPage, ?callable $isActiveFn): string
{
    $html = '';
    foreach ($sections as $section) {
        $label = trim((string) ($section['label'] ?? ''));
        $items = $section['items'] ?? [];
        if (empty($items)) {
            continue;
        }
        if ($label !== '') {
            $html .= '<li class="legalpro-nav-section" aria-hidden="true"><span class="legalpro-nav-section__label">' . htmlspecialchars($label) . '</span></li>';
        }
        $html .= legalpro_portal_nav_render_items($items, $currentPage, $isActiveFn);
    }

    return $html;
}

function legalpro_portal_nav_render_collapsed_rail(array $items, string $currentPage, ?callable $isActiveFn): string
{
    $html = '';
    foreach ($items as $item) {
        if (!empty($item['children'])) {
            $railUrl = $item['children'][0]['url'] ?? '#';
            $active = legalpro_portal_nav_group_is_open($item, $currentPage, $isActiveFn)
                || legalpro_portal_nav_item_is_active($item, $currentPage, $isActiveFn);
        } else {
            $railUrl = $item['url'] ?? '#';
            $active = legalpro_portal_nav_item_is_active($item, $currentPage, $isActiveFn);
        }
        $title = legalpro_portal_nav_resolve_title($item);
        $html .= '<a class="legalpro-sidebar-collapsed-rail__link' . ($active ? ' active' : '') . '"'
            . ' href="' . htmlspecialchars((string) $railUrl) . '"'
            . ' title="' . htmlspecialchars($title) . '"'
            . ' aria-label="' . htmlspecialchars($title) . '">'
            . '<span class="legalpro-sidebar-collapsed-rail__icon">' . legalpro_icon((string) ($item['icon'] ?? 'circle')) . '</span>'
            . '</a>';
    }

    return $html;
}

function legalpro_portal_nav_flatten_items(array $sectionsOrItems): array
{
    $flat = [];
    foreach ($sectionsOrItems as $entry) {
        if (isset($entry['items']) && is_array($entry['items'])) {
            foreach ($entry['items'] as $item) {
                $flat[] = $item;
            }
        } else {
            $flat[] = $entry;
        }
    }

    return $flat;
}

/**
 * @param array{
 *   portal: string,
 *   home_url: string,
 *   role_label: string,
 *   company_name: string,
 *   logo_url: string,
 *   current_page: string,
 *   sections?: array,
 *   items?: array,
 *   footer_items?: array,
 *   is_active?: callable|null,
 *   compact?: bool,
 *   collapsed_rail?: bool,
 *   body_class?: string,
 *   collapse_storage_key?: string,
 * } $config
 */
function legalpro_render_portal_sidebar(array $config): string
{
    $portal = (string) ($config['portal'] ?? 'admin');
    $homeUrl = htmlspecialchars((string) ($config['home_url'] ?? 'dashboard.php'));
    $roleLabel = htmlspecialchars((string) ($config['role_label'] ?? strtoupper($portal)));
    $companyName = htmlspecialchars((string) ($config['company_name'] ?? 'LegalPro'));
    $logoUrl = htmlspecialchars((string) ($config['logo_url'] ?? ''));
    $currentPage = (string) ($config['current_page'] ?? '');
    $isActiveFn = $config['is_active'] ?? null;
    $compact = !empty($config['compact']);
    $collapsedRail = array_key_exists('collapsed_rail', $config) ? (bool) $config['collapsed_rail'] : true;
    $bodyClass = (string) ($config['body_class'] ?? '');
    $storageKey = (string) ($config['collapse_storage_key'] ?? 'legalproSidebarCollapsed');

    $sections = $config['sections'] ?? [];
    $items = $config['items'] ?? [];
    $footerItems = $config['footer_items'] ?? [];

    if (!empty($sections)) {
        $navHtml = legalpro_portal_nav_render_sections($sections, $currentPage, $isActiveFn);
        $railItems = legalpro_portal_nav_flatten_items($sections);
    } else {
        $navHtml = legalpro_portal_nav_render_items($items, $currentPage, $isActiveFn);
        $railItems = $items;
    }

    $footerHtml = '';
    if (!empty($footerItems)) {
        $footerHtml = '<div class="legalpro-sidebar-footer">';
        foreach ($footerItems as $item) {
            $title = legalpro_portal_nav_resolve_title($item);
            $active = legalpro_portal_nav_item_is_active($item, $currentPage, $isActiveFn);
            $footerHtml .= '<a class="nav-link legalpro-nav-link' . ($active ? ' active' : '') . '" href="' . htmlspecialchars((string) $item['url']) . '">'
                . '<span class="legalpro-sidebar-nav__icon">' . legalpro_icon((string) ($item['icon'] ?? 'settings')) . '</span>'
                . '<span class="nav-link-text legalpro-sidebar-nav__label">' . htmlspecialchars($title) . '</span>'
                . '</a>';
        }
        $footerHtml .= '</div>';
    }

    $railHtml = $collapsedRail
        ? '<nav class="legalpro-sidebar-collapsed-rail" id="legalpro-sidebar-collapsed-rail" aria-label="Collapsed navigation">'
            . legalpro_portal_nav_render_collapsed_rail($railItems, $currentPage, $isActiveFn)
            . '</nav>'
        : '';

    $asideClass = 'sidenav navbar navbar-vertical navbar-expand-xs fixed-start legalpro-admin-sidebar legalpro-modern-sidebar'
        . ($compact ? ' legalpro-admin-sidebar--compact' : '')
        . ($bodyClass !== '' ? ' ' . htmlspecialchars($bodyClass) : '');

    return '<aside class="' . $asideClass . '" id="sidenav-main" data-legalpro-portal="' . htmlspecialchars($portal) . '">'
        . '<div class="legalpro-sidebar-brand">'
        . '<a href="' . $homeUrl . '" class="legalpro-sidebar-brand__link">'
        . '<img src="' . $logoUrl . '" width="38" height="38" alt="' . $companyName . ' logo" class="legalpro-sidebar-brand__logo">'
        . '<span class="legalpro-sidebar-brand__text">'
        . '<span class="legalpro-sidebar-brand__name">' . $companyName . '</span>'
        . '<span class="legalpro-sidebar-brand__role">' . $roleLabel . '</span>'
        . '</span>'
        . '</a>'
        . '<button type="button" class="legalpro-sidebar-collapse btn btn-link p-0 d-none d-xl-inline-flex" id="legalproSidebarCollapse" aria-label="Collapse sidebar" aria-expanded="true">'
        . legalpro_icon('chevron-left')
        . '</button>'
        . '<button type="button" class="legalpro-sidebar-close d-xl-none" id="iconSidenav" aria-label="Close navigation">'
        . legalpro_icon('x')
        . '</button>'
        . '</div>'
        . '<div class="collapse show navbar-collapse w-100 legalpro-sidebar-nav-wrap legalpro-sidebar-nav-wrap--expanded" id="sidenav-collapse-main">'
        . '<ul class="navbar-nav legalpro-sidebar-nav">' . $navHtml . '</ul>'
        . '</div>'
        . $footerHtml
        . $railHtml
        . '</aside>'
        . '<script>document.documentElement.classList.add("legalpro-modern-nav-ready");</script>'
        . '<script src="../assets/js/legalpro-sidebar.js?v=5"></script>';
}

function legalpro_portal_nav_bootstrap_body(string $portal, string $storageKey = ''): string
{
    $storageKey = $storageKey !== '' ? $storageKey : 'legalpro' . ucfirst($portal) . 'SidebarCollapsed';
    if ($portal === 'admin') {
        $storageKey = 'legalproAdminSidebarCollapsed';
    }

    return '<script>(function(){var b=document.body;if(!b)return;b.classList.add("legalpro-modern-nav");b.classList.remove("g-sidenav-hidden");'
        . 'try{var k="' . addslashes($storageKey) . '";if(window.localStorage.getItem(k)==="1"){b.classList.add("legalpro-sidebar-collapsed");}}catch(e){}'
        . 'if(window.innerWidth>=1200){b.classList.add("g-sidenav-pinned","g-sidenav-show");}})();</script>';
}
