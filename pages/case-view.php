<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../lib/case_events.php';

$message = '';
$messageType = '';

// Ensure appointments table has case_id column
try {
    $pdo->query("ALTER TABLE appointments ADD COLUMN case_id INT NULL AFTER client_id");
    $pdo->query("ALTER TABLE appointments ADD FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false &&
        stripos($e->getMessage(), 'duplicate key') === false) {
        // Log error but continue
    }
}

// Ensure tasks table exists - simple approach
try {
    // Check if table exists first
    $tableExists = false;
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'tasks'");
        $tableExists = $result->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist
    }

    if (!$tableExists) {
        // Create table with basic structure first
        $pdo->exec("
            CREATE TABLE `tasks` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `case_id` INT NOT NULL,
              `assigned_lawyer_id` INT NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `description` TEXT,
              `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
              `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
              `due_date` DATE NULL,
              `created_by` INT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `completed_at` TIMESTAMP NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add indexes
        $pdo->exec("CREATE INDEX `idx_tasks_case_id` ON `tasks` (`case_id`)");
        $pdo->exec("CREATE INDEX `idx_tasks_assigned_lawyer_id` ON `tasks` (`assigned_lawyer_id`)");
        $pdo->exec("CREATE INDEX `idx_tasks_status` ON `tasks` (`status`)");
        $pdo->exec("CREATE INDEX `idx_tasks_due_date` ON `tasks` (`due_date`)");

        error_log("Tasks table created successfully");
    }
} catch (PDOException $e) {
    // If table creation fails, continue anyway - the INSERT might still work if table exists
    error_log("Failed to create tasks table: " . $e->getMessage());
}

$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$caseId) {
    header('Location: tables.php?msg=' . urlencode('Invalid case ID') . '&type=danger');
    exit;
}

