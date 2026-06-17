<?php

require_once __DIR__ . '/case_events.php';
require_once __DIR__ . '/client-portal-features.php';
require_once dirname(__DIR__) . '/inc/admin-layout.php';
require_once dirname(__DIR__) . '/inc/client-portal-navbar.php';
require_once dirname(__DIR__) . '/inc/legalpro-icons.php';

function legalpro_client_case_nav(): array
{
    return [
        'client-case-view' => [
            'file' => 'client-case-view.php',
            'title' => 'Overview',
            'heading' => 'Case overview',
            'subtitle' => 'Summary, status, and key dates for this matter.',
        ],
        'client-case-activity' => [
            'file' => 'client-case-activity.php',
            'title' => 'Activity',
            'heading' => 'Case activity',
            'subtitle' => 'Timeline of documents, appointments, payments, and updates.',
        ],
        'client-case-services' => [
            'file' => 'client-case-services.php',
            'title' => 'Services',
            'heading' => 'Services & fees',
            'subtitle' => 'Line items and total fees for this matter.',
        ],
        'client-case-appointments' => [
            'file' => 'client-case-appointments.php',
            'title' => 'Appointments',
            'heading' => 'Appointments',
            'subtitle' => 'Scheduled meetings with your legal team.',
        ],
        'client-case-documents' => [
            'file' => 'client-case-documents.php',
            'title' => 'Documents',
            'heading' => 'Documents',
            'subtitle' => 'View, download, acknowledge, and upload files.',
        ],
        'client-case-comments' => [
            'file' => 'client-case-comments.php',
            'title' => 'Comments',
            'heading' => 'Case comments',
            'subtitle' => 'Notes and updates from you and your legal team.',
        ],
    ];
}

function legalpro_client_case_page_key(): string
{
    $current = basename($_SERVER['PHP_SELF'], '.php');
    $nav = legalpro_client_case_nav();

    return array_key_exists($current, $nav) ? $current : 'client-case-view';
}

