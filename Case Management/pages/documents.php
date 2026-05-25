<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

$previewContent = '';
$previewTitle = '';

// Ensure documents table has richer metadata
try {
    $pdo->query("ALTER TABLE documents ADD COLUMN label VARCHAR(255) DEFAULT NULL AFTER filename");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE documents ADD COLUMN uploaded_by VARCHAR(100) DEFAULT NULL AFTER label");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
}

// Ensure template storage exists
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
    $message = 'Unable to prepare template storage: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

// Seed starter templates if empty
try {
    $templatesCount = (int)$pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn();
    if ($templatesCount === 0) {
        $seedStmt = $pdo->prepare("INSERT INTO document_templates (name, description, body) VALUES (?, ?, ?)");
        $seedStmt->execute([
            'Retainer Agreement',
            'Standard engagement/retainer letter',
            "This Retainer Agreement is made on {{today}} between {{client_name}} and {{firm_name}} regarding case {{case_number}} ({{case_title}}).\n\nScope: {{scope}}\nFee Arrangement: {{fee_structure}}\nPrimary Contact: {{lawyer_name}}\n\nThank you,\n{{firm_name}}"
        ]);
        $seedStmt->execute([
            'Affidavit Template',
            'Sworn statement placeholder',
            "I, {{client_name}}, being duly sworn, depose and state:\n1. {{statement_one}}\n2. {{statement_two}}\n\nDated: {{today}}\nCase: {{case_number}} – {{case_title}}"
        ]);
        $seedStmt->execute([
            'Invoice Cover Letter',
            'Short cover note for invoices',
            "Dear {{client_name}},\n\nPlease find the invoice for {{case_title}} attached. The outstanding balance is {{balance}}.\n\nSincerely,\n{{firm_name}}"
        ]);
    }
} catch (PDOException $e) {
    $message = 'Unable to seed templates: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';

    if ($formType === 'upload') {
        $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
        $label = isset($_POST['label']) ? trim($_POST['label']) : '';
        $uploadedBy = isset($_POST['uploaded_by']) ? trim($_POST['uploaded_by']) : 'admin';

        if (empty($caseId)) {
            $message = 'Please select a case before uploading.';
            $messageType = 'danger';
        } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Please choose a valid file to upload.';
            $messageType = 'danger';
        } else {
            $fileInfo = $_FILES['document_file'];
            $allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'jpeg'];
            $extension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) {
                $message = 'Unsupported file type. Allowed: ' . implode(', ', $allowedExtensions);
                $messageType = 'danger';
            } else {
                $uploadRoot = __DIR__ . '/../uploads';
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
                    $message = 'Unable to store the uploaded file. Check folder permissions.';
                    $messageType = 'danger';
                } else {
                    $relativePath = 'uploads/documents/' . $uniqueName;
                    $displayLabel = $label ?: $safeName;
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO documents (case_id, filename, label, uploaded_by, filepath) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$caseId, $uniqueName, $displayLabel, $uploadedBy ?: 'admin', $relativePath]);

                        // Track document upload
                        CaseEvents::trackDocumentUploaded($caseId, [
                            'filename' => $uniqueName,
                            'label' => $displayLabel
                        ]);

                        $msg = 'Document uploaded successfully.';
                        header('Location: documents.php?msg=' . urlencode($msg) . '&type=success');
                        exit;
                    } catch (PDOException $e) {
                        $message = 'Error saving document: ' . htmlspecialchars($e->getMessage());
                        $messageType = 'danger';
                    }
                }
            }
        }
    } elseif ($formType === 'template') {
        $name = isset($_POST['template_name']) ? trim($_POST['template_name']) : '';
        $description = isset($_POST['template_description']) ? trim($_POST['template_description']) : '';
        $body = isset($_POST['template_body']) ? trim($_POST['template_body']) : '';

        if (empty($name) || empty($body)) {
            $message = 'Template name and body are required.';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO document_templates (name, description, body) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $body]);
                $msg = 'Template saved.';
                header('Location: documents.php?msg=' . urlencode($msg) . '&type=success');
                exit;
            } catch (PDOException $e) {
                $message = 'Unable to save template: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'generate') {
        $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
        $caseId = isset($_POST['case_for_template']) ? (int)$_POST['case_for_template'] : 0;
        $customFieldsRaw = isset($_POST['custom_fields']) ? trim($_POST['custom_fields']) : '';
        $outputTitle = isset($_POST['output_title']) ? trim($_POST['output_title']) : 'Draft Document';

        if (empty($templateId) || empty($caseId)) {
            $message = 'Select a template and a case to generate a document.';
            $messageType = 'danger';
        } else {
            $templateStmt = $pdo->prepare("SELECT * FROM document_templates WHERE id = ?");
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
                $message = 'Unable to locate the selected template or case.';
                $messageType = 'danger';
            } else {
                $replacements = [
                    '{{case_title}}' => isset($case['title']) ? $case['title'] : '',
                    '{{case_number}}' => 'C-' . str_pad($case['id'], 4, '0', STR_PAD_LEFT),
                    '{{client_name}}' => isset($case['client_name']) ? $case['client_name'] : 'Client',
                    '{{client_email}}' => isset($case['client_email']) ? $case['client_email'] : '',
                    '{{client_phone}}' => isset($case['client_phone']) ? $case['client_phone'] : '',
                    '{{status}}' => isset($case['status']) ? $case['status'] : '',
                    '{{priority}}' => isset($case['priority']) ? $case['priority'] : '',
                    '{{category}}' => isset($case['category']) ? $case['category'] : '',
                    '{{fee}}' => isset($case['estimated_fees']) ? formatCurrency((float)$case['estimated_fees']) : formatCurrency(0),
                    '{{start_date}}' => isset($case['start_date']) ? $case['start_date'] : '',
                    '{{expected_completion}}' => isset($case['expected_completion']) ? $case['expected_completion'] : '',
                    '{{today}}' => date('d M Y'),
                    '{{firm_name}}' => 'LegalPro Case Manager',
                    '{{lawyer_name}}' => 'Assigned Counsel',
                    '{{balance}}' => isset($case['estimated_fees']) ? formatCurrency((float)$case['estimated_fees']) : formatCurrency(0),
                    '{{scope}}' => 'Legal representation as described herein',
                    '{{fee_structure}}' => 'Flat fee'
                ];

                if (!empty($customFieldsRaw)) {
                    $lines = preg_split('/\r\n|\r|\n/', $customFieldsRaw);
                    foreach ($lines as $line) {
                        if (strpos($line, '=') !== false) {
                            list($key, $value) = array_map('trim', explode('=', $line, 2));
                            if ($key !== '') {
                                $replacements['{{' . strtolower($key) . '}}'] = $value;
                            }
                        }
                    }
                }

                $generated = $template['body'];
                foreach ($replacements as $token => $value) {
                    $generated = str_replace($token, $value, $generated);
                }

                $previewContent = nl2br(htmlspecialchars($generated));
                $previewTitle = $outputTitle ?: ($template['name'] . ' · Draft');
                $message = 'Document generated below. Copy, print, or download as needed.';
                $messageType = 'success';
            }
        }
    }
}

