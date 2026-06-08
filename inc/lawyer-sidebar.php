<?php
/**
 * Render lawyer sidebar and portal head into nowdoc-built page templates.
 * (PHP include tags inside <<<'HTML' blocks are not executed.)
 */

function inject_lawyer_portal_head(string $html): string
{
    static $headHtml = null;

    if ($headHtml === null) {
        ob_start();
        include __DIR__ . '/lawyer-portal-head.php';
        $headHtml = ob_get_clean();
    }

    $marker = "<?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>";

    return str_replace($marker, $headHtml, $html);
}

function inject_lawyer_sidebar(string $html): string
{
    static $sidebarHtml = null;

    if ($sidebarHtml === null) {
        ob_start();
        include __DIR__ . '/lawyer-menunav.php';
        $sidebarHtml = ob_get_clean();
    }

    $marker = "<?php include __DIR__ . '/../inc/lawyer-menunav.php'; ?>";

    return str_replace($marker, $sidebarHtml, $html);
}
