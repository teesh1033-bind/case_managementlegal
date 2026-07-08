<?php

require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../inc/admin-layout.php';

function legalpro_admin_documents_nav(): array
{
    return [
        'documents' => [
            'file' => 'documents.php',
            'title' => 'Overview',
            'heading' => 'Documents Overview',
            'subtitle' => 'Your document command center — upload, template, generate, and browse in one place.',
        ],
        'document-upload' => [
            'file' => 'document-upload.php',
            'title' => 'Upload',
            'heading' => 'Upload Document',
            'subtitle' => 'Attach files to any case in seconds.',
        ],
        'document-templates' => [
            'file' => 'document-templates.php',
            'title' => 'Templates',
            'heading' => 'Template Library',
            'subtitle' => 'Build reusable legal drafts with smart placeholders.',
        ],
        'document-generate' => [
            'file' => 'document-generate.php',
            'title' => 'Generate',
            'heading' => 'Generate Document',
            'subtitle' => 'Merge templates with live case data instantly.',
        ],
        'document-browse' => [
            'file' => 'document-browse.php',
            'title' => 'Browse',
            'heading' => 'Browse Documents',
            'subtitle' => 'Search and review every file across your matters.',
        ],
    ];
}

function legalpro_admin_documents_page_key(): string
{
    $current = basename($_SERVER['PHP_SELF'], '.php');

    return array_key_exists($current, legalpro_admin_documents_nav()) ? $current : 'documents';
}

