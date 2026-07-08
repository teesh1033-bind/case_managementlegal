<?php
/**
 * Standard client portal top navbar (matches client-appointments.php).
 */

require_once __DIR__ . '/admin-layout.php';
require_once __DIR__ . '/../lib/client-locale.php';

function legalpro_client_page_search_query(): string
{
    return isset($_GET['q']) ? trim((string) $_GET['q']) : '';
}

/** Navbar options: scope search to the current page (GET ?q=). */
function legalpro_client_page_search_options(string $searchAction, ?string $searchValue = null): array
{
    return [
        'search_action' => $searchAction,
        'search_value' => $searchValue ?? legalpro_client_page_search_query(),
    ];
}

function legalpro_client_search_data_attr(array $parts): string
{
    $hay = strtolower(trim(implode(' ', array_filter(array_map(static function ($part) {
        return trim((string) $part);
    }, $parts), static function ($part) {
        return $part !== '';
    }))));

    return ' data-search="' . htmlspecialchars($hay, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Filter rows with data-search on the current page using ?q= from the navbar form.
 */
function legalpro_render_client_page_search_script(
    string $rowSelector,
    string $countSelector = '',
    string $countSingular = 'item',
    string $countPlural = 'items',
    string $countSuffix = ''
): string {
    $rowSel = json_encode($rowSelector);
    $countSel = json_encode($countSelector);
    $singular = json_encode($countSingular);
    $plural = json_encode($countPlural);
    $suffix = json_encode($countSuffix);

    return '<script>
(function () {
    function applyClientPageSearch() {
        var params = new URLSearchParams(window.location.search);
        var q = (params.get("q") || "").trim().toLowerCase();
        var rows = document.querySelectorAll(' . $rowSel . ');
        var visible = 0;
        rows.forEach(function (row) {
            if (!q) {
                row.style.display = "";
                visible++;
                return;
            }
            var hay = (row.getAttribute("data-search") || row.textContent || "").toLowerCase();
            var show = hay.indexOf(q) !== -1;
            row.style.display = show ? "" : "none";
            if (show) visible++;
        });
        var countEl = ' . $countSel . ' ? document.querySelector(' . $countSel . ') : null;
        if (countEl) {
            countEl.textContent = visible + " " + (visible === 1 ? ' . $singular . ' : ' . $plural . ') + ' . $suffix . ';
        }
    }
    document.addEventListener("DOMContentLoaded", applyClientPageSearch);
})();
</script>';
}

function legalpro_render_client_page_navbar(
    string $pageTitle,
    string $breadcrumbActive = '',
    string $searchPlaceholder = '',
    array $options = []
): string {
    if ($breadcrumbActive === '') {
        $breadcrumbActive = $pageTitle;
    }

    $subtitle = (string) ($options['subtitle'] ?? '');
    unset($options['subtitle']);

    return legalpro_render_portal_page_navbar($pageTitle, $subtitle, $options);
}
