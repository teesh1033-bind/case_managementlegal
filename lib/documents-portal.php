<?php

require_once __DIR__ . '/case_events.php';

function legalpro_documents_portal_pages(): array
{
    return [
        'documents' => ['file' => 'documents.php', 'title' => 'Overview', 'nav' => 'Documents Overview'],
        'document-upload' => ['file' => 'document-upload.php', 'title' => 'Upload', 'nav' => 'Upload Document'],
        'document-templates' => ['file' => 'document-templates.php', 'title' => 'Templates', 'nav' => 'Template Library'],
        'document-generate' => ['file' => 'document-generate.php', 'title' => 'Generate', 'nav' => 'Generate Document'],
        'document-browse' => ['file' => 'document-browse.php', 'title' => 'Browse', 'nav' => 'Browse Documents'],
    ];
}

function legalpro_documents_portal_page_ids(): array
{
    return array_keys(legalpro_documents_portal_pages());
}

function legalpro_documents_portal_is_active_page(string $currentPage): bool
{
    return in_array($currentPage, legalpro_documents_portal_page_ids(), true);
}

function legalpro_documents_portal_init_state(): array
{
    $state = [
        'message' => '',
        'messageType' => '',
        'previewContent' => '',
        'previewTitle' => '',
        'cases' => [],
        'documents' => [],
        'documentsByCase' => [],
        'recentDocuments' => [],
        'templates' => [],
        'caseOptions' => '',
        'templateOptions' => '',
        'caseRows' => '',
        'documentAccordion' => '',
        'templatesRows' => '',
        'recentDocsList' => '',
        'totalDocuments' => 0,
        'totalTemplates' => 0,
        'casesWithDocs' => 0,
        'recentCount' => 0,
    ];

    if (isset($_GET['msg'], $_GET['type'])) {
        $state['message'] = urldecode((string) $_GET['msg']);
        $state['messageType'] = (string) $_GET['type'];
    }

    return $state;
}

function legalpro_documents_portal_bootstrap(PDO $pdo, array &$state): void
{
    try {
        $pdo->query('ALTER TABLE documents ADD COLUMN label VARCHAR(255) DEFAULT NULL AFTER filename');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }
    try {
        $pdo->query('ALTER TABLE documents ADD COLUMN uploaded_by VARCHAR(100) DEFAULT NULL AFTER label');
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) {
            throw $e;
        }
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS document_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                description VARCHAR(255),
                body TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        $state['message'] = 'Unable to prepare template storage: ' . htmlspecialchars($e->getMessage());
        $state['messageType'] = 'danger';
    }

    try {
        $templatesCount = (int) $pdo->query('SELECT COUNT(*) FROM document_templates')->fetchColumn();
        if ($templatesCount === 0) {
            $seedStmt = $pdo->prepare('INSERT INTO document_templates (name, description, body) VALUES (?, ?, ?)');
            $seedStmt->execute([
                'Retainer Agreement',
                'Standard engagement/retainer letter',
                "This Retainer Agreement is made on {{today}} between {{client_name}} and {{firm_name}} regarding case {{case_number}} ({{case_title}}).\n\nScope: {{scope}}\nFee Arrangement: {{fee_structure}}\nPrimary Contact: {{lawyer_name}}\n\nThank you,\n{{firm_name}}",
            ]);
            $seedStmt->execute([
                'Affidavit Template',
                'Sworn statement placeholder',
                "I, {{client_name}}, being duly sworn, depose and state:\n1. {{statement_one}}\n2. {{statement_two}}\n\nDated: {{today}}\nCase: {{case_number}} – {{case_title}}",
            ]);
            $seedStmt->execute([
                'Invoice Cover Letter',
                'Short cover note for invoices',
                "Dear {{client_name}},\n\nPlease find the invoice for {{case_title}} attached. The outstanding balance is {{balance}}.\n\nSincerely,\n{{firm_name}}",
            ]);
        }
    } catch (PDOException $e) {
        $state['message'] = 'Unable to seed templates: ' . htmlspecialchars($e->getMessage());
        $state['messageType'] = 'danger';
    }
}

