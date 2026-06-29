<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/documents-portal.php';

$state = legalpro_documents_portal_init_state();
legalpro_documents_portal_bootstrap($pdo, $state);
legalpro_documents_portal_handle_post($pdo, 'document-templates', $state);
legalpro_documents_portal_load($pdo, $state);

$content = '
<div class="card">
    <div class="card-header pb-0">
        <h6 class="mb-0">Template Library</h6>
        <p class="text-sm text-muted mb-0">Reusable legal drafts with merge placeholders.</p>
    </div>
    <div class="card-body px-0 pt-0 pb-0">
        <div class="lp-admin-table-paginate" data-lp-admin-paginate data-lp-per-page="10" data-lp-row=".legalpro-admin-list-row">
        <div class="table-responsive">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Template</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>' . $state['templatesRows'] . '</tbody>
            </table>
        </div>
        <nav class="lp-admin-pagination" data-lp-pagination-nav aria-label="Templates pagination" hidden><p class="lp-admin-pagination__info" data-lp-range></p><div class="lp-admin-pagination__controls" data-lp-pages></div></nav>
        </div>
        <hr class="my-3">
        <div class="px-3 pb-3">
            <h6 class="text-sm mb-2">Add Template</h6>
            <form method="post">
                <input type="hidden" name="form_type" value="template">
                <div class="mb-2">
                    <input type="text" class="form-control" name="template_name" placeholder="Template Name" required>
                </div>
                <div class="mb-2">
                    <input type="text" class="form-control" name="template_description" placeholder="Short description">
                </div>
                <div class="mb-3">
                    <textarea class="form-control" rows="4" name="template_body" placeholder="Use placeholders like {{client_name}}, {{case_number}}" required></textarea>
                </div>
                <button class="btn btn-sm btn-dark">Save Template</button>
            </form>
        </div>
    </div>
</div>';

legalpro_documents_render_page('document-templates', $content, $state);
