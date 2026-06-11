<?php
/**
 * Render client sidebar into nowdoc-built page templates.
 * (PHP include tags inside <<<'HTML' blocks are not executed.)
 */

function inject_client_sidebar(string $html): string
{
    ob_start();
    include __DIR__ . '/client-menunav.php';
    $sidebarHtml = ob_get_clean();

    $marker = "<?php include __DIR__ . '/../inc/client-menunav.php'; ?>";

    $html = str_replace($marker, $sidebarHtml, $html);
    $html = str_replace('{PORTAL_THEME_BODY_CLASS}', legalpro_portal_theme_body_class(), $html);

    return $html;
}