function legalpro_client_case_subnav_html(int $caseId, string $activeKey): string
{
    $nav = legalpro_client_case_nav();
    $html = '<nav class="legalpro-doc-subnav legalpro-case-subnav" aria-label="Case sections">';
    foreach ($nav as $key => $item) {
        $active = $key === $activeKey ? ' is-active' : '';
        $href = htmlspecialchars($item['file'] . '?id=' . $caseId);
        $html .= '<a class="legalpro-doc-subnav__link' . $active . '" href="' . $href . '">'
            . htmlspecialchars($item['title']) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function legalpro_client_case_require_session(): array
{
    if (!isset($_SESSION['client_id'])) {
        header('Location: login.php');
        exit;
    }

    return [
        'client_id' => (int) $_SESSION['client_id'],
        'client_name' => (string) ($_SESSION['client_name'] ?? 'Client'),
        'client_user_id' => (int) ($_SESSION['client_user_id'] ?? 0),
    ];
}

function legalpro_client_case_redirect(int $caseId, string $pageFile, string $hash = ''): void
{
    $url = $pageFile . '?id=' . $caseId;
    if ($hash !== '') {
        $url .= '#' . ltrim($hash, '#');
    }
    header('Location: ' . $url);
    exit;
}

function legalpro_client_case_handle_post(PDO $pdo, array &$state): void
{
    $caseId = (int) ($state['case_id'] ?? 0);
    $clientId = (int) ($state['client_id'] ?? 0);
    $clientUserId = (int) ($state['client_user_id'] ?? 0);
    $clientName = (string) ($state['client_name'] ?? 'Client');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $caseId <= 0) {
        return;
    }

    if (isset($_POST['delete_comment_id'])) {
        $commentId = (int) $_POST['delete_comment_id'];
        if ($commentId > 0 && $clientUserId > 0) {
            try {
                $stmt = $pdo->prepare("
                    SELECT cc.id
                    FROM case_comments cc
                    INNER JOIN cases c ON c.id = cc.case_id
                    WHERE cc.id = ? AND cc.case_id = ? AND c.client_id = ?
                      AND cc.user_id = ? AND cc.comment_type = 'client'
                ");
                $stmt->execute([$commentId, $caseId, $clientId, $clientUserId]);
                if ($stmt->fetch()) {
                    $del = $pdo->prepare('DELETE FROM case_comments WHERE id = ? AND case_id = ?');
                    $del->execute([$commentId, $caseId]);
                }
            } catch (PDOException $e) {
                // ignore
            }
        }
        legalpro_client_case_redirect($caseId, 'client-case-comments.php', 'case-comments');
    }

    if (isset($_POST['edit_comment_id'])) {
        $commentId = (int) $_POST['edit_comment_id'];
        $commentText = trim((string) ($_POST['comment'] ?? ''));
        if ($commentId > 0 && $clientUserId > 0 && $commentText !== '') {
            try {
                $stmt = $pdo->prepare("
                    SELECT cc.id
                    FROM case_comments cc
                    INNER JOIN cases c ON c.id = cc.case_id
                    WHERE cc.id = ? AND cc.case_id = ? AND c.client_id = ?
                      AND cc.user_id = ? AND cc.comment_type = 'client'
                ");
                $stmt->execute([$commentId, $caseId, $clientId, $clientUserId]);
                if ($stmt->fetch()) {
                    $upd = $pdo->prepare('UPDATE case_comments SET comment = ? WHERE id = ? AND case_id = ?');
                    $upd->execute([$commentText, $commentId, $caseId]);
                }
            } catch (PDOException $e) {
                // ignore
            }
        }
        legalpro_client_case_redirect($caseId, 'client-case-comments.php', 'case-comments');
    }

    if (isset($_POST['comment']) && !isset($_POST['edit_comment_id'])) {
        $comment = trim((string) $_POST['comment']);
        if ($comment !== '' && $clientUserId > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO case_comments (case_id, user_id, comment, comment_type) VALUES (?, ?, ?, 'client')");
                $stmt->execute([$caseId, $clientUserId, $comment]);
                $state['message'] = 'Comment added successfully!';
                $state['messageType'] = 'success';
                CaseEvents::trackCommentAdded($caseId, [
                    'comment' => $comment,
                    'comment_type' => 'client',
                ]);
            } catch (PDOException $e) {
                $state['message'] = 'Error adding comment: ' . htmlspecialchars($e->getMessage());
                $state['messageType'] = 'danger';
            }
        }
        if (($state['messageType'] ?? '') === 'success') {
            legalpro_client_case_redirect($caseId, 'client-case-comments.php', 'case-comments');
        }
        return;
    }

    if (($_POST['action'] ?? '') === 'acknowledge') {
        $docId = (int) ($_POST['document_id'] ?? 0);
        if (legalpro_client_acknowledge_document($pdo, $clientId, $docId)) {
            $state['message'] = 'Receipt acknowledged.';
            $state['messageType'] = 'success';
        }
        legalpro_client_case_redirect($caseId, 'client-case-documents.php');
    }

    if (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        $label = trim((string) ($_POST['file_label'] ?? ''));
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploadDir = dirname(__DIR__) . '/uploads/client_files/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = uniqid() . '_' . basename($file['name']);
            $filePath = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO documents (case_id, filename, filepath, label, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$caseId, $file['name'], 'uploads/client_files/' . $fileName, $label ?: $file['name'], $clientName]);
                    $state['message'] = 'File uploaded successfully!';
                    $state['messageType'] = 'success';
                    CaseEvents::trackDocumentUploaded($caseId, [
                        'filename' => $file['name'],
                        'label' => $label ?: $file['name'],
                    ]);
                } catch (PDOException $e) {
                    $state['message'] = 'Error saving file information: ' . htmlspecialchars($e->getMessage());
                    $state['messageType'] = 'danger';
                }
            } else {
                $state['message'] = 'Error uploading file.';
                $state['messageType'] = 'danger';
            }
        }
        if (($state['messageType'] ?? '') === 'success') {
            legalpro_client_case_redirect($caseId, 'client-case-documents.php');
        }
    }
}