// Handle comment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $comment = trim($_POST['comment']);

    if (!empty($comment)) {
        try {
            // For admin case view, we'll assume admin role (you can enhance this with proper user authentication)
            $stmt = $pdo->prepare("INSERT INTO case_comments (case_id, user_id, comment, comment_type) VALUES (?, NULL, ?, 'admin')");
            $stmt->execute([$caseId, $comment]);
            $message = 'Comment added successfully!';
            $messageType = 'success';

            // Track comment addition
            CaseEvents::trackCommentAdded($caseId, [
                'comment' => $comment,
                'comment_type' => 'admin'
            ]);
        } catch (PDOException $e) {
            $message = 'Error adding comment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}


// Handle task deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task') {
    $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

    if ($taskId > 0) {
        try {
            // Get task details for logging before deletion
            $stmt = $pdo->prepare("SELECT title FROM tasks WHERE id = ? AND case_id = ?");
            $stmt->execute([$taskId, $caseId]);
            $taskData = $stmt->fetch();

            if ($taskData) {
                // Delete the task
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND case_id = ?");
                $stmt->execute([$taskId, $caseId]);

                $message = 'Task "' . htmlspecialchars($taskData['title']) . '" deleted successfully!';
                $messageType = 'success';

                // Track task deletion
                CaseEvents::trackTaskDeleted($caseId, $taskData['title']);
            } else {
                $message = 'Task not found or access denied.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting task: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } else {
        $message = 'Invalid task ID.';
        $messageType = 'danger';
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $label = trim(isset($_POST['file_label']) ? $_POST['file_label'] : '');

    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/admin_files/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = uniqid() . '_' . basename($file['name']);
        $filePath = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO documents (case_id, filename, filepath, label, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$caseId, $file['name'], 'uploads/admin_files/' . $fileName, $label ?: $file['name'], 'Admin']);
                $message = 'File uploaded successfully!';
                $messageType = 'success';

                // Log the event
                logDocumentUpload($pdo, $caseId, $label ?: $file['name'], 'Admin');
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

// Fetch case details
$case = null;
$assignedLawyers = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name,
            cl.email AS client_email,
            cl.phone AS client_phone
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        WHERE c.id = ?
    ");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();

    // Fetch assigned lawyers
    if ($case) {
        $stmt = $pdo->prepare("
            SELECT
                l.first_name,
                l.last_name,
                cl2.is_primary
            FROM case_lawyers cl2
            LEFT JOIN lawyers l ON l.id = cl2.lawyer_id
            WHERE cl2.case_id = ?
            ORDER BY cl2.is_primary DESC, cl2.assigned_at ASC
        ");
        $stmt->execute([$caseId]);
        $assignedLawyers = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $message = 'Error loading case: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

if (!$case) {
    header('Location: tables.php?msg=' . urlencode('Case not found') . '&type=danger');
    exit;
}

// Fetch services for this case
$services = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at");
    $stmt->execute([$caseId]);
    $services = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without services if there's an error
}

// Fetch stages for this case
$stages = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number");
    $stmt->execute([$caseId]);
    $stages = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without stages if there's an error
}

// Build services HTML
$servicesHtml = '';
if (empty($services)) {
    $servicesHtml = '<div class="text-center text-muted py-3"><i class="ni ni-single-copy-04 text-lg opacity-50 mb-2"></i><br>No services added</div>';
} else {
    foreach ($services as $service) {
        $servicesHtml .= '
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <div>
                <h6 class="mb-0 text-sm">' . htmlspecialchars($service['service_name']) . '</h6>
            </div>
            <div class="text-end">
                <span class="badge bg-gradient-primary">' . formatCurrency($service['price']) . '</span>
            </div>
        </div>';
    }

    // Add total
    $totalServices = array_sum(array_column($services, 'price'));
    $servicesHtml .= '
    <div class="d-flex justify-content-between align-items-center py-2 mt-2 border-top">
        <div>
            <strong class="text-sm">Total Services</strong>
        </div>
        <div class="text-end">
            <span class="badge bg-gradient-success">' . formatCurrency($totalServices) . '</span>
        </div>
    </div>';
}

function caseDetailFeedEmpty(string $iconName, string $message): string
{
    return '<div class="case-feed-empty"><div class="case-feed-empty__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center">'
        . legalpro_icon($iconName)
        . '</div><p>' . htmlspecialchars($message) . '</p></div>';
}

function caseDetailFeedItem(string $accent, string $iconName, string $title, string $subtitle, string $asideHtml): string
{
    $accentKey = preg_replace('/[^a-z]/', '', strtolower($accent));
    if ($accentKey === '') {
        $accentKey = 'primary';
    }

    return '<article class="case-feed-item">
        <div class="case-feed-item__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--' . htmlspecialchars($accentKey) . ' flex-shrink-0">'
        . legalpro_icon($iconName)
        . '</div>
        <div class="case-feed-item__body">
            <h6 class="case-feed-item__title">' . $title . '</h6>
            <p class="case-feed-item__subtitle">' . $subtitle . '</p>
        </div>
        <div class="case-feed-item__aside">' . $asideHtml . '</div>
    </article>';
}

function legalpro_case_detail_tab(string $href, string $icon, string $label, int $count, bool $active = false): string
{
    $activeClass = $active ? ' active' : '';

    return '<li class="nav-item" role="presentation">'
        . '<a class="nav-link' . $activeClass . '" data-bs-toggle="tab" href="' . htmlspecialchars($href) . '" role="tab">'
        . '<span class="case-detail-tabs__icon">' . legalpro_icon($icon) . '</span>'
        . '<span class="case-detail-tabs__label">' . htmlspecialchars($label) . '</span>'
        . '<span class="case-detail-tabs__count">' . (int) $count . '</span>'
        . '</a></li>';
}

function caseDetailFeedWrap($inner)
{
    return '<div class="case-feed-list">' . $inner . '</div>';
}

// Build stages HTML
$stagesHtml = '';
if (empty($stages)) {
    $stagesHtml = caseDetailFeedEmpty('layers', 'No case stages recorded yet.');
} else {
    foreach ($stages as $stage) {
        $stagesHtml .= '
        <div class="card mb-3 border">
            <div class="card-header bg-gradient-light">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Stage ' . (int)$stage['stage_number'] . ': ' . htmlspecialchars($stage['title']) . '</h6>
                    <span class="badge bg-gradient-primary">Stage ' . (int)$stage['stage_number'] . '</span>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6 class="text-sm font-weight-bold mb-2">Description</h6>
                        <p class="text-sm text-muted">' . nl2br(htmlspecialchars($stage['description'] ?: 'No description provided')) . '</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6 class="text-sm font-weight-bold mb-2">Result</h6>
                        <p class="text-sm text-muted">' . nl2br(htmlspecialchars($stage['result'] ?: 'No result recorded')) . '</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <h6 class="text-xs font-weight-bold mb-1">Start Date</h6>
                        <p class="text-sm">' . ($stage['start_date'] ? date('M j, Y', strtotime($stage['start_date'])) : 'Not set') . '</p>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-xs font-weight-bold mb-1">Expected End</h6>
                        <p class="text-sm">' . ($stage['expected_end_date'] ? date('M j, Y', strtotime($stage['expected_end_date'])) : 'Not set') . '</p>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-xs font-weight-bold mb-1">Actual End</h6>
                        <p class="text-sm">' . ($stage['actual_end_date'] ? date('M j, Y', strtotime($stage['actual_end_date'])) : 'Not set') . '</p>
                    </div>
                </div>
                ' . ($stage['file_path'] ? '<div class="mt-3 pt-3 border-top"><a href="' . htmlspecialchars('../' . $stage['file_path']) . '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="ni ni-single-copy-04 me-1"></i>View Attached Document</a></div>' : '') . '
            </div>
        </div>';
    }
}

// Fetch appointments for this case
$appointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.*,
            u.username AS lawyer_name
        FROM appointments a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.case_id = ?
        ORDER BY a.starts_at DESC
    ");
    $stmt->execute([$caseId]);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without appointments if there's an error
}

// Fetch invoices for this case
$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, p.total_paid
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount) as total_paid
            FROM payments
            WHERE invoice_id IS NOT NULL
            GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE i.case_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$caseId]);
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without invoices if there's an error
}

