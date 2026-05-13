<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];
$client_user_id = $_SESSION['client_user_id'];

$message = '';
$messageType = '';

// Get case ID from URL
$case_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $comment = trim($_POST['comment']);

    if (!empty($comment)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO case_comments (case_id, user_id, comment, comment_type) VALUES (?, ?, ?, 'client')");
            $stmt->execute([$case_id, $client_user_id, $comment]);
            $message = 'Comment added successfully!';
            $messageType = 'success';

            // Track comment addition
            CaseEvents::trackCommentAdded($case_id, [
                'comment' => $comment,
                'comment_type' => 'client'
            ]);
        } catch (PDOException $e) {
            $message = 'Error adding comment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $label = trim(isset($_POST['file_label']) ? $_POST['file_label'] : '');

    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/client_files/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = uniqid() . '_' . basename($file['name']);
        $filePath = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO documents (case_id, filename, filepath, label, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$case_id, $file['name'], 'uploads/client_files/' . $fileName, $label ?: $file['name'], $client_name]);
                $message = 'File uploaded successfully!';
                $messageType = 'success';

                // Log the event
                logDocumentUpload($pdo, $case_id, $label ?: $file['name'], $client_name, $client_user_id);
            } catch (PDOException $e) {
                $message = 'Error saving file information: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Error uploading file.';
            $messageType = 'danger';
        }
    }
}