function legalpro_documents_portal_redirect(string $pageKey, string $msg, string $type = 'success'): void
{
    $pages = legalpro_documents_portal_pages();
    $file = $pages[$pageKey]['file'] ?? 'documents.php';
    header('Location: ' . $file . '?msg=' . urlencode($msg) . '&type=' . urlencode($type));
    exit;
}

function legalpro_documents_portal_handle_post(PDO $pdo, string $returnPageKey, array &$state): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $formType = isset($_POST['form_type']) ? (string) $_POST['form_type'] : '';

    if ($formType === 'upload') {
        $caseId = isset($_POST['case_id']) ? (int) $_POST['case_id'] : 0;
        $label = isset($_POST['label']) ? trim((string) $_POST['label']) : '';
        $uploadedBy = isset($_POST['uploaded_by']) ? trim((string) $_POST['uploaded_by']) : 'admin';

        if ($caseId <= 0) {
            $state['message'] = 'Please select a case before uploading.';
            $state['messageType'] = 'danger';
        } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $state['message'] = 'Please choose a valid file to upload.';
            $state['messageType'] = 'danger';
        } else {
            $fileInfo = $_FILES['document_file'];
            $allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'jpeg'];
            $extension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) {
                $state['message'] = 'Unsupported file type. Allowed: ' . implode(', ', $allowedExtensions);
                $state['messageType'] = 'danger';
            } else {
                $uploadRoot = dirname(__DIR__) . '/uploads';
                if (!is_dir($uploadRoot)) {
                    mkdir($uploadRoot, 0755, true);
                }
                $docsDir = $uploadRoot . '/documents';
                if (!is_dir($docsDir)) {
                    mkdir($docsDir, 0755, true);
                }

                $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($fileInfo['name'], PATHINFO_FILENAME));
                $uniqueName = $safeName . '_' . time() . '.' . $extension;
                $targetPath = $docsDir . '/' . $uniqueName;

                if (!move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
                    $state['message'] = 'Unable to store the uploaded file. Check folder permissions.';
                    $state['messageType'] = 'danger';
                } else {
                    $relativePath = 'uploads/documents/' . $uniqueName;
                    $displayLabel = $label !== '' ? $label : $safeName;
                    try {
                        $stmt = $pdo->prepare('
                            INSERT INTO documents (case_id, filename, label, uploaded_by, filepath)
                            VALUES (?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([$caseId, $uniqueName, $displayLabel, $uploadedBy !== '' ? $uploadedBy : 'admin', $relativePath]);

                        CaseEvents::trackDocumentUploaded($caseId, [
                            'filename' => $uniqueName,
                            'label' => $displayLabel,
                        ]);

                        legalpro_documents_portal_redirect($returnPageKey, 'Document uploaded successfully.');
                    } catch (PDOException $e) {
                        $state['message'] = 'Error saving document: ' . htmlspecialchars($e->getMessage());
                        $state['messageType'] = 'danger';
                    }
                }
            }
        }
    } elseif ($formType === 'template') {
        $name = isset($_POST['template_name']) ? trim((string) $_POST['template_name']) : '';
        $description = isset($_POST['template_description']) ? trim((string) $_POST['template_description']) : '';
        $body = isset($_POST['template_body']) ? trim((string) $_POST['template_body']) : '';

        if ($name === '' || $body === '') {
            $state['message'] = 'Template name and body are required.';
            $state['messageType'] = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO document_templates (name, description, body) VALUES (?, ?, ?)');
                $stmt->execute([$name, $description, $body]);
                legalpro_documents_portal_redirect($returnPageKey, 'Template saved.');
            } catch (PDOException $e) {
                $state['message'] = 'Unable to save template: ' . htmlspecialchars($e->getMessage());
                $state['messageType'] = 'danger';
            }
        }
    } elseif ($formType === 'generate') {
        $templateId = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        $caseId = isset($_POST['case_for_template']) ? (int) $_POST['case_for_template'] : 0;
        $customFieldsRaw = isset($_POST['custom_fields']) ? trim((string) $_POST['custom_fields']) : '';
        $outputTitle = isset($_POST['output_title']) ? trim((string) $_POST['output_title']) : 'Draft Document';

        if ($templateId <= 0 || $caseId <= 0) {
            $state['message'] = 'Select a template and a case to generate a document.';
            $state['messageType'] = 'danger';
        } else {
            $templateStmt = $pdo->prepare('SELECT * FROM document_templates WHERE id = ?');
            $templateStmt->execute([$templateId]);
            $template = $templateStmt->fetch();

            $caseStmt = $pdo->prepare("
                SELECT c.*, CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
                       cl.email AS client_email, cl.phone AS client_phone
                FROM cases c
                LEFT JOIN clients cl ON cl.id = c.client_id
                WHERE c.id = ?
            ");
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch();

            if (!$template || !$case) {
                $state['message'] = 'Unable to locate the selected template or case.';
                $state['messageType'] = 'danger';
            } else {
                $replacements = [
                    '{{case_title}}' => $case['title'] ?? '',
                    '{{case_number}}' => 'C-' . str_pad((string) $case['id'], 4, '0', STR_PAD_LEFT),
                    '{{client_name}}' => $case['client_name'] ?? 'Client',
                    '{{client_email}}' => $case['client_email'] ?? '',
                    '{{client_phone}}' => $case['client_phone'] ?? '',
                    '{{status}}' => $case['status'] ?? '',
                    '{{priority}}' => $case['priority'] ?? '',
                    '{{category}}' => $case['category'] ?? '',
                    '{{fee}}' => isset($case['estimated_fees']) ? formatCurrency((float) $case['estimated_fees']) : formatCurrency(0),
                    '{{start_date}}' => $case['start_date'] ?? '',
                    '{{expected_completion}}' => $case['expected_completion'] ?? '',
                    '{{today}}' => date('d M Y'),
                    '{{firm_name}}' => getCompanyName(),
                    '{{lawyer_name}}' => 'Assigned Counsel',
                    '{{balance}}' => isset($case['estimated_fees']) ? formatCurrency((float) $case['estimated_fees']) : formatCurrency(0),
                    '{{scope}}' => 'Legal representation as described herein',
                    '{{fee_structure}}' => 'Flat fee',
                ];

                if ($customFieldsRaw !== '') {
                    $lines = preg_split('/\r\n|\r|\n/', $customFieldsRaw);
                    foreach ($lines as $line) {
                        if (strpos($line, '=') !== false) {
                            [$key, $value] = array_map('trim', explode('=', $line, 2));
                            if ($key !== '') {
                                $replacements['{{' . strtolower($key) . '}}'] = $value;
                            }
                        }
                    }
                }

                $generated = (string) $template['body'];
                foreach ($replacements as $token => $value) {
                    $generated = str_replace($token, $value, $generated);
                }

                $state['previewContent'] = nl2br(htmlspecialchars($generated));
                $state['previewTitle'] = $outputTitle !== '' ? $outputTitle : ($template['name'] . ' · Draft');
                $state['message'] = 'Document generated below. Copy, print, or download as needed.';
                $state['messageType'] = 'success';
            }
        }
    }
}