// Fetch payments/receipts for this case
$payments = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            i.invoice_number,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name
        FROM payments p
        LEFT JOIN invoices i ON i.id = p.invoice_id
        LEFT JOIN clients cl ON cl.id = p.client_id
        WHERE p.case_id = ?
        ORDER BY p.payment_date DESC, p.created_at DESC
    ");
    $stmt->execute([$caseId]);
    $payments = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without payments if there's an error
}

// Fetch documents for this case
$documents = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.username AS uploaded_by_name
        FROM documents d
        LEFT JOIN users u ON u.username = d.uploaded_by
        WHERE d.case_id = ?
        ORDER BY d.uploaded_at DESC
    ");
    $stmt->execute([$caseId]);
    $documents = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without documents if there's an error
}

// Fetch comments for this case
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
    // Continue without comments if there's an error
}

// Fetch tasks for this case
$tasks = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*,
               l.first_name as lawyer_first_name,
               l.last_name as lawyer_last_name,
               u.username as created_by_username
        FROM tasks t
        LEFT JOIN lawyers l ON l.id = t.assigned_lawyer_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.case_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$caseId]);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    // Continue without tasks if there's an error
}

// Fetch case events for this case
$caseEvents = CaseEvents::getCaseEvents($caseId);

// Helper function to format currency

// Build case summary data
$caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
$clientFirstName = isset($case['client_first_name']) ? $case['client_first_name'] : '';
$clientLastName = isset($case['client_last_name']) ? $case['client_last_name'] : '';
$clientName = trim($clientFirstName . ' ' . $clientLastName);
if (empty($clientName)) {
    $clientName = 'Unassigned';
}
// Build lawyer names string
$lawyerNames = [];
foreach ($assignedLawyers as $lawyer) {
    $lawyerNames[] = $lawyer['first_name'] . ' ' . $lawyer['last_name'] . ($lawyer['is_primary'] ? ' (Primary)' : '');
}
$lawyerName = !empty($lawyerNames) ? implode(', ', $lawyerNames) : 'No lawyers assigned';

$caseStatus = isset($case['status']) ? (string) $case['status'] : 'open';
$caseStatusBadgeHtml = legalpro_case_status_badge($caseStatus);

// Build HTML sections
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show mx-3 mt-3" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

