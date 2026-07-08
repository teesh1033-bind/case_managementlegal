<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/documents-portal.php';

$state = legalpro_documents_portal_init_state();
legalpro_documents_portal_bootstrap($pdo, $state);
legalpro_documents_portal_handle_post($pdo, 'document-browse', $state);
legalpro_documents_portal_load($pdo, $state);

$content = legalpro_documents_render_browse_workspace_html($state);

legalpro_documents_render_page('document-browse', $content, $state, '<script>' . legalpro_documents_browse_script() . '</script>');