function legalpro_admin_documents_subnav_html(string $activeKey): string
{
    $nav = legalpro_admin_documents_nav();
    $html = '<nav class="legalpro-doc-subnav" aria-label="Documents sections">';
    foreach ($nav as $key => $item) {
        $active = $key === $activeKey ? ' is-active' : '';
        $html .= '<a class="legalpro-doc-subnav__link' . $active . '" href="' . htmlspecialchars($item['file']) . '">'
            . htmlspecialchars($item['title']) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function legalpro_admin_documents_message_html(array $state): string
{
    if (empty($state['message'])) {
        return '';
    }

    $type = $state['messageType'] !== '' ? $state['messageType'] : 'info';

    return '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($state['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

function legalpro_admin_documents_overview_html(array $state): string
{
    $nav = legalpro_admin_documents_nav();
    $actions = [
        'document-upload' => ['icon' => 'upload', 'tone' => 'primary', 'blurb' => 'Attach evidence, contracts, and scans to any matter.'],
        'document-templates' => ['icon' => 'files', 'tone' => 'info', 'blurb' => 'Save retainer letters, affidavits, and more for reuse.'],
        'document-generate' => ['icon' => 'file-text', 'tone' => 'dark', 'blurb' => 'Auto-fill drafts with client and case details.'],
        'document-browse' => ['icon' => 'folder-open', 'tone' => 'success', 'blurb' => 'Filter files by case and download in one click.'],
    ];

    $cards = '';
    foreach ($actions as $key => $meta) {
        $page = $nav[$key];
        $cards .= '
        <div class="col-md-6 col-xl-3">
            <a href="' . htmlspecialchars($page['file']) . '" class="legalpro-doc-action-card legalpro-doc-action-card--' . $meta['tone'] . '">
                <span class="legalpro-doc-action-card__icon">' . legalpro_icon($meta['icon']) . '</span>
                <span class="legalpro-doc-action-card__title">' . htmlspecialchars($page['title']) . '</span>
                <span class="legalpro-doc-action-card__text">' . htmlspecialchars($meta['blurb']) . '</span>
                <span class="legalpro-doc-action-card__cta">Open &rarr;</span>
            </a>
        </div>';
    }

    $recent = '';
    if (empty($state['recentDocuments'])) {
        $recent = '<p class="text-muted text-sm mb-0">No uploads yet. <a href="document-upload.php">Upload your first document</a>.</p>';
    } else {
        foreach (array_slice($state['recentDocuments'], 0, 4) as $doc) {
            $name = !empty($doc['label']) ? $doc['label'] : $doc['filename'];
            $caseTitle = !empty($doc['case_title']) ? $doc['case_title'] : 'Unassigned';
            $url = !empty($doc['filepath']) ? '../' . ltrim($doc['filepath'], '/') : '#';
            $recent .= '
            <div class="legalpro-doc-recent-item">
                ' . legalpro_document_file_icon_wrap($doc['filename'], 'legalpro-doc-recent-item__icon') . '
                <div class="legalpro-doc-recent-item__body">
                    <strong>' . htmlspecialchars($name) . '</strong>
                    <span>' . htmlspecialchars($caseTitle) . '</span>
                </div>
                <a href="' . htmlspecialchars($url) . '" class="btn btn-sm lp-portal-accent-btn mb-0" target="_blank" rel="noopener">View</a>
            </div>';
        }
    }

    return '
    <div class="legalpro-doc-hero mb-4">
        <div class="legalpro-doc-hero__content">
            <p class="legalpro-doc-hero__eyebrow">Document workspace</p>
            <h4 class="legalpro-doc-hero__title">Everything your firm files — organized</h4>
            <p class="legalpro-doc-hero__lead">Store case files, maintain templates, and produce client-ready drafts without leaving LegalPro.</p>
        </div>
        <div class="legalpro-doc-hero__stats">
            <div class="legalpro-doc-hero__stat"><span>' . number_format((int) $state['totalDocuments']) . '</span><small>Files stored</small></div>
            <div class="legalpro-doc-hero__stat"><span>' . (int) $state['totalTemplates'] . '</span><small>Templates</small></div>
            <div class="legalpro-doc-hero__stat"><span>' . (int) $state['casesWithDocs'] . '</span><small>Cases with docs</small></div>
        </div>
    </div>
    <div class="row g-3 mb-4">' . $cards . '</div>
    <div class="card legalpro-doc-panel">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <div>
                <h6 class="mb-0">Latest activity</h6>
                <p class="text-sm text-muted mb-0">Recently uploaded files</p>
            </div>
            <a href="document-browse.php" class="btn btn-sm btn-outline-dark mb-0">Browse all</a>
        </div>
        <div class="card-body">' . $recent . '</div>
    </div>';
}

function legalpro_admin_documents_templates_grid_html(array $state): string
{
    if (empty($state['templates'])) {
        return '<div class="legalpro-doc-empty"><div class="legalpro-doc-empty__icon">' . legalpro_icon('files') . '</div><p>No templates yet. Create your first reusable draft below.</p></div>';
    }

    $html = '<div class="row g-3 mb-4">';
    foreach ($state['templates'] as $template) {
        $html .= '
        <div class="col-md-6 col-xl-4">
            <article class="legalpro-doc-template-card">
                <div class="legalpro-doc-template-card__icon">' . legalpro_icon('file-text') . '</div>
                <h6>' . htmlspecialchars($template['name']) . '</h6>
                <p>' . htmlspecialchars($template['description'] ?? 'No description') . '</p>
                <small>Updated ' . htmlspecialchars(date('d M Y', strtotime($template['updated_at']))) . '</small>
            </article>
        </div>';
    }
    $html .= '</div>';

    return $html;
}

function legalpro_admin_documents_render(string $pageKey, string $contentHtml, array $state = [], string $extraScripts = ''): void
{
    $nav = legalpro_admin_documents_nav();
    $page = $nav[$pageKey] ?? $nav['documents'];
    $bodyClass = function_exists('legalpro_portal_theme_body_class') ? legalpro_portal_theme_body_class() : '';
    $messageHtml = legalpro_admin_documents_message_html($state);
    $subnavHtml = legalpro_admin_documents_subnav_html($pageKey);

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LegalPro · ' . htmlspecialchars($page['heading']) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="../assets/css/legalpro-icons.css?v=2" rel="stylesheet" />
    <link href="../assets/css/legalpro-documents-hub.css?v=5" rel="stylesheet" />';

    ob_start();
    include dirname(__DIR__) . '/inc/admin-portal-head.php';
    $html .= ob_get_clean();

    $html .= '
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-documents-hub' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav navbar navbar-vertical navbar-expand-xs fixed-start" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        ' . legalpro_render_admin_page_navbar($page['heading'], (string) ($page['subtitle'] ?? '')) . '
        <div class="container-fluid py-4">
            ' . $messageHtml . '
            <div class="legalpro-doc-page-head mb-3">
                <div>
                    <h5 class="mb-1">' . htmlspecialchars($page['heading']) . '</h5>
                    <p class="text-sm text-muted mb-0">' . htmlspecialchars($page['subtitle']) . '</p>
                </div>
            </div>
            ' . $subnavHtml . '
            <div class="legalpro-doc-page-content">' . $contentHtml . '</div>
        </div>
    </main>
    ' . $extraScripts . '
</body>
</html>';

    $html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
    ob_start();
    include dirname(__DIR__) . '/inc/menunav.php';
    $sidebar = ob_get_clean();
    $html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
    ob_start();
    include dirname(__DIR__) . '/inc/footer.php';
    $footer = ob_get_clean();
    $html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

    echo legalpro_apply_copyright_line($html);
}