function legalpro_client_case_load(PDO $pdo, array &$state): bool
{
    $caseId = (int) ($state['case_id'] ?? 0);
    $clientId = (int) ($state['client_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') as lawyer_names
            FROM cases c
            LEFT JOIN case_lawyers cl ON cl.case_id = c.id
            LEFT JOIN lawyers l ON l.id = cl.lawyer_id
            WHERE c.id = ? AND c.client_id = ?
            GROUP BY c.id
        ");
        $stmt->execute([$caseId, $clientId]);
        $case = $stmt->fetch();
        if (!$case) {
            header('Location: client-cases.php');
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at ASC");
        $stmt->execute([$caseId]);
        $services = $stmt->fetchAll();

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
        $stmt->execute([$caseId]);
        $comments = $stmt->fetchAll();

        $caseEvents = CaseEvents::getCaseEvents($caseId);

        $stmt = $pdo->prepare("SELECT * FROM documents WHERE case_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$caseId]);
        $documents = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT a.*, CONCAT(l.first_name, ' ', l.last_name) as lawyer_name
            FROM appointments a
            LEFT JOIN case_lawyers cl ON cl.case_id = a.case_id
            LEFT JOIN lawyers l ON l.id = cl.lawyer_id
            WHERE a.case_id = ?
            ORDER BY a.starts_at DESC
        ");
        $stmt->execute([$caseId]);
        $appointments = $stmt->fetchAll();

        $ackMap = [];
        foreach (legalpro_client_get_documents($pdo, $clientId, $caseId) as $d) {
            $ackMap[(int) $d['id']] = $d;
        }

        $state['case'] = $case;
        $state['services'] = $services;
        $state['comments'] = $comments;
        $state['caseEvents'] = $caseEvents;
        $state['documents'] = $documents;
        $state['appointments'] = $appointments;
        $state['ackMap'] = $ackMap;
        $state['case_number'] = 'C-' . str_pad((string) $case['id'], 4, '0', STR_PAD_LEFT);

        return true;
    } catch (PDOException $e) {
        $state['message'] = 'Error loading case details: ' . htmlspecialchars($e->getMessage());
        $state['messageType'] = 'danger';
        $state['case'] = null;

        return false;
    }
}

function legalpro_client_case_init_state(PDO $pdo): array
{
    $session = legalpro_client_case_require_session();
    $caseId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($caseId <= 0) {
        header('Location: client-cases.php');
        exit;
    }

    $state = array_merge($session, [
        'case_id' => $caseId,
        'message' => '',
        'messageType' => '',
        'case' => null,
        'services' => [],
        'comments' => [],
        'caseEvents' => [],
        'documents' => [],
        'appointments' => [],
        'ackMap' => [],
        'case_number' => '',
    ]);

    legalpro_client_case_handle_post($pdo, $state);
    legalpro_client_case_load($pdo, $state);

    return $state;
}

function legalpro_client_case_message_html(array $state): string
{
    if (empty($state['message'])) {
        return '';
    }

    $type = $state['messageType'] !== '' ? $state['messageType'] : 'info';

    return '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($state['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
        . '</div>';
}

function legalpro_client_case_context_bar_html(array $state): string
{
    $case = $state['case'] ?? null;
    if (!$case) {
        return '';
    }

    $caseNumber = htmlspecialchars((string) ($state['case_number'] ?? ''));
    $title = htmlspecialchars((string) ($case['title'] ?? ''));
    $statusBadge = client_case_status_badge((string) ($case['status'] ?? ''));
    $priorityBadge = client_case_priority_badge((string) ($case['priority'] ?? 'Normal'));

    return '
    <div class="legalpro-case-context-bar card mb-3">
        <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="min-width-0">
                <a href="client-cases.php" class="legalpro-case-context-bar__back text-sm">← My cases</a>
                <h5 class="mb-1 mt-1">' . $caseNumber . ' · ' . $title . '</h5>
                <div class="d-flex gap-2 flex-wrap">' . $statusBadge . $priorityBadge . '</div>
            </div>
        </div>
    </div>';
}

function legalpro_client_case_comment_role_badge(string $type): string
{
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
}

function legalpro_client_case_overview_html(array $state): string
{
    $case = $state['case'];
    $lawyerNames = htmlspecialchars($case['lawyer_names'] ?: 'Unassigned');
    $categoryBadge = '<span class="ca-status-pill ca-status-pill--muted">' . htmlspecialchars((string) ($case['category'] ?? '')) . '</span>';

    return '
    <div class="card">
        <div class="card-body">
            <p class="text-muted mb-3">' . htmlspecialchars($case['description'] ?: 'No description provided.') . '</p>
            <div class="d-flex gap-2 mb-3 flex-wrap">' . $categoryBadge . '</div>
            <div class="row">
                <div class="col-md-6">
                    <p class="text-sm mb-1"><strong>Lawyer(s):</strong> ' . $lawyerNames . '</p>
                    <p class="text-sm mb-1"><strong>Start Date:</strong> ' . ($case['start_date'] ? date('M d, Y', strtotime($case['start_date'])) : 'Not set') . '</p>
                    <p class="text-sm mb-1"><strong>Expected Completion:</strong> ' . ($case['expected_completion'] ? date('M d, Y', strtotime($case['expected_completion'])) : 'Not set') . '</p>
                </div>
                <div class="col-md-6">
                    <p class="text-sm mb-1"><strong>Estimated Fees:</strong> $' . number_format((float) $case['estimated_fees'], 2) . '</p>
                    <p class="text-sm mb-1"><strong>Last Updated:</strong> ' . date('M d, Y', strtotime($case['updated_at'])) . '</p>
                </div>
            </div>
        </div>
    </div>';
}

function legalpro_client_case_activity_html(array $state): string
{
    $activityHtml = CaseEvents::renderEventsTimeline((int) $state['case_id']);
    $count = count($state['caseEvents'] ?? []);

    return '
    <div class="card ccv-activity-panel">
        <div class="card-header pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h6 class="mb-0 ccv-activity-panel__title">Case Activity</h6>
                <p class="text-xs mb-0 mt-1 ccv-activity-panel__subtitle">Updates on documents, appointments, payments, and case changes</p>
            </div>
            <span class="badge bg-gradient-primary">' . $count . '</span>
        </div>
        <div class="card-body ccv-activity-feed">' . $activityHtml . '</div>
    </div>';
}

function legalpro_client_case_services_html(array $state): string
{
    $services = $state['services'] ?? [];
    $iconServiceRow = legalpro_icon('receipt');
    $iconServiceEmpty = legalpro_icon('receipt');
    $servicesHtml = '';
    $totalFees = 0.0;

    if (!empty($services)) {
        foreach ($services as $service) {
            $pricePill = '<span class="ca-status-pill ca-status-pill--muted">$ ' . number_format((float) $service['price'], 2) . '</span>';
            $servicesHtml .= '<li class="list-group-item border-0 px-0">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3 min-width-0">
                        <div class="ccv-service-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">' . $iconServiceRow . '</div>
                        <span class="text-sm font-weight-bold">' . htmlspecialchars($service['service_name']) . '</span>
                    </div>
                    ' . $pricePill . '
                </div>
            </li>';
            $totalFees += (float) $service['price'];
        }
        $totalPill = '<span class="ca-status-pill ca-status-pill--done">$ ' . number_format($totalFees, 2) . '</span>';
        $servicesHtml .= '<li class="list-group-item border-0 px-0 pt-3">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <span class="text-sm font-weight-bold">Total Fees</span>
                ' . $totalPill . '
            </div>
        </li>';
    } else {
        $servicesHtml = '<div class="text-center py-4">
            <div class="ccv-service-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconServiceEmpty . '</div>
            <p class="text-sm text-muted mb-0 mt-3">No services defined yet.</p>
        </div>';
    }

    return '
    <div class="card">
        <div class="card-header pb-0"><h6>Services & Fees</h6></div>
        <div class="card-body"><ul class="list-group list-group-flush">' . $servicesHtml . '</ul></div>
    </div>';
}

function legalpro_client_case_appointments_html(array $state): string
{
    $appointments = $state['appointments'] ?? [];
    $iconApptRow = legalpro_icon('calendar-clock');
    $iconApptEmpty = legalpro_icon('calendar');
    $html = '';

    if (!empty($appointments)) {
        foreach ($appointments as $apt) {
            $statusBadge = client_appointment_status_badge($apt);
            $html .= '<div class="d-flex align-items-start gap-3 mb-3 cp-appt-card">
                <div class="ccv-appt-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">' . $iconApptRow . '</div>
                <div class="w-100 min-width-0">
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <h6 class="mb-0 text-sm font-weight-bold">' . date('M d, Y g:i A', strtotime($apt['starts_at'])) . '</h6>
                        ' . $statusBadge . '
                    </div>
                    <p class="text-xs text-secondary mb-0 mt-1">Lawyer: ' . htmlspecialchars($apt['lawyer_name'] ?: 'TBD') . '</p>
                    <p class="text-xs text-secondary mb-0">Notes: ' . htmlspecialchars($apt['notes'] ?: 'No notes') . '</p>
                </div>
            </div>';
        }
    } else {
        $html = '<div class="text-center py-4">
            <div class="ccv-appt-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconApptEmpty . '</div>
            <p class="text-sm text-muted mb-0 mt-3">No appointments scheduled.</p>
        </div>';
    }

    return '
    <div class="card">
        <div class="card-header pb-0"><h6>Appointments</h6></div>
        <div class="card-body">' . $html . '</div>
    </div>';
}

function legalpro_client_case_documents_html(array $state): string
{
    $documents = $state['documents'] ?? [];
    $ackMap = $state['ackMap'] ?? [];
    $caseId = (int) $state['case_id'];
    $listHtml = '';

    if (!empty($documents)) {
        foreach ($documents as $doc) {
            $docMeta = $ackMap[(int) $doc['id']] ?? null;
            $viewUrl = (string) ($docMeta['view_url'] ?? '../' . ltrim((string) $doc['filepath'], '/'));
            $downloadUrl = (string) ($docMeta['download_url'] ?? $viewUrl);
            $downloadName = (string) ($docMeta['download_filename'] ?? $doc['filename']);
            $docLabel = (string) ($docMeta['display_label'] ?? ($doc['label'] ?: $doc['filename']));
            $ackHtml = '';
            if ($docMeta && !empty($docMeta['needs_ack'])) {
                $ackHtml = '<form method="post" class="d-inline ms-1">
                    <input type="hidden" name="action" value="acknowledge">
                    <input type="hidden" name="document_id" value="' . (int) $doc['id'] . '">
                    <button type="submit" class="btn btn-sm btn-outline-success cdoc-touch-btn">Acknowledge</button>
                </form>';
            } elseif ($docMeta && !empty($docMeta['is_acknowledged'])) {
                $ackHtml = '<span class="text-xs text-success ms-1">✓ Acknowledged</span>';
            }
            $newBadge = ($docMeta && !empty($docMeta['is_new'])) ? ' <span class="cdoc-new-badge">New</span>' : '';
            $listHtml .= '<div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                <div class="w-100">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($docLabel) . $newBadge . '</h6>
                        <div>
                            <a href="' . htmlspecialchars($viewUrl) . '" target="_blank" class="btn btn-sm btn-outline-primary cdoc-touch-btn">View</a>
                            <a href="' . htmlspecialchars($downloadUrl) . '" download="' . htmlspecialchars($downloadName) . '" class="btn btn-sm btn-primary cdoc-touch-btn">Download PDF</a>
                            ' . $ackHtml . '
                        </div>
                    </div>
                    <p class="text-xs text-secondary mb-0">Uploaded by ' . htmlspecialchars($doc['uploaded_by']) . ' on ' . date('M d, Y', strtotime($doc['uploaded_at'])) . '</p>
                </div>
            </div>';
        }
    } else {
        $listHtml = '<p class="text-muted text-sm mb-0">No documents uploaded yet. <a href="client-documents.php">View all documents</a></p>';
    }

    return '
    <div class="card mb-4">
        <div class="card-header pb-0"><h6>Documents</h6></div>
        <div class="card-body">' . $listHtml . '</div>
    </div>
    <div class="card">
        <div class="card-header pb-0"><h6>Upload Document</h6></div>
        <div class="card-body">
            <form method="POST" action="client-case-documents.php?id=' . $caseId . '" enctype="multipart/form-data">
                <div class="form-group mb-2">
                    <input type="text" class="form-control form-control-sm" name="file_label" placeholder="Document description (optional)">
                </div>
                <div class="form-group mb-2">
                    <input type="file" class="form-control form-control-sm" name="file" required>
                </div>
                <button type="submit" class="btn btn-success btn-sm">Upload File</button>
            </form>
        </div>
    </div>';
}

function legalpro_client_case_comments_html(array $state): string
{
    $comments = $state['comments'] ?? [];
    $clientUserId = (int) ($state['client_user_id'] ?? 0);
    $caseId = (int) $state['case_id'];
    $commentsHtml = '';

    if (!empty($comments)) {
        $commentsHtml .= '<ul class="cc-comment-list list-unstyled mb-0">';
        foreach ($comments as $comment) {
            $isCurrentUser = ((int) $comment['user_id'] === $clientUserId);
            $username = trim((string) ($comment['username'] ?? ''));
            $displayName = $username !== '' ? $username : 'User';
            $type = $comment['comment_type'] ?? '';
            $itemClass = 'cc-comment-item cc-comment-item--' . preg_replace('/[^a-z]/', '', $type);
            if ($isCurrentUser) {
                $itemClass .= ' cc-comment-item--yours';
            }
            $timeLabel = date('M j, Y · g:i A', strtotime($comment['created_at']));
            $body = nl2br(htmlspecialchars($comment['comment']));
            $rawComment = htmlspecialchars((string) $comment['comment'], ENT_QUOTES, 'UTF-8');
            $roleBadge = legalpro_client_case_comment_role_badge($type);
            $youBadge = $isCurrentUser ? '<span class="badge badge-sm bg-gradient-primary ms-1">You</span>' : '';
            $commentId = (int) ($comment['id'] ?? 0);
            $canManage = $isCurrentUser && $type === 'client' && $commentId > 0;

            if ($canManage) {
                $headActions = '
                    <div class="cc-comment-head-actions">
                        <time class="cc-comment-time" datetime="' . htmlspecialchars(date('c', strtotime($comment['created_at']))) . '">' . htmlspecialchars($timeLabel) . '</time>
                        <button type="button" class="btn btn-sm btn-outline-primary mb-0 cc-comment-edit-btn" data-comment-id="' . $commentId . '">Edit</button>
                        <form method="post" class="cc-comment-delete-form" onsubmit="return confirm(\'Delete this comment?\');">
                            <input type="hidden" name="delete_comment_id" value="' . $commentId . '">
                            <button type="submit" class="btn btn-sm btn-outline-danger mb-0">Delete</button>
                        </form>
                    </div>';
                $commentBody = '
                <div class="cc-comment-text cc-comment-view" id="cc-comment-view-' . $commentId . '">' . $body . '</div>
                <div class="cc-comment-edit-wrap d-none" id="cc-comment-edit-' . $commentId . '">
                    <form method="post" class="cc-comment-edit-form">
                        <input type="hidden" name="edit_comment_id" value="' . $commentId . '">
                        <textarea class="form-control form-control-sm mb-2" name="comment" rows="3" required>' . $rawComment . '</textarea>
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary mb-0 cc-comment-edit-cancel">Cancel</button>
                            <button type="submit" class="btn btn-sm bg-gradient-primary mb-0">Save</button>
                        </div>
                    </form>
                </div>';
            } else {
                $headActions = '<time class="cc-comment-time" datetime="' . htmlspecialchars(date('c', strtotime($comment['created_at']))) . '">' . htmlspecialchars($timeLabel) . '</time>';
                $commentBody = '<div class="cc-comment-text">' . $body . '</div>';
            }

            $commentsHtml .= '
            <li class="' . $itemClass . '">
                <div class="cc-comment-item-inner">
                    <div class="cc-comment-head">
                        <div class="cc-comment-head-main">
                            <span class="cc-comment-author">' . htmlspecialchars($displayName) . '</span>
                            ' . $youBadge . '
                            ' . $roleBadge . '
                        </div>
                        ' . $headActions . '
                    </div>
                    ' . $commentBody . '
                </div>
            </li>';
        }
        $commentsHtml .= '</ul>';
    } else {
        $commentsHtml = '
        <div class="cc-comments-empty text-center py-5 mb-0">
            <div class="cc-comments-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                <i class="ni ni-chat-round text-primary text-lg opacity-10" aria-hidden="true"></i>
            </div>
            <h6 class="font-weight-bolder mt-4 mb-2">No comments yet</h6>
            <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 22rem;">Add a comment below to communicate with your legal team about this case.</p>
        </div>';
    }

    $commentFormHtml = '
    <form method="POST" action="client-case-comments.php?id=' . $caseId . '#case-comments" class="cc-comment-form mt-4 pt-4 border-top">
        <label for="case-comment-input" class="form-label text-sm font-weight-bold mb-2">Add a comment</label>
        <textarea id="case-comment-input" class="form-control" name="comment" rows="4" placeholder="Write your comment here…" required></textarea>
        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn bg-gradient-primary mb-0">Post comment</button>
        </div>
    </form>';

    return '
    <div class="card cc-comments-panel shadow-sm" id="case-comments">
        <div class="card-header pb-0 pt-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h6 class="mb-0">Case comments</h6>
                <p class="text-xs text-muted mb-0 mt-1">Notes and updates from you and your legal team</p>
            </div>
            <span class="badge bg-gradient-primary">' . count($comments) . '</span>
        </div>
        <div class="card-body">' . $commentsHtml . $commentFormHtml . '</div>
    </div>';
}

function legalpro_client_case_shared_styles(): string
{
    return <<<'CSS'
        .legalpro-case-context-bar { border: 1px solid #e9ecf3; box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
        .legalpro-case-context-bar__back { color: #5e72e4; text-decoration: none; font-weight: 600; }
        .legalpro-case-context-bar__back:hover { text-decoration: underline; }
        .legalpro-case-page-head h5 { font-weight: 700; color: #1e293b; }
        .cc-comments-panel .card-header { border-bottom: 1px solid rgba(0,0,0,.06); }
        .cc-comments-panel .card-body { padding: 1.25rem 1.5rem 1.5rem; }
        .cc-comment-list {
            display: flex; flex-direction: column; gap: 0.75rem;
            max-height: min(32rem, 60vh); overflow-y: auto; padding-right: 0.15rem;
        }
        .cc-comment-list::-webkit-scrollbar { width: 6px; }
        .cc-comment-list::-webkit-scrollbar-thumb { background: rgba(94, 114, 228, 0.3); border-radius: 999px; }
        .cc-comment-item-inner {
            background: #fff; border: 1px solid rgba(0,0,0,.06); border-radius: 0.75rem;
            padding: 1rem 1.15rem; border-left: 4px solid #8392ab; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .cc-comment-item--client .cc-comment-item-inner { border-left-color: #8898aa; }
        .cc-comment-item--lawyer .cc-comment-item-inner { border-left-color: #2dce89; }
        .cc-comment-item--admin .cc-comment-item-inner { border-left-color: #fb6340; }
        .cc-comment-item--yours .cc-comment-item-inner { background: #f8f9fe; border-color: rgba(94, 114, 228, 0.2); }
        .cc-comment-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.65rem; flex-wrap: wrap; }
        .cc-comment-head-main { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; min-width: 0; }
        .cc-comment-author { font-size: 0.875rem; font-weight: 700; color: #344767; line-height: 1.3; }
        .cc-comment-time { font-size: 0.75rem; color: #8392ab; white-space: nowrap; flex-shrink: 0; }
        .cc-comment-text { font-size: 0.875rem; line-height: 1.6; color: #525f7f; word-break: break-word; margin: 0; }
        .cc-comment-form textarea { border-radius: 0.65rem; resize: vertical; min-height: 6rem; }
        .cc-comment-head-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
        .cc-comment-delete-form { display: inline-flex; margin: 0; }
        body.legalpro-dark-mode .legalpro-case-context-bar { background: var(--lp-dark-surface, #1e293b); border-color: rgba(255,255,255,0.08); }
        body.legalpro-dark-mode .legalpro-case-page-head h5 { color: var(--lp-dark-text, #f1f5f9); }
CSS;
}

function legalpro_client_case_comments_script(): string
{
    return <<<'JS'
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.cc-comment-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-comment-id');
                var view = document.getElementById('cc-comment-view-' + id);
                var edit = document.getElementById('cc-comment-edit-' + id);
                if (view) view.classList.add('d-none');
                if (edit) edit.classList.remove('d-none');
            });
        });
        document.querySelectorAll('.cc-comment-edit-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('.cc-comment-edit-wrap');
                if (!wrap) return;
                var id = wrap.id.replace('cc-comment-edit-', '');
                wrap.classList.add('d-none');
                var view = document.getElementById('cc-comment-view-' + id);
                if (view) view.classList.remove('d-none');
            });
        });
    });
    </script>
JS;
}

function legalpro_client_case_render(string $pageKey, string $contentHtml, array $state, string $extraScripts = ''): void
{
    $nav = legalpro_client_case_nav();
    $page = $nav[$pageKey] ?? $nav['client-case-view'];
    $caseId = (int) ($state['case_id'] ?? 0);
    $caseNumber = htmlspecialchars((string) ($state['case_number'] ?? ''));
    $bodyClass = function_exists('legalpro_portal_theme_body_class') ? legalpro_portal_theme_body_class() : '';
    $messageHtml = legalpro_client_case_message_html($state);
    $contextBar = legalpro_client_case_context_bar_html($state);
    $subnavHtml = legalpro_client_case_subnav_html($caseId, $pageKey);

    $clientPageNavbar = legalpro_render_client_page_navbar(
        'Case ' . ($state['case_number'] ?? ''),
        'Case Details',
        'Search cases…',
        array_merge(
            legalpro_client_page_search_options('client-cases.php'),
            [
                'parent_label' => 'My Cases',
                'parent_url' => 'client-cases.php',
                'title_tag' => 'h6',
            ]
        )
    );

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro · ' . htmlspecialchars($page['heading']) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <link href="../assets/css/legalpro-documents-hub.css?v=3" rel="stylesheet" />
    <link href="../assets/css/legalpro-client-cases-hub.css?v=1" rel="stylesheet" />';

    ob_start();
    include dirname(__DIR__) . '/inc/client-portal-head.php';
    $html .= ob_get_clean();

    $html .= '
    <style>' . legalpro_client_case_shared_styles() . '</style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-portal-page legalpro-client-cases-hub' . $bodyClass . '">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>';

    ob_start();
    include dirname(__DIR__) . '/inc/client-menunav.php';
    $html .= ob_get_clean();

    $html .= '
    <main class="main-content position-relative border-radius-lg">
        ' . $clientPageNavbar . '
        <div class="container-fluid py-4">
            ' . $messageHtml . '
            ' . $contextBar . '
            <div class="legalpro-case-page-head mb-2">
                <h5 class="mb-1">' . htmlspecialchars($page['heading']) . '</h5>
                <p class="text-sm text-muted mb-0">' . htmlspecialchars($page['subtitle']) . '</p>
            </div>
            ' . $subnavHtml . '
            <div class="legalpro-case-page-content">' . $contentHtml . '</div>
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    ' . $extraScripts . '
</body>
</html>';

    require_once dirname(__DIR__) . '/inc/client-sidebar.php';
    $html = inject_client_sidebar($html);
    echo $html;
}
