<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/documents-portal.php';

$state = legalpro_documents_portal_init_state();
legalpro_documents_portal_bootstrap($pdo, $state);
legalpro_documents_portal_handle_post($pdo, 'document-upload', $state);
legalpro_documents_portal_load($pdo, $state);

$selectedCaseId = isset($_GET['case_id']) ? (int) $_GET['case_id'] : 0;
if ($selectedCaseId > 0) {
    $caseOptions = '<option value="">Select case</option>';
    foreach ($state['cases'] as $case) {
        $caseId = (int) $case['id'];
        $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
        $selected = $caseId === $selectedCaseId ? ' selected' : '';
        $caseOptions .= '<option value="' . $caseId . '"' . $selected . '>' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</option>';
    }
    $state['caseOptions'] = $caseOptions;
}

$content = '
<div class="row">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Case Library</h6>
                <p class="text-sm text-muted mb-0">Pick a matter and attach files directly.</p>
            </div>
            <div class="card-body p-0">
                <div class="case-library-container" style="max-height: 480px; overflow-y: auto;">' . $state['caseRows'] . '</div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-4" id="upload-card">
            <div class="card-header pb-0">
                <h6 class="mb-0">Upload Document</h6>
                <p class="text-sm text-muted mb-0" id="selected-case-label">Select a case on the left or choose below.</p>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="form_type" value="upload">
                    <div class="mb-3">
                        <label class="form-label">Case</label>
                        <select class="form-select" name="case_id" id="upload_case_id" required>' . $state['caseOptions'] . '</select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Document Name</label>
                        <input type="text" class="form-control" name="label" placeholder="e.g., Evidence Packet">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Uploaded By</label>
                        <input type="text" class="form-control" name="uploaded_by" placeholder="Staff name" value="admin">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">File</label>
                        <input type="file" class="form-control" name="document_file" accept=".pdf,.doc,.docx,.txt,.png,.jpg,.jpeg" required>
                        <small class="text-muted">Accepted: PDF, Word, TXT, JPG/PNG</small>
                    </div>
                    <button type="submit" class="btn btn-dark w-100">Save Document</button>
                </form>
            </div>
        </div>
    </div>
</div>';

legalpro_documents_render_page('document-upload', $content, $state, '<script>' . legalpro_documents_upload_script() . '</script>');
