<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/case_lawyers.php';
require_once __DIR__ . '/../lib/case_quotations.php';
require_once __DIR__ . '/../lib/case_quotations_ui.php';
require_once __DIR__ . '/../lib/lawyer_portal_vocab.php';

ensure_lawyer_case_vocabulary($pdo);

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];
$lawyerUserId = $_SESSION['lawyer_user_id'];

$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$case = null;

// Initialize case events tracking
require_once __DIR__ . '/../lib/case_events.php';

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $comment = trim($_POST['comment']);

    if (!empty($comment)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO case_comments (case_id, user_id, comment, comment_type) VALUES (?, ?, ?, 'lawyer')");
            $stmt->execute([$caseId, $lawyerUserId, $comment]);

            // Track comment addition
            CaseEvents::trackCommentAdded($caseId, [
                'comment' => $comment,
                'comment_type' => 'lawyer'
            ]);
        } catch (PDOException $e) {
            // Handle error silently for now
        }
    }
    header('Location: lawyer-case-view.php?id=' . $caseId . '#case-comments');
    exit;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $label = trim(isset($_POST['file_label']) ? $_POST['file_label'] : '');

    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/lawyer_files/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = uniqid() . '_' . basename($file['name']);
        $filePath = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO documents (case_id, filename, filepath, label, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$caseId, $file['name'], 'uploads/lawyer_files/' . $fileName, $label ?: $file['name'], $lawyerName]);

                CaseEvents::trackDocumentUploaded($caseId, [
                    'filename' => $file['name'],
                    'label' => $label ?: $file['name'],
                ]);
            } catch (PDOException $e) {
                // Handle error silently for now
            }
        }
    }
    header('Location: lawyer-case-view.php?id=' . $caseId . '#case-documents');
    exit;
}

// Check if this case is assigned to the logged-in lawyer or linked via an appointment
try {
    if (!lawyerHasCaseAccess($pdo, $caseId, $lawyerId)) {
        die('Access denied: This case is not assigned to you.');
    }
    ensureLawyerAssignedToCase($pdo, $caseId, $lawyerId);
} catch (PDOException $e) {
    die('Error checking case access: ' . htmlspecialchars($e->getMessage()));
}

// Handle comment deletion (lawyer's own comments on this case only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment_id'])) {
    $commentId = (int) $_POST['delete_comment_id'];
    if ($commentId > 0 && $lawyerUserId) {
        try {
            $stmt = $pdo->prepare("
                SELECT cc.id
                FROM case_comments cc
                INNER JOIN case_lawyers cl ON cl.case_id = cc.case_id AND cl.lawyer_id = ?
                WHERE cc.id = ? AND cc.case_id = ? AND cc.user_id = ?
            ");
            $stmt->execute([$lawyerId, $commentId, $caseId, $lawyerUserId]);
            if ($stmt->fetch()) {
                $del = $pdo->prepare('DELETE FROM case_comments WHERE id = ? AND case_id = ?');
                $del->execute([$commentId, $caseId]);
            }
        } catch (PDOException $e) {
            // Deletion failed silently; page will reload without changes
        }
    }
    header('Location: lawyer-case-view.php?id=' . $caseId . '#case-comments');
    exit;
}

// Handle document deletion (assigned lawyer, documents on this case)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document_id'])) {
    $documentId = (int) $_POST['delete_document_id'];
    if ($documentId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT d.id, d.filepath, d.filename, d.label
                FROM documents d
                INNER JOIN case_lawyers cl ON cl.case_id = d.case_id AND cl.lawyer_id = ?
                WHERE d.id = ? AND d.case_id = ?
            ");
            $stmt->execute([$lawyerId, $documentId, $caseId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($doc) {
                $del = $pdo->prepare('DELETE FROM documents WHERE id = ? AND case_id = ?');
                $del->execute([$documentId, $caseId]);

                $relativePath = ltrim((string) ($doc['filepath'] ?? ''), '/\\');
                if ($relativePath !== '') {
                    $fsPath = __DIR__ . '/../' . $relativePath;
                    if (is_file($fsPath)) {
                        @unlink($fsPath);
                    }
                }

                CaseEvents::trackDocumentDeleted($caseId, [
                    'filename' => (string) ($doc['filename'] ?? basename($relativePath)),
                    'label' => (string) ($doc['label'] ?? ''),
                ]);
            }
        } catch (PDOException $e) {
            // Deletion failed silently; page will reload without changes
        }
    }
    header('Location: lawyer-case-view.php?id=' . $caseId . '#case-documents');
    exit;
}

