<?php
declare(strict_types=1);

/**
 * Shared hero / panel chrome for client portal list pages.
 */
function client_portal_render_hero(array $opts): string
{
    if (!function_exists('legalpro_icon')) {
        require_once __DIR__ . '/../inc/legalpro-icons.php';
    }

    $kicker = htmlspecialchars((string) ($opts['kicker'] ?? (function_exists('client_t') ? client_t('common.client_portal') : 'Client portal')));
    $title = htmlspecialchars((string) ($opts['title'] ?? ''));
    $subtitle = isset($opts['subtitle']) ? htmlspecialchars((string) $opts['subtitle']) : '';
    $meta = isset($opts['meta']) && $opts['meta'] !== ''
        ? '<p class="cp-hero-meta">' . htmlspecialchars((string) $opts['meta']) . '</p>'
        : '';
    $aria = htmlspecialchars((string) ($opts['aria_label'] ?? $title));

    $dateHtml = '';
    if (!empty($opts['show_date'])) {
        $dateHtml = '<span class="cp-hero-date">'
            . legalpro_icon('calendar')
            . htmlspecialchars(date('l, F j'))
            . '</span>';
    }

    $statsHtml = '';
    foreach ($opts['stats'] ?? [] as $stat) {
        $num = htmlspecialchars((string) ($stat['num'] ?? ''));
        $lbl = htmlspecialchars((string) ($stat['lbl'] ?? ''));
        $statsHtml .= '<div class="cp-stat-pill"><div class="num">' . $num . '</div><div class="lbl">' . $lbl . '</div></div>';
    }
    $statsBlock = $statsHtml !== '' ? '<div class="cp-hero-stats">' . $statsHtml . '</div>' : '';

    $actionsHtml = '';
    foreach ($opts['actions'] ?? [] as $action) {
        $url = htmlspecialchars((string) ($action['url'] ?? '#'));
        $label = htmlspecialchars((string) ($action['label'] ?? ''));
        $cls = !empty($action['primary']) ? 'btn btn-primary-solid' : 'btn btn-ghost';
        $icon = !empty($action['icon']) ? legalpro_icon((string) $action['icon']) . ' ' : '';
        $actionsHtml .= '<a href="' . $url . '" class="' . $cls . '">' . $icon . $label . '</a>';
    }
    $actionsBlock = $actionsHtml !== '' ? '<div class="cp-hero-actions">' . $actionsHtml . '</div>' : '';

    $subBlock = $subtitle !== '' ? '<p class="cp-hero-sub">' . $subtitle . '</p>' : '';

    return '<section class="cp-hero-card" aria-label="' . $aria . '">'
        . '<div class="cp-hero-main">'
        . '<div class="cp-hero-top">'
        . '<p class="cp-hero-kicker">' . $kicker . '</p>'
        . $dateHtml
        . '</div>'
        . '<h1 class="cp-hero-title">' . $title . '</h1>'
        . $subBlock
        . $meta
        . $statsBlock
        . '</div>'
        . $actionsBlock
        . '</section>';
}

function client_portal_render_panel_header(array $opts): string
{
    if (!function_exists('legalpro_icon')) {
        require_once __DIR__ . '/../inc/legalpro-icons.php';
    }

    $title = htmlspecialchars((string) ($opts['title'] ?? ''));
    $subtitle = isset($opts['subtitle']) ? htmlspecialchars((string) $opts['subtitle']) : '';
    $icon = !empty($opts['icon']) ? legalpro_icon((string) $opts['icon']) : '';
    $badge = isset($opts['badge']) && $opts['badge'] !== ''
        ? '<span class="cp-row-count"' . (!empty($opts['badge_id']) ? ' id="' . htmlspecialchars((string) $opts['badge_id']) . '"' : '') . '>'
            . htmlspecialchars((string) $opts['badge'])
            . '</span>'
        : '';

    $linksHtml = '';
    foreach ($opts['links'] ?? [] as $link) {
        $linksHtml .= '<a href="'
            . htmlspecialchars((string) ($link['url'] ?? '#'))
            . '" class="btn-cp-link">'
            . htmlspecialchars((string) ($link['label'] ?? ''))
            . '</a>';
    }

    $iconBlock = $icon !== '' ? '<div class="cp-panel-header__icon">' . $icon . '</div>' : '';
    $subBlock = $subtitle !== '' ? '<p>' . $subtitle . '</p>' : '';

    return '<div class="cp-panel-header">'
        . '<div class="cp-panel-header__left">'
        . $iconBlock
        . '<div><h5>' . $title . '</h5>' . $subBlock . '</div>'
        . '</div>'
        . '<div class="cp-panel-header__right">'
        . $badge
        . $linksHtml
        . '</div>'
        . '</div>';
}

function client_portal_render_panel(array $opts, string $bodyHtml): string
{
    $idAttr = !empty($opts['panel_id'])
        ? ' id="' . htmlspecialchars((string) $opts['panel_id']) . '"'
        : '';

    return '<section class="cp-panel"' . $idAttr . '>'
        . client_portal_render_panel_header($opts)
        . '<div class="cp-panel-body">' . $bodyHtml . '</div>'
        . '</section>';
}
