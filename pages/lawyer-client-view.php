<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/lawyer_portal_vocab.php';

ensure_lawyer_case_vocabulary($pdo);

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

$iconDocRow = legalpro_icon('file-text');
$iconCommentEmpty = legalpro_icon('message-circle');
$iconCaseRow = legalpro_icon('briefcase');
$iconMail = legalpro_icon('mail');
$iconPhone = legalpro_icon('phone');
$iconMap = legalpro_icon('map-pin');
$iconCalendar = legalpro_icon('calendar');
$iconArrowLeft = legalpro_icon('arrow-left');
$iconPanelContact = legalpro_icon('user');
$iconPanelCases = legalpro_icon('briefcase');
$iconPanelActivity = legalpro_icon('message-circle');

$clientFullName = trim($client['first_name'] . ' ' . $client['last_name']);
$clientInitials = legalpro_portal_initials($clientFullName, 'CL');
$documentsCount = count($clientDocuments);
$commentsCount = count($clientComments);
$totalCases = count($clientCases);
$activeCases = count(array_filter($clientCases, static function ($case) {
    return ($case['status'] ?? '') !== 'closed';
}));

$contactDetailsHtml = '';
$contactRows = [
    ['icon' => $iconMail, 'label' => 'Email', 'value' => $client['email'] ?: 'Not provided'],
    ['icon' => $iconPhone, 'label' => 'Phone', 'value' => $client['phone'] ?: 'Not provided'],
    ['icon' => $iconMap, 'label' => 'Address', 'value' => $client['address'] ?: 'Not provided'],
    ['icon' => $iconCalendar, 'label' => 'Client since', 'value' => date('M d, Y', strtotime($client['created_at']))],
];
foreach ($contactRows as $row) {
    $contactDetailsHtml .= '
    <div class="lcv-detail-item">
        <div class="lcv-detail-item__icon">' . $row['icon'] . '</div>
        <div class="min-width-0">
            <span class="lcv-detail-item__label">' . htmlspecialchars($row['label']) . '</span>
            <span class="lcv-detail-item__value">' . htmlspecialchars($row['value']) . '</span>
        </div>
    </div>';
}