// Fetch case details
try {
    $stmt = $pdo->prepare("
        SELECT c.*, cl.first_name, cl.last_name, cl.email, cl.phone,
               GROUP_CONCAT(DISTINCT l.first_name, ' ', l.last_name SEPARATOR ', ') as assigned_lawyers
        FROM cases c
        INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
        INNER JOIN clients cl ON cl.id = c.client_id
        INNER JOIN lawyers l ON l.id = cl2.lawyer_id
        WHERE c.id = ? AND cl2.lawyer_id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$caseId, $lawyerId]);
    $case = $stmt->fetch();

    if (!$case) {
        die('Case not found or access denied.');
    }
} catch (PDOException $e) {
    die('Error loading case: ' . htmlspecialchars($e->getMessage()));
}

ensure_case_quotation_schema($pdo);
$quotationsView = case_quotations_build_readonly_view($pdo, $caseId);
$quotationsPanelHtml = $quotationsView['html'];

// Fetch case services
$services = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at");
    $stmt->execute([$caseId]);
    $services = $stmt->fetchAll();
} catch (PDOException $e) {
    $services = [];
}

// Fetch case stages
$stages = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number");
    $stmt->execute([$caseId]);
    $stages = $stmt->fetchAll();
} catch (PDOException $e) {
    $stages = [];
}

// Fetch appointments
$appointments = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE case_id = ? ORDER BY appointment_date DESC, appointment_time DESC");
    $stmt->execute([$caseId]);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $appointments = [];
}

// Fetch documents
$documents = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE case_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$caseId]);
    $documents = $stmt->fetchAll();
} catch (PDOException $e) {
    $documents = [];
}

