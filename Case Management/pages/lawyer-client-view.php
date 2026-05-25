<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if this client has cases assigned to the logged-in lawyer
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM cases c
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE c.client_id = ? AND cl.lawyer_id = ?
    ");
    $stmt->execute([$clientId, $lawyerId]);
    if (!$stmt->fetchColumn()) {
        die('Access denied: This client is not associated with your cases.');
    }
} catch (PDOException $e) {
    die('Error checking client access: ' . htmlspecialchars($e->getMessage()));
}

// Fetch client details
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        die('Client not found.');
    }
} catch (PDOException $e) {
    die('Error loading client: ' . htmlspecialchars($e->getMessage()));
}

// Fetch client's cases assigned to this lawyer
$clientCases = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, cl2.is_primary
        FROM cases c
        INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
        WHERE c.client_id = ? AND cl2.lawyer_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$clientId, $lawyerId]);
    $clientCases = $stmt->fetchAll();
} catch (PDOException $e) {
    $clientCases = [];
}

// Fetch client's comments (from all their cases that this lawyer has access to)
$clientComments = [];
try {
    $stmt = $pdo->prepare("
        SELECT cc.*, c.title as case_title, c.id as case_id
        FROM client_comments cc
        INNER JOIN cases c ON c.id = cc.case_id
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE c.client_id = ? AND cl.lawyer_id = ?
        ORDER BY cc.created_at DESC
    ");
    $stmt->execute([$clientId, $lawyerId]);
    $clientComments = $stmt->fetchAll();
} catch (PDOException $e) {
    $clientComments = [];
}

// Fetch client's documents (from all their cases that this lawyer has access to)
$clientDocuments = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, c.title as case_title, c.id as case_id
        FROM documents d
        INNER JOIN cases c ON c.id = d.case_id
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE c.client_id = ? AND cl.lawyer_id = ?
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute([$clientId, $lawyerId]);
    $clientDocuments = $stmt->fetchAll();
} catch (PDOException $e) {
    $clientDocuments = [];
}

// Build cases HTML
$casesHtml = '';
if (empty($clientCases)) {
    $casesHtml = '<p class="text-muted">No cases found for this client.</p>';
} else {
    $casesHtml = '<div class="row">';
    foreach ($clientCases as $case) {
        $statusBadge = '';
        switch ($case['status']) {
            case 'open': $statusBadge = '<span class="badge bg-success">Open</span>'; break;
            case 'in_progress': $statusBadge = '<span class="badge bg-primary">In Progress</span>'; break;
            case 'closed': $statusBadge = '<span class="badge bg-secondary">Closed</span>'; break;
            default: $statusBadge = '<span class="badge bg-light">' . htmlspecialchars($case['status']) . '</span>';
        }

        $primaryBadge = $case['is_primary'] ? '<span class="badge bg-warning text-dark ms-1">Primary</span>' : '';

        $casesHtml .= '
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0">' . htmlspecialchars($case['title']) . '</h6>
                        <div>' . $statusBadge . $primaryBadge . '</div>
                    </div>
                    <p class="text-sm text-muted mb-2">Case #' . htmlspecialchars($case['id']) . '</p>
                    <p class="text-sm mb-2">' . htmlspecialchars(substr($case['description'] ?: 'No description', 0, 100)) . '...</p>
                    <div class="text-end">
                        <a href="lawyer-case-view.php?id=' . (int)$case['id'] . '" class="btn btn-sm btn-outline-primary">View Case</a>
                    </div>
                </div>
            </div>
        </div>';
    }
    $casesHtml .= '</div>';
}

// Build comments HTML
$commentsHtml = '';
if (empty($clientComments)) {
    $commentsHtml = '<tr><td colspan="3" class="text-center text-muted py-3">No comments from this client yet</td></tr>';
} else {
    foreach ($clientComments as $comment) {
        $commentsHtml .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md me-3">
                        <i class="ni ni-chat-round text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($comment['case_title']) . '</h6>
                        <p class="text-xs text-muted mb-0">' . date('M d, Y H:i', strtotime($comment['created_at'])) . '</p>
                    </div>
                </div>
            </td>
            <td>' . nl2br(htmlspecialchars($comment['comment'])) . '</td>
            <td class="text-center">
                <span class="badge bg-' . ($comment['is_read'] ? 'success' : 'warning') . '">' . ($comment['is_read'] ? 'Read' : 'Unread') . '</span>
            </td>
        </tr>';
    }
}

