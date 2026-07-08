<?php
/**
 * Standard lawyer portal top navbar (matches client-settings layout).
 */

require_once __DIR__ . '/../lib/lawyer-locale.php';
require_once __DIR__ . '/admin-layout.php';

function legalpro_render_lawyer_page_navbar(
    string $pageTitle,
    string $breadcrumbActive = '',
    array $options = []
): string {
    if ($breadcrumbActive === '') {
        $breadcrumbActive = $pageTitle;
    }

    $subtitle = (string) ($options['subtitle'] ?? '');
    unset($options['subtitle']);

    return legalpro_render_portal_page_navbar($pageTitle, $subtitle, $options);
}
