<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/case_lawyers.php';

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
    header('Location: lawyer-case-view.php?id=' . $caseId . '#lawyer-case-comments');
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

$statusBadge = client_case_status_badge((string) ($case['status'] ?? ''));
$priorityBadge = client_case_priority_badge((string) ($case['priority'] ?? 'Normal'));
$categoryLabel = trim((string) ($case['category'] ?? ''));
$categoryBadge = $categoryLabel !== ''
    ? '<span class="lc-category-pill">' . htmlspecialchars($categoryLabel) . '</span>'
    : '<span class="ca-status-pill ca-status-pill--muted">—</span>';
$iconDocRow = legalpro_icon('file-text');
$iconCommentEmpty = legalpro_icon('message-circle');

// Build services HTML
$servicesHtml = '';
$totalFees = 0;
if (empty($services)) {
    $servicesHtml = '<tr><td colspan="3" class="text-center text-muted py-3">No services added yet</td></tr>';
} else {
    foreach ($services as $service) {
        $servicesHtml .= '
        <tr>
            <td>' . htmlspecialchars($service['service_name']) . '</td>
            <td class="text-end">$' . number_format($service['price'], 2) . '</td>
        </tr>';
        $totalFees += $service['price'];
    }
    $servicesHtml .= '
    <tr class="table-active">
        <td><strong>Total Estimated Fees</strong></td>
        <td class="text-end"><strong>$' . number_format($totalFees, 2) . '</strong></td>
    </tr>';
}

