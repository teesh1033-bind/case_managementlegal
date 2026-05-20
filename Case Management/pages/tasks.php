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

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : 'all';

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

// Count tasks by status
$statusCounts = [
    'all' => 0,
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($tasks as $task) {
    $statusCounts['all']++;
    $statusCounts[$task['status']]++;
}

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
        $statusBadge = '';
        $statusClass = '';
        switch ($task['status']) {
            case 'pending':
                $statusBadge = 'Pending';
                $statusClass = 'bg-warning';
                break;
            case 'in_progress':
                $statusBadge = 'In Progress';
                $statusClass = 'bg-info';
                break;
            case 'completed':
                $statusBadge = 'Completed';
                $statusClass = 'bg-success';
                break;
            case 'cancelled':
                $statusBadge = 'Cancelled';
                $statusClass = 'bg-secondary';
                break;
        }

        $priorityBadge = '';
        $priorityClass = '';
        switch ($task['priority']) {
            case 'low':
                $priorityBadge = 'Low';
                $priorityClass = 'bg-light text-dark';
                break;
            case 'medium':
                $priorityBadge = 'Medium';
                $priorityClass = 'bg-warning';
                break;
            case 'high':
                $priorityBadge = 'High';
                $priorityClass = 'bg-danger';
                break;
        }

        $dueDate = $task['due_date'] ? date('M j, Y', strtotime($task['due_date'])) : 'No due date';
        $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time() && $task['status'] !== 'completed';

        $caseNumber = 'C-' . str_pad($task['case_id'], 4, '0', STR_PAD_LEFT);

        $tasksHtml .= '<div class="card mb-3">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h6 class="mb-1">' . htmlspecialchars($task['title']) . '</h6>
                        <p class="text-sm text-muted mb-2">' . htmlspecialchars($task['case_title']) . ' (' . $caseNumber . ')</p>
                        <p class="text-sm mb-0">Client: ' . htmlspecialchars($task['client_first_name'] . ' ' . $task['client_last_name']) . '</p>';
        if (!empty($task['description'])) {
            $tasksHtml .= '<p class="text-sm mt-2">' . htmlspecialchars($task['description']) . '</p>';
        }
        $tasksHtml .= '</div>
                    <div class="col-md-3">
                        <div class="d-flex flex-column gap-2">
                            <span class="badge ' . $statusClass . '">' . $statusBadge . '</span>
                            <span class="badge ' . $priorityClass . '">' . $priorityBadge . '</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <p class="text-sm mb-2 ' . ($isOverdue ? 'text-danger font-weight-bold' : 'text-muted') . '">Due: ' . $dueDate . '</p>
                        <form method="POST" action="" class="d-inline">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="task_id" value="' . $task['id'] . '">
                            <select name="status" class="form-select form-select-sm task-status-select d-inline-block w-auto" onchange="this.form.submit()">
                                <option value="pending" ' . ($task['status'] === 'pending' ? 'selected' : '') . '>Pending</option>
                                <option value="in_progress" ' . ($task['status'] === 'in_progress' ? 'selected' : '') . '>In Progress</option>
                                <option value="completed" ' . ($task['status'] === 'completed' ? 'selected' : '') . '>Completed</option>
                                <option value="cancelled" ' . ($task['status'] === 'cancelled' ? 'selected' : '') . '>Cancelled</option>
                            </select>
                        </form>
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

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Argon Dashboard - My Tasks</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <style>
        /* More space between option text and dropdown chevron */
        .lawyer-tasks-page select.form-select {
            padding-left: 0.875rem;
            padding-right: 2.85rem;
            background-position: right 0.85rem center;
        }
        .lawyer-tasks-page select.form-select-sm {
            padding-left: 0.75rem;
            padding-right: 2.65rem;
            background-position: right 0.65rem center;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal lawyer-tasks-page">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
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
                    <a class="nav-link active" href="tasks.php">
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
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="../pages/lawyer-dashboard.html">Dashboard</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Tasks</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">My Tasks</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search cases or tasks…" value="" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>
                </div>
            </div>
        </nav>
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6>My Tasks</h6>
                                <div class="d-flex gap-2">
                                    <select class="form-select form-select-sm" onchange="filterByStatus(this.value)">
                                        <option value="all" {STATUS_ALL_SELECTED}>All Status ({STATUS_ALL_COUNT})</option>
                                        <option value="pending" {STATUS_PENDING_SELECTED}>Pending ({STATUS_PENDING_COUNT})</option>
                                        <option value="in_progress" {STATUS_IN_PROGRESS_SELECTED}>In Progress ({STATUS_IN_PROGRESS_COUNT})</option>
                                        <option value="completed" {STATUS_COMPLETED_SELECTED}>Completed ({STATUS_COMPLETED_COUNT})</option>
                                        <option value="cancelled" {STATUS_CANCELLED_SELECTED}>Cancelled ({STATUS_CANCELLED_COUNT})</option>
                                    </select>
                                    <select class="form-select form-select-sm" onchange="filterByPriority(this.value)">
                                        <option value="all" {PRIORITY_ALL_SELECTED}>All Priorities</option>
                                        <option value="high" {PRIORITY_HIGH_SELECTED}>High Priority</option>
                                        <option value="medium" {PRIORITY_MEDIUM_SELECTED}>Medium Priority</option>
                                        <option value="low" {PRIORITY_LOW_SELECTED}>Low Priority</option>
                                    </select>
                                </div>
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
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        function filterByStatus(status) {
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }

        function filterByPriority(priority) {
            const url = new URL(window.location);
            url.searchParams.set('priority', priority);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
HTML;

// Replace placeholders
$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{TASKS_HTML}' => $tasksHtml,
    '{LAWYER_NAME}' => htmlspecialchars($lawyerName),
    '{STATUS_ALL_COUNT}' => $statusCounts['all'],
    '{STATUS_PENDING_COUNT}' => $statusCounts['pending'],
    '{STATUS_IN_PROGRESS_COUNT}' => $statusCounts['in_progress'],
    '{STATUS_COMPLETED_COUNT}' => $statusCounts['completed'],
    '{STATUS_CANCELLED_COUNT}' => $statusCounts['cancelled'],
    '{STATUS_ALL_SELECTED}' => $statusFilter === 'all' ? 'selected' : '',
    '{STATUS_PENDING_SELECTED}' => $statusFilter === 'pending' ? 'selected' : '',
    '{STATUS_IN_PROGRESS_SELECTED}' => $statusFilter === 'in_progress' ? 'selected' : '',
    '{STATUS_COMPLETED_SELECTED}' => $statusFilter === 'completed' ? 'selected' : '',
    '{STATUS_CANCELLED_SELECTED}' => $statusFilter === 'cancelled' ? 'selected' : '',
    '{PRIORITY_ALL_SELECTED}' => $priorityFilter === 'all' ? 'selected' : '',
    '{PRIORITY_HIGH_SELECTED}' => $priorityFilter === 'high' ? 'selected' : '',
    '{PRIORITY_MEDIUM_SELECTED}' => $priorityFilter === 'medium' ? 'selected' : '',
    '{PRIORITY_LOW_SELECTED}' => $priorityFilter === 'low' ? 'selected' : '',
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
echo $html;
?>
