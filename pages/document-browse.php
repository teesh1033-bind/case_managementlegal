<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/documents-portal.php';

$state = legalpro_documents_portal_init_state();
legalpro_documents_portal_bootstrap($pdo, $state);
legalpro_documents_portal_handle_post($pdo, 'document-browse', $state);
legalpro_documents_portal_load($pdo, $state);

$content = '
<div class="row">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Recent Documents</h6>
                <p class="text-sm text-muted mb-0">Latest uploaded files</p>
            </div>
            <div class="card-body p-0" style="max-height: 520px; overflow-y: auto;">' . $state['recentDocsList'] . '</div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">Documents by Case</h6>
                <p class="text-sm text-muted mb-0">Review every upload grouped per matter.</p>
            </div>
            <div class="card-body">
                <div class="accordion" id="documentsAccordion">' . $state['documentAccordion'] . '</div>
            </div>
        </div>
    </div>
</div>';

legalpro_documents_render_page('document-browse', $content, $state, '<script>' . legalpro_documents_browse_script() . '</script>');