// Appointments section
$appointmentsHtml = '';
if (empty($appointments)) {
    $appointmentsHtml = caseDetailFeedEmpty('calendar', 'No appointments scheduled for this case.');
} else {
    $items = '';
    foreach ($appointments as $appointment) {
        $startDate = date('M j, Y g:i A', strtotime($appointment['starts_at']));
        $lawyer = isset($appointment['lawyer_name']) ? $appointment['lawyer_name'] : 'Unassigned';
        $items .= caseDetailFeedItem(
            'info',
            'calendar',
            'Appointment',
            htmlspecialchars($startDate) . ' · with ' . htmlspecialchars($lawyer),
            '<span class="case-status-pill case-status-pill--scheduled">Scheduled</span>'
        );
    }
    $appointmentsHtml = caseDetailFeedWrap($items);
}

// Invoices section
$invoicesHtml = '';
if (empty($invoices)) {
    $invoicesHtml = caseDetailFeedEmpty('file-text', 'No invoices have been created for this case.');
} else {
    $items = '';
    foreach ($invoices as $invoice) {
        $invoiceNumber = !empty($invoice['invoice_number']) ? $invoice['invoice_number'] : 'INV-' . str_pad($invoice['id'], 4, '0', STR_PAD_LEFT);
        $amount = formatCurrency($invoice['amount']);
        $paid = (float)(isset($invoice['total_paid']) ? $invoice['total_paid'] : 0);
        $status = $paid >= (float)$invoice['amount'] ? 'Paid' : 'Pending';
        $pillClass = $status === 'Paid' ? 'case-status-pill--paid' : 'case-status-pill--pending';
        $items .= caseDetailFeedItem(
            'primary',
            'file-text',
            htmlspecialchars($invoiceNumber),
            htmlspecialchars($amount),
            '<span class="case-status-pill ' . $pillClass . '">' . htmlspecialchars($status) . '</span>
                <a href="invoice-download.php?id=' . (int)$invoice['id'] . '" class="btn btn-sm bg-gradient-primary mb-0" target="_blank">
                    <i class="ni ni-single-copy-04 me-1"></i>View
                </a>'
        );
    }
    $invoicesHtml = caseDetailFeedWrap($items);
}

// Payments/Receipts section
$paymentsHtml = '';
if (empty($payments)) {
    $paymentsHtml = caseDetailFeedEmpty('banknote', 'No payments recorded for this case.');
} else {
    $items = '';
    foreach ($payments as $payment) {
        $amount = formatCurrency($payment['amount']);
        $date = $payment['payment_date'] ? date('M j, Y', strtotime($payment['payment_date'])) : 'N/A';
        $method = ucfirst(isset($payment['method']) ? $payment['method'] : 'cash');
        $items .= caseDetailFeedItem(
            'success',
            'banknote',
            htmlspecialchars($amount),
            htmlspecialchars($method) . ' · ' . htmlspecialchars($date),
            '<a href="payment-receipt.php?id=' . (int)$payment['id'] . '" class="btn btn-sm bg-gradient-success mb-0" target="_blank">
                <i class="ni ni-single-copy-04 me-1"></i>Receipt
            </a>'
        );
    }
    $paymentsHtml = caseDetailFeedWrap($items);
}

// Documents section
$documentsHtml = '';
if (empty($documents)) {
    $documentsHtml = caseDetailFeedEmpty('folder-open', 'No documents uploaded for this case.');
} else {
    $items = '';
    foreach ($documents as $document) {
        $displayName = !empty($document['label']) ? $document['label'] : $document['filename'];
        $uploadedDate = date('M j, Y', strtotime($document['uploaded_at']));
        $uploadedBy = !empty($document['uploaded_by_name']) ? $document['uploaded_by_name'] : (!empty($document['uploaded_by']) ? $document['uploaded_by'] : 'System');
        $fileUrl = '../' . ltrim($document['filepath'], '/');

        $fileExtension = strtolower(pathinfo($document['filename'], PATHINFO_EXTENSION));
        $iconName = 'file-text';
        if (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $iconName = 'image';
        }

        $items .= caseDetailFeedItem(
            'info',
            $iconName,
            htmlspecialchars($displayName),
            'Uploaded ' . htmlspecialchars($uploadedDate) . ' · by ' . htmlspecialchars($uploadedBy),
            '<a href="' . htmlspecialchars($fileUrl) . '" class="btn btn-sm btn-outline-primary mb-0" target="_blank">
                <i class="ni ni-zoom-split-in me-1"></i>View
            </a>
            <a href="' . htmlspecialchars($fileUrl) . '" class="btn btn-sm bg-gradient-success mb-0" download>
                <i class="ni ni-cloud-download-95 me-1"></i>Download
            </a>'
        );
    }
    $documentsHtml = caseDetailFeedWrap($items);
}