// Fetch data for rendering
$cases = [];
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
    $cases = $stmt->fetchAll();
} catch (PDOException $e) {
    $cases = [];
    if (!$message) {
        $message = 'Unable to load cases: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

$documents = [];
$documentsByCase = [];
$recentDocuments = [];
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
    $documents = $stmt->fetchAll();
    foreach ($documents as $doc) {
        $caseId = isset($doc['case_id']) ? $doc['case_id'] : 0;
        if (!isset($documentsByCase[$caseId])) {
            $documentsByCase[$caseId] = [];
        }
        $documentsByCase[$caseId][] = $doc;
    }
    $recentDocuments = array_slice($documents, 0, 6);
} catch (PDOException $e) {
    $documents = [];
    $documentsByCase = [];
    $recentDocuments = [];
}

$templates = [];
try {
    $stmt = $pdo->query("SELECT * FROM document_templates ORDER BY updated_at DESC");
    $templates = $stmt->fetchAll();
} catch (PDOException $e) {
    $templates = [];
}

$caseOptions = '<option value="">Select case</option>';
foreach ($cases as $case) {
    $caseId = (int)$case['id'];
    $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
    $caseOptions .= '<option value="' . $caseId . '">' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '</option>';
}

$templateOptions = '<option value="">Select template</option>';
foreach ($templates as $template) {
    $templateOptions .= '<option value="' . (int)$template['id'] . '">' . htmlspecialchars($template['name']) . '</option>';
}

$caseRows = '';
if (empty($cases)) {
    $caseRows = '<div class="text-center text-muted py-4"><i class="ni ni-folder-17 text-lg opacity-50 mb-2"></i><br>No cases available.</div>';
} else {
    foreach ($cases as $case) {
        $caseId = (int)$case['id'];
        $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
        $docsCount = isset($case['total_docs']) ? (int)$case['total_docs'] : 0;

        // Status color mapping
        $statusColor = 'dark';
        switch (strtolower($case['status'])) {
            case 'open':
                $statusColor = 'success';
                break;
            case 'closed':
                $statusColor = 'secondary';
                break;
            case 'pending':
                $statusColor = 'warning';
                break;
        }

        $caseRows .= '
        <div class="case-item border-bottom p-3 hover-shadow" style="cursor: pointer;" data-case-attach="' . $caseId . '" data-case-label="' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1 me-3">
                    <div class="d-flex align-items-center mb-1">
                        <h6 class="mb-0 me-2">' . htmlspecialchars($caseNumber) . '</h6>
                        <span class="badge bg-gradient-' . $statusColor . ' text-xs">' . htmlspecialchars(ucfirst($case['status'])) . '</span>
                    </div>
                    <p class="text-sm mb-1 font-weight-bold">' . htmlspecialchars($case['title']) . '</p>
                    <p class="text-xs text-muted mb-0">' . htmlspecialchars($case['client_name']) . '</p>
                </div>
                <div class="text-end">
                    <div class="mb-2">
                        ' . ($docsCount > 0 ? '<span class="badge bg-gradient-info">' . $docsCount . ' files</span>' : '<span class="badge bg-gradient-secondary">No files</span>') . '
                    </div>
                    <button class="btn btn-sm btn-primary attach-btn" data-case-attach="' . $caseId . '" data-case-label="' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '">
                        <i class="ni ni-cloud-upload-96 me-1"></i>Attach File
                    </button>
                </div>
            </div>
        </div>';
    }
}

$documentAccordion = '';
if (empty($cases)) {
    $documentAccordion = '<div class="text-center text-muted py-4"><i class="ni ni-folder-17 text-lg opacity-50 mb-2"></i><br>No cases available.</div>';
} else {
    $collapseIndex = 0;
    foreach ($cases as $case) {
        $caseId = (int)$case['id'];
        $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
        $docs = isset($documentsByCase[$caseId]) ? $documentsByCase[$caseId] : [];
        $docList = '';

        if (empty($docs)) {
            $docList = '<div class="text-center text-muted py-3"><i class="ni ni-single-copy-04 text-lg opacity-50 mb-2"></i><br>No documents uploaded yet.</div>';
        } else {
            foreach ($docs as $doc) {
                $displayName = isset($doc['label']) && $doc['label'] ? $doc['label'] : $doc['filename'];
                $downloadUrl = isset($doc['filepath']) ? '../' . ltrim($doc['filepath'], '/') : '#';
                $uploadedAt = isset($doc['uploaded_at']) ? date('M j, Y g:i A', strtotime($doc['uploaded_at'])) : '';
                $uploadedBy = isset($doc['uploaded_by']) && $doc['uploaded_by'] ? $doc['uploaded_by'] : 'System';

                // Determine file type icon
                $fileExtension = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
                $iconClass = 'ni-single-copy-04';
                switch ($fileExtension) {
                    case 'pdf':
                        $iconClass = 'ni-single-copy-04';
                        break;
                    case 'doc':
                    case 'docx':
                        $iconClass = 'ni-single-copy-04';
                        break;
                    case 'jpg':
                    case 'jpeg':
                    case 'png':
                        $iconClass = 'ni-image';
                        break;
                    case 'txt':
                        $iconClass = 'ni-single-copy-04';
                        break;
                }

                $docList .= '
                <div class="document-item d-flex justify-content-between align-items-center p-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="icon-shape icon-sm bg-gradient-primary shadow text-center rounded-circle me-3">
                            <i class="ni ' . $iconClass . ' text-white text-xs"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 text-sm">' . htmlspecialchars($displayName) . '</h6>
                            <p class="text-xs text-muted mb-0">Uploaded ' . htmlspecialchars($uploadedAt) . ' by ' . htmlspecialchars($uploadedBy) . '</p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-primary" href="' . htmlspecialchars($downloadUrl) . '" target="_blank" title="View Document">
                            <i class="ni ni-zoom-split-in me-1"></i>View
                        </a>
                        <a class="btn btn-sm btn-success" href="' . htmlspecialchars($downloadUrl) . '" download title="Download Document">
                            <i class="ni ni-cloud-download-95 me-1"></i>Download
                        </a>
                    </div>
                </div>';
            }
        }

        $docsCount = count($docs);
        $documentAccordion .= '
        <div class="accordion-item border">
            <h2 class="accordion-header" id="heading-' . $collapseIndex . '">
                <button class="accordion-button d-flex justify-content-between align-items-center' . ($collapseIndex === 0 ? '' : ' collapsed') . '" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-' . $collapseIndex . '" aria-expanded="' . ($collapseIndex === 0 ? 'true' : 'false') . '">
                    <div>
                        <span class="badge bg-gradient-info me-2">' . $docsCount . '</span>
                        ' . htmlspecialchars($caseNumber . ' · ' . $case['title']) . '
                    </div>
                    <small class="text-muted">' . htmlspecialchars($case['client_name']) . '</small>
                </button>
            </h2>
            <div id="collapse-' . $collapseIndex . '" class="accordion-collapse collapse' . ($collapseIndex === 0 ? ' show' : '') . '" aria-labelledby="heading-' . $collapseIndex . '" data-bs-parent="#documentsAccordion">
                <div class="accordion-body p-0">
                    ' . $docList . '
                </div>
            </div>
        </div>';
        $collapseIndex++;
    }
}

$templatesRows = '';
if (empty($templates)) {
    $templatesRows = '<tr><td colspan="3" class="text-center text-muted py-3">No templates yet.</td></tr>';
} else {
    foreach ($templates as $template) {
        $templatesRows .= '
        <tr>
            <td>
                <strong>' . htmlspecialchars($template['name']) . '</strong>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($template['description']) . '</p>
            </td>
            <td class="text-center">' . htmlspecialchars(date('d M Y', strtotime($template['updated_at']))) . '</td>
            <td class="text-end">
                <span class="badge bg-gradient-dark">Ready</span>
            </td>
        </tr>';
    }
}

$recentDocsList = '';
if (empty($recentDocuments)) {
    $recentDocsList = '<div class="text-center text-muted py-4"><i class="ni ni-single-copy-04 text-lg opacity-50 mb-2"></i><br>No recent documents.</div>';
} else {
    foreach ($recentDocuments as $doc) {
        $displayName = isset($doc['label']) && $doc['label'] ? $doc['label'] : $doc['filename'];
        $downloadUrl = isset($doc['filepath']) ? '../' . ltrim($doc['filepath'], '/') : '#';
        $caseTitle = isset($doc['case_title']) && $doc['case_title'] ? $doc['case_title'] : 'Unassigned case';
        $uploadedAt = isset($doc['uploaded_at']) ? date('M j, Y', strtotime($doc['uploaded_at'])) : '';

        // Determine file type icon
        $fileExtension = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
        $iconClass = 'ni-single-copy-04';
        switch ($fileExtension) {
            case 'pdf':
                $iconClass = 'ni-single-copy-04';
                break;
            case 'doc':
            case 'docx':
                $iconClass = 'ni-single-copy-04';
                break;
            case 'jpg':
            case 'jpeg':
            case 'png':
                $iconClass = 'ni-image';
                break;
            case 'txt':
                $iconClass = 'ni-single-copy-04';
                break;
        }

        $recentDocsList .= '
        <div class="document-item d-flex justify-content-between align-items-center p-3 border-bottom">
            <div class="d-flex align-items-center">
                <div class="icon-shape icon-sm bg-gradient-success shadow text-center rounded-circle me-3">
                    <i class="ni ' . $iconClass . ' text-white text-xs"></i>
                </div>
                <div>
                    <h6 class="mb-0 text-sm">' . htmlspecialchars($displayName) . '</h6>
                    <p class="text-xs text-muted mb-0">' . htmlspecialchars($caseTitle) . ' • ' . htmlspecialchars($uploadedAt) . '</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-primary" href="' . htmlspecialchars($downloadUrl) . '" target="_blank" title="View Document">
                    <i class="ni ni-zoom-split-in me-1"></i>View
                </a>
                <a class="btn btn-sm btn-success" href="' . htmlspecialchars($downloadUrl) . '" download title="Download Document">
                    <i class="ni ni-cloud-download-95 me-1"></i>Download
                </a>
            </div>
        </div>';
    }
}

$messageHtml = '';
if (!empty($message)) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType ? $messageType : 'info') . ' alert-dismissible fade show mx-3 mt-3" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$previewHtml = '';
if (!empty($previewContent)) {
    $previewHtml = '
    <div class="card mt-4">
        <div class="card-header pb-0 d-flex justify-content-between align-items-center">
            <h6 class="mb-0">' . htmlspecialchars($previewTitle) . '</h6>
            <button class="btn btn-sm btn-outline-dark" onclick="window.print()">Print</button>
        </div>
        <div class="card-body">
            <div class="border rounded p-3 bg-white" style="min-height: 200px;">
                ' . $previewContent . '
            </div>
        </div>
    </div>';
}