// Fetch comments
$comments = [];
try {
    $stmt = $pdo->prepare("
        SELECT cc.*, u.username,
               CASE
                   WHEN cc.comment_type = 'client' THEN CONCAT('Client: ', u.username)
                   WHEN cc.comment_type = 'lawyer' THEN CONCAT('Lawyer: ', u.username)
                   WHEN cc.comment_type = 'admin' THEN 'Admin'
                   WHEN cc.comment_type = 'staff' THEN CONCAT('Staff: ', u.username)
                   ELSE 'System'
               END as commenter_name,
               cc.comment_type as user_type
        FROM case_comments cc
        LEFT JOIN users u ON u.id = cc.user_id
        WHERE cc.case_id = ? AND cc.is_private = 0
        ORDER BY cc.created_at ASC
    ");
    $stmt->execute([$caseId]);
    $comments = $stmt->fetchAll();
} catch (PDOException $e) {
    $comments = [];
}

// Fetch case events for this case
$caseEvents = CaseEvents::getCaseEvents($caseId);

$statusBadge = lawyer_case_status_badge((string) ($case['status'] ?? ''));
$priorityBadge = lawyer_case_priority_badge((string) ($case['priority'] ?? 'Normal'));
$categoryLabel = trim((string) ($case['category'] ?? ''));
$categoryBadge = $categoryLabel !== ''
    ? '<span class="lc-category-pill">' . htmlspecialchars($categoryLabel) . '</span>'
    : '<span class="ca-status-pill ca-status-pill--muted">—</span>';
$iconDocRow = legalpro_icon('file-text');
$iconCommentEmpty = legalpro_icon('message-circle');
$iconBriefcase = legalpro_icon('briefcase');
$iconMail = legalpro_icon('mail');
$iconPhone = legalpro_icon('phone');
$iconUser = legalpro_icon('user');
$iconCalendar = legalpro_icon('calendar');
$iconArrowLeft = legalpro_icon('arrow-left');
$iconLayers = legalpro_icon('layers');
$iconClock = legalpro_icon('clock');
$iconQuote = legalpro_icon('receipt');
$iconActivity = legalpro_icon('activity');
$iconUpload = legalpro_icon('upload');

// Build services HTML
$servicesHtml = '';
$totalFees = 0;
if (empty($services)) {
    $servicesHtml = '<tr><td colspan="2" class="text-center text-muted py-3">' . htmlspecialchars(lawyer_tf('case_view.no_services', 'No services added yet')) . '</td></tr>';
} else {
    foreach ($services as $service) {
        $servicesHtml .= '
        <tr>
            <td>' . htmlspecialchars($service['service_name']) . '</td>
            <td class="text-end">' . formatCurrency($service['price']) . '</td>
        </tr>';
        $totalFees += $service['price'];
    }
    $servicesHtml .= '
    <tr class="lcv-table-total">
        <td><strong>' . htmlspecialchars(lawyer_tf('case_view.total_fees', 'Total estimated fees')) . '</strong></td>
        <td class="text-end"><strong>' . formatCurrency($totalFees) . '</strong></td>
    </tr>';
}

// Build stages HTML
$stagesHtml = '';
if (empty($stages)) {
    $stagesHtml = '<tr><td colspan="6" class="text-center text-muted py-3">' . htmlspecialchars(lawyer_tf('case_view.no_stages', 'No stages defined yet')) . '</td></tr>';
} else {
    foreach ($stages as $stage) {
        $stagesHtml .= '
        <tr>
            <td>' . htmlspecialchars($stage['stage_number']) . '</td>
            <td>' . htmlspecialchars($stage['title']) . '</td>
            <td>' . htmlspecialchars($stage['description'] ?: 'No description') . '</td>
            <td>' . htmlspecialchars($stage['result'] ?: 'Pending') . '</td>
            <td>' . ($stage['start_date'] ? date('M d, Y', strtotime($stage['start_date'])) : 'Not set') . '</td>
            <td>' . ($stage['actual_end_date'] ? date('M d, Y', strtotime($stage['actual_end_date'])) : 'Pending') . '</td>
        </tr>';
    }
}

// Build appointments HTML
$appointmentsHtml = '';
if (empty($appointments)) {
    $appointmentsHtml = '<tr><td colspan="4" class="text-center text-muted py-3">' . htmlspecialchars(lawyer_tf('case_view.no_appointments', 'No appointments scheduled')) . '</td></tr>';
} else {
    foreach ($appointments as $appointment) {
        $appointmentDate = date('M d, Y', strtotime($appointment['appointment_date']));
        $appointmentTime = $appointment['appointment_time'];
        $statusClass = strtotime($appointment['appointment_date']) < time() ? 'text-muted' : 'text-dark';

        $appointmentsHtml .= '
        <tr class="' . $statusClass . '">
            <td>' . htmlspecialchars($appointmentDate) . '</td>
            <td>' . htmlspecialchars($appointmentTime) . '</td>
            <td>' . htmlspecialchars($appointment['description'] ?: 'No description') . '</td>
            <td>' . htmlspecialchars($appointment['location'] ?: 'Not specified') . '</td>
        </tr>';
    }
}

// Build documents HTML
$documentsHtml = '';
if (empty($documents)) {
    $documentsHtml = '<tr><td colspan="4" class="text-center text-muted py-3">' . htmlspecialchars(lawyer_tf('case_view.no_documents', 'No documents uploaded')) . '</td></tr>';
} else {
    foreach ($documents as $document) {
        $documentId = (int) ($document['id'] ?? 0);
        $documentPath = isset($document['filepath']) ? (string) $document['filepath'] : '';
        $documentName = !empty($document['label']) ? $document['label'] : (!empty($document['filename']) ? $document['filename'] : basename($documentPath));
        $fileUrl = '../' . ltrim($documentPath, '/\\');
        $fileSystemPath = __DIR__ . '/../' . ltrim($documentPath, '/\\');
        $fileSize = ($documentPath !== '' && is_file($fileSystemPath)) ? filesize($fileSystemPath) : false;
        $fileSizeFormatted = $fileSize ? round($fileSize / 1024, 1) . ' KB' : 'Unknown';
        $fileType = !empty($document['filename']) ? strtoupper(pathinfo($document['filename'], PATHINFO_EXTENSION)) : 'File';

        $actionButtons = '';
        if ($documentPath !== '' && is_file($fileSystemPath)) {
            $actionButtons .= '
                <a href="' . htmlspecialchars($fileUrl) . '" target="_blank" class="btn btn-sm lp-portal-accent-btn mb-0">View</a>
                <a href="' . htmlspecialchars($fileUrl) . '" download class="btn btn-sm btn-outline-secondary mb-0">Download</a>';
        }
        if ($documentId > 0) {
            $actionButtons .= '
                <form method="post" class="d-inline mb-0" onsubmit="return confirm(\'Remove this document permanently?\');">
                    <input type="hidden" name="delete_document_id" value="' . $documentId . '">
                    <button type="submit" class="btn btn-sm btn-outline-danger mb-0">Remove</button>
                </form>';
        }
        if ($actionButtons === '') {
            $actionButtons = '<span class="text-xs text-muted">Unavailable</span>';
        }

        $documentsHtml .= '
        <tr>
            <td class="align-middle">
                <div class="d-flex align-items-center">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconDocRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($documentName) . '</h6>
                        <p class="text-xs text-muted mb-0">Uploaded ' . date('M d, Y', strtotime($document['uploaded_at'])) . '</p>
                    </div>
                </div>
            </td>
            <td class="align-middle text-center">' . htmlspecialchars($fileType) . '</td>
            <td class="align-middle text-center">' . $fileSizeFormatted . '</td>
            <td class="align-middle text-end lp-table-actions">
                <div class="lp-table-actions-inner">' . $actionButtons . '</div>
            </td>
        </tr>';
    }
}

$clientFullName = trim($case['first_name'] . ' ' . $case['last_name']);
$clientId = (int) ($case['client_id'] ?? 0);
$caseInitials = legalpro_portal_initials((string) $case['title'], 'CS');
$caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
$servicesCount = count($services);
$stagesCount = count($stages);
$appointmentsCount = count($appointments);
$documentsCount = count($documents);
$commentsCount = count($comments);

$clientDetailsHtml = '';
$clientRows = [
    ['icon' => $iconUser, 'label' => 'Client', 'value' => $clientFullName],
    ['icon' => $iconMail, 'label' => 'Email', 'value' => $case['email'] ?: 'Not provided'],
    ['icon' => $iconPhone, 'label' => 'Phone', 'value' => $case['phone'] ?: 'Not provided'],
];
foreach ($clientRows as $row) {
    $clientDetailsHtml .= '
    <div class="lcv-detail-item">
        <div class="lcv-detail-item__icon">' . $row['icon'] . '</div>
        <div class="min-width-0">
            <span class="lcv-detail-item__label">' . htmlspecialchars($row['label']) . '</span>
            <span class="lcv-detail-item__value">' . htmlspecialchars($row['value']) . '</span>
        </div>
    </div>';
}

$caseDetailsHtml = '';
$caseRows = [
    ['icon' => $iconCalendar, 'label' => 'Created', 'value' => date('M d, Y', strtotime($case['created_at']))],
    ['icon' => $iconClock, 'label' => 'Last updated', 'value' => date('M d, Y', strtotime(!empty($case['updated_at']) ? $case['updated_at'] : $case['created_at']))],
    ['icon' => $iconUser, 'label' => 'Assigned lawyers', 'value' => $case['assigned_lawyers'] ?: 'Not assigned'],
];
foreach ($caseRows as $row) {
    $caseDetailsHtml .= '
    <div class="lcv-detail-item">
        <div class="lcv-detail-item__icon">' . $row['icon'] . '</div>
        <div class="min-width-0">
            <span class="lcv-detail-item__label">' . htmlspecialchars($row['label']) . '</span>
            <span class="lcv-detail-item__value">' . htmlspecialchars($row['value']) . '</span>
        </div>
    </div>';
}

$caseDescHeroHtml = '';
if (!empty($case['description'])) {
    $caseDescHeroHtml = '
    <div class="lcv-case-desc">
        <h6>' . htmlspecialchars(lawyer_tf('tasks.form_description', 'Description')) . '</h6>
        <p>' . nl2br(htmlspecialchars((string) $case['description'])) . '</p>
    </div>';
}

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

$pageTitle = (string) ($case['title'] ?? lawyer_tf('case_view.page_title', 'Case Details'));
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle, [
    ['label' => lawyer_tf('cases.page_title', 'My Cases'), 'url' => 'lawyer-cases.php'],
]);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-case-view-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}

        <div class="container-fluid py-4">
            <div class="card lcv-hero mb-4">
                <div class="lcv-hero__gradient">
                    <a href="lawyer-cases.php" class="lcv-back">{ICON_ARROW_LEFT} {LBL_BACK_TO_CASES}</a>
                    <div class="lcv-hero__main">
                        <div class="lcv-avatar" aria-hidden="true">{CASE_INITIALS}</div>
                        <div class="min-width-0">
                            <h1 class="lcv-hero__name">{CASE_TITLE}</h1>
                            <p class="lcv-hero__meta">{CASE_NUMBER} · {LBL_CLIENT_PREFIX} {CLIENT_NAME}</p>
                            <div class="lcv-hero__badges">{STATUS_BADGE} {PRIORITY_BADGE} {CATEGORY_BADGE}</div>
                        </div>
                    </div>
                    {CASE_DESC_HERO}
                </div>
            </div>

            <div class="lcv-glance">
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{SERVICES_COUNT}</div>
                        <div class="lcv-glance__lbl">{LBL_SERVICES}</div>
                    </div>
                    <div class="lcv-glance__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">{ICON_BRIEFCASE}</div>
                </div>
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{APPOINTMENTS_COUNT}</div>
                        <div class="lcv-glance__lbl">{LBL_APPOINTMENTS}</div>
                    </div>
                    <div class="lcv-glance__icon" style="background:rgba(17,205,239,.12);color:#11cdef;">{ICON_CALENDAR}</div>
                </div>
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{DOCUMENTS_COUNT}</div>
                        <div class="lcv-glance__lbl">{LBL_DOCUMENTS}</div>
                    </div>
                    <div class="lcv-glance__icon" style="background:rgba(251,99,64,.12);color:#fb6340;">{ICON_DOC_ROW}</div>
                </div>
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{COMMENTS_COUNT}</div>
                        <div class="lcv-glance__lbl">{LBL_COMMENTS}</div>
                    </div>
                    <div class="lcv-glance__icon" style="background:rgba(45,206,137,.12);color:#2dce89;">{ICON_COMMENT_EMPTY}</div>
                </div>
            </div>

            <div class="row mb-4 g-4">
                <div class="col-lg-6">
                    <div class="card lcv-panel h-100">
                        <div class="lcv-panel__head">
                            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">{ICON_USER}</div>
                            <div>
                                <h6>{LBL_CLIENT_INFO}</h6>
                                <p>{LBL_CLIENT_INFO_SUB}</p>
                            </div>
                            {CLIENT_VIEW_LINK}
                        </div>
                        <div class="lcv-panel__body">
                            <div class="lcv-detail-grid">{CLIENT_DETAILS}</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card lcv-panel h-100">
                        <div class="lcv-panel__head">
                            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">{ICON_BRIEFCASE}</div>
                            <div>
                                <h6>{LBL_CASE_INFO}</h6>
                                <p>{LBL_CASE_INFO_SUB}</p>
                            </div>
                        </div>
                        <div class="lcv-panel__body">
                            <div class="lcv-detail-grid">{CASE_DETAILS}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card lcv-panel">
                <div class="lcv-panel__head">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">{ICON_LAYERS}</div>
                    <div>
                        <h6>{LBL_WORKSPACE}</h6>
                        <p>{LBL_WORKSPACE_SUB}</p>
                    </div>
                </div>
                <div class="lcv-tabs-wrap">
                    <ul class="nav lcv-tabs" id="caseTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="services-tab" data-bs-toggle="tab" data-bs-target="#services" type="button" role="tab">
                                {LBL_TAB_SERVICES} <span class="lcv-tab-badge">{SERVICES_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="stages-tab" data-bs-toggle="tab" data-bs-target="#stages" type="button" role="tab">
                                {LBL_TAB_STAGES} <span class="lcv-tab-badge">{STAGES_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments" type="button" role="tab">
                                {LBL_TAB_APPOINTMENTS} <span class="lcv-tab-badge">{APPOINTMENTS_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#case-documents" type="button" role="tab">
                                {LBL_TAB_DOCUMENTS} <span class="lcv-tab-badge">{DOCUMENTS_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="quotations-tab" data-bs-toggle="tab" data-bs-target="#quotations" type="button" role="tab">
                                {LBL_TAB_QUOTATIONS} <span class="lcv-tab-badge">{QUOTATION_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="case-comments-tab" data-bs-toggle="tab" data-bs-target="#case-comments" type="button" role="tab">
                                {LBL_TAB_COMMENTS} <span class="lcv-tab-badge">{COMMENTS_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">{LBL_TAB_EVENTS}</button>
                        </li>
                    </ul>
                </div>
                <div class="lcv-panel__body pt-3">
                    <div class="tab-content" id="caseTabsContent">
                        <div class="tab-pane fade show active" id="services" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table lcv-table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th>{LBL_COL_SERVICE}</th>
                                            <th class="text-end">{LBL_COL_PRICE}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {SERVICES_HTML}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="stages" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table lcv-table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th>{LBL_COL_STAGE_NUM}</th>
                                            <th>{LBL_COL_TITLE}</th>
                                            <th>{LBL_COL_DESCRIPTION}</th>
                                            <th>{LBL_COL_RESULT}</th>
                                            <th>{LBL_COL_START_DATE}</th>
                                            <th>{LBL_COL_END_DATE}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {STAGES_HTML}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="appointments" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table lcv-table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th>{LBL_COL_DATE}</th>
                                            <th>{LBL_COL_TIME}</th>
                                            <th>{LBL_COL_DESCRIPTION}</th>
                                            <th>{LBL_COL_LOCATION}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {APPOINTMENTS_HTML}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="case-documents" role="tabpanel">
                            <div class="d-flex justify-content-end mb-3">
                                <button type="button"
                                        class="btn btn-sm btn-primary mb-0 d-inline-flex align-items-center gap-1"
                                        id="lawyerUploadDocToggle"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#lawyerUploadDocPanel"
                                        aria-expanded="false"
                                        aria-controls="lawyerUploadDocPanel">
                                    {ICON_UPLOAD} {LBL_UPLOAD_DOCUMENT}
                                </button>
                            </div>
                            <div id="lawyerUploadDocPanel" class="collapse">
                                <div class="lcv-upload-card">
                                    <form method="POST" action="" enctype="multipart/form-data">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-8">
                                                <label for="lawyer-doc-file-label" class="form-label text-sm mb-1">{LBL_COL_DESCRIPTION}</label>
                                                <input type="text" class="form-control" id="lawyer-doc-file-label" name="file_label" placeholder="{PH_DOC_DESC}">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="lawyer-doc-file-input" class="form-label text-sm mb-1">{LBL_FILE}</label>
                                                <input type="file" class="form-control" id="lawyer-doc-file-input" name="file" required>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-end gap-2 mt-3">
                                            <button type="button" class="btn btn-sm btn-outline-secondary mb-0" data-bs-toggle="collapse" data-bs-target="#lawyerUploadDocPanel">{LBL_CANCEL}</button>
                                            <button type="submit" class="btn btn-sm btn-primary mb-0">{LBL_UPLOAD_FILE}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table lcv-table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th class="text-center">Type</th>
                                            <th class="text-center">Size</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {DOCUMENTS_HTML}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="quotations" role="tabpanel">
                            {QUOTATIONS_PANEL}
                        </div>

                        <div class="tab-pane fade" id="case-comments" role="tabpanel">
                            <div id="lawyer-case-comments" class="lawyer-case-comments">
                                <p class="text-sm text-muted mb-3">Discussion and updates shared on this case</p>
                                {COMMENTS_HTML}
                                <form method="POST" action="" class="cc-comment-form mt-4 pt-4 border-top">
                                    <label for="lawyer-case-comment-input" class="form-label text-sm font-weight-bold mb-2">Add a comment</label>
                                    <textarea id="lawyer-case-comment-input" class="form-control" name="comment" rows="4" placeholder="Write your comment here…" required></textarea>
                                    <div class="d-flex justify-content-end mt-3">
                                        <button type="submit" class="btn btn-primary mb-0">Post comment</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="events" role="tabpanel">
                            {EVENTS_HTML}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer pt-3">
            <div class="container-fluid">
                <div class="row align-items-center justify-content-lg-between">
                    <div class="col-lg-6 mb-lg-0 mb-4">
                        <div class="copyright text-center text-sm text-muted text-lg-start">
                            {COPYRIGHT_LINE}
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var hash = window.location.hash;
        if (hash === '#case-documents' || hash === '#case-comments' || hash === '#lawyer-case-comments' || hash === '#quotations') {
            var target = hash === '#lawyer-case-comments' ? '#case-comments' : hash;
            var tabBtn = document.querySelector('[data-bs-target="' + target + '"]');
            if (tabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(tabBtn).show();
            }
        }

        var uploadToggle = document.getElementById('lawyerUploadDocToggle');
        var uploadPanel = document.getElementById('lawyerUploadDocPanel');
        if (uploadToggle && uploadPanel && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
            uploadPanel.addEventListener('show.bs.collapse', function () {
                uploadToggle.innerHTML = '{ICON_UPLOAD} Hide upload';
            });
            uploadPanel.addEventListener('hide.bs.collapse', function () {
                uploadToggle.innerHTML = '{ICON_UPLOAD} Upload document';
            });
        }
    });
    </script>
</body>
</html>
HTML;

$commentRoleBadge = static function (string $type): string {
    switch ($type) {
        case 'client':
            return '<span class="cc-comment-role badge badge-sm bg-gradient-secondary">Client</span>';
        case 'lawyer':
            return '<span class="cc-comment-role badge badge-sm bg-gradient-success">Lawyer</span>';
        case 'admin':
            return '<span class="cc-comment-role badge badge-sm bg-gradient-warning">Admin</span>';
        case 'staff':
            return '<span class="cc-comment-role badge badge-sm bg-gradient-secondary">Staff</span>';
        default:
            return '<span class="cc-comment-role badge badge-sm bg-gradient-secondary">System</span>';
    }
};

// Build comments feed (left-aligned, full width)
$commentsHtml = '';
if (!empty($comments)) {
    $commentsHtml .= '<ul class="cc-comment-list list-unstyled mb-0">';
    foreach ($comments as $comment) {
        $isCurrentUser = ((int) ($comment['user_id'] ?? 0) === (int) $lawyerUserId);
        $type = (string) ($comment['comment_type'] ?? '');
        $itemClass = 'cc-comment-item cc-comment-item--' . preg_replace('/[^a-z]/', '', $type);
        if ($isCurrentUser) {
            $itemClass .= ' cc-comment-item--yours';
        }
        $timeLabel = date('M j, Y · g:i A', strtotime($comment['created_at']));
        $body = nl2br(htmlspecialchars((string) ($comment['comment'] ?? '')));
        $authorLabel = htmlspecialchars((string) ($comment['commenter_name'] ?? 'User'));
        $roleBadge = $commentRoleBadge($type);
        $youBadge = $isCurrentUser ? '<span class="badge badge-sm bg-gradient-success ms-1">You</span>' : '';
        $commentId = (int) ($comment['id'] ?? 0);
        $deleteBtn = '';
        if ($isCurrentUser && $commentId > 0) {
            $deleteBtn = '
            <form method="post" class="cc-comment-delete-form" onsubmit="return confirm(\'Delete this comment permanently?\');">
                <input type="hidden" name="delete_comment_id" value="' . $commentId . '">
                <button type="submit" class="btn btn-sm btn-outline-danger mb-0">Delete</button>
            </form>';
        }

        $commentsHtml .= '
        <li class="' . $itemClass . '">
            <div class="cc-comment-item-inner">
                <div class="cc-comment-head">
                    <div class="cc-comment-head-main">
                        <span class="cc-comment-author">' . $authorLabel . '</span>
                        ' . $youBadge . '
                        ' . $roleBadge . '
                    </div>
                    <div class="cc-comment-head-actions">
                        <time class="cc-comment-time" datetime="' . htmlspecialchars(date('c', strtotime($comment['created_at']))) . '">' . htmlspecialchars($timeLabel) . '</time>
                        ' . $deleteBtn . '
                    </div>
                </div>
                <div class="cc-comment-text">' . $body . '</div>
            </div>
        </li>';
    }
    $commentsHtml .= '</ul>';
} else {
    $commentsHtml = '
    <div class="cc-comments-empty text-center py-5 mb-0">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success mx-auto d-flex align-items-center justify-content-center">' . $iconCommentEmpty . '</div>
        <h6 class="font-weight-bolder mt-4 mb-2">' . htmlspecialchars(lawyer_tf('case_view.no_comments', 'No comments yet')) . '</h6>
        <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">Post a comment below to communicate with the client and your team about this case.</p>
    </div>';
}

// Build events HTML using the new CaseEvents class
$eventsHtml = CaseEvents::renderEventsTimeline($caseId);

$clientViewLinkHtml = $clientId > 0
    ? '<a href="lawyer-client-view.php?id=' . $clientId . '" class="lcv-panel__head-actions btn btn-sm lp-portal-accent-btn mb-0">' . htmlspecialchars(lawyer_tf('case_view.view_client', 'View client')) . '</a>'
    : '';

$replacements = [
    '{HTML_LANG}' => lawyer_portal_html_lang(),
    '{PAGE_TITLE}' => htmlspecialchars($pageTitle),
    '{BREADCRUMB_NAVBAR}' => $breadcrumbNavbar,
    '{LBL_BACK_TO_CASES}' => htmlspecialchars(lawyer_tf('case_view.back_to_cases', 'Back to cases')),
    '{LBL_CLIENT_PREFIX}' => htmlspecialchars(lawyer_tf('case_view.client_prefix', 'Client:')),
    '{LBL_SERVICES}' => htmlspecialchars(lawyer_tf('common.services', 'Services')),
    '{LBL_APPOINTMENTS}' => htmlspecialchars(lawyer_tf('nav.appointments', 'Appointments')),
    '{LBL_DOCUMENTS}' => htmlspecialchars(lawyer_tf('common.documents', 'Documents')),
    '{LBL_COMMENTS}' => htmlspecialchars(lawyer_tf('common.comments', 'Comments')),
    '{LBL_CLIENT_INFO}' => htmlspecialchars(lawyer_tf('case_view.client_info', 'Client information')),
    '{LBL_CLIENT_INFO_SUB}' => htmlspecialchars(lawyer_tf('case_view.client_info_sub', 'Contact details for this case')),
    '{LBL_CASE_INFO}' => htmlspecialchars(lawyer_tf('case_view.case_info', 'Case information')),
    '{LBL_CASE_INFO_SUB}' => htmlspecialchars(lawyer_tf('case_view.case_info_sub', 'Timeline and assignment details')),
    '{LBL_WORKSPACE}' => htmlspecialchars(lawyer_tf('case_view.workspace', 'Case workspace')),
    '{LBL_WORKSPACE_SUB}' => htmlspecialchars(lawyer_tf('case_view.workspace_sub', 'Services, documents, quotations, and activity for this case')),
    '{LBL_TAB_SERVICES}' => htmlspecialchars(lawyer_tf('common.services', 'Services')),
    '{LBL_TAB_STAGES}' => htmlspecialchars(lawyer_tf('case_view.tab_stages', 'Stages')),
    '{LBL_TAB_APPOINTMENTS}' => htmlspecialchars(lawyer_tf('nav.appointments', 'Appointments')),
    '{LBL_TAB_DOCUMENTS}' => htmlspecialchars(lawyer_tf('common.documents', 'Documents')),
    '{LBL_TAB_QUOTATIONS}' => htmlspecialchars(lawyer_tf('case_view.tab_quotations', 'Quotations')),
    '{LBL_TAB_COMMENTS}' => htmlspecialchars(lawyer_tf('common.comments', 'Comments')),
    '{LBL_TAB_EVENTS}' => htmlspecialchars(lawyer_tf('case_view.tab_events', 'Events')),
    '{LBL_COL_SERVICE}' => htmlspecialchars(lawyer_tf('case_view.col_service', 'Service')),
    '{LBL_COL_PRICE}' => htmlspecialchars(lawyer_tf('case_view.col_price', 'Price')),
    '{LBL_COL_STAGE_NUM}' => htmlspecialchars(lawyer_tf('case_view.col_stage_num', 'Stage #')),
    '{LBL_COL_TITLE}' => htmlspecialchars(lawyer_tf('case_view.col_title', 'Title')),
    '{LBL_COL_DESCRIPTION}' => htmlspecialchars(lawyer_tf('tasks.form_description', 'Description')),
    '{LBL_COL_RESULT}' => htmlspecialchars(lawyer_tf('case_view.col_result', 'Result')),
    '{LBL_COL_START_DATE}' => htmlspecialchars(lawyer_tf('case_view.col_start_date', 'Start date')),
    '{LBL_COL_END_DATE}' => htmlspecialchars(lawyer_tf('case_view.col_end_date', 'End date')),
    '{LBL_COL_DATE}' => htmlspecialchars(lawyer_tf('case_view.col_date', 'Date')),
    '{LBL_COL_TIME}' => htmlspecialchars(lawyer_tf('case_view.col_time', 'Time')),
    '{LBL_COL_LOCATION}' => htmlspecialchars(lawyer_tf('case_view.col_location', 'Location')),
    '{LBL_UPLOAD_DOCUMENT}' => htmlspecialchars(lawyer_tf('case_view.upload_document', 'Upload document')),
    '{PH_DOC_DESC}' => htmlspecialchars(lawyer_tf('case_view.doc_desc_placeholder', 'Document description (optional)')),
    '{LBL_FILE}' => htmlspecialchars(lawyer_tf('case_view.file', 'File')),
    '{LBL_CANCEL}' => htmlspecialchars(lawyer_tf('common.cancel', 'Cancel')),
    '{LBL_UPLOAD_FILE}' => htmlspecialchars(lawyer_tf('case_view.upload_file', 'Upload file')),
    '{NAVIGATION}' => $navHtml,
    '{CASE_ID}' => $caseId,
    '{CASE_NUMBER}' => htmlspecialchars($caseNumber),
    '{CASE_TITLE}' => htmlspecialchars($case['title']),
    '{CASE_INITIALS}' => htmlspecialchars($caseInitials),
    '{CLIENT_NAME}' => htmlspecialchars($clientFullName),
    '{CLIENT_DETAILS}' => $clientDetailsHtml,
    '{CASE_DETAILS}' => $caseDetailsHtml,
    '{CASE_DESC_HERO}' => $caseDescHeroHtml,
    '{CLIENT_VIEW_LINK}' => $clientViewLinkHtml,
    '{STATUS_BADGE}' => $statusBadge,
    '{PRIORITY_BADGE}' => $priorityBadge,
    '{CATEGORY_BADGE}' => $categoryBadge,
    '{SERVICES_COUNT}' => (string) $servicesCount,
    '{STAGES_COUNT}' => (string) $stagesCount,
    '{APPOINTMENTS_COUNT}' => (string) $appointmentsCount,
    '{DOCUMENTS_COUNT}' => (string) $documentsCount,
    '{COMMENTS_COUNT}' => (string) $commentsCount,
    '{SERVICES_HTML}' => $servicesHtml,
    '{STAGES_HTML}' => $stagesHtml,
    '{APPOINTMENTS_HTML}' => $appointmentsHtml,
    '{DOCUMENTS_HTML}' => $documentsHtml,
    '{COMMENTS_HTML}' => $commentsHtml,
    '{EVENTS_HTML}' => $eventsHtml,
    '{QUOTATIONS_PANEL}' => $quotationsPanelHtml,
    '{QUOTATION_COUNT}' => (string) $quotationsView['count'],
    '{ICON_ARROW_LEFT}' => $iconArrowLeft,
    '{ICON_BRIEFCASE}' => $iconBriefcase,
    '{ICON_CALENDAR}' => $iconCalendar,
    '{ICON_DOC_ROW}' => $iconDocRow,
    '{ICON_COMMENT_EMPTY}' => $iconCommentEmpty,
    '{ICON_USER}' => $iconUser,
    '{ICON_LAYERS}' => $iconLayers,
    '{ICON_UPLOAD}' => $iconUpload,
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
?>