$caseDetailTabsNav = '<ul class="nav case-detail-tabs" role="tablist">'
    . legalpro_case_detail_tab('#appointments', 'calendar', 'Appointments', count($appointments), true)
    . legalpro_case_detail_tab('#invoices', 'file-text', 'Invoices', count($invoices))
    . legalpro_case_detail_tab('#payments', 'banknote', 'Payments', count($payments))
    . legalpro_case_detail_tab('#documents', 'folder-open', 'Documents', count($documents))
    . legalpro_case_detail_tab('#stages', 'layers', 'Summary', count($stages))
    . legalpro_case_detail_tab('#tasks', 'list-checks', 'Tasks', count($tasks))
    . legalpro_case_detail_tab('#comments', 'message-circle', 'Comments', count($comments))
    . legalpro_case_detail_tab('#events', 'activity', 'Activity', count($caseEvents))
    . '</ul>';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro Case Manager - Case View</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="../assets/css/case-detail-tabs.css?v=2" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-case-view-page">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
    </aside>
    <main class="main-content position-relative border-radius-lg ">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="tables.php">Cases</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Case View</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">{CASE_NUMBER} · {CASE_TITLE}</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Case Summary -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Case Summary</h6>
                                <div class="d-flex gap-2">
                                    <a href="case-edit.php?id={CASE_ID}" class="btn btn-sm btn-dark">
                                        <i class="ni ni-settings me-1"></i>Edit Case
                                    </a>
                                    <a href="documents.php?case_id={CASE_ID}" class="btn btn-sm btn-outline-primary">
                                        <i class="ni ni-cloud-upload-96 me-1"></i>Add Document
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-8">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Case Number</p>
                                            <p class="text-sm font-weight-bold mb-0">{CASE_NUMBER}</p>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Status</p>
                                            {STATUS_BADGE_HTML}
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Client</p>
                                            <p class="text-sm mb-0">{CLIENT_NAME}</p>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Assigned Lawyer</p>
                                            <p class="text-sm mb-0">{LAWYER_NAME}</p>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Priority</p>
                                            <p class="text-sm mb-0">{PRIORITY}</p>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Category</p>
                                            <p class="text-sm mb-0">{CATEGORY}</p>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Start Date</p>
                                            <p class="text-sm mb-0">{START_DATE}</p>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-1">Expected Completion</p>
                                            <p class="text-sm mb-0">{EXPECTED_COMPLETION}</p>
                                        </div>
                                    </div>
                                    <hr class="horizontal dark my-4">
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <p class="text-xs text-uppercase text-muted mb-2">Description</p>
                                            <p class="text-sm mb-0">{DESCRIPTION}</p>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-12">
                                            <p class="text-xs text-uppercase text-muted mb-2">Services & Pricing</p>
                                            <div class="services-list">
                                                {SERVICES_HTML}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="card h-100">
                                        <div class="card-header pb-0">
                                            <h6 class="mb-0">Financial Summary</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row text-center">
                                                <div class="col-12 mb-3">
                                                    <p class="text-sm mb-1">Total Fees</p>
                                                    <h4 class="mb-0">{TOTAL_FEES}</h4>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <p class="text-sm mb-1">Invoiced</p>
                                                    <h6 class="mb-0 text-primary">{TOTAL_INVOICED}</h6>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <p class="text-sm mb-1">Paid</p>
                                                    <h6 class="mb-0 text-success">{TOTAL_PAID}</h6>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case Details Tabs -->
            <div class="row">
                <div class="col-12">
                    <div class="card case-detail-hub border-0 shadow-sm">
                        <div class="card-header case-detail-hub__header border-0">
                            <div class="case-detail-tabs-wrap">
                                {CASE_DETAIL_TABS_NAV}
                            </div>
                        </div>
                        <div class="card-body case-detail-hub__body">
                            <div class="tab-content case-detail-panels">
                                <div class="tab-pane active" id="appointments" role="tabpanel">
                                    {APPOINTMENTS_HTML}
                                </div>
                                <div class="tab-pane" id="invoices" role="tabpanel">
                                    {INVOICES_HTML}
                                </div>
                                <div class="tab-pane" id="payments" role="tabpanel">
                                    {PAYMENTS_HTML}
                                </div>
                                <div class="tab-pane" id="documents" role="tabpanel">
                                    {DOCUMENTS_HTML}
                                </div>
                                <div class="tab-pane" id="stages" role="tabpanel">
                                    {STAGES_HTML}
                                </div>
                                <div class="tab-pane" id="comments" role="tabpanel">
                                    {COMMENTS_HTML}

                                    <div class="mt-4">
                                        <div class="card case-detail-form-card border-0">
                                            <div class="card-header border-0">
                                                <h6 class="mb-0">Add Comment</h6>
                                            </div>
                                            <div class="card-body pt-0">
                                                <form method="POST" action="">
                                                    <textarea class="form-control" name="comment" rows="3" placeholder="Write a comment for this case..." required></textarea>
                                                    <button type="submit" class="btn btn-dark btn-sm mt-3 mb-0">Post Comment</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <div class="card case-detail-form-card border-0">
                                            <div class="card-header border-0">
                                                <h6 class="mb-0">Upload Document</h6>
                                            </div>
                                            <div class="card-body pt-0">
                                                <form method="POST" action="" enctype="multipart/form-data">
                                                    <div class="row g-2">
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" name="file_label" placeholder="Document description (optional)">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <input type="file" class="form-control" name="file" required>
                                                        </div>
                                                    </div>
                                                    <button type="submit" class="btn btn-dark btn-sm mt-3 mb-0">Upload File</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane" id="tasks" role="tabpanel">
                                    {TASKS_HTML}
                                </div>
                                <div class="tab-pane" id="events" role="tabpanel">
                                    {EVENTS_HTML}
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
</body>
</html>
HTML;