$totalDocuments = count($documents);
$totalTemplates = count($templates);
$casesWithDocs = 0;
foreach ($cases as $case) {
    if (!empty($case['total_docs'])) {
        $casesWithDocs++;
    }
}
$recentCount = count($recentDocuments);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro · Documents</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <style>
        .case-item:hover {
            background-color: #f8f9fa !important;
            transition: background-color 0.2s ease;
        }
        .document-item:hover {
            background-color: #f8f9fa !important;
            transition: background-color 0.2s ease;
        }
        .attach-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        .case-library-container::-webkit-scrollbar {
            width: 6px;
        }
        .case-library-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        .case-library-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        .case-library-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        .hover-shadow:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
            transition: box-shadow 0.2s ease;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
        <!-- replaced dynamically -->
    </aside>
    <main class="main-content position-relative border-radius-lg ">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Workspace</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Documents</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Case Documents & Legal Drafts</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE}
            <div class="row mb-4">
                <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Documents Stored</p>
                                        <h5 class="font-weight-bolder">{TOTAL_DOCS}</h5>
                                        <p class="mb-0 text-sm text-muted">{CASES_WITH_DOCS} cases attached</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-dark shadow text-center rounded-circle">
                                        <i class="ni ni-folder-17 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Templates</p>
                                        <h5 class="font-weight-bolder">{TOTAL_TEMPLATES}</h5>
                                        <p class="mb-0 text-sm text-muted">Reusable legal drafts</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-info shadow text-center rounded-circle">
                                        <i class="ni ni-single-copy-04 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Recent Uploads</p>
                                        <h5 class="font-weight-bolder">{RECENT_COUNT}</h5>
                                        <p class="mb-0 text-sm text-muted">Last 6 documents</p>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-success shadow text-center rounded-circle">
                                        <i class="ni ni-cloud-upload-96 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-5">
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Case Library</h6>
                            <p class="text-sm text-muted mb-0">Pick a matter and attach files directly.</p>
                        </div>
                        <div class="card-body p-0">
                            <div class="case-library-container" style="max-height: 400px; overflow-y: auto;">
                                {CASE_ROWS}
                            </div>
                        </div>
                    </div>
                    <div class="card mb-4" id="upload-card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Upload Document</h6>
                            <p class="text-sm text-muted mb-0" id="selected-case-label">Select a case above or choose below.</p>
                        </div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="form_type" value="upload">
                                <div class="mb-3">
                                    <label class="form-label">Case</label>
                                    <select class="form-select" name="case_id" id="upload_case_id" required>
                                        {CASE_OPTIONS}
                                    </select>
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
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Template Library</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Template</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Updated</th>
                                        <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {TEMPLATE_ROWS}
                                    </tbody>
                                </table>
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
                                        <textarea class="form-control" rows="3" name="template_body" placeholder="Use placeholders like {{client_name}}, {{case_number}}" required></textarea>
                                    </div>
                                    <button class="btn btn-sm btn-dark">Save Template</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Recent Documents</h6>
                            <p class="text-sm text-muted mb-0">Latest uploaded files</p>
                        </div>
                        <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                            {RECENT_DOCS}
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card mb-4">
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
                                         <select class="form-select" name="template_id" required>
                                            {TEMPLATE_OPTIONS}
                                         </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Case</label>
                                        <select class="form-select" name="case_for_template" required>
                                            {CASE_OPTIONS}
                                        </select>
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
                    </div>
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Documents by Case</h6>
                            <p class="text-sm text-muted mb-0">Review every upload grouped per matter.</p>
                        </div>
                        <div class="card-body">
                            <div class="accordion" id="documentsAccordion">
                                {DOCUMENT_ACCORDION}
                            </div>
                        </div>
                    </div>
                    {PREVIEW_HTML}
                </div>
            </div>
            <footer class="footer pt-3">
                <div class="container-fluid">
                    <div class="row align-items-center justify-content-lg-between">
                        <div class="col-lg-6 mb-lg-0 mb-4">
                            <div class="text-center text-sm text-muted text-lg-start">
                                © <script>document.write(new Date().getFullYear())</script>, LegalPro Case Manager.
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
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

            // Handle attach button clicks (both direct buttons and case item clicks)
            document.addEventListener('click', function(e) {
                var attachBtn = e.target.closest('[data-case-attach]');
                if (attachBtn) {
                    e.preventDefault();
                    var caseId = attachBtn.getAttribute('data-case-attach');
                    var caseLabel = attachBtn.getAttribute('data-case-label');

                    if (caseSelect) {
                        caseSelect.value = caseId;
                        // Trigger change event to update the label
                        caseSelect.dispatchEvent(new Event('change'));
                    }

                    if (labelEl && caseLabel) {
                        labelEl.textContent = 'Attaching to ' + caseLabel;
                    }

                    var uploadCard = document.getElementById('upload-card');
                    if (uploadCard) {
                        // Scroll to upload card with some offset for better visibility
                        var offset = 100;
                        var elementPosition = uploadCard.offsetTop;
                        var offsetPosition = elementPosition - offset;

                        window.scrollTo({
                            top: offsetPosition,
                            behavior: 'smooth'
                        });

                        // Highlight the upload card
                        uploadCard.classList.add('shadow-lg', 'border-primary');
                        uploadCard.style.borderWidth = '2px';
                        setTimeout(function() {
                            uploadCard.classList.remove('shadow-lg', 'border-primary');
                            uploadCard.style.borderWidth = '';
                        }, 2000);

                        // Focus on the file input
                        var fileInput = uploadCard.querySelector('input[type="file"]');
                        if (fileInput) {
                            setTimeout(function() {
                                fileInput.focus();
                            }, 1000);
                        }
                    }
                }
            });

            // Add hover effect for case items
            var caseItems = document.querySelectorAll('.case-item');
            caseItems.forEach(function(item) {
                item.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f8f9fa';
                });
                item.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });
        });
    </script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{TOTAL_DOCS}', number_format($totalDocuments), $html);
$html = str_replace('{CASES_WITH_DOCS}', $casesWithDocs, $html);
$html = str_replace('{TOTAL_TEMPLATES}', $totalTemplates, $html);
$html = str_replace('{RECENT_COUNT}', $recentCount, $html);
$html = str_replace('{CASE_ROWS}', $caseRows, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{TEMPLATE_ROWS}', $templatesRows, $html);
$html = str_replace('{RECENT_DOCS}', $recentDocsList, $html);
$html = str_replace('{DOCUMENT_ACCORDION}', $documentAccordion, $html);
$html = str_replace('{PREVIEW_HTML}', $previewHtml, $html);
$html = str_replace('{TEMPLATE_OPTIONS}', $templateOptions, $html);

// replace legacy html links, inject shared layout
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo $html;
?>