function legalpro_documents_portal_load(PDO $pdo, array &$state): void
{
    try {
        $stmt = $pdo->query("
            SELECT
                c.id,
                c.title,
                COALESCE(c.status, 'open') AS status,
                COALESCE(c.priority, 'Normal') AS priority,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name,
                COALESCE(doc_counts.total_docs, 0) AS total_docs
            FROM cases c
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN (
                SELECT case_id, COUNT(*) AS total_docs
                FROM documents
                GROUP BY case_id
            ) doc_counts ON doc_counts.case_id = c.id
            ORDER BY c.created_at DESC
        ");
        $state['cases'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $state['cases'] = [];
        if ($state['message'] === '') {
            $state['message'] = 'Unable to load cases: ' . htmlspecialchars($e->getMessage());
            $state['messageType'] = 'danger';
        }
    }

    try {
        $stmt = $pdo->query("
            SELECT
                d.*,
                c.id AS case_id,
                c.title AS case_title,
                CONCAT(cl.first_name, ' ', cl.last_name) AS client_name
            FROM documents d
            LEFT JOIN cases c ON c.id = d.case_id
            LEFT JOIN clients cl ON cl.id = c.client_id
            ORDER BY d.uploaded_at DESC
        ");
        $state['documents'] = $stmt->fetchAll();
        $documentsByCase = [];
        foreach ($state['documents'] as $doc) {
            $caseId = (int) ($doc['case_id'] ?? 0);
            if (!isset($documentsByCase[$caseId])) {
                $documentsByCase[$caseId] = [];
            }
            $documentsByCase[$caseId][] = $doc;
        }
        $state['documentsByCase'] = $documentsByCase;
        $state['recentDocuments'] = array_slice($state['documents'], 0, 6);
    } catch (PDOException $e) {
        $state['documents'] = [];
        $state['documentsByCase'] = [];
        $state['recentDocuments'] = [];
    }

    try {
        $stmt = $pdo->query('SELECT * FROM document_templates ORDER BY updated_at DESC');
        $state['templates'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $state['templates'] = [];
    }

    legalpro_documents_portal_build_fragments($state);
}

function legalpro_documents_portal_build_fragments(array &$state): void
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';

    $cases = $state['cases'];
    $templates = $state['templates'];
    $documentsByCase = $state['documentsByCase'];
    $recentDocuments = $state['recentDocuments'];

    $caseOptions = '<option value="">Select case</option>';
    foreach ($cases as $case) {
        $caseId = (int) $case['id'];
        $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
        $caseOptions .= '<option value="' . $caseId . '">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</option>';
    }
    $state['caseOptions'] = $caseOptions;

    $templateOptions = '<option value="">Select template</option>';
    foreach ($templates as $template) {
        $templateOptions .= '<option value="' . (int) $template['id'] . '">' . htmlspecialchars($template['name']) . '</option>';
    }
    $state['templateOptions'] = $templateOptions;

    if (empty($cases)) {
        $state['caseRows'] = '<div class="text-center text-muted py-4"><i class="ni ni-folder-17 text-lg opacity-50 mb-2"></i><br>No cases available.</div>';
    } else {
        $caseRows = '';
        foreach ($cases as $case) {
            $caseId = (int) $case['id'];
            $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
            $docsCount = (int) ($case['total_docs'] ?? 0);
            $statusBadge = legalpro_case_status_badge((string) ($case['status'] ?? 'open'));
            $filesBadge = legalpro_document_count_badge($docsCount);

            $caseRows .= '
            <div class="case-item border-bottom p-3 hover-shadow" style="cursor: pointer;" data-case-attach="' . $caseId . '" data-case-label="' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1 me-3">
                        <div class="d-flex align-items-center mb-1">
                            <h6 class="mb-0 me-2">' . htmlspecialchars($caseNumber) . '</h6>
                            ' . $statusBadge . '
                        </div>
                        <p class="text-sm mb-1 font-weight-bold">' . htmlspecialchars($case['title']) . '</p>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($case['client_name']) . '</p>
                    </div>
                    <div class="text-end">
                        <div class="mb-2">' . $filesBadge . '</div>
                        <button type="button" class="btn btn-sm btn-primary attach-btn" data-case-attach="' . $caseId . '" data-case-label="' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '">
                            <i class="ni ni-cloud-upload-96 me-1"></i>Attach File
                        </button>
                    </div>
                </div>
            </div>';
        }
        $state['caseRows'] = $caseRows;
    }

    if (empty($cases)) {
        $state['documentAccordion'] = '<div class="text-center text-muted py-4"><i class="ni ni-folder-17 text-lg opacity-50 mb-2"></i><br>No cases available.</div>';
    } else {
        $documentAccordion = '';
        $collapseIndex = 0;
        foreach ($cases as $case) {
            $caseId = (int) $case['id'];
            $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
            $docs = $documentsByCase[$caseId] ?? [];
            $docList = '';

            if (empty($docs)) {
                $docList = '<div class="text-center text-muted py-3">'
                    . '<div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary document-item-icon d-inline-flex align-items-center justify-content-center mb-2">'
                    . legalpro_icon('folder-open')
                    . '</div><br>No documents uploaded yet.</div>';
            } else {
                foreach ($docs as $doc) {
                    $displayName = !empty($doc['label']) ? $doc['label'] : $doc['filename'];
                    $downloadUrl = !empty($doc['filepath']) ? '../' . ltrim($doc['filepath'], '/') : '#';
                    $uploadedAt = !empty($doc['uploaded_at']) ? date('M j, Y g:i A', strtotime($doc['uploaded_at'])) : '';
                    $uploadedBy = !empty($doc['uploaded_by']) ? $doc['uploaded_by'] : 'System';

                    $docList .= '
                    <div class="document-item d-flex justify-content-between align-items-center p-3 border-bottom">
                        <div class="d-flex align-items-center">
                            ' . legalpro_document_file_icon_wrap($doc['filename']) . '
                            <div>
                                <h6 class="mb-0 text-sm">' . htmlspecialchars($displayName) . '</h6>
                                <p class="text-xs text-muted mb-0">Uploaded ' . htmlspecialchars($uploadedAt) . ' by ' . htmlspecialchars($uploadedBy) . '</p>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-primary" href="' . htmlspecialchars($downloadUrl) . '" target="_blank" rel="noopener">View</a>
                            <a class="btn btn-sm btn-success" href="' . htmlspecialchars($downloadUrl) . '" download>Download</a>
                        </div>
                    </div>';
                }
            }

            $docsCount = count($docs);
            $documentAccordion .= '
            <div class="accordion-item border">
                <h2 class="accordion-header" id="heading-' . $collapseIndex . '">
                    <button class="accordion-button doc-case-accordion-btn' . ($collapseIndex === 0 ? '' : ' collapsed') . '" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-' . $collapseIndex . '" aria-expanded="' . ($collapseIndex === 0 ? 'true' : 'false') . '">
                        <div class="doc-case-accordion-meta">
                            <div class="doc-case-accordion-title">
                                ' . legalpro_document_count_badge($docsCount, true) . '
                                <span class="text-sm font-weight-bold">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</span>
                            </div>
                            <small class="text-muted doc-case-accordion-client">' . htmlspecialchars($case['client_name']) . '</small>
                        </div>
                    </button>
                </h2>
                <div id="collapse-' . $collapseIndex . '" class="accordion-collapse collapse' . ($collapseIndex === 0 ? ' show' : '') . '" aria-labelledby="heading-' . $collapseIndex . '" data-bs-parent="#documentsAccordion">
                    <div class="accordion-body p-0">' . $docList . '</div>
                </div>
            </div>';
            $collapseIndex++;
        }
        $state['documentAccordion'] = $documentAccordion;
    }

    if (empty($templates)) {
        $state['templatesRows'] = '<tr><td colspan="3" class="text-center text-muted py-3">No templates yet.</td></tr>';
    } else {
        $templatesRows = '';
        foreach ($templates as $template) {
            $templatesRows .= '
            <tr>
                <td>
                    <strong>' . htmlspecialchars($template['name']) . '</strong>
                    <p class="text-xs text-muted mb-0">' . htmlspecialchars($template['description'] ?? '') . '</p>
                </td>
                <td class="text-center">' . htmlspecialchars(date('d M Y', strtotime($template['updated_at']))) . '</td>
                <td class="text-end"><span class="badge bg-gradient-dark">Ready</span></td>
            </tr>';
        }
        $state['templatesRows'] = $templatesRows;
    }

    if (empty($recentDocuments)) {
        $state['recentDocsList'] = '<div class="text-center text-muted py-4">'
            . '<div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary document-item-icon d-inline-flex align-items-center justify-content-center mb-2">'
            . legalpro_icon('file-text')
            . '</div><br>No recent documents.</div>';
    } else {
        $recentDocsList = '';
        foreach ($recentDocuments as $doc) {
            $displayName = !empty($doc['label']) ? $doc['label'] : $doc['filename'];
            $downloadUrl = !empty($doc['filepath']) ? '../' . ltrim($doc['filepath'], '/') : '#';
            $caseTitle = !empty($doc['case_title']) ? $doc['case_title'] : 'Unassigned case';
            $uploadedAt = !empty($doc['uploaded_at']) ? date('M j, Y', strtotime($doc['uploaded_at'])) : '';

            $recentDocsList .= '
            <div class="document-item recent-document-item d-flex justify-content-between align-items-center p-3 border-bottom">
                <div class="d-flex align-items-center">
                    ' . legalpro_document_file_icon_wrap($doc['filename']) . '
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($displayName) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($caseTitle) . ' • ' . htmlspecialchars($uploadedAt) . '</p>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-primary" href="' . htmlspecialchars($downloadUrl) . '" target="_blank" rel="noopener">View</a>
                    <a class="btn btn-sm btn-success" href="' . htmlspecialchars($downloadUrl) . '" download>Download</a>
                </div>
            </div>';
        }
        $state['recentDocsList'] = $recentDocsList;
    }

    $state['totalDocuments'] = count($state['documents']);
    $state['totalTemplates'] = count($templates);
    $casesWithDocs = 0;
    foreach ($cases as $case) {
        if (!empty($case['total_docs'])) {
            $casesWithDocs++;
        }
    }
    $state['casesWithDocs'] = $casesWithDocs;
    $state['recentCount'] = count($recentDocuments);
}

function legalpro_documents_message_html(array $state): string
{
    if (empty($state['message'])) {
        return '';
    }

    $type = $state['messageType'] !== '' ? $state['messageType'] : 'info';

    return '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show mx-3 mt-3" role="alert">'
        . htmlspecialchars($state['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

function legalpro_documents_preview_html(array $state): string
{
    if (empty($state['previewContent'])) {
        return '';
    }

    return '
    <div class="card mt-4">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0">' . htmlspecialchars($state['previewTitle']) . '</h6>
            <button type="button" class="btn btn-sm btn-outline-dark" onclick="window.print()">Print</button>
        </div>
        <div class="card-body">
            <div class="border rounded p-3 bg-white legalpro-doc-preview-body" style="min-height: 200px;">'
                . $state['previewContent']
            . '</div>
        </div>
    </div>';
}

function legalpro_documents_stats_row_html(array $state): string
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';
    $iconStatDocs = legalpro_icon('folder-open');
    $iconStatTemplates = legalpro_icon('files');
    $iconStatUploads = legalpro_icon('upload');

    return '
    <div class="row mb-4">
        <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
            <div class="card dashboard-stat-card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Documents Stored</p>
                                <h5 class="font-weight-bolder">' . number_format((int) $state['totalDocuments']) . '</h5>
                                <p class="mb-0 text-sm text-muted">' . (int) $state['casesWithDocs'] . ' cases attached</p>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--dark">' . $iconStatDocs . '</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
            <div class="card dashboard-stat-card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Templates</p>
                                <h5 class="font-weight-bolder">' . (int) $state['totalTemplates'] . '</h5>
                                <p class="mb-0 text-sm text-muted">Reusable legal drafts</p>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--info">' . $iconStatTemplates . '</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-sm-6">
            <div class="card dashboard-stat-card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Recent Uploads</p>
                                <h5 class="font-weight-bolder">' . (int) $state['recentCount'] . '</h5>
                                <p class="mb-0 text-sm text-muted">Last 6 documents</p>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success">' . $iconStatUploads . '</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>';
}

function legalpro_documents_hub_cards_html(): string
{
    require_once __DIR__ . '/../inc/legalpro-icons.php';
    $pages = legalpro_documents_portal_pages();
    $cards = [
        'document-upload' => ['desc' => 'Pick a case and attach files.', 'icon' => 'upload', 'accent' => 'primary'],
        'document-templates' => ['desc' => 'Manage reusable legal drafts.', 'icon' => 'files', 'accent' => 'info'],
        'document-generate' => ['desc' => 'Merge templates with case data.', 'icon' => 'file-text', 'accent' => 'dark'],
        'document-browse' => ['desc' => 'Recent uploads and files by case.', 'icon' => 'folder-open', 'accent' => 'success'],
    ];

    $html = '<div class="row g-3">';
    foreach ($cards as $key => $meta) {
        $page = $pages[$key];
        $html .= '
        <div class="col-md-6 col-xl-3">
            <a href="' . htmlspecialchars($page['file']) . '" class="card legalpro-doc-hub-card h-100 text-decoration-none">
                <div class="card-body p-3">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--' . $meta['accent'] . ' mb-3">' . legalpro_icon($meta['icon']) . '</div>
                    <h6 class="mb-1 text-dark">' . htmlspecialchars($page['title']) . '</h6>
                    <p class="text-sm text-muted mb-0">' . htmlspecialchars($meta['desc']) . '</p>
                </div>
            </a>
        </div>';
    }
    $html .= '</div>';

    return $html;
}

function legalpro_documents_subnav_html(string $activeKey): string
{
    $pages = legalpro_documents_portal_pages();
    $html = '<nav class="legalpro-doc-subnav" aria-label="Documents sections">';
    foreach ($pages as $key => $page) {
        $active = $key === $activeKey ? ' is-active' : '';
        $html .= '<a class="legalpro-doc-subnav__link' . $active . '" href="' . htmlspecialchars($page['file']) . '">'
            . htmlspecialchars($page['title']) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function legalpro_documents_shared_styles(): string
{
    return <<<'CSS'
        .case-item:hover { background-color: #f8f9fa !important; transition: background-color 0.2s ease; }
        body.legalpro-dark-mode .case-item:hover { background-color: var(--lp-dark-surface-hover, #464f68) !important; }
        .document-item { gap: 0.75rem; }
        .document-item:hover { background-color: #f8f9fa !important; transition: background-color 0.2s ease; }
        body.legalpro-dark-mode .document-item:hover { background-color: var(--lp-dark-surface-hover, #464f68) !important; }
        .document-item > .d-flex.align-items-center:first-child { flex: 1 1 auto; min-width: 0; }
        .document-item > .d-flex.align-items-center:first-child > div:last-child { min-width: 0; overflow: hidden; }
        .document-item h6, .document-item p.text-xs { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .document-item > .d-flex.gap-2 { flex: 0 0 auto; flex-shrink: 0; flex-wrap: nowrap; }
        .document-item .btn { white-space: nowrap; }
        .document-item .document-item-icon.dashboard-stat-icon-wrap { width: 2.5rem; height: 2.5rem; min-width: 2.5rem; }
        .doc-case-accordion-btn { align-items: flex-start; }
        .doc-case-accordion-meta { display: flex; flex-direction: column; gap: 0.35rem; min-width: 0; padding-right: 1.5rem; }
        .doc-case-accordion-title { display: flex; align-items: center; flex-wrap: wrap; gap: 0.35rem; }
        .doc-case-accordion-client { display: block; margin-top: 0.15rem; padding-left: 0.1rem; }
        .attach-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); transition: all 0.2s ease; }
        .case-library-container::-webkit-scrollbar { width: 6px; }
        .case-library-container::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 4px; }
        body.legalpro-dark-mode .accordion-item { background: var(--lp-dark-surface) !important; border-color: var(--lp-dark-border) !important; }
        body.legalpro-dark-mode .accordion-button { background: var(--lp-dark-surface-raised) !important; color: var(--lp-dark-text) !important; }
        body.legalpro-dark-mode .accordion-button:not(.collapsed) { background: var(--lp-dark-surface-hover) !important; color: var(--lp-dark-text) !important; }
        body.legalpro-dark-mode .legalpro-doc-preview-body { background: #fff !important; color: #1e293b !important; }
        .legalpro-doc-hub-card { transition: transform 0.15s ease, box-shadow 0.15s ease; border: 1px solid #e9ecf3; }
        .legalpro-doc-hub-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
        body.legalpro-dark-mode .legalpro-doc-hub-card { border-color: var(--lp-dark-border); }
        body.legalpro-dark-mode .legalpro-doc-hub-card h6 { color: var(--lp-dark-text) !important; }
CSS;
}

function legalpro_documents_upload_script(): string
{
    return <<<'JS'
        document.addEventListener('DOMContentLoaded', function() {
            var caseSelect = document.getElementById('upload_case_id');
            var labelEl = document.getElementById('selected-case-label');
            if (caseSelect && labelEl) {
                caseSelect.addEventListener('change', function () {
                    if (this.value) {
                        var selectedOption = this.options[this.selectedIndex];
                        labelEl.textContent = 'Attaching to ' + (selectedOption ? selectedOption.text : 'selected case');
                    } else {
                        labelEl.textContent = 'Select a case above or choose below.';
                    }
                });
            }
            document.addEventListener('click', function(e) {
                var attachBtn = e.target.closest('[data-case-attach]');
                if (!attachBtn) return;
                e.preventDefault();
                var caseId = attachBtn.getAttribute('data-case-attach');
                var caseLabel = attachBtn.getAttribute('data-case-label');
                if (caseSelect) {
                    caseSelect.value = caseId;
                    caseSelect.dispatchEvent(new Event('change'));
                }
                if (labelEl && caseLabel) {
                    labelEl.textContent = 'Attaching to ' + caseLabel;
                }
                var uploadCard = document.getElementById('upload-card');
                if (!uploadCard) return;
                var offsetPosition = uploadCard.offsetTop - 100;
                window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                uploadCard.classList.add('shadow-lg', 'border-primary');
                uploadCard.style.borderWidth = '2px';
                setTimeout(function() {
                    uploadCard.classList.remove('shadow-lg', 'border-primary');
                    uploadCard.style.borderWidth = '';
                }, 2000);
                var fileInput = uploadCard.querySelector('input[type="file"]');
                if (fileInput) {
                    setTimeout(function() { fileInput.focus(); }, 600);
                }
            });
        });
JS;
}

function legalpro_documents_render_page(string $pageKey, string $contentHtml, array $state, string $extraScripts = ''): void
{
    $pages = legalpro_documents_portal_pages();
    $page = $pages[$pageKey] ?? $pages['documents'];
    $navTitle = $page['nav'];
    $bodyClass = legalpro_portal_theme_body_class();

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>LegalPro · ' . htmlspecialchars($navTitle) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="../assets/css/legalpro-icons.css?v=2" rel="stylesheet" />
    ';
    ob_start();
    include dirname(__DIR__) . '/inc/admin-portal-head.php';
    $html .= ob_get_clean();
    $html .= '<link href="../assets/css/legalpro-documents-hub.css?v=3" rel="stylesheet" />'
        . '<style>' . legalpro_documents_shared_styles() . '</style>
</head>
<body class="g-sidenav-show g-sidenav-pinned bg-gray-100 legalpro-admin-portal legalpro-documents-page' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="documents.php">Documents</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">' . htmlspecialchars($page['title']) . '</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">' . htmlspecialchars($navTitle) . '</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            ' . legalpro_documents_message_html($state) . '
            ' . legalpro_documents_subnav_html($pageKey) . '
            ' . $contentHtml . '
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
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