// Build comments HTML (chat-like interface)
$commentsHtml = '';
if (!empty($comments)) {
    $commentsHtml .= '<div class="chat-messages" style="max-height: 400px; overflow-y: auto;">';
    foreach ($comments as $comment) {
        $bgColor = 'bg-light';
        $textColor = 'text-dark';
        $alignment = 'justify-content-start';
        $marginClass = 'me-3';

        $userTypeBadge = legalpro_comment_role_badge((string) ($comment['comment_type'] ?? ''));

        $commentsHtml .= '<div class="d-flex ' . $alignment . ' mb-3">
            <div class="chat-message ' . $bgColor . ' ' . $textColor . ' rounded-lg p-3 ' . $marginClass . '" style="max-width: 70%;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center">
                        <strong class="me-2">' . htmlspecialchars($comment['commenter_name']) . '</strong>
                        ' . $userTypeBadge . '
                    </div>
                    <small class="text-muted">' . date('M d, H:i', strtotime($comment['created_at'])) . '</small>
                </div>
                <p class="mb-0" style="word-wrap: break-word;">' . nl2br(htmlspecialchars($comment['comment'])) . '</p>
            </div>
        </div>';
    }
    $commentsHtml .= '</div>';
} else {
    $commentsHtml = caseDetailFeedEmpty('message-circle', 'No comments yet. Start the conversation below.');
}

