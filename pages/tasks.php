<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/task_helpers.php';
require_once __DIR__ . '/../lib/lawyer_portal_vocab.php';

ensure_task_support_schema($pdo);
ensure_task_comment_files_schema($pdo);
ensure_lawyer_task_vocabulary($pdo);

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];
$minDueDate = date('Y-m-d');

if (isset($_GET['action']) && $_GET['action'] === 'download_task_file') {
    $fileId = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
    $fileRow = lawyer_get_task_comment_file($pdo, $fileId, $lawyerId);
    if (!$fileRow) {
        http_response_code(404);
        exit('File not found.');
    }

    $absolutePath = realpath(__DIR__ . '/../' . ltrim((string) $fileRow['stored_path'], '/'));
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($absolutePath === false || $uploadsRoot === false || strpos($absolutePath, $uploadsRoot) !== 0 || !is_file($absolutePath)) {
        http_response_code(404);
        exit('File not found.');
    }

    $downloadName = (string) ($fileRow['original_name'] ?? 'attachment');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . filesize($absolutePath));
    readfile($absolutePath);
    exit;
}

$taskForm = [
    'task_id' => 0,
    'case_id' => 0,
    'task_title' => '',
    'task_description' => '',
    'task_priority' => 'normal',
    'due_date' => '',
    'task_comment' => '',
];
$showTaskModalOnLoad = false;

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
              `status` ENUM('active', 'pending', 'under_review', 'closed') DEFAULT 'pending',
              `priority` ENUM('normal', 'high', 'urgent') DEFAULT 'normal',
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
    }
} catch (PDOException $e) {
    // If table creation fails, continue anyway - the INSERT might still work if table exists
    error_log("Failed to create tasks table: " . $e->getMessage());
}

