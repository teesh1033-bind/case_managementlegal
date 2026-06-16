<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/task_helpers.php';
require_once __DIR__ . '/../lib/case_quotations.php';

ensure_task_support_schema($pdo);
ensure_case_quotation_schema($pdo);

$message = '';
$messageType = '';
$activeTab = '';

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

// Handle case summary (stage) save / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {
    $formType = (string) $_POST['form_type'];

    if ($formType === 'save_stage') {
        $stageId = isset($_POST['stage_id']) ? (int) $_POST['stage_id'] : 0;
        $stageNumber = isset($_POST['stage_number']) ? (int) $_POST['stage_number'] : 0;
        $title = trim((string) ($_POST['stage_title'] ?? ''));
        $description = trim((string) ($_POST['stage_description'] ?? ''));
        $result = trim((string) ($_POST['stage_result'] ?? ''));
        $startDate = trim((string) ($_POST['stage_start_date'] ?? ''));
        $expectedEndDate = trim((string) ($_POST['stage_expected_end_date'] ?? ''));
        $actualEndDate = trim((string) ($_POST['stage_actual_end_date'] ?? ''));
        $activeTab = 'stages';

        $hasInvalidStageExpectedDate = $startDate !== '' && $expectedEndDate !== ''
            && strtotime($expectedEndDate) <= strtotime($startDate);
        $hasInvalidStageActualDate = $startDate !== '' && $actualEndDate !== ''
            && strtotime($actualEndDate) < strtotime($startDate);

        if ($hasInvalidStageExpectedDate) {
            $message = 'Expected end date must be later than the start date.';
            $messageType = 'danger';
        } elseif ($hasInvalidStageActualDate) {
            $message = 'Actual end date cannot be earlier than the start date.';
            $messageType = 'danger';
        } elseif ($title === '' || $stageNumber <= 0) {
            $message = 'Summary title and entry number are required.';
            $messageType = 'danger';
        } else {
            $filePath = null;
            if (isset($_FILES['stage_file']) && $_FILES['stage_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/case_stages/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $_FILES['stage_file']['name']);
                if (move_uploaded_file($_FILES['stage_file']['tmp_name'], $uploadDir . $fileName)) {
                    $filePath = 'uploads/case_stages/' . $fileName;
                }
            }

            try {
                $dupStmt = $pdo->prepare('SELECT id FROM case_stages WHERE case_id = ? AND stage_number = ? AND id != ? LIMIT 1');
                $dupStmt->execute([$caseId, $stageNumber, $stageId]);
                if ($dupStmt->fetch()) {
                    $message = 'Summary entry #' . $stageNumber . ' already exists. Choose a different number.';
                    $messageType = 'danger';
                } elseif ($stageId > 0) {
                    if ($filePath) {
                        $stmt = $pdo->prepare('
                            UPDATE case_stages SET
                                stage_number = ?, title = ?, description = ?, result = ?, file_path = ?,
                                start_date = ?, expected_end_date = ?, actual_end_date = ?
                            WHERE id = ? AND case_id = ?
                        ');
                        $stmt->execute([
                            $stageNumber, $title, $description, $result, $filePath,
                            $startDate !== '' ? $startDate : null,
                            $expectedEndDate !== '' ? $expectedEndDate : null,
                            $actualEndDate !== '' ? $actualEndDate : null,
                            $stageId, $caseId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare('
                            UPDATE case_stages SET
                                stage_number = ?, title = ?, description = ?, result = ?,
                                start_date = ?, expected_end_date = ?, actual_end_date = ?
                            WHERE id = ? AND case_id = ?
                        ');
                        $stmt->execute([
                            $stageNumber, $title, $description, $result,
                            $startDate !== '' ? $startDate : null,
                            $expectedEndDate !== '' ? $expectedEndDate : null,
                            $actualEndDate !== '' ? $actualEndDate : null,
                            $stageId, $caseId,
                        ]);
                    }
                    $message = 'Summary entry updated successfully.';
                    $messageType = 'success';
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO case_stages (case_id, stage_number, title, description, result, file_path, start_date, expected_end_date, actual_end_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $caseId, $stageNumber, $title, $description, $result, $filePath,
                        $startDate !== '' ? $startDate : null,
                        $expectedEndDate !== '' ? $expectedEndDate : null,
                        $actualEndDate !== '' ? $actualEndDate : null,
                    ]);
                    $message = 'Summary entry added successfully.';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Error saving summary entry: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'delete_stage') {
        $stageId = isset($_POST['stage_id']) ? (int) $_POST['stage_id'] : 0;
        $activeTab = 'stages';

        if ($stageId > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM case_stages WHERE id = ? AND case_id = ?');
                $stmt->execute([$stageId, $caseId]);
                $message = 'Summary entry deleted successfully.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Error deleting summary entry: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid summary entry.';
            $messageType = 'danger';
        }
    } elseif ($formType === 'save_quotation') {
        $activeTab = 'quotations';
        $quotationNumber = trim((string) ($_POST['quotation_number'] ?? ''));
        $title = trim((string) ($_POST['quotation_title'] ?? ''));
        $status = trim((string) ($_POST['quotation_status'] ?? 'draft'));
        $validUntil = trim((string) ($_POST['quotation_valid_until'] ?? ''));
        $notes = trim((string) ($_POST['quotation_notes'] ?? ''));
        $taxRate = isset($_POST['quotation_tax_rate']) ? (float) $_POST['quotation_tax_rate'] : 0;
        $statusOptions = quotation_status_options();

        if ($quotationNumber === '') {
            $message = 'Quotation number is required.';
            $messageType = 'danger';
        } elseif (!isset($statusOptions[$status])) {
            $message = 'Invalid quotation status selected.';
            $messageType = 'danger';
        } else {
            try {
                $items = parse_quotation_line_items_from_post($_POST);
                $quotationId = save_case_quotation($pdo, $caseId, [
                    'quotation_number' => $quotationNumber,
                    'title' => $title,
                    'status' => $status,
                    'valid_until' => $validUntil,
                    'notes' => $notes,
                    'tax_rate' => $taxRate,
                    'created_by' => 'Admin',
                ], $items);
                notify_client_about_quotation($pdo, $quotationId);
                $message = 'Quotation saved successfully.';
                $messageType = 'success';
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
                $messageType = 'danger';
            } catch (PDOException $e) {
                $message = 'Error saving quotation: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'delete_quotation') {
        $activeTab = 'quotations';
        $quotationId = isset($_POST['quotation_id']) ? (int) $_POST['quotation_id'] : 0;

        if ($quotationId > 0) {
            try {
                if (delete_case_quotation($pdo, $caseId, $quotationId)) {
                    $message = 'Quotation deleted successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Quotation not found.';
                    $messageType = 'danger';
                }
            } catch (PDOException $e) {
                $message = 'Error deleting quotation: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid quotation.';
            $messageType = 'danger';
        }
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

$nextStageNumber = 1;
foreach ($stages as $stageRow) {
    $nextStageNumber = max($nextStageNumber, (int) $stageRow['stage_number'] + 1);
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

function caseDetailActionButton(string $url, string $label, string $gradient = 'dark'): string
{
    return '<a href="' . htmlspecialchars($url) . '" class="btn btn-sm bg-gradient-' . htmlspecialchars($gradient) . ' mb-0">'
        . htmlspecialchars($label) . '</a>';
}

// Build stages HTML
$stagesHtml = '';
if (empty($stages)) {
    $stagesHtml = '<div class="case-summary-empty text-center py-4 mb-3" id="case-summary-empty" role="button" tabindex="0" aria-label="Add a summary entry">'
        . '<div class="case-feed-empty__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center mb-2">'
        . legalpro_icon('layers')
        . '</div>'
        . '<p class="mb-1">No summary entries yet.</p>'
        . '<p class="text-sm text-muted mb-0">Click here or use <strong>Add Summary</strong> to record notes, outcomes, and milestones for this case.</p>'
        . '</div>';
} else {
    foreach ($stages as $stage) {
        $stagePayload = htmlspecialchars(json_encode([
            'id' => (int) $stage['id'],
            'stage_number' => (int) $stage['stage_number'],
            'title' => $stage['title'],
            'description' => $stage['description'] ?? '',
            'result' => $stage['result'] ?? '',
            'start_date' => $stage['start_date'] ?? '',
            'expected_end_date' => $stage['expected_end_date'] ?? '',
            'actual_end_date' => $stage['actual_end_date'] ?? '',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');

        $stagesHtml .= '
        <div class="card mb-3 border case-summary-entry">
            <div class="card-header bg-gradient-light">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0">#' . (int) $stage['stage_number'] . ' · ' . htmlspecialchars($stage['title']) . '</h6>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-dark mb-0 case-stage-edit-btn" data-stage="' . $stagePayload . '">Edit</button>
                        <form method="POST" action="" class="d-inline" onsubmit="return confirm(\'Delete this summary entry?\');">
                            <input type="hidden" name="form_type" value="delete_stage">
                            <input type="hidden" name="stage_id" value="' . (int) $stage['id'] . '">
                            <button type="submit" class="btn btn-sm btn-danger mb-0">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6 class="text-sm font-weight-bold mb-2">Notes</h6>
                        <p class="text-sm text-muted mb-0">' . nl2br(htmlspecialchars($stage['description'] ?: 'No notes added')) . '</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6 class="text-sm font-weight-bold mb-2">Outcome</h6>
                        <p class="text-sm text-muted mb-0">' . nl2br(htmlspecialchars($stage['result'] ?: 'No outcome recorded')) . '</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <h6 class="text-xs font-weight-bold mb-1">Start Date</h6>
                        <p class="text-sm mb-0">' . ($stage['start_date'] ? date('M j, Y', strtotime($stage['start_date'])) : 'Not set') . '</p>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-xs font-weight-bold mb-1">Expected End</h6>
                        <p class="text-sm mb-0">' . ($stage['expected_end_date'] ? date('M j, Y', strtotime($stage['expected_end_date'])) : 'Not set') . '</p>
                    </div>
                    <div class="col-md-4 mb-2">
                        <h6 class="text-xs font-weight-bold mb-1">Actual End</h6>
                        <p class="text-sm mb-0">' . ($stage['actual_end_date'] ? date('M j, Y', strtotime($stage['actual_end_date'])) : 'Not set') . '</p>
                    </div>
                </div>'
                . ($stage['file_path']
                    ? '<div class="mt-3 pt-3 border-top"><a href="' . htmlspecialchars('../' . $stage['file_path']) . '" target="_blank" class="btn btn-sm btn-outline-primary mb-0"><i class="ni ni-single-copy-04 me-1"></i>View attachment</a></div>'
                    : '')
                . '</div>
        </div>';
    }
}

$stagesFormHtml = '
<div class="card case-detail-form-card border-0 mt-4" id="case-summary-form-card" hidden>
    <div class="card-header border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h6 class="mb-0" id="case-summary-form-title">Add Summary Entry</h6>
            <p class="text-sm text-muted mb-0">Record notes, outcomes, and key milestones for this case.</p>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-0" id="case-summary-cancel-edit">Cancel</button>
    </div>
    <div class="card-body pt-0">
        <form method="POST" action="" enctype="multipart/form-data" id="case-summary-form">
            <input type="hidden" name="form_type" value="save_stage">
            <input type="hidden" name="stage_id" id="case_summary_stage_id" value="">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label text-sm">Entry #</label>
                    <input type="number" class="form-control" name="stage_number" id="case_summary_stage_number" min="1" value="' . (int) $nextStageNumber . '" required>
                </div>
                <div class="col-md-9">
                    <label class="form-label text-sm">Title</label>
                    <input type="text" class="form-control" name="stage_title" id="case_summary_stage_title" placeholder="e.g. Initial hearing, Client meeting, Filing completed" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm">Notes</label>
                    <textarea class="form-control" name="stage_description" id="case_summary_stage_description" rows="4" placeholder="What happened, key details, follow-ups..."></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm">Outcome / Result</label>
                    <textarea class="form-control" name="stage_result" id="case_summary_stage_result" rows="4" placeholder="Decision, result, or next steps..."></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label text-sm">Start date</label>
                    <input type="date" class="form-control" name="stage_start_date" id="case_summary_stage_start_date">
                </div>
                <div class="col-md-4">
                    <label class="form-label text-sm">Expected end</label>
                    <input type="date" class="form-control" name="stage_expected_end_date" id="case_summary_stage_expected_end_date">
                </div>
                <div class="col-md-4">
                    <label class="form-label text-sm">Actual end</label>
                    <input type="date" class="form-control" name="stage_actual_end_date" id="case_summary_stage_actual_end_date">
                </div>
                <div class="col-12">
                    <label class="form-label text-sm">Attachment (optional)</label>
                    <input type="file" class="form-control" name="stage_file" id="case_summary_stage_file">
                </div>
            </div>
            <button type="submit" class="btn btn-dark btn-sm mt-3 mb-0" id="case-summary-submit-btn">Save Summary Entry</button>
        </form>
    </div>
</div>';

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

// Fetch quotations for this case
$quotations = fetch_case_quotations($pdo, $caseId);
$nextQuotationNumber = get_next_quotation_number($pdo);
$quotationStatusOptions = quotation_status_options();

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

$caseClientId = (int) ($case['client_id'] ?? 0);
$invoiceCreateUrl = 'invoices.php?case_id=' . (int) $caseId . ($caseClientId > 0 ? '&client_id=' . $caseClientId : '');
$paymentCreateUrl = 'payments.php?case_id=' . (int) $caseId;
$createInvoiceBtn = caseDetailActionButton($invoiceCreateUrl, 'Create Invoice');
$recordPaymentBtn = caseDetailActionButton($paymentCreateUrl, 'Record Payment', 'success');

// Invoices section
if (empty($invoices)) {
    $invoicesHtml = caseDetailFeedEmpty('file-text', 'No invoices have been created for this case.');
} else {
    $invoiceItems = '';
    foreach ($invoices as $invoice) {
        $invoiceNumber = !empty($invoice['invoice_number']) ? $invoice['invoice_number'] : 'INV-' . str_pad($invoice['id'], 4, '0', STR_PAD_LEFT);
        $amount = formatCurrency($invoice['amount']);
        $paid = (float)(isset($invoice['total_paid']) ? $invoice['total_paid'] : 0);
        $status = $paid >= (float)$invoice['amount'] ? 'Paid' : 'Pending';
        $pillClass = $status === 'Paid' ? 'case-status-pill--paid' : 'case-status-pill--pending';
        $invoiceItems .= caseDetailFeedItem(
            'primary',
            'file-text',
            htmlspecialchars($invoiceNumber),
            htmlspecialchars($amount),
            '<span class="case-status-pill ' . $pillClass . '">' . htmlspecialchars($status) . '</span>
                <a href="invoice-download.php?id=' . (int)$invoice['id'] . '" class="btn btn-sm bg-gradient-primary mb-0" target="_blank">
                    <i class="ni ni-single-copy-04 me-1"></i>PDF
                </a>'
        );
    }
    $invoicesHtml = caseDetailFeedWrap($invoiceItems);
}

// Payments/Receipts section
if (empty($payments)) {
    $paymentsHtml = caseDetailFeedEmpty('banknote', 'No payments recorded for this case.');
} else {
    $paymentItems = '';
    foreach ($payments as $payment) {
        $amount = formatCurrency($payment['amount']);
        $date = $payment['payment_date'] ? date('M j, Y', strtotime($payment['payment_date'])) : 'N/A';
        $method = ucfirst(isset($payment['method']) ? $payment['method'] : 'cash');
        $paymentItems .= caseDetailFeedItem(
            'success',
            'banknote',
            htmlspecialchars($amount),
            htmlspecialchars($method) . ' · ' . htmlspecialchars($date),
            '<a href="payment-receipt.php?id=' . (int)$payment['id'] . '" class="btn btn-sm bg-gradient-success mb-0" target="_blank">
                <i class="ni ni-single-copy-04 me-1"></i>PDF
            </a>'
        );
    }
    $paymentsHtml = caseDetailFeedWrap($paymentItems);
}

// Quotations section
$quotationsHtml = '';
if (empty($quotations)) {
    $quotationsHtml = caseDetailFeedEmpty('clipboard-list', 'No quotations yet. Click Add Quotation to create one.');
} else {
    $quotationItems = '';
    foreach ($quotations as $quotation) {
        $quoteNumber = !empty($quotation['quotation_number'])
            ? $quotation['quotation_number']
            : 'QUO-' . str_pad((string) $quotation['id'], 4, '0', STR_PAD_LEFT);
        $quoteTitle = trim((string) ($quotation['title'] ?? ''));
        $quoteLabel = $quoteTitle !== '' ? $quoteTitle : 'Quotation';
        $quoteTotal = formatCurrency((float) ($quotation['total_amount'] ?? 0));
        $quoteStatus = quotation_status_label((string) ($quotation['status'] ?? 'draft'));
        $quotePillClass = quotation_status_pill_class((string) ($quotation['status'] ?? 'draft'));
        $validUntilText = !empty($quotation['valid_until'])
            ? 'Valid until ' . date('M j, Y', strtotime($quotation['valid_until']))
            : 'No expiry date';
        $createdText = !empty($quotation['created_at'])
            ? 'Created ' . date('M j, Y', strtotime($quotation['created_at']))
            : '';

        $lineItems = fetch_quotation_items($pdo, (int) $quotation['id']);
        $linesPreview = '';
        if (!empty($lineItems)) {
            $linesPreview = '<div class="case-quotation-lines mt-2"><table class="table table-sm mb-0"><thead><tr>'
                . '<th class="text-xxs text-uppercase text-secondary">Item</th>'
                . '<th class="text-xxs text-uppercase text-secondary text-end">Qty</th>'
                . '<th class="text-xxs text-uppercase text-secondary text-end">Price</th>'
                . '<th class="text-xxs text-uppercase text-secondary text-end">Total</th>'
                . '</tr></thead><tbody>';
            foreach ($lineItems as $lineItem) {
                $linesPreview .= '<tr>'
                    . '<td class="text-sm">' . htmlspecialchars((string) $lineItem['description']) . '</td>'
                    . '<td class="text-sm text-end">' . htmlspecialchars(rtrim(rtrim(number_format((float) $lineItem['quantity'], 2, '.', ''), '0'), '.')) . '</td>'
                    . '<td class="text-sm text-end">' . formatCurrency((float) $lineItem['unit_price']) . '</td>'
                    . '<td class="text-sm text-end">' . formatCurrency((float) $lineItem['line_total']) . '</td>'
                    . '</tr>';
            }
            $linesPreview .= '</tbody></table></div>';
        }

        $notesBlock = '';
        if (!empty($quotation['notes'])) {
            $notesBlock = '<p class="text-xs text-muted mb-0 mt-2">' . nl2br(htmlspecialchars((string) $quotation['notes'])) . '</p>';
        }

        $quotationItems .= '<article class="case-feed-item case-quotation-card flex-wrap align-items-start">'
            . '<div class="case-feed-item__icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center">'
            . legalpro_icon('clipboard-list')
            . '</div>'
            . '<div class="case-feed-item__body">'
            . '<h6 class="case-feed-item__title">' . htmlspecialchars($quoteNumber) . ' · ' . htmlspecialchars($quoteLabel) . '</h6>'
            . '<p class="case-feed-item__subtitle mb-0">' . htmlspecialchars($quoteTotal)
            . ' · ' . htmlspecialchars($validUntilText)
            . ($createdText !== '' ? ' · ' . htmlspecialchars($createdText) : '')
            . '</p>'
            . $linesPreview
            . $notesBlock
            . '</div>'
            . '<div class="case-feed-item__aside flex-column align-items-end gap-2">'
            . '<span class="case-status-pill ' . htmlspecialchars($quotePillClass) . '">' . htmlspecialchars($quoteStatus) . '</span>'
            . '<form method="POST" action="" onsubmit="return confirm(\'Delete this quotation?\');">'
            . '<input type="hidden" name="form_type" value="delete_quotation">'
            . '<input type="hidden" name="quotation_id" value="' . (int) $quotation['id'] . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger mb-0">Delete</button>'
            . '</form>'
            . '</div>'
            . '</article>';
    }
    $quotationsHtml = '<div class="case-feed-list">' . $quotationItems . '</div>';
}

$quotationStatusOptionsHtml = '';
foreach ($quotationStatusOptions as $statusValue => $statusLabel) {
    $selected = $statusValue === 'sent' ? ' selected' : '';
    $quotationStatusOptionsHtml .= '<option value="' . htmlspecialchars($statusValue) . '"' . $selected . '>'
        . htmlspecialchars($statusLabel) . '</option>';
}

$caseDetailTabActionsHtml = '<div class="case-detail-tab-actions">'
    . '<div id="case-detail-action-invoices" class="case-detail-tab-action" hidden>' . $createInvoiceBtn . '</div>'
    . '<div id="case-detail-action-payments" class="case-detail-tab-action" hidden>' . $recordPaymentBtn . '</div>'
    . '<div id="case-detail-action-stages" class="case-detail-tab-action" hidden>'
    . '<button type="button" class="btn btn-sm bg-gradient-dark mb-0" id="case-summary-add-btn">Add Summary</button>'
    . '</div>'
    . '</div>';

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

$caseDetailTabsNav = '<ul class="nav case-detail-tabs case-detail-tabs--sidebar" role="tablist">'
    . legalpro_case_detail_tab('#appointments', 'calendar', 'Appointments', count($appointments), true)
    . legalpro_case_detail_tab('#invoices', 'file-text', 'Invoices', count($invoices))
    . legalpro_case_detail_tab('#quotations', 'clipboard-list', 'Quotations', count($quotations))
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
    <link href="../assets/css/case-detail-tabs.css?v=6" rel="stylesheet" />
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
                                                <div class="col-12 pt-2 border-top">
                                                    <p class="text-sm mb-1">Remaining to Pay</p>
                                                    <h5 class="mb-0 {REMAINING_FEES_CLASS}">{REMAINING_FEES}</h5>
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
                        <div class="case-detail-hub__layout">
                            <aside class="case-detail-hub__sidebar" aria-label="Case sections">
                                <div class="case-detail-tabs-wrap">
                                    {CASE_DETAIL_TABS_NAV}
                                </div>
                            </aside>
                            <div class="case-detail-hub__content">
                                <div class="case-detail-hub__toolbar">
                                    {CASE_DETAIL_TAB_ACTIONS}
                                </div>
                                <div class="tab-content case-detail-panels">
                                <div class="tab-pane active" id="appointments" role="tabpanel">
                                    {APPOINTMENTS_HTML}
                                </div>
                                <div class="tab-pane" id="invoices" role="tabpanel">
                                    {INVOICES_HTML}
                                </div>
                                <div class="tab-pane" id="quotations" role="tabpanel">
                                    <div class="d-flex justify-content-end mb-3">
                                        <button type="button" class="btn btn-sm bg-gradient-dark mb-0" id="case-quotation-add-btn">Add Quotation</button>
                                    </div>

                                    <div class="card case-detail-form-card border-0 mb-4" id="case-quotation-form-card" hidden>
                                        <div class="card-header border-0 d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">New Quotation</h6>
                                            <button type="button" class="btn btn-link text-secondary btn-sm mb-0 p-0" id="case-quotation-cancel-btn">Cancel</button>
                                        </div>
                                        <div class="card-body pt-0">
                                            <form method="POST" action="" id="case-quotation-form">
                                                <input type="hidden" name="form_type" value="save_quotation">
                                                <div class="row g-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label text-sm">Quotation Number</label>
                                                        <input type="text" class="form-control" name="quotation_number" id="quotation_number" value="{NEXT_QUOTATION_NUMBER}" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label text-sm">Title</label>
                                                        <input type="text" class="form-control" name="quotation_title" placeholder="e.g., Initial legal fees quote">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label text-sm">Valid Until</label>
                                                        <input type="date" class="form-control" name="quotation_valid_until" value="{QUOTATION_DEFAULT_VALID_UNTIL}">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label text-sm">Status</label>
                                                        <select class="form-select" name="quotation_status">
                                                            {QUOTATION_STATUS_OPTIONS}
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label text-sm">Tax Rate (%)</label>
                                                        <input type="number" class="form-control" name="quotation_tax_rate" id="quotation_tax_rate" min="0" step="0.01" value="0">
                                                    </div>
                                                </div>

                                                <div class="mt-4">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h6 class="text-sm mb-0">Line Items</h6>
                                                        <button type="button" class="btn btn-sm btn-outline-primary mb-0" id="case-quotation-add-line-btn">Add Line</button>
                                                    </div>
                                                    <div class="case-detail-table-wrap">
                                                        <div class="table-responsive">
                                                            <table class="table align-items-center mb-0 case-quotation-lines-table">
                                                                <thead>
                                                                    <tr>
                                                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Description</th>
                                                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end" style="width:7rem;">Qty</th>
                                                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end" style="width:9rem;">Unit Price</th>
                                                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end" style="width:9rem;">Line Total</th>
                                                                        <th style="width:3rem;"></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody id="case-quotation-lines-body">
                                                                    <tr class="case-quotation-line-row">
                                                                        <td><input type="text" class="form-control form-control-sm" name="quotation_item_desc[]" placeholder="Service or item description" required></td>
                                                                        <td><input type="number" class="form-control form-control-sm text-end case-quotation-qty" name="quotation_item_qty[]" min="0" step="0.01" value="1"></td>
                                                                        <td><input type="number" class="form-control form-control-sm text-end case-quotation-price" name="quotation_item_price[]" min="0" step="0.01" value="0"></td>
                                                                        <td class="text-end align-middle"><span class="case-quotation-line-total text-sm font-weight-bold">0.00</span></td>
                                                                        <td class="text-end align-middle"><button type="button" class="btn btn-link text-danger btn-sm mb-0 p-0 case-quotation-remove-line" hidden aria-label="Remove line">&times;</button></td>
                                                                    </tr>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="row justify-content-end mt-3">
                                                    <div class="col-md-5">
                                                        <div class="case-quotation-totals">
                                                            <div class="d-flex justify-content-between text-sm mb-1"><span>Subtotal</span><strong id="quotation_subtotal_display">0.00</strong></div>
                                                            <div class="d-flex justify-content-between text-sm mb-1"><span>Tax</span><strong id="quotation_tax_display">0.00</strong></div>
                                                            <div class="d-flex justify-content-between text-sm border-top pt-2"><span>Total</span><strong id="quotation_total_display">0.00</strong></div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="mt-3">
                                                    <label class="form-label text-sm">Notes</label>
                                                    <textarea class="form-control" name="quotation_notes" rows="3" placeholder="Terms, scope, or additional notes for the client"></textarea>
                                                </div>

                                                <button type="submit" class="btn btn-dark btn-sm mt-3 mb-0">Save Quotation</button>
                                            </form>
                                        </div>
                                    </div>

                                    {QUOTATIONS_HTML}
                                </div>
                                <div class="tab-pane" id="payments" role="tabpanel">
                                    {PAYMENTS_HTML}
                                </div>
                                <div class="tab-pane" id="documents" role="tabpanel">
                                    {DOCUMENTS_HTML}
                                </div>
                                <div class="tab-pane" id="stages" role="tabpanel">
                                    {STAGES_HTML}
                                    {STAGES_FORM_HTML}
                                </div>
                                <div class="tab-pane" id="comments" role="tabpanel">
                                    <div class="d-flex justify-content-end mb-3">
                                        <button type="button" class="btn btn-sm bg-gradient-dark mb-0" id="case-comment-add-btn">Add Comment</button>
                                    </div>

                                    <div class="card case-detail-form-card border-0 mb-4" id="case-comment-form-card" hidden>
                                        <div class="card-header border-0 d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">Add Comment</h6>
                                            <button type="button" class="btn btn-link text-secondary btn-sm mb-0 p-0" id="case-comment-cancel-btn">Cancel</button>
                                        </div>
                                        <div class="card-body pt-0">
                                            <form method="POST" action="" id="case-comment-form">
                                                <textarea class="form-control" name="comment" id="case_comment_text" rows="3" placeholder="Write a comment for this case..." required></textarea>
                                                <button type="submit" class="btn btn-dark btn-sm mt-3 mb-0">Post Comment</button>
                                            </form>
                                        </div>
                                    </div>

                                    {COMMENTS_HTML}

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
        </div>
    </main>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    {CASE_DETAIL_TAB_SCRIPT}
    {QUOTATION_TAB_SCRIPT}
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
    $commentsHtml = caseDetailFeedEmpty('message-circle', 'No comments yet. Click Add Comment to start the conversation.');
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
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Lawyer Comment</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($tasks as $task) {
        $statusBadgeHtml = legalpro_task_status_badge((string) ($task['status'] ?? ''));
        $priorityBadgeHtml = legalpro_task_priority_badge((string) ($task['priority'] ?? ''));

        $dueDate = $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'No due date';
        $lawyerName = htmlspecialchars($task['lawyer_first_name'] . ' ' . $task['lawyer_last_name']);
        $taskCommentCell = render_admin_task_comment_html((string)($task['task_comment'] ?? ''));

        $tasksHtml .= '<tr>
            <td>
                <div>
                    <h6 class="mb-0 text-sm">' . htmlspecialchars($task['title']) . '</h6>';
        if (!empty($task['description'])) {
            $tasksHtml .= '<small class="text-muted">' . htmlspecialchars(substr($task['description'], 0, 50)) . (strlen($task['description']) > 50 ? '...' : '') . '</small>';
        }
        if (trim((string)($task['task_comment'] ?? '')) !== '') {
            $tasksHtml .= render_admin_task_comment_html((string)$task['task_comment']);
        }
        $tasksHtml .= '</div>
            </td>
            <td class="text-sm">' . $lawyerName . '</td>
            <td>' . $statusBadgeHtml . '</td>
            <td>' . $priorityBadgeHtml . '</td>
            <td class="text-sm">' . $dueDate . '</td>
            <td>' . $taskCommentCell . '</td>
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
$estimatedFeesRaw = (float) ($case['estimated_fees'] ?? 0);
$invoicedRaw = (float) array_sum(array_column($invoices, 'amount'));
$paidRaw = (float) array_sum(array_column($payments, 'amount'));
if ($estimatedFeesRaw > 0) {
    $remainingFeesRaw = max($estimatedFeesRaw - $paidRaw, 0);
} else {
    $remainingFeesRaw = max($invoicedRaw - $paidRaw, 0);
}

$totalFees = formatCurrency($estimatedFeesRaw);
$totalInvoiced = formatCurrency($invoicedRaw);
$totalPaid = formatCurrency($paidRaw);
$remainingFees = formatCurrency($remainingFeesRaw);
$remainingFeesClass = $remainingFeesRaw > 0.01 ? 'text-warning' : 'text-success';

if ($activeTab === '' && isset($_GET['tab'])) {
    $activeTab = preg_replace('/[^a-z]/', '', strtolower((string) $_GET['tab']));
}

$quotationDefaultValidUntil = date('Y-m-d', strtotime('+30 days'));

$quotationTabScript = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    var formCard = document.getElementById('case-quotation-form-card');
    var form = document.getElementById('case-quotation-form');
    var linesBody = document.getElementById('case-quotation-lines-body');
    var addBtn = document.getElementById('case-quotation-add-btn');
    var cancelBtn = document.getElementById('case-quotation-cancel-btn');
    var addLineBtn = document.getElementById('case-quotation-add-line-btn');
    var taxRateInput = document.getElementById('quotation_tax_rate');

    function formatMoney(value) {
        var num = isNaN(value) ? 0 : Number(value);
        return num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateQuotationRemoveButtons() {
        if (!linesBody) {
            return;
        }
        var rows = linesBody.querySelectorAll('.case-quotation-line-row');
        rows.forEach(function (row, index) {
            var removeBtn = row.querySelector('.case-quotation-remove-line');
            if (removeBtn) {
                removeBtn.hidden = rows.length <= 1;
            }
        });
    }

    function recalculateQuotationTotals() {
        if (!linesBody) {
            return;
        }

        var subtotal = 0;
        linesBody.querySelectorAll('.case-quotation-line-row').forEach(function (row) {
            var qtyInput = row.querySelector('.case-quotation-qty');
            var priceInput = row.querySelector('.case-quotation-price');
            var totalEl = row.querySelector('.case-quotation-line-total');
            var qty = parseFloat(qtyInput && qtyInput.value ? qtyInput.value : '0') || 0;
            var price = parseFloat(priceInput && priceInput.value ? priceInput.value : '0') || 0;
            var lineTotal = qty * price;
            subtotal += lineTotal;
            if (totalEl) {
                totalEl.textContent = formatMoney(lineTotal);
            }
        });

        var taxRate = parseFloat(taxRateInput && taxRateInput.value ? taxRateInput.value : '0') || 0;
        var taxAmount = subtotal * (taxRate / 100);
        var total = subtotal + taxAmount;

        var subtotalEl = document.getElementById('quotation_subtotal_display');
        var taxEl = document.getElementById('quotation_tax_display');
        var totalEl = document.getElementById('quotation_total_display');
        if (subtotalEl) subtotalEl.textContent = formatMoney(subtotal);
        if (taxEl) taxEl.textContent = formatMoney(taxAmount);
        if (totalEl) totalEl.textContent = formatMoney(total);
    }

    function bindQuotationLineRow(row) {
        if (!row) {
            return;
        }
        row.querySelectorAll('.case-quotation-qty, .case-quotation-price').forEach(function (input) {
            input.addEventListener('input', recalculateQuotationTotals);
        });
        var removeBtn = row.querySelector('.case-quotation-remove-line');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                row.remove();
                updateQuotationRemoveButtons();
                recalculateQuotationTotals();
            });
        }
    }

    function resetQuotationForm() {
        if (!form) {
            return;
        }
        form.reset();
        if (!linesBody) {
            return;
        }
        linesBody.innerHTML = ''
            + '<tr class="case-quotation-line-row">'
            + '<td><input type="text" class="form-control form-control-sm" name="quotation_item_desc[]" placeholder="Service or item description" required></td>'
            + '<td><input type="number" class="form-control form-control-sm text-end case-quotation-qty" name="quotation_item_qty[]" min="0" step="0.01" value="1"></td>'
            + '<td><input type="number" class="form-control form-control-sm text-end case-quotation-price" name="quotation_item_price[]" min="0" step="0.01" value="0"></td>'
            + '<td class="text-end align-middle"><span class="case-quotation-line-total text-sm font-weight-bold">0.00</span></td>'
            + '<td class="text-end align-middle"><button type="button" class="btn btn-link text-danger btn-sm mb-0 p-0 case-quotation-remove-line" hidden aria-label="Remove line">&times;</button></td>'
            + '</tr>';
        bindQuotationLineRow(linesBody.querySelector('.case-quotation-line-row'));
        updateQuotationRemoveButtons();
        recalculateQuotationTotals();
    }

    window.showCaseQuotationForm = function () {
        if (formCard) {
            formCard.hidden = false;
        }
    };

    window.hideCaseQuotationForm = function () {
        if (formCard) {
            formCard.hidden = true;
        }
        resetQuotationForm();
    };

    window.focusCaseQuotationForm = function () {
        window.showCaseQuotationForm();
        if (formCard) {
            formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        var firstInput = formCard ? formCard.querySelector('input[name="quotation_title"]') : null;
        if (firstInput) {
            firstInput.focus();
        }
    };

    if (linesBody) {
        linesBody.querySelectorAll('.case-quotation-line-row').forEach(bindQuotationLineRow);
        updateQuotationRemoveButtons();
        recalculateQuotationTotals();
    }

    if (addBtn) {
        addBtn.addEventListener('click', window.focusCaseQuotationForm);
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', window.hideCaseQuotationForm);
    }
    if (taxRateInput) {
        taxRateInput.addEventListener('input', recalculateQuotationTotals);
    }
    if (addLineBtn && linesBody) {
        addLineBtn.addEventListener('click', function () {
            var row = document.createElement('tr');
            row.className = 'case-quotation-line-row';
            row.innerHTML = ''
                + '<td><input type="text" class="form-control form-control-sm" name="quotation_item_desc[]" placeholder="Service or item description" required></td>'
                + '<td><input type="number" class="form-control form-control-sm text-end case-quotation-qty" name="quotation_item_qty[]" min="0" step="0.01" value="1"></td>'
                + '<td><input type="number" class="form-control form-control-sm text-end case-quotation-price" name="quotation_item_price[]" min="0" step="0.01" value="0"></td>'
                + '<td class="text-end align-middle"><span class="case-quotation-line-total text-sm font-weight-bold">0.00</span></td>'
                + '<td class="text-end align-middle"><button type="button" class="btn btn-link text-danger btn-sm mb-0 p-0 case-quotation-remove-line" aria-label="Remove line">&times;</button></td>';
            linesBody.appendChild(row);
            bindQuotationLineRow(row);
            updateQuotationRemoveButtons();
            recalculateQuotationTotals();
            var descInput = row.querySelector('input[name="quotation_item_desc[]"]');
            if (descInput) {
                descInput.focus();
            }
        });
    }
});
</script>
JS;

$caseDetailTabScript = '<script>document.addEventListener("DOMContentLoaded",function(){var nextStageNumber=' . (int) $nextStageNumber . ';function showCaseSummaryForm(){var card=document.getElementById("case-summary-form-card");if(card){card.hidden=false;}}function hideCaseSummaryForm(){var card=document.getElementById("case-summary-form-card");if(card){card.hidden=true;}resetCaseSummaryForm();}function focusCaseSummaryForm(){showCaseSummaryForm();var card=document.getElementById("case-summary-form-card");var titleInput=document.getElementById("case_summary_stage_title");if(card){card.scrollIntoView({behavior:"smooth",block:"start"});}if(titleInput){titleInput.focus();}}function resetCaseSummaryForm(){var form=document.getElementById("case-summary-form");if(!form){return;}form.reset();document.getElementById("case_summary_stage_id").value="";document.getElementById("case_summary_stage_number").value=String(nextStageNumber);document.getElementById("case-summary-form-title").textContent="Add Summary Entry";document.getElementById("case-summary-submit-btn").textContent="Save Summary Entry";}function fillCaseSummaryForm(stage){if(!stage){return;}document.getElementById("case_summary_stage_id").value=stage.id||"";document.getElementById("case_summary_stage_number").value=stage.stage_number||nextStageNumber;document.getElementById("case_summary_stage_title").value=stage.title||"";document.getElementById("case_summary_stage_description").value=stage.description||"";document.getElementById("case_summary_stage_result").value=stage.result||"";document.getElementById("case_summary_stage_start_date").value=stage.start_date||"";document.getElementById("case_summary_stage_expected_end_date").value=stage.expected_end_date||"";document.getElementById("case_summary_stage_actual_end_date").value=stage.actual_end_date||"";document.getElementById("case-summary-form-title").textContent="Edit Summary Entry";document.getElementById("case-summary-submit-btn").textContent="Update Summary Entry";focusCaseSummaryForm();}function showCaseCommentForm(){var card=document.getElementById("case-comment-form-card");if(card){card.hidden=false;}}function hideCaseCommentForm(){var card=document.getElementById("case-comment-form-card");if(card){card.hidden=true;}var form=document.getElementById("case-comment-form");if(form){form.reset();}}function focusCaseCommentForm(){showCaseCommentForm();var input=document.getElementById("case_comment_text");if(input){input.focus();}}function updateCaseDetailTabActions(tabId){var inv=document.getElementById("case-detail-action-invoices");var pay=document.getElementById("case-detail-action-payments");var stages=document.getElementById("case-detail-action-stages");if(inv){inv.hidden=tabId!=="invoices";}if(pay){pay.hidden=tabId!=="payments";}if(stages){stages.hidden=tabId!=="stages";}if(tabId!=="stages"){hideCaseSummaryForm();}if(tabId!=="comments"){hideCaseCommentForm();}if(tabId!=="quotations"&&typeof window.hideCaseQuotationForm==="function"){window.hideCaseQuotationForm();}}function getActiveCaseDetailTabId(){var active=document.querySelector(".case-detail-tabs .nav-link.active");return active&&active.getAttribute("href")?active.getAttribute("href").slice(1):"appointments";}document.querySelectorAll(".case-detail-tabs a[data-bs-toggle=\'tab\']").forEach(function(link){link.addEventListener("shown.bs.tab",function(e){var tabId=e.target.getAttribute("href").slice(1);updateCaseDetailTabActions(tabId);});});document.querySelectorAll(".case-stage-edit-btn").forEach(function(btn){btn.addEventListener("click",function(){try{fillCaseSummaryForm(JSON.parse(btn.getAttribute("data-stage")||"{}"));}catch(err){}});});var addSummaryBtn=document.getElementById("case-summary-add-btn");if(addSummaryBtn){addSummaryBtn.addEventListener("click",function(){resetCaseSummaryForm();focusCaseSummaryForm();});}var summaryEmpty=document.getElementById("case-summary-empty");if(summaryEmpty){summaryEmpty.addEventListener("click",function(){resetCaseSummaryForm();focusCaseSummaryForm();});summaryEmpty.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" "){e.preventDefault();resetCaseSummaryForm();focusCaseSummaryForm();}});}var cancelEditBtn=document.getElementById("case-summary-cancel-edit");if(cancelEditBtn){cancelEditBtn.addEventListener("click",hideCaseSummaryForm);}var addCommentBtn=document.getElementById("case-comment-add-btn");if(addCommentBtn){addCommentBtn.addEventListener("click",focusCaseCommentForm);}var cancelCommentBtn=document.getElementById("case-comment-cancel-btn");if(cancelCommentBtn){cancelCommentBtn.addEventListener("click",hideCaseCommentForm);}var tab=' . json_encode($activeTab) . ';if(!tab&&window.location.hash){tab=window.location.hash.slice(1);}if(tab){var link=document.querySelector(\'.case-detail-tabs a[href="#\'+tab+\'"]\');if(link&&window.bootstrap&&bootstrap.Tab){bootstrap.Tab.getOrCreateInstance(link).show();}}updateCaseDetailTabActions(tab||getActiveCaseDetailTabId());});</script>';

// Replace placeholders
$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{CASE_DETAIL_TAB_SCRIPT}' => $caseDetailTabScript,
    '{QUOTATION_TAB_SCRIPT}' => $quotationTabScript,
    '{NEXT_QUOTATION_NUMBER}' => htmlspecialchars($nextQuotationNumber),
    '{QUOTATION_DEFAULT_VALID_UNTIL}' => htmlspecialchars($quotationDefaultValidUntil),
    '{QUOTATION_STATUS_OPTIONS}' => $quotationStatusOptionsHtml,
    '{QUOTATIONS_HTML}' => $quotationsHtml,
    '{QUOTATIONS_COUNT}' => count($quotations),
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
    '{REMAINING_FEES}' => $remainingFees,
    '{REMAINING_FEES_CLASS}' => $remainingFeesClass,
    '{STAGES_FORM_HTML}' => $stagesFormHtml,
    '{CASE_DETAIL_TABS_NAV}' => $caseDetailTabsNav,
    '{CASE_DETAIL_TAB_ACTIONS}' => $caseDetailTabActionsHtml,
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
echo legalpro_apply_copyright_line($html);
?>