// Build tasks HTML
$tasksHtml = '';
if (!empty($tasks)) {
    $tasksHtml .= '<div class="case-detail-table-wrap"><div class="table-responsive">
        <table class="table align-items-center mb-0">
            <thead>
                <tr>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Task</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Assigned To</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Priority</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Due Date</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($tasks as $task) {
        $statusBadgeHtml = legalpro_task_status_badge((string) ($task['status'] ?? ''));
        $priorityBadgeHtml = legalpro_task_priority_badge((string) ($task['priority'] ?? ''));

        $dueDate = $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'No due date';
        $lawyerName = htmlspecialchars($task['lawyer_first_name'] . ' ' . $task['lawyer_last_name']);

        $tasksHtml .= '<tr>
            <td>
                <div>
                    <h6 class="mb-0 text-sm">' . htmlspecialchars($task['title']) . '</h6>';
        if (!empty($task['description'])) {
            $tasksHtml .= '<small class="text-muted">' . htmlspecialchars(substr($task['description'], 0, 50)) . (strlen($task['description']) > 50 ? '...' : '') . '</small>';
        }
        $tasksHtml .= '</div>
            </td>
            <td class="text-sm">' . $lawyerName . '</td>
            <td>' . $statusBadgeHtml . '</td>
            <td>' . $priorityBadgeHtml . '</td>
            <td class="text-sm">' . $dueDate . '</td>
            <td>
                <form method="POST" action="" style="display: inline;" onsubmit="return confirm(\'Are you sure you want to delete this task? This will remove it from the assigned lawyer\'s task list.\')">
                    <input type="hidden" name="action" value="delete_task">
                    <input type="hidden" name="task_id" value="' . $task['id'] . '">
                    <button type="submit" class="btn btn-sm btn-danger mb-0">Delete</button>
                </form>
            </td>
        </tr>';
    }

    $tasksHtml .= '</tbody></table></div></div>';
} else {
    $tasksHtml = caseDetailFeedEmpty('list-checks', 'No tasks assigned to this case yet.');
}


// Build events HTML using the new CaseEvents class
    $eventsHtml = CaseEvents::renderEventsTimeline($caseId);

// Calculate financial summary
$totalFees = formatCurrency(isset($case['estimated_fees']) ? $case['estimated_fees'] : 0);
$totalInvoiced = formatCurrency(array_sum(array_column($invoices, 'amount')));
$totalPaid = formatCurrency(array_sum(array_column($payments, 'amount')));

// Replace placeholders
$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{CASE_ID}' => $caseId,
    '{CASE_NUMBER}' => $caseNumber,
    '{CASE_TITLE}' => htmlspecialchars($case['title']),
    '{CLIENT_NAME}' => htmlspecialchars($clientName),
    '{LAWYER_NAME}' => htmlspecialchars($lawyerName),
    '{STATUS_BADGE_HTML}' => $caseStatusBadgeHtml,
    '{PRIORITY}' => htmlspecialchars(isset($case['priority']) ? $case['priority'] : 'Normal'),
    '{CATEGORY}' => htmlspecialchars(isset($case['category']) ? $case['category'] : 'Civil'),
    '{START_DATE}' => $case['start_date'] ? date('M j, Y', strtotime($case['start_date'])) : 'Not set',
    '{EXPECTED_COMPLETION}' => $case['expected_completion'] ? date('M j, Y', strtotime($case['expected_completion'])) : 'Not set',
    '{DESCRIPTION}' => htmlspecialchars(!empty($case['description']) ? $case['description'] : 'No description provided'),
    '{SERVICES_HTML}' => $servicesHtml,
    '{TOTAL_FEES}' => $totalFees,
    '{TOTAL_INVOICED}' => $totalInvoiced,
    '{TOTAL_PAID}' => $totalPaid,
    '{CASE_DETAIL_TABS_NAV}' => $caseDetailTabsNav,
    '{APPOINTMENTS_COUNT}' => count($appointments),
    '{INVOICES_COUNT}' => count($invoices),
    '{PAYMENTS_COUNT}' => count($payments),
    '{DOCUMENTS_COUNT}' => count($documents),
    '{APPOINTMENTS_HTML}' => $appointmentsHtml,
    '{INVOICES_HTML}' => $invoicesHtml,
    '{PAYMENTS_HTML}' => $paymentsHtml,
    '{DOCUMENTS_HTML}' => $documentsHtml,
    '{STAGES_HTML}' => $stagesHtml,
    '{STAGES_COUNT}' => count($stages),
    '{COMMENTS_HTML}' => $commentsHtml,
    '{COMMENTS_COUNT}' => count($comments),
    '{TASKS_HTML}' => $tasksHtml,
    '{TASKS_COUNT}' => count($tasks),
    '{EVENTS_HTML}' => $eventsHtml,
    '{EVENTS_COUNT}' => count($caseEvents),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo $html;
?>
