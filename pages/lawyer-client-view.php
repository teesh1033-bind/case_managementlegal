<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

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

// Fetch comments from all client cases assigned to this lawyer
$clientComments = [];
try {
    $stmt = $pdo->prepare("
        SELECT cc.*, c.title AS case_title, c.id AS case_id, u.username
        FROM case_comments cc
        INNER JOIN cases c ON c.id = cc.case_id
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        LEFT JOIN users u ON u.id = cc.user_id
        WHERE c.client_id = ? AND cl.lawyer_id = ? AND cc.is_private = 0
        ORDER BY cc.created_at DESC
    ");
    $stmt->execute([$clientId, $lawyerId]);
    $clientComments = $stmt->fetchAll();
} catch (PDOException $e) {
    $clientComments = [];
}

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
        $statusBadge = client_case_status_badge((string) ($case['status'] ?? ''));
        $priorityBadge = client_case_priority_badge((string) ($case['priority'] ?? 'Normal'));
        $primaryBadge = $case['is_primary'] ? '<span class="ca-status-pill ca-status-pill--pending ms-1">Primary</span>' : '';

        $casesHtml .= '
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0">' . htmlspecialchars($case['title']) . '</h6>
                        <div class="d-flex flex-wrap gap-1 justify-content-end">' . $statusBadge . $priorityBadge . $primaryBadge . '</div>
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

$iconDocRow = legalpro_icon('file-text');
$iconCommentEmpty = legalpro_icon('message-circle');

// Build comments feed HTML
$commentsHtml = '';
if (empty($clientComments)) {
    $commentsHtml = '
    <div class="cc-comments-empty text-center py-5 mb-0">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconCommentEmpty . '</div>
        <h6 class="font-weight-bolder mt-4 mb-2">No comments yet</h6>
        <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">Comments from this client and your team on shared cases will appear here.</p>
    </div>';
} else {
    $commentsHtml .= '<ul class="cc-comment-list list-unstyled mb-0">';
    foreach ($clientComments as $comment) {
        $type = (string) ($comment['comment_type'] ?? '');
        $username = trim((string) ($comment['username'] ?? ''));
        $displayName = $username !== '' ? $username : ucfirst($type !== '' ? $type : 'User');
        $itemClass = 'cc-comment-item cc-comment-item--' . preg_replace('/[^a-z]/', '', $type);
        $timeLabel = date('M j, Y · g:i A', strtotime($comment['created_at']));
        $body = nl2br(htmlspecialchars((string) ($comment['comment'] ?? '')));
        $roleBadge = $commentRoleBadge($type);
        $caseId = (int) ($comment['case_id'] ?? 0);
        $caseTitle = htmlspecialchars((string) ($comment['case_title'] ?? 'Case'));
        $caseLink = $caseId > 0
            ? '<a href="lawyer-case-view.php?id=' . $caseId . '" class="text-xs text-primary font-weight-bold">' . $caseTitle . '</a>'
            : '<span class="text-xs text-muted">' . $caseTitle . '</span>';

        $commentsHtml .= '
        <li class="' . $itemClass . '">
            <div class="cc-comment-item-inner">
                <div class="cc-comment-head">
                    <div class="cc-comment-head-main">
                        <span class="cc-comment-author">' . htmlspecialchars($displayName) . '</span>
                        ' . $roleBadge . '
                        <span class="cc-comment-case text-xs text-muted">· ' . $caseLink . '</span>
                    </div>
                    <time class="cc-comment-time" datetime="' . htmlspecialchars(date('c', strtotime($comment['created_at']))) . '">' . htmlspecialchars($timeLabel) . '</time>
                </div>
                <div class="cc-comment-text">' . $body . '</div>
            </div>
        </li>';
    }
    $commentsHtml .= '</ul>';
}

// Build documents HTML
$documentsHtml = '';
if (empty($clientDocuments)) {
    $documentsHtml = '<tr><td colspan="4" class="text-center text-muted py-3">No documents uploaded by this client</td></tr>';
} else {
    foreach ($clientDocuments as $document) {
        $filePath = isset($document['file_path']) ? trim((string) $document['file_path']) : '';
        $fileType = isset($document['file_type']) ? trim((string) $document['file_type']) : '';
        $fileLabel = isset($document['label']) ? trim((string) $document['label']) : '';
        $caseTitle = isset($document['case_title']) ? (string) $document['case_title'] : '';

        $absoluteUploadPath = $filePath !== '' ? (__DIR__ . '/../uploads/' . ltrim($filePath, '/\\')) : '';
        $fileExists = $absoluteUploadPath !== '' && is_file($absoluteUploadPath);
        $fileSize = $fileExists ? @filesize($absoluteUploadPath) : false;
        $fileSizeFormatted = $fileSize !== false ? round($fileSize / 1024, 1) . ' KB' : 'Unknown';

        if ($fileType === '' && $filePath !== '') {
            $fileType = strtoupper((string) pathinfo($filePath, PATHINFO_EXTENSION));
        }
        if ($fileType === '') {
            $fileType = 'Unknown';
        }

        $fallbackName = $filePath !== '' ? basename($filePath) : 'Untitled document';
        $displayName = $fileLabel !== '' ? $fileLabel : $fallbackName;
        $safeDisplayName = htmlspecialchars($displayName);
        $safeCaseTitle = htmlspecialchars($caseTitle !== '' ? $caseTitle : 'Unknown case');
        $safeFileType = htmlspecialchars($fileType);

        $documentActionsHtml = '<span class="text-xs text-muted">File unavailable</span>';
        if ($filePath !== '') {
            $safeFilePath = htmlspecialchars($filePath);
            $documentActionsHtml = '
                <a href="../uploads/' . $safeFilePath . '" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                <a href="../uploads/' . $safeFilePath . '" download class="btn btn-sm btn-outline-secondary">Download</a>';
        }

        $documentsHtml .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconDocRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . $safeDisplayName . '</h6>
                        <p class="text-xs text-muted mb-0">' . $safeCaseTitle . '</p>
                    </div>
                </div>
            </td>
            <td class="text-center">' . $safeFileType . '</td>
            <td class="text-center">' . $fileSizeFormatted . '</td>
            <td class="text-center">' . date('M d, Y', strtotime($document['uploaded_at'])) . '</td>
            <td class="text-end">
                ' . $documentActionsHtml . '
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
    <title>LegalPro - Client Details</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <style>
        .lawyer-client-comments-feed .cc-comment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: min(36rem, 65vh);
            overflow-y: auto;
            padding-right: 0.15rem;
        }
        .lawyer-client-comments-feed .cc-comment-list::-webkit-scrollbar { width: 6px; }
        .lawyer-client-comments-feed .cc-comment-list::-webkit-scrollbar-thumb {
            background: rgba(45, 206, 137, 0.35);
            border-radius: 999px;
        }
        .lawyer-client-comments-feed .cc-comment-item-inner {
            background: #fff;
            border: 1px solid rgba(0,0,0,.06);
            border-radius: 0.75rem;
            padding: 1rem 1.15rem;
            border-left: 4px solid #8392ab;
            box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .lawyer-client-comments-feed .cc-comment-item--client .cc-comment-item-inner { border-left-color: #11cdef; }
        .lawyer-client-comments-feed .cc-comment-item--lawyer .cc-comment-item-inner { border-left-color: #2dce89; }
        .lawyer-client-comments-feed .cc-comment-item--admin .cc-comment-item-inner { border-left-color: #fb6340; }
        .lawyer-client-comments-feed .cc-comment-item--staff .cc-comment-item-inner { border-left-color: #8898aa; }
        .lawyer-client-comments-feed .cc-comment-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.65rem;
            flex-wrap: wrap;
        }
        .lawyer-client-comments-feed .cc-comment-head-main {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
            min-width: 0;
        }
        .lawyer-client-comments-feed .cc-comment-author {
            font-size: 0.875rem;
            font-weight: 700;
            color: #344767;
        }
        .lawyer-client-comments-feed .cc-comment-case {
            display: inline;
        }
        .lawyer-client-comments-feed .cc-comment-time {
            font-size: 0.75rem;
            color: #8392ab;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .lawyer-client-comments-feed .cc-comment-text {
            font-size: 0.875rem;
            line-height: 1.6;
            color: #525f7f;
            word-break: break-word;
            overflow-wrap: anywhere;
            margin: 0;
        }
        body.lawyer-client-view-page:not(.legalpro-dark-mode) #clientTabs .nav-link,
        body.lawyer-client-view-page:not(.legalpro-dark-mode) #clientTabs .nav-link:hover,
        body.lawyer-client-view-page:not(.legalpro-dark-mode) #clientTabs .nav-link:focus,
        body.lawyer-client-view-page:not(.legalpro-dark-mode) #clientTabs .nav-link.active {
            color: #344767 !important;
        }
    </style>
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
                                    <div class="lawyer-client-comments-feed">
                                        {COMMENTS_HTML}
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
echo legalpro_apply_copyright_line($html);
?>