// Build stages HTML
$stagesHtml = '';
if (empty($stages)) {
    $stagesHtml = '<tr><td colspan="6" class="text-center text-muted py-3">No stages defined yet</td></tr>';
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
    $appointmentsHtml = '<tr><td colspan="4" class="text-center text-muted py-3">No appointments scheduled</td></tr>';
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
    $documentsHtml = '<tr><td colspan="4" class="text-center text-muted py-3">No documents uploaded</td></tr>';
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
                <a href="' . htmlspecialchars($fileUrl) . '" target="_blank" class="btn btn-sm btn-outline-primary mb-0">View</a>
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
            <td>
                <div class="d-flex align-items-center">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconDocRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($documentName) . '</h6>
                        <p class="text-xs text-muted mb-0">Uploaded ' . date('M d, Y', strtotime($document['uploaded_at'])) . '</p>
                    </div>
                </div>
            </td>
            <td class="text-center">' . htmlspecialchars($fileType) . '</td>
            <td class="text-center">' . $fileSizeFormatted . '</td>
            <td class="text-end">
                <div class="d-inline-flex flex-wrap justify-content-end gap-1">' . $actionButtons . '</div>
            </td>
        </tr>';
    }
}

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - Case Details</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <style>
        .lawyer-case-comments .cc-comment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: min(32rem, 55vh);
            overflow-y: auto;
            padding-right: 0.15rem;
        }
        .lawyer-case-comments .cc-comment-list::-webkit-scrollbar { width: 6px; }
        .lawyer-case-comments .cc-comment-list::-webkit-scrollbar-thumb {
            background: rgba(45, 206, 137, 0.35);
            border-radius: 999px;
        }
        .lawyer-case-comments .cc-comment-item-inner {
            background: #fff;
            border: 1px solid rgba(0,0,0,.06);
            border-radius: 0.75rem;
            padding: 1rem 1.15rem;
            border-left: 4px solid #8392ab;
            box-shadow: 0 1px 4px rgba(0,0,0,.04);
            width: 100%;
            max-width: 100%;
        }
        .lawyer-case-comments .cc-comment-item--client .cc-comment-item-inner { border-left-color: #11cdef; }
        .lawyer-case-comments .cc-comment-item--lawyer .cc-comment-item-inner { border-left-color: #2dce89; }
        .lawyer-case-comments .cc-comment-item--admin .cc-comment-item-inner { border-left-color: #fb6340; }
        .lawyer-case-comments .cc-comment-item--staff .cc-comment-item-inner { border-left-color: #8898aa; }
        .lawyer-case-comments .cc-comment-item--yours .cc-comment-item-inner {
            background: #f8fdfb;
            border-color: rgba(45, 206, 137, 0.25);
        }
        .lawyer-case-comments .cc-comment-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.65rem;
            flex-wrap: wrap;
        }
        .lawyer-case-comments .cc-comment-head-main {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
            min-width: 0;
        }
        .lawyer-case-comments .cc-comment-author {
            font-size: 0.875rem;
            font-weight: 700;
            color: #344767;
        }
        .lawyer-case-comments .cc-comment-time {
            font-size: 0.75rem;
            color: #8392ab;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .lawyer-case-comments .cc-comment-text {
            font-size: 0.875rem;
            line-height: 1.6;
            color: #525f7f;
            word-break: break-word;
            overflow-wrap: anywhere;
            margin: 0;
        }
        .lawyer-case-comments .cc-comment-form textarea {
            border-radius: 0.65rem;
            resize: vertical;
            min-height: 6rem;
        }
        .lawyer-case-comments .cc-comment-head-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .lawyer-case-comments .cc-comment-delete-form {
            display: inline-flex;
            margin: 0;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-case-view-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-cases.php">My Cases</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Case #{CASE_ID}</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">{CASE_TITLE}</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <!-- Case Overview -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0">Case Overview</h5>
                                    <p class="text-sm text-muted mb-0">Case #{CASE_ID} - {CASE_TITLE}</p>
                                </div>
                                <div>
                                    {STATUS_BADGE} {PRIORITY_BADGE} {CATEGORY_BADGE}
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-sm font-weight-bold mb-3">Client Information</h6>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Name:</span>
                                        <span class="text-sm font-weight-bold ms-2">{CLIENT_NAME}</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Email:</span>
                                        <span class="text-sm font-weight-bold ms-2">{CLIENT_EMAIL}</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Phone:</span>
                                        <span class="text-sm font-weight-bold ms-2">{CLIENT_PHONE}</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Assigned Lawyers:</span>
                                        <span class="text-sm font-weight-bold ms-2">{ASSIGNED_LAWYERS}</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-sm font-weight-bold mb-3">Case Information</h6>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Created:</span>
                                        <span class="text-sm font-weight-bold ms-2">{CREATED_DATE}</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Last Updated:</span>
                                        <span class="text-sm font-weight-bold ms-2">{UPDATED_DATE}</span>
                                    </div>
                                </div>
                            </div>
                            {CASE_DESCRIPTION}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case Details Tabs -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <ul class="nav nav-tabs" id="caseTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="services-tab" data-bs-toggle="tab" data-bs-target="#services" type="button" role="tab">Services & Fees</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="stages-tab" data-bs-toggle="tab" data-bs-target="#stages" type="button" role="tab">Case Stages</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments" type="button" role="tab">Appointments</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#case-documents" type="button" role="tab">Documents</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">Track of Events</button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="caseTabsContent">
                                <!-- Services Tab -->
                                <div class="tab-pane fade show active" id="services" role="tabpanel">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Service</th>
                                                    <th class="text-end">Price</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {SERVICES_HTML}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Stages Tab -->
                                <div class="tab-pane fade" id="stages" role="tabpanel">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Stage #</th>
                                                    <th>Title</th>
                                                    <th>Description</th>
                                                    <th>Result</th>
                                                    <th>Start Date</th>
                                                    <th>End Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {STAGES_HTML}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Appointments Tab -->
                                <div class="tab-pane fade" id="appointments" role="tabpanel">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th>Description</th>
                                                    <th>Location</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {APPOINTMENTS_HTML}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Documents Tab -->
                                <div class="tab-pane fade" id="case-documents" role="tabpanel">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
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
                                <!-- Events Tab -->
                                <div class="tab-pane fade" id="events" role="tabpanel">
                                    {EVENTS_HTML}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comments Section -->
            <div class="row mt-4">
                <div class="col-12">
                    <div id="lawyer-case-comments" class="card cc-comments-panel lawyer-case-comments shadow-sm">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Case Comments &amp; Files</h6>
                            <p class="text-sm text-muted mb-0">Discussion on this case</p>
                        </div>
                        <div class="card-body">
                            {COMMENTS_HTML}

                            <form method="POST" action="" class="cc-comment-form mt-4 pt-4 border-top">
                                <label for="lawyer-case-comment-input" class="form-label text-sm font-weight-bold mb-2">Add a comment</label>
                                <textarea id="lawyer-case-comment-input" class="form-control" name="comment" rows="4" placeholder="Write your comment here…" required></textarea>
                                <div class="d-flex justify-content-end mt-3">
                                    <button type="submit" class="btn bg-gradient-success mb-0">Post comment</button>
                                </div>
                            </form>

                            <!-- Upload File Form -->
                            <div class="mt-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Upload Document</h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="" enctype="multipart/form-data">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <input type="text" class="form-control" name="file_label" placeholder="Document description (optional)">
                                                </div>
                                                <div class="col-md-4">
                                                    <input type="file" class="form-control" name="file" required>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-success btn-sm mt-2">Upload File</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
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
                            © <script>document.write(new Date().getFullYear())</script>, LegalPro Lawyer Portal.
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
        if (hash === '#case-documents' || hash === '#lawyer-case-comments') {
            var tabBtn = document.querySelector('[data-bs-target="' + hash + '"]');
            if (tabBtn && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(tabBtn).show();
            }
        }
    });
    </script>
</body>
</html>
HTML;

$commentRoleBadge = static function (string $type): string {
    switch ($type) {
        case 'client':
            return '<span class="cc-comment-role badge badge-sm bg-gradient-info">Client</span>';
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
        <h6 class="font-weight-bolder mt-4 mb-2">No comments yet</h6>
        <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">Post a comment below to communicate with the client and your team about this case.</p>
    </div>';
}

// Build events HTML using the new CaseEvents class
$eventsHtml = CaseEvents::renderEventsTimeline($caseId);

$descriptionHtml = '';
if (!empty($case['description'])) {
    $descriptionHtml = '
    <div class="row mt-4">
        <div class="col-12">
            <h6 class="text-sm font-weight-bold mb-2">Case Description</h6>
            <p class="text-sm">' . nl2br(htmlspecialchars($case['description'])) . '</p>
        </div>
    </div>';
}

$replacements = [
    '{NAVIGATION}' => $navHtml,
    '{CASE_ID}' => $caseId,
    '{CASE_TITLE}' => htmlspecialchars($case['title']),
    '{CLIENT_NAME}' => htmlspecialchars($case['first_name'] . ' ' . $case['last_name']),
    '{CLIENT_EMAIL}' => htmlspecialchars($case['email']),
    '{CLIENT_PHONE}' => htmlspecialchars($case['phone'] ?: 'Not provided'),
    '{ASSIGNED_LAWYERS}' => htmlspecialchars($case['assigned_lawyers']),
    '{CREATED_DATE}' => date('M d, Y', strtotime($case['created_at'])),
    '{UPDATED_DATE}' => date('M d, Y', strtotime(!empty($case['updated_at']) ? $case['updated_at'] : $case['created_at'])),
    '{STATUS_BADGE}' => $statusBadge,
    '{PRIORITY_BADGE}' => $priorityBadge,
    '{CATEGORY_BADGE}' => $categoryBadge,
    '{CASE_DESCRIPTION}' => $descriptionHtml,
    '{SERVICES_HTML}' => $servicesHtml,
    '{STAGES_HTML}' => $stagesHtml,
    '{APPOINTMENTS_HTML}' => $appointmentsHtml,
    '{DOCUMENTS_HTML}' => $documentsHtml,
    '{COMMENTS_HTML}' => $commentsHtml,
    '{EVENTS_HTML}' => $eventsHtml,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo $html;
?>
