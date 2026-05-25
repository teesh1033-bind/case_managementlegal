<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';

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

                // Log the event
                logDocumentUpload($pdo, $caseId, $label ?: $file['name'], $lawyerName, $lawyerUserId);
            } catch (PDOException $e) {
                // Handle error silently for now
            }
        }
    }
}

// Check if this case is assigned to the logged-in lawyer
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM case_lawyers
        WHERE case_id = ? AND lawyer_id = ?
    ");
    $stmt->execute([$caseId, $lawyerId]);
    if (!$stmt->fetchColumn()) {
        die('Access denied: This case is not assigned to you.');
    }
} catch (PDOException $e) {
    die('Error checking case access: ' . htmlspecialchars($e->getMessage()));
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

// Build status badge
$statusBadge = '';
switch ($case['status']) {
    case 'open': $statusBadge = '<span class="badge bg-success">Open</span>'; break;
    case 'in_progress': $statusBadge = '<span class="badge bg-primary">In Progress</span>'; break;
    case 'closed': $statusBadge = '<span class="badge bg-secondary">Closed</span>'; break;
    default: $statusBadge = '<span class="badge bg-light">' . htmlspecialchars($case['status']) . '</span>';
}

// Build priority badge
$priorityBadge = '';
switch ($case['priority']) {
    case 'High': $priorityBadge = '<span class="badge bg-danger">High</span>'; break;
    case 'Urgent': $priorityBadge = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Urgent</span>'; break;
    case 'Normal': $priorityBadge = '<span class="badge bg-warning">Normal</span>'; break;
    default: $priorityBadge = '<span class="badge bg-light">' . htmlspecialchars($case['priority']) . '</span>';
}

// Build category badge
$categoryBadge = '<span class="badge bg-info">' . htmlspecialchars($case['category']) . '</span>';

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
    $documentsHtml = '<tr><td colspan="3" class="text-center text-muted py-3">No documents uploaded</td></tr>';
} else {
    foreach ($documents as $document) {
        $documentPath = isset($document['filepath']) ? (string) $document['filepath'] : '';
        $documentName = !empty($document['label']) ? $document['label'] : (!empty($document['filename']) ? $document['filename'] : basename($documentPath));
        $fileUrl = '../' . ltrim($documentPath, '/');
        $fileSystemPath = __DIR__ . '/../' . ltrim($documentPath, '/');
        $fileSize = ($documentPath !== '' && is_file($fileSystemPath)) ? filesize($fileSystemPath) : false;
        $fileSizeFormatted = $fileSize ? round($fileSize / 1024, 1) . ' KB' : 'Unknown';
        $fileType = !empty($document['filename']) ? strtoupper(pathinfo($document['filename'], PATHINFO_EXTENSION)) : 'File';

        $documentsHtml .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-single-copy-04 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($documentName) . '</h6>
                        <p class="text-xs text-muted mb-0">Uploaded ' . date('M d, Y', strtotime($document['uploaded_at'])) . '</p>
                    </div>
                </div>
            </td>
            <td class="text-center">' . htmlspecialchars($fileType) . '</td>
            <td class="text-center">' . $fileSizeFormatted . '</td>
            <td class="text-end">
                <a href="' . htmlspecialchars($fileUrl) . '" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                <a href="' . htmlspecialchars($fileUrl) . '" download class="btn btn-sm btn-outline-secondary">Download</a>
            </td>
        </tr>';
    }
}

// Create navigation HTML
$navHtml = <<<'NAV'
<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0" href="lawyer-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0">
    <div class="collapse navbar-collapse w-auto">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="lawyer-dashboard.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="tasks.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-check-bold text-success text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Tasks</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="lawyer-cases.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Cases</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-clients.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-circle-08 text-info text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Clients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-appointments.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-calendar-grid-58 text-success text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Appointments</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-court-tracking.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-collection text-primary text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Court Tracking</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-availability.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-time-alarm text-danger text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Availability</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="sidenav-footer position-absolute bottom-0 w-100">
        <div class="text-center">
            <p class="text-xs text-muted mb-1">Logged in as</p>
            <p class="text-sm font-weight-bold mb-2">{LAWYER_NAME}</p>
            <a href="lawyer-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
        </div>
    </div>
</aside>
NAV;

$navHtml = str_replace('{LAWYER_NAME}', htmlspecialchars($lawyerName), $navHtml);

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
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-sm font-weight-bold mb-3">Case Information</h6>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Assigned Lawyers:</span>
                                        <span class="text-sm font-weight-bold ms-2">{ASSIGNED_LAWYERS}</span>
                                    </div>
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
                                    <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">Documents</button>
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
                                <div class="tab-pane fade" id="documents" role="tabpanel">
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
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Case Comments & Files</h6>
                        </div>
                        <div class="card-body">
                            <!-- Comments -->
                            {COMMENTS_HTML}

                            <!-- Add Comment Form -->
                            <div class="mt-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Add Comment</h6>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="">
                                            <div class="form-group">
                                                <textarea class="form-control" name="comment" rows="3" placeholder="Add your comment here..." required></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm mt-2">Add Comment</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

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
</body>
</html>
HTML;

// Build comments HTML (chat-like interface)
$commentsHtml = '';
if (!empty($comments)) {
    $commentsHtml .= '<div class="chat-messages" style="max-height: 300px; overflow-y: auto;">';
    foreach ($comments as $comment) {
        $isCurrentUser = ($comment['user_id'] == $lawyerUserId);
        $alignment = $isCurrentUser ? 'justify-content-end' : 'justify-content-start';
        $bgColor = $isCurrentUser ? 'bg-primary' : 'bg-light';
        $textColor = $isCurrentUser ? 'text-white' : 'text-dark';
        $marginClass = $isCurrentUser ? 'ms-3' : 'me-3';

        // Add user type badge
        $userTypeBadge = '';
        switch ($comment['comment_type']) {
            case 'client':
                $userTypeBadge = '<span class="badge badge-sm bg-info">Client</span>';
                break;
            case 'lawyer':
                $userTypeBadge = '<span class="badge badge-sm bg-success">Lawyer</span>';
                break;
            case 'admin':
                $userTypeBadge = '<span class="badge badge-sm bg-warning">Admin</span>';
                break;
            case 'staff':
                $userTypeBadge = '<span class="badge badge-sm bg-secondary">Staff</span>';
                break;
        }

        $commentsHtml .= '<div class="d-flex ' . $alignment . ' mb-3">
            <div class="chat-message ' . $bgColor . ' ' . $textColor . ' rounded-lg p-3 ' . $marginClass . '" style="max-width: 70%;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center">
                        <strong class="me-2">' . htmlspecialchars($comment['commenter_name']) . '</strong>
                        ' . $userTypeBadge . '
                    </div>
                    <small class="opacity-75">' . date('M d, H:i', strtotime($comment['created_at'])) . '</small>
                </div>
                <p class="mb-0" style="word-wrap: break-word;">' . nl2br(htmlspecialchars($comment['comment'])) . '</p>
            </div>
        </div>';
    }
    $commentsHtml .= '</div>';
} else {
    $commentsHtml = '<div class="text-center py-3">
        <i class="ni ni-chat-round text-muted" style="font-size: 2rem;"></i>
        <p class="text-muted mt-1 mb-0">No comments yet. Start the conversation!</p>
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
