<?php
/**
 * Render client sidebar into nowdoc-built page templates.
 * (PHP include tags inside <<<'HTML' blocks are not executed.)
 */

function inject_client_sidebar(string $html): string
{
    static $sidebarHtml = null;

    if ($sidebarHtml === null) {
        ob_start();
        include __DIR__ . '/client-menunav.php';
        $sidebarHtml = ob_get_clean();
    }

    $marker = "<?php include __DIR__ . '/../inc/client-menunav.php'; ?>";

    return str_replace($marker, $sidebarHtml, $html);
}
