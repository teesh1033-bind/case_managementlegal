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
$taskForm = [
    'task_id' => 0,
    'case_id' => 0,
    'task_title' => '',
    'task_description' => '',
    'task_priority' => 'medium',
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
    }
} catch (PDOException $e) {
    // If table creation fails, continue anyway - the INSERT might still work if table exists
    error_log("Failed to create tasks table: " . $e->getMessage());
}

try {
    $pdo->query('ALTER TABLE tasks ADD COLUMN task_comment TEXT NULL');
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false && stripos($e->getMessage(), 'duplicate column name') === false) {
        // Column may already exist from manual migration.
    }
}

// Handle task status updates
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $newStatus = isset($_POST['status']) ? $_POST['status'] : '';

    if ($taskId > 0 && in_array($newStatus, ['pending', 'in_progress', 'completed', 'cancelled'])) {
        try {
            // Get current task status for tracking
            $stmt = $pdo->prepare("SELECT status, title FROM tasks WHERE id = ? AND assigned_lawyer_id = ?");
            $stmt->execute([$taskId, $lawyerId]);
            $currentTask = $stmt->fetch();

            if ($currentTask) {
                $oldStatus = $currentTask['status'];

                // Update task status
                if ($newStatus === 'completed') {
                    $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = NOW() WHERE id = ? AND assigned_lawyer_id = ?");
                    $stmt->execute([$newStatus, $taskId, $lawyerId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ? AND assigned_lawyer_id = ?");
                    $stmt->execute([$newStatus, $taskId, $lawyerId]);
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

                    if ($newStatus === 'completed') {
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
    $taskPriority = isset($_POST['task_priority']) ? $_POST['task_priority'] : 'medium';
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

    if ($taskTitle === '' || $caseId <= 0 || !in_array($taskPriority, ['low', 'medium', 'high'], true)) {
        $message = 'Please provide a valid title, case, and priority.';
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
                    $existingStmt = $pdo->prepare("SELECT id, case_id, status, title FROM tasks WHERE id = ? AND assigned_lawyer_id = ?");
                    $existingStmt->execute([$taskId, $lawyerId]);
                    $existingTask = $existingStmt->fetch();

                    if (!$existingTask) {
                        $message = 'Task not found or access denied.';
                        $messageType = 'danger';
                    } else {
                        $updateStmt = $pdo->prepare("
                            UPDATE tasks
                            SET case_id = ?, title = ?, description = ?, priority = ?, due_date = ?, task_comment = ?
                            WHERE id = ? AND assigned_lawyer_id = ?
                        ");
                        $updateStmt->execute([
                            $caseId,
                            $taskTitle,
                            $taskDescription,
                            $taskPriority,
                            $dueDate ?: null,
                            $taskComment !== '' ? $taskComment : null,
                            $taskId,
                            $lawyerId,
                        ]);

                        $message = 'Task updated successfully!';
                        $messageType = 'success';
                        $showTaskModalOnLoad = false;
                        $taskForm = [
                            'task_id' => 0,
                            'case_id' => 0,
                            'task_title' => '',
                            'task_description' => '',
                            'task_priority' => 'medium',
                            'due_date' => '',
                            'task_comment' => '',
                        ];

                        CaseEvents::trackTaskUpdated($caseId, $taskId, $existingTask['status'], $existingTask['status'], $taskTitle);
                    }
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO tasks (case_id, assigned_lawyer_id, title, description, priority, due_date, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, NULL)
                    ");
                    $insertStmt->execute([$caseId, $lawyerId, $taskTitle, $taskDescription, $taskPriority, $dueDate ?: null]);

                    $message = 'Task added successfully!';
                    $messageType = 'success';
                    $showTaskModalOnLoad = false;
                    $taskForm = [
                        'task_id' => 0,
                        'case_id' => 0,
                        'task_title' => '',
                        'task_description' => '',
                        'task_priority' => 'medium',
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
        }
    }
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query to get tasks for this lawyer
$query = "
    SELECT t.*, c.title as case_title, c.id as case_id,
           cl.first_name as client_first_name, cl.last_name as client_last_name
    FROM tasks t
    INNER JOIN cases c ON c.id = t.case_id
    INNER JOIN clients cl ON cl.id = c.client_id
    WHERE t.assigned_lawyer_id = ?
";

$params = [$lawyerId];

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

$query .= " ORDER BY
    CASE t.priority
        WHEN 'high' THEN 1
        WHEN 'medium' THEN 2
        WHEN 'low' THEN 3
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

$iconTaskHeader = legalpro_icon('list-checks');

// Build HTML
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$tasksHtml = '';
if (!empty($tasks)) {
    foreach ($tasks as $task) {
        $statusBadge = legalpro_task_status_badge((string) ($task['status'] ?? ''));
        $priorityBadge = legalpro_task_priority_badge((string) ($task['priority'] ?? 'medium'));

        $dueDate = $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'No due date';
        $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] !== 'completed';
        $taskTitleJs = htmlspecialchars(json_encode($task['title']), ENT_QUOTES, 'UTF-8');
        $taskDescriptionJs = htmlspecialchars(json_encode((string)$task['description']), ENT_QUOTES, 'UTF-8');
        $taskPriorityJs = htmlspecialchars(json_encode($task['priority']), ENT_QUOTES, 'UTF-8');
        $taskDueDateJs = htmlspecialchars(json_encode((string)$task['due_date']), ENT_QUOTES, 'UTF-8');
        $taskCommentJs = htmlspecialchars(json_encode((string)($task['task_comment'] ?? '')), ENT_QUOTES, 'UTF-8');

        $caseNumber = 'C-' . str_pad($task['case_id'], 4, '0', STR_PAD_LEFT);

        $tasksHtml .= '<div class="card mb-3 task-card-themed">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h6 class="mb-1">' . htmlspecialchars($task['title']) . '</h6>
                        <p class="text-sm text-muted mb-2">' . htmlspecialchars($task['case_title']) . ' (' . $caseNumber . ')</p>
                        <p class="text-sm mb-0">Client: ' . htmlspecialchars($task['client_first_name'] . ' ' . $task['client_last_name']) . '</p>';
        if (!empty($task['description'])) {
            $tasksHtml .= '<p class="text-sm mt-2">' . htmlspecialchars($task['description']) . '</p>';
        }
        if (!empty($task['task_comment'])) {
            $tasksHtml .= '<p class="text-sm mt-2 mb-0"><span class="text-muted">Your comment:</span> ' . htmlspecialchars($task['task_comment']) . '</p>';
        }
        $tasksHtml .= '</div>
                    <div class="col-md-3">
                        <div class="d-flex flex-column gap-2">
                            ' . $statusBadge . '
                            ' . $priorityBadge . '
                        </div>
                    </div>
                    <div class="col-md-3">
                        <p class="text-sm mb-2 ' . ($isOverdue ? 'text-danger font-weight-bold' : 'text-muted') . '">Due: ' . $dueDate . '</p>
                        <div class="d-flex align-items-center gap-2 task-actions-row">
                            <button
                                type="button"
                                class="btn btn-sm task-edit-btn mb-0"
                                onclick="showEditTaskModal(' . (int)$task['id'] . ', ' . (int)$task['case_id'] . ', ' . $taskTitleJs . ', ' . $taskDescriptionJs . ', ' . $taskPriorityJs . ', ' . $taskDueDateJs . ', ' . $taskCommentJs . ')"
                            >
                                Edit
                            </button>
                            <form method="POST" action="" class="d-flex align-items-center mb-0">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="task_id" value="' . $task['id'] . '">
                                <select name="status" class="form-select form-select-sm task-status-select mb-0" onchange="this.form.submit()">
                                    <option value="pending" ' . ($task['status'] === 'pending' ? 'selected' : '') . '>Pending</option>
                                    <option value="in_progress" ' . ($task['status'] === 'in_progress' ? 'selected' : '') . '>In Progress</option>
                                    <option value="completed" ' . ($task['status'] === 'completed' ? 'selected' : '') . '>Completed</option>
                                    <option value="cancelled" ' . ($task['status'] === 'cancelled' ? 'selected' : '') . '>Cancelled</option>
                                </select>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }
} else {
    $tasksHtml = '<div class="text-center py-5">
        <i class="ni ni-check-bold text-muted" style="font-size: 4rem;"></i>
        <h4 class="text-muted mt-3">No tasks found</h4>
        <p class="text-muted">You don\'t have any tasks assigned to you yet.</p>
    </div>';
}

$caseOptions = '<option value="">Select case</option>';
foreach ($lawyerCases as $lawyerCase) {
    $selected = ((int)$taskForm['case_id'] === (int)$lawyerCase['id']) ? ' selected' : '';
    $caseOptions .= '<option value="' . (int)$lawyerCase['id'] . '"' . $selected . '>' . htmlspecialchars($lawyerCase['title']) . '</option>';
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
    <title>LegalPro - My Tasks</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <style>
        /* More space between option text and dropdown chevron (in-card filters) */
        .lawyer-tasks-page .task-actions-row .form-select,
        .lawyer-tasks-page #taskModal .form-select {
            padding-left: 0.875rem;
            padding-right: 2.85rem;
            background-position: right 0.85rem center;
        }
        .lawyer-tasks-page .task-card-themed {
            background: var(--lp-task-card-bg, #f4f6fc);
            border: 1px solid var(--lp-task-card-border, #dbe4f7);
        }
        .lawyer-tasks-page .task-card-themed .card-body {
            background: var(--lp-task-card-body-bg, linear-gradient(180deg, #ffffff 0%, #f0f3fa 100%));
        }
        .lawyer-tasks-page .task-edit-btn {
            background: linear-gradient(140deg, #2d3f6f 0%, #4a5fa8 44%, #6f7fd2 100%);
            border: none;
            color: #fff;
        }
        .lawyer-tasks-page .task-edit-btn:hover {
            color: #fff;
            opacity: 0.92;
        }
        .lawyer-tasks-page #taskModal .modal-header {
            background: linear-gradient(140deg, #2d3f6f 0%, #4a5fa8 44%, #6f7fd2 100%);
            color: #fff;
            border-bottom: none;
        }
        .lawyer-tasks-page #taskModal .modal-header .modal-title {
            color: #fff !important;
            font-weight: 700;
        }
        .lawyer-tasks-page #taskModal .modal-header .btn-close {
            filter: invert(1) grayscale(1) brightness(200%);
            opacity: 0.9;
        }
        .lawyer-tasks-page .task-actions-row {
            flex-wrap: wrap;
        }
        .lawyer-tasks-page .task-actions-row .task-edit-btn,
        .lawyer-tasks-page .task-actions-row .task-status-select {
            height: 31px;
            line-height: 1.25;
        }
        .lawyer-tasks-page .task-actions-row .task-status-select {
            min-width: 9.75rem;
            width: auto;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-tasks-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Tasks</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">My Tasks</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            <!-- Filters -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <form method="GET" class="row align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">Search Tasks</label>
                                    <input type="text" class="form-control" name="search" value="{SEARCH_VALUE}" placeholder="Task title, case or client">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status Filter</label>
                                    <select class="form-select" name="status">
                                        <option value="all"{STATUS_ALL_SELECTED}>All Status</option>
                                        <option value="pending"{STATUS_PENDING_SELECTED}>Pending</option>
                                        <option value="in_progress"{STATUS_IN_PROGRESS_SELECTED}>In Progress</option>
                                        <option value="completed"{STATUS_COMPLETED_SELECTED}>Completed</option>
                                        <option value="cancelled"{STATUS_CANCELLED_SELECTED}>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Priority Filter</label>
                                    <select class="form-select" name="priority">
                                        <option value="all"{PRIORITY_ALL_SELECTED}>All Priorities</option>
                                        <option value="high"{PRIORITY_HIGH_SELECTED}>High</option>
                                        <option value="medium"{PRIORITY_MEDIUM_SELECTED}>Medium</option>
                                        <option value="low"{PRIORITY_LOW_SELECTED}>Low</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label d-block invisible">Filter</label>
                                    <button type="submit" class="btn btn-primary w-100 mb-0">Filter</button>
                                </div>
                                <div class="col-md-3 text-end">
                                    <p class="text-sm text-muted mb-0">Total: {TOTAL_TASKS} tasks</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tasks list -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center">
                                    <div class="lp-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary me-3">{ICON_TASK_HEADER}</div>
                                    <div>
                                        <h6 class="mb-0">My Tasks</h6>
                                        <p class="text-xs text-muted mb-0">Tasks assigned to you</p>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-primary mb-0" type="button" onclick="showAddTaskModal()">
                                    <i class="ni ni-fat-add me-1"></i>Add Task
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            {MESSAGE}
                            {TASKS_HTML}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskModalTitle">Add Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_task">
                        <input type="hidden" name="task_id" id="task_id" value="{TASK_FORM_ID}">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="task_title" id="task_title" value="{TASK_FORM_TITLE}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Case <span class="text-danger">*</span></label>
                                <select class="form-control" name="case_id" id="task_case_id" required>
                                    {TASK_CASE_OPTIONS}
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-control" name="task_priority" id="task_priority">
                                    <option value="low" {TASK_PRIORITY_LOW}>Low</option>
                                    <option value="medium" {TASK_PRIORITY_MEDIUM}>Medium</option>
                                    <option value="high" {TASK_PRIORITY_HIGH}>High</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date" id="task_due_date" value="{TASK_FORM_DUE_DATE}">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="task_description" id="task_description" rows="3" placeholder="Task description (optional)">{TASK_FORM_DESCRIPTION}</textarea>
                        </div>
                        <div class="mb-0" id="task_comment_wrap" style="display: none;">
                            <label class="form-label">Your comment</label>
                            <textarea class="form-control" name="task_comment" id="task_comment" rows="3" placeholder="Add a note for the admin about this task (optional)">{TASK_FORM_COMMENT}</textarea>
                            <small class="text-muted">Visible to administrators when they review this task.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="taskSaveButton">Save Task</button>
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
        function showAddTaskModal() {
            document.getElementById('taskModalTitle').textContent = 'Add Task';
            document.getElementById('taskSaveButton').textContent = 'Add Task';
            document.getElementById('task_id').value = '';
            document.getElementById('task_title').value = '';
            document.getElementById('task_description').value = '';
            document.getElementById('task_comment').value = '';
            document.getElementById('task_priority').value = 'medium';
            document.getElementById('task_due_date').value = '';
            document.getElementById('task_case_id').value = '';
            document.getElementById('task_comment_wrap').style.display = 'none';
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        function showEditTaskModal(taskId, caseId, title, description, priority, dueDate, taskComment) {
            document.getElementById('taskModalTitle').textContent = 'Edit Task';
            document.getElementById('taskSaveButton').textContent = 'Update Task';
            document.getElementById('task_id').value = taskId;
            document.getElementById('task_case_id').value = String(caseId || '');
            document.getElementById('task_title').value = title || '';
            document.getElementById('task_description').value = description || '';
            document.getElementById('task_comment').value = taskComment || '';
            document.getElementById('task_priority').value = priority || 'medium';
            document.getElementById('task_due_date').value = dueDate || '';
            document.getElementById('task_comment_wrap').style.display = '';
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        {SHOW_TASK_MODAL}
    </script>
</body>
</html>
HTML;

// Replace placeholders
$replacements = [
    '{NAVIGATION}' => $navHtml,
    '{MESSAGE}' => $messageHtml,
    '{TASKS_HTML}' => $tasksHtml,
    '{TASK_CASE_OPTIONS}' => $caseOptions,
    '{TASK_FORM_ID}' => (int)$taskForm['task_id'],
    '{TASK_FORM_TITLE}' => htmlspecialchars($taskForm['task_title']),
    '{TASK_FORM_DESCRIPTION}' => htmlspecialchars($taskForm['task_description']),
    '{TASK_FORM_COMMENT}' => htmlspecialchars($taskForm['task_comment']),
    '{TASK_FORM_DUE_DATE}' => htmlspecialchars($taskForm['due_date']),
    '{TASK_PRIORITY_LOW}' => $taskForm['task_priority'] === 'low' ? 'selected' : '',
    '{TASK_PRIORITY_MEDIUM}' => $taskForm['task_priority'] === 'medium' ? 'selected' : '',
    '{TASK_PRIORITY_HIGH}' => $taskForm['task_priority'] === 'high' ? 'selected' : '',
    '{SHOW_TASK_MODAL}' => $showTaskModalOnLoad ? 'setTimeout(function(){ new bootstrap.Modal(document.getElementById("taskModal")).show(); }, 120);' : '',
    '{LAWYER_NAME}' => htmlspecialchars($lawyerName),
    '{SEARCH_VALUE}' => htmlspecialchars($search),
    '{TOTAL_TASKS}' => count($tasks),
    '{ICON_TASK_HEADER}' => $iconTaskHeader,
    '{STATUS_ALL_SELECTED}' => $statusFilter === 'all' ? ' selected' : '',
    '{STATUS_PENDING_SELECTED}' => $statusFilter === 'pending' ? ' selected' : '',
    '{STATUS_IN_PROGRESS_SELECTED}' => $statusFilter === 'in_progress' ? ' selected' : '',
    '{STATUS_COMPLETED_SELECTED}' => $statusFilter === 'completed' ? ' selected' : '',
    '{STATUS_CANCELLED_SELECTED}' => $statusFilter === 'cancelled' ? ' selected' : '',
    '{PRIORITY_ALL_SELECTED}' => $priorityFilter === 'all' ? ' selected' : '',
    '{PRIORITY_HIGH_SELECTED}' => $priorityFilter === 'high' ? ' selected' : '',
    '{PRIORITY_MEDIUM_SELECTED}' => $priorityFilter === 'medium' ? ' selected' : '',
    '{PRIORITY_LOW_SELECTED}' => $priorityFilter === 'low' ? ' selected' : '',
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
echo $html;
?>