// Build cases HTML
$casesHtml = '';
if (empty($clientCases)) {
    $casesHtml = '<div class="text-center py-5 px-3">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconCaseRow . '</div>
        <h6 class="font-weight-bolder mt-3 mb-2">No cases yet</h6>
        <p class="text-sm text-muted mb-0">Cases you share with this client will appear here.</p>
    </div>';
} else {
    $casesHtml = '<div class="lcv-case-grid">';
    foreach ($clientCases as $case) {
        $statusBadge = lawyer_case_status_badge((string) ($case['status'] ?? ''));
        $priorityBadge = lawyer_case_priority_badge((string) ($case['priority'] ?? 'Normal'));
        $primaryBadge = $case['is_primary'] ? '<span class="ca-status-pill ca-status-pill--pending">Primary</span>' : '';
        $description = trim((string) ($case['description'] ?? ''));
        if ($description === '') {
            $description = 'No description provided.';
        } elseif (strlen($description) > 120) {
            $description = substr($description, 0, 117) . '...';
        }

        $casesHtml .= '
        <article class="lcv-case-card">
            <div class="lcv-case-card__head">
                <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">' . $iconCaseRow . '</div>
                <div class="min-width-0">
                    <h6 class="lcv-case-card__title">' . htmlspecialchars($case['title']) . '</h6>
                    <p class="lcv-case-card__id">Case #' . (int) $case['id'] . '</p>
                </div>
            </div>
            <div class="lcv-case-card__badges">' . $statusBadge . $priorityBadge . $primaryBadge . '</div>
            <p class="lcv-case-card__desc">' . htmlspecialchars($description) . '</p>
            <div class="lcv-case-card__foot">
                <a href="lawyer-case-view.php?id=' . (int) $case['id'] . '" class="btn btn-sm btn-primary mb-0">View case</a>
            </div>
        </article>';
    }
    $casesHtml .= '</div>';
}

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
    $documentsHtml = '<tr><td colspan="5" class="text-center text-muted py-5">No documents uploaded for this client\'s cases</td></tr>';
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
            <td class="align-middle">
                <div class="d-flex align-items-center">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconDocRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . $safeDisplayName . '</h6>
                        <p class="text-xs text-muted mb-0">' . $safeCaseTitle . '</p>
                    </div>
                </div>
            </td>
            <td class="align-middle text-center">' . $safeFileType . '</td>
            <td class="align-middle text-center">' . $fileSizeFormatted . '</td>
            <td class="align-middle text-center">' . date('M d, Y', strtotime($document['uploaded_at'])) . '</td>
            <td class="align-middle text-end lp-table-actions">
                <div class="lp-table-actions-inner">' . $documentActionsHtml . '</div>
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
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-client-view-page{PORTAL_THEME_BODY_CLASS}">
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
            <div class="card lcv-hero mb-4">
                <div class="lcv-hero__gradient">
                    <a href="lawyer-clients.php" class="lcv-back">{ICON_ARROW_LEFT} Back to clients</a>
                    <div class="lcv-hero__main">
                        <div class="lcv-avatar" aria-hidden="true">{CLIENT_INITIALS}</div>
                        <div>
                            <h1 class="lcv-hero__name">{CLIENT_NAME}</h1>
                            <p class="lcv-hero__meta">Client since {CLIENT_SINCE}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lcv-glance">
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{TOTAL_CASES}</div>
                        <div class="lcv-glance__lbl">Total cases</div>
                    </div>
                    <div class="lcv-glance__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">{ICON_PANEL_CASES}</div>
                </div>
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{ACTIVE_CASES}</div>
                        <div class="lcv-glance__lbl">Active cases</div>
                    </div>
                    <div class="lcv-glance__icon" style="background:rgba(45,206,137,.12);color:#2dce89;">{ICON_PANEL_CASES}</div>
                </div>
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{COMMENTS_COUNT}</div>
                        <div class="lcv-glance__lbl">Comments</div>
                    </div>
                    <div class="lcv-glance__icon" style="background:rgba(17,205,239,.12);color:#11cdef;">{ICON_PANEL_ACTIVITY}</div>
                </div>
                <div class="lcv-glance__item">
                    <div>
                        <div class="lcv-glance__val">{DOCUMENTS_COUNT}</div>
                        <div class="lcv-glance__lbl">Documents</div>
                    </div>
                    <div class="lcv-glance__icon" style="background:rgba(251,99,64,.12);color:#fb6340;">{ICON_DOC_ROW}</div>
                </div>
            </div>

            <div class="card lcv-panel mb-4">
                <div class="lcv-panel__head">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">{ICON_PANEL_CONTACT}</div>
                    <div>
                        <h6>Contact information</h6>
                        <p>How to reach this client</p>
                    </div>
                </div>
                <div class="lcv-panel__body">
                    <div class="lcv-detail-grid">{CONTACT_DETAILS}</div>
                </div>
            </div>

            <div class="card lcv-panel mb-4">
                <div class="lcv-panel__head">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">{ICON_PANEL_CASES}</div>
                    <div>
                        <h6>Associated cases</h6>
                        <p>Cases involving this client that are assigned to you</p>
                    </div>
                </div>
                <div class="lcv-panel__body">
                    {CLIENT_CASES}
                </div>
            </div>

            <div class="card lcv-panel">
                <div class="lcv-panel__head">
                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">{ICON_PANEL_ACTIVITY}</div>
                    <div>
                        <h6>Activity</h6>
                        <p>Comments and documents from shared cases</p>
                    </div>
                </div>
                <div class="lcv-tabs-wrap">
                    <ul class="nav lcv-tabs" id="clientTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments" type="button" role="tab">
                                Comments <span class="lcv-tab-badge">{COMMENTS_COUNT}</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab">
                                Documents <span class="lcv-tab-badge">{DOCUMENTS_COUNT}</span>
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="lcv-panel__body pt-3">
                    <div class="tab-content" id="clientTabsContent">
                        <div class="tab-pane fade show active" id="comments" role="tabpanel">
                            <div class="lawyer-client-comments-feed">
                                {COMMENTS_HTML}
                            </div>
                        </div>
                        <div class="tab-pane fade" id="documents" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table lcv-table align-items-center mb-0">
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
$replacements = [
    '{NAVIGATION}' => $navHtml,
    '{CLIENT_NAME}' => htmlspecialchars($clientFullName),
    '{CLIENT_INITIALS}' => htmlspecialchars($clientInitials),
    '{CLIENT_SINCE}' => date('M d, Y', strtotime($client['created_at'])),
    '{TOTAL_CASES}' => $totalCases,
    '{ACTIVE_CASES}' => $activeCases,
    '{COMMENTS_COUNT}' => $commentsCount,
    '{DOCUMENTS_COUNT}' => $documentsCount,
    '{CONTACT_DETAILS}' => $contactDetailsHtml,
    '{CLIENT_CASES}' => $casesHtml,
    '{COMMENTS_HTML}' => $commentsHtml,
    '{DOCUMENTS_HTML}' => $documentsHtml,
    '{ICON_ARROW_LEFT}' => $iconArrowLeft,
    '{ICON_PANEL_CONTACT}' => $iconPanelContact,
    '{ICON_PANEL_CASES}' => $iconPanelCases,
    '{ICON_PANEL_ACTIVITY}' => $iconPanelActivity,
    '{ICON_DOC_ROW}' => $iconDocRow,
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
?>