// Build documents HTML
$documentsHtml = '';
if (empty($clientDocuments)) {
    $documentsHtml = '<tr><td colspan="4" class="text-center text-muted py-3">No documents uploaded by this client</td></tr>';
} else {
    foreach ($clientDocuments as $document) {
        $fileSize = filesize('../uploads/' . $document['file_path']);
        $fileSizeFormatted = $fileSize ? round($fileSize / 1024, 1) . ' KB' : 'Unknown';

        $documentsHtml .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-single-copy-04 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($document['label'] ?: basename($document['file_path'])) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($document['case_title']) . '</p>
                    </div>
                </div>
            </td>
            <td class="text-center">' . htmlspecialchars($document['file_type']) . '</td>
            <td class="text-center">' . $fileSizeFormatted . '</td>
            <td class="text-center">' . date('M d, Y', strtotime($document['uploaded_at'])) . '</td>
            <td class="text-end">
                <a href="../uploads/' . htmlspecialchars($document['file_path']) . '" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                <a href="../uploads/' . htmlspecialchars($document['file_path']) . '" download class="btn btn-sm btn-outline-secondary">Download</a>
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
                <a class="nav-link" href="lawyer-cases.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Cases</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="lawyer-clients.php">
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
    <title>LegalPro - Client Details</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-client-view-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-clients.php">My Clients</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">{CLIENT_NAME}</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Client Details</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <!-- Client Overview -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h5 class="mb-0">Client Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-sm font-weight-bold mb-3">Personal Information</h6>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Full Name:</span>
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
                                        <span class="text-sm text-muted">Address:</span>
                                        <span class="text-sm font-weight-bold ms-2">{CLIENT_ADDRESS}</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-sm font-weight-bold mb-3">Case Statistics</h6>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Total Cases:</span>
                                        <span class="text-sm font-weight-bold ms-2">{TOTAL_CASES}</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Active Cases:</span>
                                        <span class="text-sm font-weight-bold ms-2">{ACTIVE_CASES}</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="text-sm text-muted">Client Since:</span>
                                        <span class="text-sm font-weight-bold ms-2">{CLIENT_SINCE}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client Cases -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h5 class="mb-0">Associated Cases</h5>
                            <p class="text-sm text-muted mb-0">Cases involving this client that are assigned to you</p>
                        </div>
                        <div class="card-body">
                            {CLIENT_CASES}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client Activity Tabs -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <ul class="nav nav-tabs" id="clientTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments" type="button" role="tab">Comments</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">Documents</button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="clientTabsContent">
                                <!-- Comments Tab -->
                                <div class="tab-pane fade show active" id="comments" role="tabpanel">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Case</th>
                                                    <th>Comment</th>
                                                    <th class="text-center">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {COMMENTS_HTML}
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
                                                    <th class="text-center">Uploaded</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {DOCUMENTS_HTML}
                                            </tbody>
                                        </table>
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

// Calculate statistics
$totalCases = count($clientCases);
$activeCases = count(array_filter($clientCases, function($case) {
    return $case['status'] !== 'closed';
}));

$replacements = [
    '{NAVIGATION}' => $navHtml,
    '{CLIENT_NAME}' => htmlspecialchars($client['first_name'] . ' ' . $client['last_name']),
    '{CLIENT_EMAIL}' => htmlspecialchars($client['email'] ?: 'Not provided'),
    '{CLIENT_PHONE}' => htmlspecialchars($client['phone'] ?: 'Not provided'),
    '{CLIENT_ADDRESS}' => htmlspecialchars($client['address'] ?: 'Not provided'),
    '{TOTAL_CASES}' => $totalCases,
    '{ACTIVE_CASES}' => $activeCases,
    '{CLIENT_SINCE}' => date('M d, Y', strtotime($client['created_at'])),
    '{CLIENT_CASES}' => $casesHtml,
    '{COMMENTS_HTML}' => $commentsHtml,
    '{DOCUMENTS_HTML}' => $documentsHtml,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo $html;
?>