// Handle task status updates
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $newStatus = isset($_POST['status']) ? $_POST['status'] : '';

    if ($taskId > 0 && in_array($newStatus, lawyer_task_status_keys(), true)) {
        try {
            // Get current task status for tracking
            $stmt = $pdo->prepare("SELECT status, title FROM tasks WHERE id = ?");
            $stmt->execute([$taskId]);
            $currentTask = $stmt->fetch();

            if ($currentTask && lawyer_has_task_access($pdo, $taskId, $lawyerId)) {
                $oldStatus = $currentTask['status'];
                $newStatus = lawyer_normalize_task_status($newStatus);

                // Update task status
                if ($newStatus === 'closed') {
                    $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = NOW() WHERE id = ?");
                    $stmt->execute([$newStatus, $taskId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ?");
                    $stmt->execute([$newStatus, $taskId]);
                }

                $message = 'Task status updated successfully!';
                $messageType = 'success';

                // Track status change
                require_once __DIR__ . '/../lib/case_events.php';
                $stmt = $pdo->prepare("SELECT case_id FROM tasks WHERE id = ?");
                $stmt->execute([$taskId]);
                $taskData = $stmt->fetch();

                if ($taskData) {
                    CaseEvents::trackTaskUpdated($taskData['case_id'], $taskId, $oldStatus, $newStatus, $currentTask['title']);

                    if ($newStatus === 'closed') {
                        CaseEvents::trackTaskCompleted($taskData['case_id'], $currentTask);
                    }
                }
            } else {
                $message = 'Task not found or access denied.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Error updating task status: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } else {
        $message = 'Invalid request parameters.';
        $messageType = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_task') {
    $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
    $taskTitle = trim(isset($_POST['task_title']) ? $_POST['task_title'] : '');
    $taskDescription = trim(isset($_POST['task_description']) ? $_POST['task_description'] : '');
    $taskPriority = lawyer_normalize_task_priority(isset($_POST['task_priority']) ? $_POST['task_priority'] : 'normal');
    $dueDate = trim(isset($_POST['due_date']) ? $_POST['due_date'] : '');
    $taskComment = trim(isset($_POST['task_comment']) ? $_POST['task_comment'] : '');

    $taskForm = [
        'task_id' => $taskId,
        'case_id' => $caseId,
        'task_title' => $taskTitle,
        'task_description' => $taskDescription,
        'task_priority' => $taskPriority,
        'due_date' => $dueDate,
        'task_comment' => $taskComment,
    ];
    $showTaskModalOnLoad = true;

    if ($taskTitle === '' || $caseId <= 0 || !in_array($taskPriority, lawyer_task_priority_keys(), true)) {
        $message = 'Please provide a valid title, case, and priority.';
        $messageType = 'danger';
    } elseif ($dueDate !== '' && $dueDate < $minDueDate && $taskId <= 0) {
        $message = 'Due date cannot be in the past.';
        $messageType = 'danger';
    } else {
        try {
            // Only allow task changes for cases assigned to the logged-in lawyer.
            $caseAccessStmt = $pdo->prepare("SELECT 1 FROM case_lawyers WHERE case_id = ? AND lawyer_id = ? LIMIT 1");
            $caseAccessStmt->execute([$caseId, $lawyerId]);
            $hasCaseAccess = (bool)$caseAccessStmt->fetchColumn();

            if (!$hasCaseAccess) {
                $message = 'You can only create or edit tasks for your assigned cases.';
                $messageType = 'danger';
            } else {
                require_once __DIR__ . '/../lib/case_events.php';

                if ($taskId > 0) {
                    $existingStmt = $pdo->prepare("SELECT id, case_id, status, title, task_comment FROM tasks WHERE id = ?");
                    $existingStmt->execute([$taskId]);
                    $existingTask = $existingStmt->fetch();

                    if (!$existingTask || !lawyer_has_task_access($pdo, $taskId, $lawyerId)) {
                        $message = 'Task not found or access denied.';
                        $messageType = 'danger';
                    } else {
                        $hasCommentFile = isset($_FILES['task_comment_file'])
                            && (int) ($_FILES['task_comment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                        if ($taskComment !== '') {
                            $commentToSave = $taskComment;
                        } elseif ($hasCommentFile) {
                            $commentToSave = trim((string) ($existingTask['task_comment'] ?? '')) !== ''
                                ? (string) $existingTask['task_comment']
                                : null;
                        } else {
                            $commentToSave = null;
                        }

                        $savedTaskId = $taskId;
                        $updateStmt = $pdo->prepare("
                            UPDATE tasks
                            SET case_id = ?, title = ?, description = ?, priority = ?, due_date = ?, task_comment = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([
                            $caseId,
                            $taskTitle,
                            $taskDescription,
                            $taskPriority,
                            $dueDate ?: null,
                            $commentToSave,
                            $taskId,
                        ]);

                        if ($hasCommentFile) {
                            $uploadResult = lawyer_save_task_comment_file($pdo, $savedTaskId, $lawyerId, $_FILES['task_comment_file']);
                            if (!$uploadResult['ok']) {
                                throw new RuntimeException((string) ($uploadResult['error'] ?? 'Unable to upload attachment.'));
                            }
                        }

                        $message = 'Task updated successfully!';
                        $messageType = 'success';
                        $showTaskModalOnLoad = false;
                        $taskForm = [
                            'task_id' => 0,
                            'case_id' => 0,
                            'task_title' => '',
                            'task_description' => '',
                            'task_priority' => 'normal',
                            'due_date' => '',
                            'task_comment' => '',
                        ];

                        CaseEvents::trackTaskUpdated($caseId, $taskId, $existingTask['status'], $existingTask['status'], $taskTitle);
                    }
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO tasks (case_id, assigned_lawyer_id, title, description, priority, due_date, task_comment, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
                    ");
                    $insertStmt->execute([
                        $caseId,
                        $lawyerId,
                        $taskTitle,
                        $taskDescription,
                        $taskPriority,
                        $dueDate ?: null,
                        $taskComment !== '' ? $taskComment : null,
                    ]);
                    $savedTaskId = (int) $pdo->lastInsertId();

                    if (isset($_FILES['task_comment_file']) && (int) ($_FILES['task_comment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $uploadResult = lawyer_save_task_comment_file($pdo, $savedTaskId, $lawyerId, $_FILES['task_comment_file']);
                        if (!$uploadResult['ok']) {
                            throw new RuntimeException((string) ($uploadResult['error'] ?? 'Unable to upload attachment.'));
                        }
                    }

                    $message = 'Task added successfully!';
                    $messageType = 'success';
                    $showTaskModalOnLoad = false;
                    $taskForm = [
                        'task_id' => 0,
                        'case_id' => 0,
                        'task_title' => '',
                        'task_description' => '',
                        'task_priority' => 'normal',
                        'due_date' => '',
                        'task_comment' => '',
                    ];

                    CaseEvents::trackTaskCreated($caseId, [
                        'title' => $taskTitle,
                        'description' => $taskDescription
                    ]);
                }
            }
        } catch (PDOException $e) {
            $message = 'Error saving task: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        } catch (RuntimeException $e) {
            $message = htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$dueFilter = isset($_GET['due']) ? strtolower(trim((string) $_GET['due'])) : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$taskDueCounts = lawyer_task_due_counts($pdo, $lawyerId);

// Build query to get tasks for this lawyer
$query = "
    SELECT t.*, c.title as case_title, c.id as case_id,
           cl.first_name as client_first_name, cl.last_name as client_last_name
    FROM tasks t
    INNER JOIN cases c ON c.id = t.case_id
    INNER JOIN clients cl ON cl.id = c.client_id
    WHERE " . lawyer_task_access_sql() . "
";

$params = [$lawyerId, $lawyerId];

if ($statusFilter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $statusFilter;
}

if ($priorityFilter !== 'all') {
    $query .= " AND t.priority = ?";
    $params[] = $priorityFilter;
}

if ($search !== '') {
    $query .= " AND (t.title LIKE ? OR c.title LIKE ? OR cl.first_name LIKE ? OR cl.last_name LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

[$dueSql, $dueParams] = lawyer_task_due_filter_sql($dueFilter);
if ($dueSql !== '') {
    $query .= $dueSql;
    foreach ($dueParams as $dueParam) {
        $params[] = $dueParam;
    }
}

$query .= " ORDER BY
    CASE t.priority
        WHEN 'urgent' THEN 1
        WHEN 'high' THEN 2
        WHEN 'normal' THEN 3
        ELSE 4
    END,
    t.due_date ASC,
    t.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    $tasks = [];
    $message = 'Error loading tasks: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

$taskAttachmentsById = lawyer_fetch_task_comment_files(
    $pdo,
    array_map(static function ($task) {
        return (int) ($task['id'] ?? 0);
    }, $tasks)
);

// Cases this lawyer can create tasks for
$lawyerCases = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title
        FROM case_lawyers cl
        INNER JOIN cases c ON c.id = cl.case_id
        WHERE cl.lawyer_id = ?
        ORDER BY c.title ASC
    ");
    $stmt->execute([$lawyerId]);
    $lawyerCases = $stmt->fetchAll();
} catch (PDOException $e) {
    $lawyerCases = [];
}

$iconTaskEmpty = legalpro_icon('list-checks');
$iconCardHeader = legalpro_icon('list-checks');

// Build HTML
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$dueAlertsHtml = '';
if ($taskDueCounts['overdue'] > 0 || $taskDueCounts['today'] > 0 || $taskDueCounts['this_week'] > 0) {
    $dueAlertsHtml = '<div class="lt-task-due-alerts mb-4"><div class="card border-0 shadow-sm"><div class="card-body p-3">';
    $dueAlertsHtml .= '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">';
    $dueAlertsHtml .= '<h6 class="mb-0">Due date alerts</h6>';
    $dueAlertsHtml .= '<a href="' . htmlspecialchars(lawyer_build_tasks_filter_url(['due' => 'all'])) . '" class="text-xs text-primary font-weight-bold">Show all tasks</a>';
    $dueAlertsHtml .= '</div><div class="d-flex flex-wrap gap-2">';
    if ($taskDueCounts['overdue'] > 0) {
        $active = $dueFilter === 'overdue' ? ' lt-task-due-chip--active' : '';
        $dueAlertsHtml .= '<a class="lt-task-due-chip lt-task-due-chip--overdue' . $active . '" href="'
            . htmlspecialchars(lawyer_build_tasks_filter_url(['due' => 'overdue'])) . '">'
            . (int) $taskDueCounts['overdue'] . ' overdue</a>';
    }
    if ($taskDueCounts['today'] > 0) {
        $active = $dueFilter === 'today' ? ' lt-task-due-chip--active' : '';
        $dueAlertsHtml .= '<a class="lt-task-due-chip lt-task-due-chip--today' . $active . '" href="'
            . htmlspecialchars(lawyer_build_tasks_filter_url(['due' => 'today'])) . '">'
            . (int) $taskDueCounts['today'] . ' due today</a>';
    }
    if ($taskDueCounts['this_week'] > 0) {
        $active = $dueFilter === 'this_week' ? ' lt-task-due-chip--active' : '';
        $dueAlertsHtml .= '<a class="lt-task-due-chip lt-task-due-chip--week' . $active . '" href="'
            . htmlspecialchars(lawyer_build_tasks_filter_url(['due' => 'this_week'])) . '">'
            . (int) $taskDueCounts['this_week'] . ' this week</a>';
    }
    $dueAlertsHtml .= '</div></div></div></div>';
}

$tasksListHtml = '';
if (empty($tasks)) {
    $tasksListHtml = '<div class="text-center py-5 px-4">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconTaskEmpty . '</div>
        <h5 class="font-weight-bolder mt-3 mb-2">' . htmlspecialchars(lawyer_tf('tasks.empty_title', 'No tasks found')) . '</h5>
        <p class="text-sm text-muted mb-0">' . htmlspecialchars(lawyer_tf('tasks.empty_sub', 'Try adjusting your filters or create a new task.')) . '</p>
    </div>';
} else {
    foreach ($tasks as $task) {
        $statusBadge = lawyer_task_status_badge((string) ($task['status'] ?? ''));
        $priorityBadge = lawyer_task_priority_badge((string) ($task['priority'] ?? 'normal'));
        $dueLabel = $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : lawyer_tf('tasks.no_due_date', 'No due date');
        $dueState = lawyer_task_due_state($task['due_date'] ?? null, (string) ($task['status'] ?? ''));
        $dueAlertBadge = lawyer_render_task_due_alert_badge($dueState);
        $rowStateClass = $dueState !== 'none' && $dueState !== 'upcoming' ? ' lt-task-row--' . str_replace('_', '-', $dueState) : '';
        $dueClass = $dueState === 'overdue' ? 'lt-task-row__due lt-task-row__due--overdue' : 'lt-task-row__due';
        $taskTitleJs = htmlspecialchars(json_encode($task['title']), ENT_QUOTES, 'UTF-8');
        $taskDescriptionJs = htmlspecialchars(json_encode((string) $task['description']), ENT_QUOTES, 'UTF-8');
        $taskPriorityJs = htmlspecialchars(json_encode($task['priority']), ENT_QUOTES, 'UTF-8');
        $taskDueDateJs = htmlspecialchars(json_encode((string) $task['due_date']), ENT_QUOTES, 'UTF-8');
        $clientName = htmlspecialchars(trim($task['client_first_name'] . ' ' . $task['client_last_name']));
        $caseNumber = 'C-' . str_pad((string) $task['case_id'], 4, '0', STR_PAD_LEFT);
        $taskFiles = $taskAttachmentsById[(int) $task['id']] ?? [];
        $commentFilesHtml = lawyer_render_task_comment_files_html($taskFiles);

        $taskStatusKey = lawyer_normalize_task_status((string) ($task['status'] ?? ''));
        $statusSelectOptions = '';
        foreach (lawyer_task_status_options() as $statusValue => $statusLabel) {
            $selected = $taskStatusKey === $statusValue ? ' selected' : '';
            $statusSelectOptions .= '<option value="' . htmlspecialchars($statusValue) . '"' . $selected . '>' . htmlspecialchars($statusLabel) . '</option>';
        }

        $tasksListHtml .= '
        <div class="lt-task-row' . $rowStateClass . '">
            <div class="row align-items-center g-3">
                <div class="col-lg-6">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">' . $dueAlertBadge . '</div>
                    <h6 class="lt-task-row__title mb-1">' . htmlspecialchars($task['title']) . '</h6>
                    <p class="lt-task-row__meta mb-1">' . htmlspecialchars($task['case_title']) . ' (' . $caseNumber . ')</p>
                    <p class="lt-task-row__meta mb-0">' . htmlspecialchars(lawyer_tf('common.client', 'Client')) . ': ' . $clientName . '</p>';
        if (!empty($task['task_comment'])) {
            $tasksListHtml .= '<p class="lt-task-row__meta mb-0 mt-1"><span class="text-muted">Comment:</span> ' . nl2br(htmlspecialchars($task['task_comment'])) . '</p>';
        }
        if ($commentFilesHtml !== '') {
            $tasksListHtml .= '<div class="mt-2">' . $commentFilesHtml . '</div>';
        }
        $tasksListHtml .= '
                </div>
                <div class="col-lg-3">
                    <div class="lt-task-row__badges d-flex flex-column gap-2 align-items-lg-end">
                        ' . $statusBadge . '
                        ' . $priorityBadge . '
                    </div>
                </div>
                <div class="col-lg-3 lp-row-actions-col">
                    <p class="' . $dueClass . ' text-lg-end mb-0">' . htmlspecialchars(lawyer_tf('tasks.col_due', 'Due date')) . ': ' . htmlspecialchars($dueLabel) . '</p>
                    <div class="lt-task-row__actions d-flex align-items-center gap-2 justify-content-lg-end flex-wrap">
                        <button
                            type="button"
                            class="btn btn-sm lt-task-edit-btn mb-0"
                            onclick="showEditTaskModal(' . (int) $task['id'] . ', ' . (int) $task['case_id'] . ', ' . $taskTitleJs . ', ' . $taskDescriptionJs . ', ' . $taskPriorityJs . ', ' . $taskDueDateJs . ')"
                        >' . htmlspecialchars(lawyer_tf('common.edit', 'Edit')) . '</button>
                        <form method="POST" action="" class="d-flex align-items-center mb-0">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="task_id" value="' . (int) $task['id'] . '">
                            <select name="status" class="form-select form-select-sm lawyer-tasks-status-select mb-0" onchange="this.form.submit()" aria-label="Update task status">
                                ' . $statusSelectOptions . '
                            </select>
                        </form>
                    </div>
                </div>
            </div>
        </div>';
    }
}

$caseOptions = '<option value="">' . htmlspecialchars(lawyer_tf('tasks.select_case', 'Select case')) . '</option>';
foreach ($lawyerCases as $lawyerCase) {
    $selected = ((int)$taskForm['case_id'] === (int)$lawyerCase['id']) ? ' selected' : '';
    $caseOptions .= '<option value="' . (int)$lawyerCase['id'] . '"' . $selected . '>' . htmlspecialchars($lawyerCase['title']) . '</option>';
}

$pageTitle = lawyer_tf('tasks.page_title', 'My Tasks');
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle);

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

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
    <style>
        .lawyer-tasks-page .lt-task-row {
            background: linear-gradient(180deg, #ffffff 0%, #f4f6fc 100%);
            border: 1px solid #dbe4f7;
            border-radius: 14px;
            padding: 1.1rem 1.25rem;
            margin-bottom: 0.85rem;
        }
        .lawyer-tasks-page .lt-task-row:last-child {
            margin-bottom: 0;
        }
        .lawyer-tasks-page .lt-task-row__title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #344767;
        }
        .lawyer-tasks-page .lt-task-row__meta {
            font-size: 0.82rem;
            color: #8392ab;
            margin-bottom: 0;
        }
        .lawyer-tasks-page .lt-task-row__due {
            font-size: 0.82rem;
            color: #8392ab;
            margin-bottom: 0;
        }
        .lawyer-tasks-page .lt-task-row__due--overdue {
            color: #ea0606;
            font-weight: 700;
        }
        .lawyer-tasks-page .lt-task-row--overdue {
            border-color: rgba(234, 6, 6, 0.35);
            box-shadow: inset 0 0 0 1px rgba(234, 6, 6, 0.08);
        }
        .lawyer-tasks-page .lt-task-row--today {
            border-color: rgba(251, 140, 0, 0.35);
        }
        .lawyer-tasks-page .lt-task-row--this-week {
            border-color: rgba(0, 119, 182, 0.28);
        }
        .lawyer-tasks-page .lt-task-due-alert {
            border-radius: 999px;
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            padding: 0.2rem 0.55rem;
            text-transform: uppercase;
        }
        .lawyer-tasks-page .lt-task-due-alert--overdue {
            background: rgba(245, 54, 92, 0.12);
            color: #f5365c;
        }
        .lawyer-tasks-page .lt-task-due-alert--today {
            background: rgba(251, 140, 0, 0.14);
            color: #c45c00;
        }
        .lawyer-tasks-page .lt-task-due-alert--week {
            background: rgba(0, 119, 182, 0.12);
            color: #0077b6;
        }
        .lawyer-tasks-page .lt-task-due-chip {
            border-radius: 999px;
            display: inline-flex;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.4rem 0.8rem;
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .lawyer-tasks-page .lt-task-due-chip:hover {
            transform: translateY(-1px);
        }
        .lawyer-tasks-page .lt-task-due-chip--overdue {
            background: rgba(245, 54, 92, 0.12);
            color: #f5365c;
        }
        .lawyer-tasks-page .lt-task-due-chip--today {
            background: rgba(251, 140, 0, 0.14);
            color: #c45c00;
        }
        .lawyer-tasks-page .lt-task-due-chip--week {
            background: rgba(0, 119, 182, 0.12);
            color: #0077b6;
        }
        .lawyer-tasks-page .lt-task-due-chip--active {
            box-shadow: 0 0 0 2px currentColor;
        }
        .lawyer-tasks-page .lt-task-comment-files {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .lawyer-tasks-page .lt-task-comment-file {
            align-items: center;
            background: rgba(0, 119, 182, 0.08);
            border: 1px solid rgba(0, 119, 182, 0.16);
            border-radius: 0.45rem;
            color: #324cdd;
            display: inline-flex;
            font-size: 0.78rem;
            font-weight: 600;
            max-width: 100%;
            padding: 0.35rem 0.55rem;
            text-decoration: none;
            width: fit-content;
        }
        .lawyer-tasks-page .lt-task-comment-file:hover {
            background: rgba(0, 119, 182, 0.14);
            color: #243bcc;
        }
        .lawyer-tasks-page .lt-task-row__badges .ca-status-pill {
            min-width: 5.5rem;
            text-align: center;
        }
        .lawyer-tasks-page .lt-task-edit-btn {
            background: linear-gradient(140deg, #2d3f6f 0%, #4a5fa8 44%, #6f7fd2 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.35rem 0.9rem;
        }
        .lawyer-tasks-page .lt-task-edit-btn:hover {
            color: #fff;
            opacity: 0.92;
        }
        .lawyer-tasks-page .lawyer-tasks-status-select {
            min-width: 9.75rem;
            height: 31px;
            padding-left: 0.75rem;
            padding-right: 2.25rem;
            background-position: right 0.65rem center;
        }
        .lawyer-tasks-page .lt-task-row__actions {
            flex-wrap: wrap;
        }
        .lawyer-tasks-page #taskModal .form-select {
            padding-left: 0.875rem;
            padding-right: 2.85rem;
            background-position: right 0.85rem center;
        }
        .lawyer-tasks-page #taskModal .modal-header {
            background: linear-gradient(140deg, #2d3f6f 0%, #4a5fa8 44%, #6f7fd2 100%);
            color: #fff;
            border-bottom: none;
        }
        .lawyer-tasks-page #taskModal .modal-header .modal-title {
            color: #fff !important;
        }
        .lawyer-tasks-page #taskModal .modal-header .btn-close {
            filter: invert(1) grayscale(1) brightness(200%);
            opacity: 1;
        }
        .lawyer-tasks-page #taskModal .task-comment-toggle {
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            padding: 0;
        }
        .lawyer-tasks-page #taskModal .task-comment-hint {
            font-size: 0.75rem;
            color: #8392ab;
            margin-top: 0.35rem;
        }
        @media (max-width: 991.98px) {
            .lawyer-tasks-page .lt-task-row__badges {
                align-items: flex-start !important;
            }
            .lawyer-tasks-page .lt-task-row__due,
            .lawyer-tasks-page .lt-task-row__actions {
                justify-content: flex-start !important;
                text-align: left !important;
            }
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-tasks-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}

        <div class="container-fluid py-4">
            {MESSAGE}

            {DUE_ALERTS}

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <form method="GET" class="row align-items-end g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">{LBL_SEARCH}</label>
                                    <input type="text" class="form-control" name="search" value="{SEARCH_VALUE}" placeholder="{PH_SEARCH}">
                                </div>
                                <div class="col-lg-2 col-md-3">
                                    <label class="form-label">{LBL_DUE}</label>
                                    <select class="form-select" name="due">
                                        <option value="all"{DUE_ALL}>{OPT_DUE_ALL}</option>
                                        <option value="overdue"{DUE_OVERDUE}>{OPT_DUE_OVERDUE}</option>
                                        <option value="today"{DUE_TODAY}>{OPT_DUE_TODAY}</option>
                                        <option value="this_week"{DUE_THIS_WEEK}>{OPT_DUE_WEEK}</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3">
                                    <label class="form-label">{LBL_STATUS}</label>
                                    <select class="form-select" name="status">
                                        <option value="all"{STATUS_ALL}>{OPT_ALL_STATUSES}</option>
                                        <option value="active"{STATUS_ACTIVE}>{OPT_ACTIVE}</option>
                                        <option value="pending"{STATUS_PENDING}>{OPT_PENDING}</option>
                                        <option value="under_review"{STATUS_UNDER_REVIEW}>{OPT_UNDER_REVIEW}</option>
                                        <option value="closed"{STATUS_CLOSED}>{OPT_CLOSED}</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3">
                                    <label class="form-label">{LBL_PRIORITY}</label>
                                    <select class="form-select" name="priority">
                                        <option value="all"{PRIORITY_ALL}>{OPT_ALL_PRIORITIES}</option>
                                        <option value="normal"{PRIORITY_NORMAL}>{OPT_NORMAL}</option>
                                        <option value="high"{PRIORITY_HIGH}>{OPT_HIGH}</option>
                                        <option value="urgent"{PRIORITY_URGENT}>{OPT_URGENT}</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3">
                                    <label class="form-label d-block invisible">{LBL_ACTIONS}</label>
                                    <div class="lp-lawyer-filter-actions">
                                        <button type="submit" class="btn btn-primary mb-0">{BTN_FILTER}</button>
                                        <a href="tasks.php" class="btn btn-outline-secondary mb-0">{BTN_RESET}</a>
                                    </div>
                                </div>
                                <div class="col-lg-1 col-md-12 text-lg-end">
                                    <p class="text-sm text-muted mb-0">{TOTAL_COUNT}</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center">
                                    <div class="lp-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary me-3">{ICON_CARD_HEADER}</div>
                                    <div>
                                        <h6 class="mb-0">{PAGE_TITLE}</h6>
                                        <p class="text-xs text-muted mb-0">{CARD_SUBTITLE}</p>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary mb-0" onclick="showAddTaskModal()">
                                    <i class="ni ni-fat-add me-1"></i>{BTN_NEW_TASK}
                                </button>
                            </div>
                        </div>
                        <div class="card-body pt-3 pb-3">
                            {TASKS_LIST}
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
    <div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskModalTitle">{LBL_ADD_TASK}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_task">
                        <input type="hidden" name="task_id" id="task_id" value="{TASK_FORM_ID}">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{LBL_FORM_TITLE} <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="task_title" id="task_title" value="{TASK_FORM_TITLE}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{LBL_CASE} <span class="text-danger">*</span></label>
                                <select class="form-control" name="case_id" id="task_case_id" required>
                                    {TASK_CASE_OPTIONS}
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{LBL_PRIORITY}</label>
                                <select class="form-control" name="task_priority" id="task_priority">
                                    <option value="normal" {TASK_PRIORITY_NORMAL}>{OPT_NORMAL}</option>
                                    <option value="high" {TASK_PRIORITY_HIGH}>{OPT_HIGH}</option>
                                    <option value="urgent" {TASK_PRIORITY_URGENT}>{OPT_URGENT}</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{LBL_FORM_DUE_DATE}</label>
                                <input type="date" class="form-control" name="due_date" id="task_due_date" value="{TASK_FORM_DUE_DATE}" min="{MIN_DUE_DATE}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{LBL_FORM_DESCRIPTION}</label>
                            <textarea class="form-control" name="task_description" id="task_description" rows="3" placeholder="{PH_FORM_DESC}">{TASK_FORM_DESCRIPTION}</textarea>
                        </div>
                        <div class="mb-0">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <label class="form-label mb-0">{LBL_FORM_COMMENT}</label>
                                <button type="button" class="btn btn-link task-comment-toggle mb-0" id="task_comment_toggle" aria-expanded="false" aria-controls="task_comment_wrap">
                                    {LBL_ADD_COMMENT}
                                </button>
                            </div>
                            <div id="task_comment_wrap" hidden>
                                <textarea class="form-control" name="task_comment" id="task_comment" rows="3" placeholder="{PH_COMMENT}">{TASK_FORM_COMMENT}</textarea>
                                <div class="mt-3">
                                    <label class="form-label mb-1">{LBL_ATTACHMENT}</label>
                                    <input type="file" class="form-control" name="task_comment_file" id="task_comment_file" accept=".pdf,.doc,.docx,.txt,.png,.jpg,.jpeg,.gif">
                                    <p class="task-comment-hint mb-0">{LBL_ATTACHMENT_HINT}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{LBL_CANCEL}</button>
                        <button type="submit" class="btn btn-primary" id="taskSaveButton">{LBL_SAVE_TASK}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        var taskI18n = {
            addTask: {TASK_I18N_ADD},
            editTask: {TASK_I18N_EDIT},
            saveTask: {TASK_I18N_SAVE},
            updateTask: {TASK_I18N_UPDATE},
            addComment: {TASK_I18N_ADD_COMMENT},
            hideComment: {TASK_I18N_HIDE_COMMENT}
        };
        var taskMinDueDate = {MIN_DUE_DATE_JSON};

        function applyTaskDueDateMin() {
            var dueInput = document.getElementById('task_due_date');
            if (!dueInput || !taskMinDueDate) {
                return;
            }
            dueInput.min = taskMinDueDate;
        }

        function setTaskCommentVisible(show) {
            var wrap = document.getElementById('task_comment_wrap');
            var toggle = document.getElementById('task_comment_toggle');
            if (!wrap || !toggle) {
                return;
            }
            wrap.hidden = !show;
            toggle.textContent = show ? taskI18n.hideComment : taskI18n.addComment;
            toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        }

        function showAddTaskModal() {
            document.getElementById('taskModalTitle').textContent = taskI18n.addTask;
            document.getElementById('taskSaveButton').textContent = taskI18n.addTask;
            document.getElementById('task_id').value = '';
            document.getElementById('task_title').value = '';
            document.getElementById('task_description').value = '';
            document.getElementById('task_comment').value = '';
            document.getElementById('task_comment_file').value = '';
            document.getElementById('task_priority').value = 'normal';
            document.getElementById('task_due_date').value = '';
            applyTaskDueDateMin();
            document.getElementById('task_case_id').value = '';
            setTaskCommentVisible(false);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        function showEditTaskModal(taskId, caseId, title, description, priority, dueDate) {
            document.getElementById('taskModalTitle').textContent = taskI18n.editTask;
            document.getElementById('taskSaveButton').textContent = taskI18n.updateTask;
            document.getElementById('task_id').value = taskId;
            document.getElementById('task_case_id').value = String(caseId || '');
            document.getElementById('task_title').value = title || '';
            document.getElementById('task_description').value = description || '';
            document.getElementById('task_comment').value = '';
            document.getElementById('task_comment_file').value = '';
            document.getElementById('task_priority').value = priority || 'normal';
            document.getElementById('task_due_date').value = dueDate || '';
            applyTaskDueDateMin();
            setTaskCommentVisible(false);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        document.getElementById('task_comment_toggle').addEventListener('click', function () {
            var wrap = document.getElementById('task_comment_wrap');
            setTaskCommentVisible(wrap.hidden);
            if (!wrap.hidden) {
                document.getElementById('task_comment').focus();
            }
        });

        {SHOW_TASK_MODAL}
    </script>
</body>
</html>
HTML;

// Replace placeholders
$replacements = [
    '{HTML_LANG}' => lawyer_portal_html_lang(),
    '{PAGE_TITLE}' => htmlspecialchars($pageTitle),
    '{BREADCRUMB_NAVBAR}' => $breadcrumbNavbar,
    '{LBL_SEARCH}' => htmlspecialchars(lawyer_tf('tasks.search_label', 'Search Tasks')),
    '{PH_SEARCH}' => htmlspecialchars(lawyer_tf('tasks.search_placeholder', 'Task title, case or client')),
    '{LBL_DUE}' => htmlspecialchars(lawyer_tf('tasks.due_filter', 'Due')),
    '{LBL_STATUS}' => htmlspecialchars(lawyer_tf('common.status', 'Status')),
    '{LBL_PRIORITY}' => htmlspecialchars(lawyer_tf('common.priority', 'Priority')),
    '{LBL_ACTIONS}' => htmlspecialchars(lawyer_tf('common.actions', 'Actions')),
    '{OPT_DUE_ALL}' => htmlspecialchars(lawyer_tf('tasks.due_all', 'All dates')),
    '{OPT_DUE_OVERDUE}' => htmlspecialchars(lawyer_tf('tasks.due_overdue', 'Overdue')),
    '{OPT_DUE_TODAY}' => htmlspecialchars(lawyer_tf('tasks.due_today', 'Due today')),
    '{OPT_DUE_WEEK}' => htmlspecialchars(lawyer_tf('tasks.due_week', 'Due this week')),
    '{OPT_ALL_STATUSES}' => htmlspecialchars(lawyer_tf('tasks.all_statuses', 'All statuses')),
    '{OPT_ACTIVE}' => htmlspecialchars(lawyer_status_label('active')),
    '{OPT_PENDING}' => htmlspecialchars(lawyer_status_label('pending')),
    '{OPT_UNDER_REVIEW}' => htmlspecialchars(lawyer_status_label('under_review')),
    '{OPT_CLOSED}' => htmlspecialchars(lawyer_status_label('closed')),
    '{OPT_ALL_PRIORITIES}' => htmlspecialchars(lawyer_tf('tasks.all_priorities', 'All priorities')),
    '{OPT_NORMAL}' => htmlspecialchars(lawyer_priority_label('normal')),
    '{OPT_HIGH}' => htmlspecialchars(lawyer_priority_label('high')),
    '{OPT_URGENT}' => htmlspecialchars(lawyer_priority_label('urgent')),
    '{BTN_FILTER}' => htmlspecialchars(lawyer_tf('common.filter', 'Filter')),
    '{BTN_RESET}' => htmlspecialchars(lawyer_tf('common.reset', 'Reset')),
    '{TOTAL_COUNT}' => htmlspecialchars(lawyer_tf('tasks.total_count', 'Total: :count tasks', ['count' => count($tasks)])),
    '{CARD_SUBTITLE}' => htmlspecialchars(lawyer_tf('tasks.card_subtitle', 'Tasks assigned to you across your cases')),
    '{BTN_NEW_TASK}' => htmlspecialchars(lawyer_tf('tasks.new_task', 'New Task')),
    '{LBL_ADD_TASK}' => htmlspecialchars(lawyer_tf('tasks.add_task', 'Add Task')),
    '{LBL_FORM_TITLE}' => htmlspecialchars(lawyer_tf('tasks.form_title', 'Task Title')),
    '{LBL_CASE}' => htmlspecialchars(lawyer_tf('common.case', 'Case')),
    '{LBL_FORM_DUE_DATE}' => htmlspecialchars(lawyer_tf('tasks.form_due_date', 'Due Date')),
    '{LBL_FORM_DESCRIPTION}' => htmlspecialchars(lawyer_tf('tasks.form_description', 'Description')),
    '{PH_FORM_DESC}' => htmlspecialchars(lawyer_tf('tasks.form_desc_placeholder', 'Task description (optional)')),
    '{LBL_FORM_COMMENT}' => htmlspecialchars(lawyer_tf('tasks.form_comment', 'Comment')),
    '{LBL_ADD_COMMENT}' => htmlspecialchars(lawyer_tf('tasks.add_comment', 'Add a comment')),
    '{PH_COMMENT}' => htmlspecialchars(lawyer_tf('tasks.comment_placeholder', 'Your comment')),
    '{LBL_ATTACHMENT}' => htmlspecialchars(lawyer_tf('tasks.attachment_optional', 'Attachment (optional)')),
    '{LBL_ATTACHMENT_HINT}' => htmlspecialchars(lawyer_tf('tasks.attachment_hint', 'PDF, Word, text or image up to 5 MB.')),
    '{LBL_CANCEL}' => htmlspecialchars(lawyer_tf('common.cancel', 'Cancel')),
    '{LBL_SAVE_TASK}' => htmlspecialchars(lawyer_tf('tasks.save_task', 'Save Task')),
    '{TASK_I18N_ADD}' => json_encode(lawyer_tf('tasks.add_task', 'Add Task')),
    '{TASK_I18N_EDIT}' => json_encode(lawyer_tf('tasks.edit_task', 'Edit Task')),
    '{TASK_I18N_SAVE}' => json_encode(lawyer_tf('tasks.save_task', 'Save Task')),
    '{TASK_I18N_UPDATE}' => json_encode(lawyer_tf('tasks.update_task', 'Update Task')),
    '{TASK_I18N_ADD_COMMENT}' => json_encode(lawyer_tf('tasks.add_comment', 'Add a comment')),
    '{TASK_I18N_HIDE_COMMENT}' => json_encode(lawyer_tf('tasks.hide_comment', 'Hide comment')),
    '{ICON_CARD_HEADER}' => $iconCardHeader,
    '{NAVIGATION}' => $navHtml,
    '{MESSAGE}' => $messageHtml,
    '{DUE_ALERTS}' => $dueAlertsHtml,
    '{TASKS_LIST}' => $tasksListHtml,
    '{SEARCH_VALUE}' => htmlspecialchars($search),
    '{TOTAL_TASKS}' => count($tasks),
    '{TASK_CASE_OPTIONS}' => $caseOptions,
    '{TASK_FORM_ID}' => (int) $taskForm['task_id'],
    '{TASK_FORM_TITLE}' => htmlspecialchars($taskForm['task_title']),
    '{TASK_FORM_DESCRIPTION}' => htmlspecialchars($taskForm['task_description']),
    '{TASK_FORM_COMMENT}' => htmlspecialchars($taskForm['task_comment']),
    '{TASK_FORM_DUE_DATE}' => htmlspecialchars($taskForm['due_date']),
    '{MIN_DUE_DATE}' => htmlspecialchars($minDueDate),
    '{MIN_DUE_DATE_JSON}' => json_encode($minDueDate),
    '{TASK_PRIORITY_NORMAL}' => lawyer_normalize_task_priority((string) $taskForm['task_priority']) === 'normal' ? 'selected' : '',
    '{TASK_PRIORITY_HIGH}' => lawyer_normalize_task_priority((string) $taskForm['task_priority']) === 'high' ? 'selected' : '',
    '{TASK_PRIORITY_URGENT}' => lawyer_normalize_task_priority((string) $taskForm['task_priority']) === 'urgent' ? 'selected' : '',
    '{SHOW_TASK_MODAL}' => $showTaskModalOnLoad
        ? 'setTimeout(function(){ setTaskCommentVisible(true); new bootstrap.Modal(document.getElementById("taskModal")).show(); }, 120);'
        : '',
    '{STATUS_ALL}' => $statusFilter === 'all' ? ' selected' : '',
    '{STATUS_ACTIVE}' => $statusFilter === 'active' ? ' selected' : '',
    '{STATUS_PENDING}' => $statusFilter === 'pending' ? ' selected' : '',
    '{STATUS_UNDER_REVIEW}' => $statusFilter === 'under_review' ? ' selected' : '',
    '{STATUS_CLOSED}' => $statusFilter === 'closed' ? ' selected' : '',
    '{PRIORITY_ALL}' => $priorityFilter === 'all' ? ' selected' : '',
    '{PRIORITY_NORMAL}' => $priorityFilter === 'normal' ? ' selected' : '',
    '{PRIORITY_HIGH}' => $priorityFilter === 'high' ? ' selected' : '',
    '{PRIORITY_URGENT}' => $priorityFilter === 'urgent' ? ' selected' : '',
    '{DUE_ALL}' => $dueFilter === 'all' ? ' selected' : '',
    '{DUE_OVERDUE}' => $dueFilter === 'overdue' ? ' selected' : '',
    '{DUE_TODAY}' => $dueFilter === 'today' ? ' selected' : '',
    '{DUE_THIS_WEEK}' => $dueFilter === 'this_week' ? ' selected' : '',
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
echo legalpro_apply_copyright_line($html);
?>
