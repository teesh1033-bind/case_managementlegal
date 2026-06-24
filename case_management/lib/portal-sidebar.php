<?php

/**
 * Unified left sidebar for admin, client, and lawyer portals.
 * Renders only the <aside> — header utilities stay in menunav files.
 */

require_once dirname(__DIR__) . '/inc/legalpro-icons.php';
require_once dirname(__DIR__) . '/lib/portal-theme.php';

function legalpro_sidebar_stylesheet_tag(): string
{
    return '<link href="../assets/css/legalpro-sidebar-nav.css?v=22" rel="stylesheet" />';
}

function legalpro_sidebar_resolve_label(array $item): string
{
    if (!empty($item['title'])) {
        return (string) $item['title'];
    }
    if (!empty($item['title_key']) && function_exists('client_t')) {
        $translated = client_t((string) $item['title_key']);
        if ($translated !== $item['title_key']) {
            return $translated;
        }
    }

    return (string) ($item['fallback'] ?? $item['title_key'] ?? 'Link');
}

function legalpro_sidebar_item_active(array $item, string $currentPage, ?callable $isActiveFn): bool
{
    if ($isActiveFn) {
        return (bool) $isActiveFn((string) ($item['id'] ?? ''), $currentPage);
    }

    return ($item['id'] ?? '') === $currentPage;
}

function legalpro_sidebar_render_items(array $items, string $currentPage, ?callable $isActiveFn): string
{
    $html = '';
    foreach ($items as $item) {
        if (!empty($item['section'])) {
            $html .= '<li class="lp-sidebar__section" aria-hidden="true"><span>'
                . htmlspecialchars((string) $item['section'])
                . '</span></li>';
        }
        $label = legalpro_sidebar_resolve_label($item);
        $active = legalpro_sidebar_item_active($item, $currentPage, $isActiveFn);
        $url = htmlspecialchars((string) ($item['url'] ?? '#'));
        $icon = !empty($item['icon']) ? legalpro_icon((string) $item['icon']) : '';

        $html .= '<li class="nav-item">'
            . '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . $url . '" title="' . htmlspecialchars($label) . '">'
            . '<span class="legalpro-sidebar-nav__icon">' . $icon . '</span>'
            . '<span class="nav-link-text legalpro-sidebar-nav__label">' . htmlspecialchars($label) . '</span>'
            . '</a></li>';
    }

    return $html;
}

/**
 * @param array{
 *   portal: string,
 *   home_url: string,
 *   role_label: string,
 *   company_name: string,
 *   logo_url: string,
 *   current_page: string,
 *   items: array,
 *   footer_items?: array,
 *   is_active?: callable|null,
 *   compact?: bool,
 *   storage_key?: string,
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
    $items = $config['items'] ?? [];
    $footerItems = $config['footer_items'] ?? [];
    $isActiveFn = $config['is_active'] ?? null;
    $compact = !empty($config['compact']);
    $storageKey = (string) ($config['storage_key'] ?? legalpro_sidebar_storage_key($portal));

    $asideClass = 'legalpro-admin-sidebar lp-sidebar fixed-start';
    if ($compact) {
        $asideClass .= ' legalpro-admin-sidebar--compact';
    }

    $navHtml = legalpro_sidebar_render_items($items, $currentPage, $isActiveFn);

    $footerHtml = '';
    if (!empty($footerItems)) {
        $footerHtml = '<div class="legalpro-sidebar-footer">';
        foreach ($footerItems as $item) {
            $label = legalpro_sidebar_resolve_label($item);
            $active = legalpro_sidebar_item_active($item, $currentPage, $isActiveFn);
            $footerHtml .= '<a class="nav-link' . ($active ? ' active' : '') . '" href="'
                . htmlspecialchars((string) ($item['url'] ?? '#')) . '">'
                . '<span class="legalpro-sidebar-nav__icon">' . legalpro_icon((string) ($item['icon'] ?? 'circle')) . '</span>'
                . '<span class="nav-link-text legalpro-sidebar-nav__label">' . htmlspecialchars($label) . '</span>'
                . '</a>';
        }
        $footerHtml .= '</div>';
    }

    $boot = htmlspecialchars($storageKey, ENT_QUOTES, 'UTF-8');

    return renderPortalSidebarPaintBlock()
        . '<aside id="sidenav-main" class="' . $asideClass . '" data-lp-portal="' . htmlspecialchars($portal) . '" aria-label="Main navigation">'
        . '<div class="legalpro-sidebar-brand">'
        . '<a href="' . $homeUrl . '" class="legalpro-sidebar-brand__link">'
        . '<img src="' . $logoUrl . '" width="40" height="40" alt="' . $companyName . ' logo" class="legalpro-sidebar-brand__logo" loading="eager" decoding="async">'
        . '<span class="legalpro-sidebar-brand__text">'
        . '<span class="legalpro-sidebar-brand__name">' . $companyName . '</span>'
        . '<span class="legalpro-sidebar-brand__role">' . $roleLabel . '</span>'
        . '</span></a>'
        . '<button type="button" class="lp-sidebar__collapse-btn d-none d-xl-inline-flex" id="legalproSidebarCollapse" aria-label="Collapse sidebar" aria-expanded="true">'
        . legalpro_icon('chevron-left')
        . '</button>'
        . '<button type="button" class="lp-sidebar__close-btn d-xl-none" id="iconSidenav" aria-label="Close navigation">'
        . legalpro_icon('x')
        . '</button>'
        . '</div>'
        . '<div class="lp-sidebar__scroll" id="lp-sidebar-scroll">'
        . '<ul class="navbar-nav legalpro-sidebar-nav">' . $navHtml . '</ul>'
        . '</div>'
        . $footerHtml
        . '</aside>'
        . '<script>(function(){var s=document.getElementById("sidenav-main");if(s&&typeof lucide!=="undefined"){lucide.createIcons({attrs:{"stroke-width":1.75},nameAttr:"data-lucide",root:s});}})();</script>'
        . '<script>(function(){var b=document.body;if(!b)return;b.classList.remove("g-sidenav-hidden");b.classList.add("g-sidenav-show");'
        . 'try{if(localStorage.getItem("' . $boot . '")==="1"){b.classList.add("legalpro-sidebar-collapsed");}}catch(e){}'
        . 'if(window.innerWidth>=1200){b.classList.add("g-sidenav-pinned");}})();</script>'
        . '<script src="../assets/js/legalpro-sidebar.js?v=6" defer></script>';
}

function legalpro_sidebar_storage_key(string $portal): string
{
    $map = [
        'admin' => 'legalproAdminSidebarCollapsed',
        'client' => 'legalproClientSidebarCollapsed',
        'lawyer' => 'legalproLawyerSidebarCollapsed',
    ];

    return $map[$portal] ?? 'legalproSidebarCollapsed';
}