try {
    // Get case details
    $stmt = $pdo->prepare("
        SELECT c.*,
               GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') as lawyer_names
        FROM cases c
        LEFT JOIN case_lawyers cl ON cl.case_id = c.id
        LEFT JOIN lawyers l ON l.id = cl.lawyer_id
        WHERE c.id = ? AND c.client_id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$case_id, $client_id]);
    $case = $stmt->fetch();

    if (!$case) {
        header('Location: client-cases.php');
        exit;
    }

    // Get case services
    $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at ASC");
    $stmt->execute([$case_id]);
    $services = $stmt->fetchAll();

    // Get case stages
    $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number ASC");
    $stmt->execute([$case_id]);
    $stages = $stmt->fetchAll();

    // Get case comments (all comments for this case)
    $stmt = $pdo->prepare("
        SELECT cc.*, u.username,
               CASE
                   WHEN cc.comment_type = 'client' THEN CONCAT('Client: ', u.username)
                   WHEN cc.comment_type = 'lawyer' THEN CONCAT('Lawyer: ', u.username)
                   WHEN cc.comment_type = 'admin' THEN CONCAT('Admin: ', u.username)
                   WHEN cc.comment_type = 'staff' THEN CONCAT('Staff: ', u.username)
                   ELSE 'System'
               END as commenter_name,
               cc.comment_type as user_type
        FROM case_comments cc
        LEFT JOIN users u ON u.id = cc.user_id
        WHERE cc.case_id = ? AND cc.is_private = 0
        ORDER BY cc.created_at ASC
    ");
    $stmt->execute([$case_id]);
    $comments = $stmt->fetchAll();

    // Get case events
    $caseEvents = CaseEvents::getCaseEvents($case_id);

    // Get case documents
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE case_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$case_id]);
    $documents = $stmt->fetchAll();

    // Get case appointments
    $stmt = $pdo->prepare("
        SELECT a.*, CONCAT(l.first_name, ' ', l.last_name) as lawyer_name
        FROM appointments a
        LEFT JOIN case_lawyers cl ON cl.case_id = a.case_id
        LEFT JOIN lawyers l ON l.id = cl.lawyer_id
        WHERE a.case_id = ?
        ORDER BY a.starts_at DESC
    ");
    $stmt->execute([$case_id]);
    $appointments = $stmt->fetchAll();

} catch (PDOException $e) {
    $message = 'Error loading case details: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $case = null;
    $services = [];
    $stages = [];
    $comments = [];
    $documents = [];
    $appointments = [];
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

if (!$case) {
    echo '<div class="container mt-5"><div class="alert alert-danger">Case not found or access denied.</div></div>';
    exit;
}

// Build services list
$servicesHtml = '';
$totalFees = 0;
if (!empty($services)) {
    foreach ($services as $service) {
        $servicesHtml .= '<li class="list-group-item d-flex justify-content-between align-items-center">' . htmlspecialchars($service['service_name']) . '<span class="badge bg-primary rounded-pill">$ ' . number_format($service['price'], 2) . '</span></li>';
        $totalFees += $service['price'];
    }
    $servicesHtml .= '<li class="list-group-item d-flex justify-content-between align-items-center fw-bold">Total Fees<span class="badge bg-success rounded-pill">$ ' . number_format($totalFees, 2) . '</span></li>';
} else {
    $servicesHtml = '<li class="list-group-item text-muted">No services defined yet.</li>';
}

// Build stages list
$stagesHtml = '';
if (!empty($stages)) {
    foreach ($stages as $stage) {
        $statusIcon = '';
        if ($stage['actual_end_date']) {
            $statusIcon = '<i class="ni ni-check-bold text-success"></i>';
        } elseif ($stage['start_date']) {
            $statusIcon = '<i class="ni ni-time-alarm text-warning"></i>';
        } else {
            $statusIcon = '<i class="ni ni-circle-08 text-muted"></i>';
        }

        $stagesHtml .= '<div class="timeline-block mb-3">
            <span class="timeline-step">' . $statusIcon . '</span>
            <div class="timeline-content">
                <h6 class="text-dark text-sm font-weight-bold mb-0">' . htmlspecialchars($stage['title']) . '</h6>
                <p class="text-secondary font-weight-bold text-xs mt-1 mb-0">' . htmlspecialchars($stage['description'] ?: 'No description') . '</p>
                <p class="text-secondary text-xs mt-1 mb-0">Result: ' . htmlspecialchars($stage['result'] ?: 'Pending') . '</p>';
        if ($stage['file_path']) {
            $stagesHtml .= '<p class="text-secondary text-xs mt-1 mb-0"><a href="' . htmlspecialchars($stage['file_path']) . '" target="_blank" class="text-primary">View Attachment</a></p>';
        }
        $stagesHtml .= '<div class="text-secondary text-xs mt-2">
                <span>Start: ' . ($stage['start_date'] ? date('M d, Y', strtotime($stage['start_date'])) : 'Not started') . '</span><br>
                <span>Expected End: ' . ($stage['expected_end_date'] ? date('M d, Y', strtotime($stage['expected_end_date'])) : 'Not set') . '</span><br>
                <span>Actual End: ' . ($stage['actual_end_date'] ? date('M d, Y', strtotime($stage['actual_end_date'])) : 'Not completed') . '</span>
            </div>
            </div>
        </div>';
    }
} else {
    $stagesHtml = '<p class="text-muted text-sm">No case stages defined yet.</p>';
}

// Build comments list (chat-like interface)
$commentsHtml = '';
if (!empty($comments)) {
    $commentsHtml .= '<div class="chat-messages" style="max-height: 400px; overflow-y: auto;">';
    foreach ($comments as $comment) {
        $isCurrentUser = ($comment['user_id'] == $client_user_id);
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
    $commentsHtml = '<div class="text-center py-4">
        <i class="ni ni-chat-round text-muted" style="font-size: 3rem;"></i>
        <p class="text-muted mt-2">No comments yet. Start the conversation!</p>
    </div>';
}

// Build documents list
$documentsHtml = '';
if (!empty($documents)) {
    foreach ($documents as $doc) {
        $fileIcon = '<i class="ni ni-single-copy-04"></i>';
        $documentsHtml .= '<div class="d-flex align-items-center mb-3">
            <div class="w-100">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 text-sm">' . htmlspecialchars($doc['label'] ?: $doc['filename']) . '</h6>
                    <a href="' . htmlspecialchars($doc['filepath']) . '" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                </div>
                <p class="text-xs text-secondary mb-0">Uploaded by ' . htmlspecialchars($doc['uploaded_by']) . ' on ' . date('M d, Y', strtotime($doc['uploaded_at'])) . '</p>
            </div>
        </div>';
    }
} else {
    $documentsHtml = '<p class="text-muted text-sm">No documents uploaded yet.</p>';
}

// Build appointments list
$appointmentsHtml = '';
if (!empty($appointments)) {
    foreach ($appointments as $apt) {
        $status = strtotime($apt['starts_at']) > time() ? '<span class="badge badge-sm bg-gradient-info">Upcoming</span>' : '<span class="badge badge-sm bg-gradient-success">Completed</span>';
        $appointmentsHtml .= '<div class="d-flex align-items-center mb-3">
            <div class="w-100">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 text-sm">' . date('M d, Y g:i A', strtotime($apt['starts_at'])) . '</h6>
                    ' . $status . '
                </div>
                <p class="text-xs text-secondary mb-0">Lawyer: ' . htmlspecialchars($apt['lawyer_name'] ?: 'TBD') . '</p>
                <p class="text-xs text-secondary mb-0">Notes: ' . htmlspecialchars($apt['notes'] ?: 'No notes') . '</p>
            </div>
        </div>';
    }
} else {
    $appointmentsHtml = '<p class="text-muted text-sm">No appointments scheduled.</p>';
}

// Build events HTML using the new CaseEvents class
$eventsHtml = CaseEvents::renderEventsTimeline($case_id);

$caseNumber = 'C-' . str_pad($case['id'], 4, '0', STR_PAD_LEFT);
$lawyerNames = $case['lawyer_names'] ?: 'Unassigned';

switch ($case['status']) {
    case 'open':
        $statusBadge = '<span class="badge badge-sm bg-gradient-success">Open</span>';
        break;
    case 'closed':
        $statusBadge = '<span class="badge badge-sm bg-gradient-danger">Closed</span>';
        break;
    case 'pending':
        $statusBadge = '<span class="badge badge-sm bg-gradient-warning">Pending</span>';
        break;
    default:
        $statusBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars($case['status']) . '</span>';
        break;
}

switch ($case['priority']) {
    case 'High':
        $priorityBadge = '<span class="badge badge-sm bg-gradient-danger">High</span>';
        break;
    case 'Normal':
        $priorityBadge = '<span class="badge badge-sm bg-gradient-warning">Normal</span>';
        break;
    case 'Low':
        $priorityBadge = '<span class="badge badge-sm bg-gradient-info">Low</span>';
        break;
    default:
        $priorityBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars($case['priority']) . '</span>';
        break;
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LexMate - Case Details</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100">
    <div class="min-height-300 bg-primary position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="#">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LexMate logo">
            <span class="ms-1 font-weight-bold">LexMate</span>
            </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="client-dashboard.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="client-cases.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Cases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-appointments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-calendar-grid-58 text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Appointments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-court-tracking.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-collection text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Court Tracking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-payments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-credit-card text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Payments</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidenav-footer position-absolute bottom-0 w-100">
            <div class="text-center">
                <p class="text-xs text-muted mb-1">Logged in as</p>
                <p class="text-sm font-weight-bold mb-2">{CLIENT_NAME}</p>
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger w-100">Logout</a>
            </div>
        </div>
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="client-cases.php">My Cases</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Case Details</li>
                    </ol>
                    <h6 class="font-weight-bolder mb-0">Case {CASE_NUMBER}</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, {CLIENT_NAME}</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        <!-- End Navbar -->
        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Case Overview -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-lg-8">
                                    <h3 class="mb-2">{CASE_TITLE}</h3>
                                    <p class="text-muted mb-3">{CASE_DESCRIPTION}</p>
                                    <div class="d-flex gap-2 mb-3">
                                        {STATUS_BADGE}
                                        {PRIORITY_BADGE}
                                        <span class="badge badge-sm bg-gradient-secondary">{CASE_CATEGORY}</span>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="text-sm mb-1"><strong>Lawyer(s):</strong> {LAWYER_NAMES}</p>
                                            <p class="text-sm mb-1"><strong>Start Date:</strong> {START_DATE}</p>
                                            <p class="text-sm mb-1"><strong>Expected Completion:</strong> {EXPECTED_COMPLETION}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="text-sm mb-1"><strong>Estimated Fees:</strong> ${ESTIMATED_FEES}</p>
                                            <p class="text-sm mb-1"><strong>Last Updated:</strong> {LAST_UPDATED}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Case Timeline & Services -->
                <div class="col-lg-8 mb-4">
                    <!-- Case Stages Timeline -->
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6>Case Progress</h6>
                        </div>
                        <div class="card-body">
                            <div class="timeline timeline-one-side">
                                {STAGES_HTML}
                            </div>
                        </div>
                    </div>

                    <!-- Services -->
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6>Services & Fees</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                {SERVICES_HTML}
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Appointments -->
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6>Appointments</h6>
                        </div>
                        <div class="card-body">
                            {APPOINTMENTS_HTML}
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6>Documents</h6>
                        </div>
                        <div class="card-body">
                            {DOCUMENTS_HTML}
                        </div>
                    </div>

                    <!-- Add Comment -->
                    <div class="card mb-4">
                        <div class="card-header pb-0">
                            <h6>Add Comment</h6>
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

                    <!-- Upload File -->
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Upload Document</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <div class="form-group mb-2">
                                    <input type="text" class="form-control form-control-sm" name="file_label" placeholder="Document description (optional)">
                                </div>
                                <div class="form-group mb-2">
                                    <input type="file" class="form-control form-control-sm" name="file" required>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm">Upload File</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comments Section -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6>Case Comments</h6>
                        </div>
                        <div class="card-body">
                            {COMMENTS_HTML}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

// Replace placeholders
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{CASE_NUMBER}', $caseNumber, $html);
$html = str_replace('{CASE_TITLE}', htmlspecialchars($case['title']), $html);
$html = str_replace('{CASE_DESCRIPTION}', htmlspecialchars($case['description'] ?: 'No description provided.'), $html);
$html = str_replace('{STATUS_BADGE}', $statusBadge, $html);
$html = str_replace('{PRIORITY_BADGE}', $priorityBadge, $html);
$html = str_replace('{CASE_CATEGORY}', htmlspecialchars($case['category']), $html);
$html = str_replace('{LAWYER_NAMES}', htmlspecialchars($lawyerNames), $html);
$html = str_replace('{START_DATE}', $case['start_date'] ? date('M d, Y', strtotime($case['start_date'])) : 'Not set', $html);
$html = str_replace('{EXPECTED_COMPLETION}', $case['expected_completion'] ? date('M d, Y', strtotime($case['expected_completion'])) : 'Not set', $html);
$html = str_replace('{ESTIMATED_FEES}', number_format($case['estimated_fees'], 2), $html);
$html = str_replace('{LAST_UPDATED}', date('M d, Y', strtotime($case['updated_at'])), $html);
$html = str_replace('{SERVICES_HTML}', $servicesHtml, $html);
$html = str_replace('{STAGES_HTML}', $stagesHtml, $html);
$html = str_replace('{COMMENTS_HTML}', $commentsHtml, $html);
$html = str_replace('{DOCUMENTS_HTML}', $documentsHtml, $html);
$html = str_replace('{APPOINTMENTS_HTML}', $appointmentsHtml, $html);

echo $html;
?>
