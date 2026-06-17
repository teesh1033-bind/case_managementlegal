<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/documents-portal.php';

$state = legalpro_documents_portal_init_state();
legalpro_documents_portal_bootstrap($pdo, $state);
legalpro_documents_portal_load($pdo, $state);

$content = legalpro_documents_stats_row_html($state)
    . '<div class="card"><div class="card-header pb-0"><h6 class="mb-0">Document Workspace</h6>'
    . '<p class="text-sm text-muted mb-0">Choose a section below to upload, manage templates, generate drafts, or browse files.</p></div>'
    . '<div class="card-body">' . legalpro_documents_hub_cards_html() . '</div></div>';

legalpro_documents_render_page('documents', $content, $state);
