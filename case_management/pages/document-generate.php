<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/documents-portal.php';

$state = legalpro_documents_portal_init_state();
legalpro_documents_portal_bootstrap($pdo, $state);
legalpro_documents_portal_handle_post($pdo, 'document-generate', $state);
legalpro_documents_portal_load($pdo, $state);

$content = '
<div class="card mb-4 legalpro-doc-generate-form no-print">
    <div class="card-header pb-0">
        <h6 class="mb-0">Generate Legal Document</h6>
        <p class="text-sm text-muted mb-0">Merge any template with live case data.</p>
    </div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="form_type" value="generate">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Template</label>
                    <select class="form-select" name="template_id" required>' . $state['templateOptions'] . '</select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Case</label>
                    <select class="form-select" name="case_for_template" required>' . $state['caseOptions'] . '</select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Output Title</label>
                <input type="text" class="form-control" name="output_title" placeholder="e.g., Retainer Agreement Draft">
            </div>
            <div class="mb-3">
                <label class="form-label">Custom Fields</label>
                <textarea class="form-control" name="custom_fields" rows="4" placeholder="Add extra placeholders using key=value format.&#10;e.g. scope=Representation; duration=6 months"></textarea>
                <small class="text-muted">One entry per line (key=value). They become {{key}} in the draft.</small>
            </div>
            <button class="btn btn-dark">Generate Draft</button>
        </form>
    </div>
</div>'
. legalpro_documents_generated_actions_html(legalpro_documents_get_generated_draft());

legalpro_documents_render_page('document-generate', $content, $state);
