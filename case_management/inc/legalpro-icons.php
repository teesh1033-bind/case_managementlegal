<?php
/**
 * LegalPro outline icons (Lucide) — Eagle-style thin line icons across portals.
 */

function legalpro_icon(string $name, string $extraClass = ''): string
{
    $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
    if ($name === '') {
        return '';
    }

    $class = 'lp-icon';
    if (trim($extraClass) !== '') {
        $class .= ' ' . trim($extraClass);
    }

    return '<i data-lucide="' . htmlspecialchars($name) . '" class="' . htmlspecialchars($class) . '" aria-hidden="true"></i>';
}

function legalpro_icons_asset_links(): void
{
    if (defined('LEGALPRO_ICONS_HEAD')) {
        return;
    }
    define('LEGALPRO_ICONS_HEAD', true);
    echo '<link href="../assets/css/legalpro-icons.css?v=1" rel="stylesheet" />' . "\n";
}

function legalpro_icons_footer_scripts(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>' . "\n";
    echo '<script>function legalproInitIcons(root){if(typeof lucide==="undefined"){return;}lucide.createIcons({attrs:{"stroke-width":1.75},nameAttr:"data-lucide",root:root||document});}function legalproScheduleIconInit(){if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){legalproInitIcons();});}else{legalproInitIcons();}}legalproScheduleIconInit();document.addEventListener("shown.bs.modal",function(e){legalproInitIcons(e.target);});</script>' . "\n";
}
