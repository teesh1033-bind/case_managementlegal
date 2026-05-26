<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';

$message = '';
$messageType = '';
$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$caseId) {
    header('Location: tables.php?msg=' . urlencode('Invalid case ID') . '&type=danger');
    exit;
}

// Load existing case data
$caseData = [];
$existingServices = [];
$existingStages = [];

try {
    // Load case data
    $stmt = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch();

    if (!$case) {
        header('Location: tables.php?msg=' . urlencode('Case not found') . '&type=danger');
        exit;
    }

    // Load services
    $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at");
    $stmt->execute([$caseId]);
    $existingServices = $stmt->fetchAll();

    // Load stages
    $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number");
    $stmt->execute([$caseId]);
    $existingStages = $stmt->fetchAll();

    // Load assigned lawyers
    $stmt = $pdo->prepare("SELECT lawyer_id FROM case_lawyers WHERE case_id = ? ORDER BY is_primary DESC, assigned_at ASC");
    $stmt->execute([$caseId]);
    $assignedLawyersResult = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $case['assigned_lawyers'] = $assignedLawyersResult;

} catch (PDOException $e) {
    $message = 'Error loading case: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

// Load tasks for this case
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';

    if ($formType === 'update_case') {
        // Update basic case information
        $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $lawyerIds = isset($_POST['lawyer_ids']) ? $_POST['lawyer_ids'] : [];
        $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : 'open';
        $priority = isset($_POST['priority']) ? $_POST['priority'] : 'Normal';
        $category = isset($_POST['category']) ? $_POST['category'] : 'Civil';
        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : '';
        $expectedCompletion = isset($_POST['expected_completion']) ? $_POST['expected_completion'] : '';

        // cases.user_id = client's portal user (not lawyer — lawyers use case_lawyers)
        $clientUserId = null;
        $stmt = $pdo->prepare("SELECT user_id FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $clientRow = $stmt->fetch();
        if ($clientRow && !empty($clientRow['user_id'])) {
            $clientUserId = (int) $clientRow['user_id'];
        }

        if (empty($title) || empty($clientId)) {
            $message = 'Case title and client are required.';
            $messageType = 'danger';
        } else {
            try {
                // Get old case data for tracking
                $oldCaseData = $case;

                $stmt = $pdo->prepare("
                    UPDATE cases SET
                        title = ?, client_id = ?, user_id = ?, description = ?,
                        status = ?, priority = ?, category = ?, start_date = ?, expected_completion = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $clientId, $clientUserId, $description, $status, $priority, $category, $startDate ? $startDate : null, $expectedCompletion ? $expectedCompletion : null, $caseId]);

                // Get old lawyer assignments for tracking
                $oldLawyers = isset($case['assigned_lawyers']) ? $case['assigned_lawyers'] : [];

                // Update lawyer assignments
                $pdo->prepare("DELETE FROM case_lawyers WHERE case_id = ?")->execute([$caseId]);
                if (!empty($lawyerIds)) {
                    $stmt = $pdo->prepare("INSERT INTO case_lawyers (case_id, lawyer_id, is_primary) VALUES (?, ?, ?)");
                    $isFirst = true;
                    foreach ($lawyerIds as $lawyerId) {
                        $stmt->execute([$caseId, $lawyerId, $isFirst ? 1 : 0]);
                        $isFirst = false;
                    }
                }

                // Track case field changes
                $newCaseData = [
                    'title' => $title,
                    'description' => $description,
                    'status' => $status,
                    'priority' => $priority,
                    'category' => $category,
                    'start_date' => $startDate ?: null,
                    'expected_completion' => $expectedCompletion ?: null
                ];

                CaseEvents::trackCaseUpdate($caseId, $oldCaseData, $newCaseData);

                // Track lawyer assignment changes
                $newLawyers = $lawyerIds ?: [];
                $addedLawyers = array_diff($newLawyers, $oldLawyers);
                $removedLawyers = array_diff($oldLawyers, $newLawyers);

                foreach ($addedLawyers as $lawyerId) {
                    try {
                        $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM lawyers WHERE id = ?");
                        $stmt->execute([$lawyerId]);
                        $lawyerData = $stmt->fetch();
                        if ($lawyerData) {
                            CaseEvents::trackLawyerAssigned($caseId, $lawyerData);
                        }
                    } catch (PDOException $e) {
                        // Continue without logging lawyer assignment
                    }
                }

                foreach ($removedLawyers as $lawyerId) {
                    try {
                        $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM lawyers WHERE id = ?");
                        $stmt->execute([$lawyerId]);
                        $lawyerData = $stmt->fetch();
                        if ($lawyerData) {
                            CaseEvents::trackLawyerUnassigned($caseId, $lawyerData);
                        }
                    } catch (PDOException $e) {
                        // Continue without logging lawyer unassignment
                    }
                }

                $message = 'Case updated successfully.';
                $messageType = 'success';

                // Reload case data
                $stmt = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
                $stmt->execute([$caseId]);
                $case = $stmt->fetch();

            } catch (PDOException $e) {
                $message = 'Error updating case: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'add_service') {
        // Add new service
        $serviceName = trim(isset($_POST['service_name']) ? $_POST['service_name'] : '');
        $servicePrice = isset($_POST['service_price']) ? (float)$_POST['service_price'] : 0;

        if (empty($serviceName)) {
            $message = 'Service name is required.';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO case_services (case_id, service_name, price) VALUES (?, ?, ?)");
                $stmt->execute([$caseId, $serviceName, $servicePrice]);

                // Update case total fees
                $stmt = $pdo->prepare("UPDATE cases SET estimated_fees = (SELECT COALESCE(SUM(price), 0) FROM case_services WHERE case_id = ?) WHERE id = ?");
                $stmt->execute([$caseId, $caseId]);

                // Track service addition
                CaseEvents::trackServiceAdded($caseId, [
                    'service_name' => $serviceName,
                    'price' => $servicePrice
                ]);

                $message = 'Service added successfully.';
                $messageType = 'success';

                // Reload services
                $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at");
                $stmt->execute([$caseId]);
                $existingServices = $stmt->fetchAll();

            } catch (PDOException $e) {
                $message = 'Error adding service: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'delete_service') {
        $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;

        if ($serviceId) {
            try {
                // Get service data before deletion for tracking
                $stmt = $pdo->prepare("SELECT service_name, price FROM case_services WHERE id = ? AND case_id = ?");
                $stmt->execute([$serviceId, $caseId]);
                $serviceData = $stmt->fetch();

                $stmt = $pdo->prepare("DELETE FROM case_services WHERE id = ? AND case_id = ?");
                $stmt->execute([$serviceId, $caseId]);

                // Update case total fees
                $stmt = $pdo->prepare("UPDATE cases SET estimated_fees = (SELECT COALESCE(SUM(price), 0) FROM case_services WHERE case_id = ?) WHERE id = ?");
                $stmt->execute([$caseId, $caseId]);

                // Track service deletion
                if ($serviceData) {
                    CaseEvents::trackServiceDeleted($caseId, $serviceData);
                }

                $message = 'Service deleted successfully.';
                $messageType = 'success';

                // Reload services
                $stmt = $pdo->prepare("SELECT * FROM case_services WHERE case_id = ? ORDER BY created_at");
                $stmt->execute([$caseId]);
                $existingServices = $stmt->fetchAll();

            } catch (PDOException $e) {
                $message = 'Error deleting service: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'save_stage') {
        $stageId = isset($_POST['stage_id']) ? (int)$_POST['stage_id'] : 0;
        $stageNumber = isset($_POST['stage_number']) ? (int)$_POST['stage_number'] : 0;
        $title = trim(isset($_POST['stage_title']) ? $_POST['stage_title'] : '');
        $description = trim(isset($_POST['stage_description']) ? $_POST['stage_description'] : '');
        $result = trim(isset($_POST['stage_result']) ? $_POST['stage_result'] : '');
        $startDate = isset($_POST['stage_start_date']) ? $_POST['stage_start_date'] : '';
        $expectedEndDate = isset($_POST['stage_expected_end_date']) ? $_POST['stage_expected_end_date'] : '';
        $actualEndDate = isset($_POST['stage_actual_end_date']) ? $_POST['stage_actual_end_date'] : '';

        if (empty($title) || empty($stageNumber)) {
            $message = 'Stage title and number are required.';
            $messageType = 'danger';
        } else {
            // Handle file upload
            $filePath = null;
            if (isset($_FILES['stage_file']) && $_FILES['stage_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/case_stages/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $_FILES['stage_file']['name']);
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['stage_file']['tmp_name'], $targetPath)) {
                    $filePath = 'uploads/case_stages/' . $fileName;
                }
            }

            try {
                if ($stageId) {
                    // Update existing stage
                    $stmt = $pdo->prepare("
                        UPDATE case_stages SET
                            title = ?, description = ?, result = ?, file_path = ?,
                            start_date = ?, expected_end_date = ?, actual_end_date = ?
                        WHERE id = ? AND case_id = ?
                    ");
                    $stmt->execute([$title, $description, $result, $filePath, $startDate ? $startDate : null, $expectedEndDate ? $expectedEndDate : null, $actualEndDate ? $actualEndDate : null, $stageId, $caseId]);
                    $msg = 'Stage updated successfully.';
                } else {
                    // Create new stage
                    $stmt = $pdo->prepare("
                        INSERT INTO case_stages (case_id, stage_number, title, description, result, file_path, start_date, expected_end_date, actual_end_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$caseId, $stageNumber, $title, $description, $result, $filePath, $startDate ? $startDate : null, $expectedEndDate ? $expectedEndDate : null, $actualEndDate ? $actualEndDate : null]);
                    $msg = 'Stage added successfully.';
                }

                $message = $msg;
                $messageType = 'success';

                // Reload stages
                $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number");
                $stmt->execute([$caseId]);
                $existingStages = $stmt->fetchAll();

            } catch (PDOException $e) {
                $message = 'Error saving stage: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'delete_stage') {
        $stageId = isset($_POST['stage_id']) ? (int)$_POST['stage_id'] : 0;

        if ($stageId) {
            try {
                $stmt = $pdo->prepare("DELETE FROM case_stages WHERE id = ? AND case_id = ?");
                $stmt->execute([$stageId, $caseId]);

                $message = 'Stage deleted successfully.';
                $messageType = 'success';

                // Reload stages
                $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number");
                $stmt->execute([$caseId]);
                $existingStages = $stmt->fetchAll();

            } catch (PDOException $e) {
                $message = 'Error deleting stage: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'add_task') {
        $taskTitle = trim(isset($_POST['task_title']) ? $_POST['task_title'] : '');
        $assignedLawyerId = isset($_POST['assigned_lawyer_id']) ? (int)$_POST['assigned_lawyer_id'] : 0;
        $taskPriority = isset($_POST['task_priority']) ? $_POST['task_priority'] : 'medium';
        $dueDate = trim(isset($_POST['due_date']) ? $_POST['due_date'] : '');
        $taskDescription = trim(isset($_POST['task_description']) ? $_POST['task_description'] : '');

        if (!empty($taskTitle) && $assignedLawyerId > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO tasks (case_id, assigned_lawyer_id, title, description, priority, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, NULL)");
                $stmt->execute([$caseId, $assignedLawyerId, $taskTitle, $taskDescription, $taskPriority, $dueDate ?: null]);

                $message = 'Task added successfully!';
                $messageType = 'success';

                // Track task creation
                CaseEvents::trackTaskCreated($caseId, [
                    'title' => $taskTitle,
                    'description' => $taskDescription
                ]);

                // Reload tasks
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
                $message = 'Error adding task: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Task title and assigned lawyer are required.';
            $messageType = 'danger';
        }
    } elseif ($formType === 'delete_task') {
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

                    // Reload tasks
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
}

// Load dropdown data
$clients = [];
$lawyers = [];

try {
    $clients = $pdo->query("
        SELECT c.id, c.first_name, c.last_name, c.email, c.user_id, u.username
        FROM clients c
        LEFT JOIN users u ON u.id = c.user_id
        ORDER BY c.first_name, c.last_name
    ")->fetchAll();
    $lawyers = $pdo->query("SELECT l.id, l.first_name, l.last_name FROM lawyers l WHERE l.is_active = 1 ORDER BY l.last_name, l.first_name")->fetchAll();


    // Debug: Check if lawyers exist
    if (empty($lawyers)) {
        // Check if there are any lawyers at all (even inactive ones)
        $totalLawyers = $pdo->query("SELECT COUNT(*) FROM lawyers")->fetchColumn();
        if ($totalLawyers == 0) {
            $message = 'No lawyers found in the system. <a href="lawyers.php" class="alert-link">Click here to add lawyers</a> before creating cases. <a href="debug_lawyers.php" target="_blank" class="alert-link">Check lawyer status</a>.';
            $messageType = 'warning';
        } else {
            $message = 'No active lawyers found. Please activate lawyers or <a href="lawyers.php" class="alert-link">add new ones</a> before assigning them to cases.';
            $messageType = 'warning';
        }
    }
} catch (PDOException $e) {
    // Continue with empty arrays
    $lawyers = [];
    $clients = [];
}

// Calculate next stage number
$nextStageNumber = 1;
if (!empty($existingStages)) {
    $maxStage = max(array_column($existingStages, 'stage_number'));
    $nextStageNumber = $maxStage + 1;
}

// Build HTML
$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);

// Build client options
$clientOptions = '<option value="">Select client</option>';
foreach ($clients as $client) {
    $fullName = trim($client['first_name'] . ' ' . $client['last_name']);
    $selected = ((int)$case['client_id'] === (int)$client['id']) ? ' selected' : '';
<<<<<<< HEAD
    $clientOptions .= '<option value="' . (int)$client['id'] . '"' . $selected . '>' . htmlspecialchars($fullName) . '</option>';
=======
    $hint = '';
    if (!empty($client['username'])) {
        $hint = ' — login: ' . $client['username'];
    } elseif (!empty($client['email'])) {
        $hint = ' — ' . $client['email'];
    }
    if (empty($client['user_id'])) {
        $hint .= ' (no client portal account)';
    }
    $clientOptions .= '<option value="' . (int)$client['id'] . '"' . $selected . '>' . htmlspecialchars($fullName . $hint) . '</option>';
>>>>>>> ac2cdddeafa742e6db4c37a5d32f4040c35f85fd
}

// Build lawyer checkboxes
$lawyerCheckboxes = '';
$assignedLawyers = isset($case['assigned_lawyers']) ? $case['assigned_lawyers'] : [];

if (empty($lawyers)) {
    $lawyerCheckboxes = '<div class="text-muted">No lawyers available</div>';
} else {
    foreach ($lawyers as $lawyer) {
        $checked = in_array($lawyer['id'], $assignedLawyers) ? ' checked' : '';
        $lawyerCheckboxes .= '
        <div class="form-check">
            <input class="form-check-input lawyer-checkbox" type="checkbox" name="lawyer_ids[]" value="' . (int)$lawyer['id'] . '" id="lawyer_' . (int)$lawyer['id'] . '"' . $checked . '>
            <label class="form-check-label" for="lawyer_' . (int)$lawyer['id'] . '">
                ' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '
            </label>
        </div>';
    }
}

// Build lawyer options for task modal
$lawyerOptions = '';
if (!empty($lawyers)) {
    foreach ($lawyers as $lawyer) {
        $lawyerOptions .= '<option value="' . (int)$lawyer['id'] . '">' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '</option>';
    }
}

// Build services HTML
$servicesHtml = '';
$totalServices = 0;
foreach ($existingServices as $service) {
    $totalServices += (float)$service['price'];
    $servicesHtml .= '
    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
        <div>
            <span class="text-sm">' . htmlspecialchars($service['service_name']) . '</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-gradient-primary">' . formatCurrency($service['price']) . '</span>
            <form method="post" class="d-inline" onsubmit="return confirm(\'Delete this service?\');">
                <input type="hidden" name="form_type" value="delete_service">
                <input type="hidden" name="service_id" value="' . (int)$service['id'] . '">
<<<<<<< HEAD
                <button class="btn btn-sm btn-danger mb-0" type="submit" title="Delete Service">Delete</button>
=======
                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete Service">
                    <i class="ni ni-fat-remove"></i>
                </button>
>>>>>>> ac2cdddeafa742e6db4c37a5d32f4040c35f85fd
            </form>
        </div>
    </div>';
}

if (empty($existingServices)) {
    $servicesHtml = '<div class="text-center text-muted py-3">No services added yet</div>';
}

// Build tasks HTML
$tasksHtml = '';
if (!empty($tasks)) {
    $tasksHtml .= '<div class="table-responsive">
        <table class="table align-items-center">
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
            <td><span class="badge badge-sm ' . $statusClass . '">' . $statusBadge . '</span></td>
            <td><span class="badge badge-sm ' . $priorityClass . '">' . $priorityBadge . '</span></td>
            <td class="text-sm">' . $dueDate . '</td>
            <td>
                <form method="post" style="display: inline;" onsubmit="return confirm(\'Are you sure you want to delete this task? This will remove it from the assigned lawyer\'s task list.\')">
                    <input type="hidden" name="form_type" value="delete_task">
                    <input type="hidden" name="task_id" value="' . $task['id'] . '">
<<<<<<< HEAD
                    <button type="submit" class="btn btn-sm btn-danger mb-0">Delete</button>
=======
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="ni ni-fat-remove"></i>
                    </button>
>>>>>>> ac2cdddeafa742e6db4c37a5d32f4040c35f85fd
                </form>
            </td>
        </tr>';
    }

    $tasksHtml .= '</tbody></table></div>';
} else {
    $tasksHtml = '<div class="text-center py-4">
        <i class="ni ni-check-bold text-muted" style="font-size: 3rem;"></i>
        <p class="text-muted mt-2">No tasks assigned to this case yet.</p>
    </div>';
}

// Build stages HTML
$stagesHtml = '';
foreach ($existingStages as $stage) {
    $stagesHtml .= '
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Stage ' . (int)$stage['stage_number'] . ': ' . htmlspecialchars($stage['title']) . '</h6>
            <div class="d-flex gap-2">
<<<<<<< HEAD
                <button class="btn btn-sm btn-dark mb-0" onclick="editStage(' . (int)$stage['id'] . ', ' . (int)$stage['stage_number'] . ', \'' . addslashes($stage['title']) . '\', \'' . addslashes($stage['description']) . '\', \'' . addslashes($stage['result']) . '\', \'' . (!empty($stage['start_date']) ? $stage['start_date'] : '') . '\', \'' . (!empty($stage['expected_end_date']) ? $stage['expected_end_date'] : '') . '\', \'' . (!empty($stage['actual_end_date']) ? $stage['actual_end_date'] : '') . '\')">Edit</button>
                <form method="post" class="d-inline" onsubmit="return confirm(\'Delete this stage?\');">
                    <input type="hidden" name="form_type" value="delete_stage">
                    <input type="hidden" name="stage_id" value="' . (int)$stage['id'] . '">
                    <button class="btn btn-sm btn-danger mb-0" type="submit">Delete</button>
=======
                <button class="btn btn-sm btn-outline-primary" onclick="editStage(' . (int)$stage['id'] . ', ' . (int)$stage['stage_number'] . ', \'' . addslashes($stage['title']) . '\', \'' . addslashes($stage['description']) . '\', \'' . addslashes($stage['result']) . '\', \'' . (!empty($stage['start_date']) ? $stage['start_date'] : '') . '\', \'' . (!empty($stage['expected_end_date']) ? $stage['expected_end_date'] : '') . '\', \'' . (!empty($stage['actual_end_date']) ? $stage['actual_end_date'] : '') . '\')">Edit</button>
                <form method="post" class="d-inline" onsubmit="return confirm(\'Delete this stage?\');">
                    <input type="hidden" name="form_type" value="delete_stage">
                    <input type="hidden" name="stage_id" value="' . (int)$stage['id'] . '">
                    <button class="btn btn-sm btn-outline-danger" type="submit">
                        <i class="ni ni-fat-remove"></i>
                    </button>
>>>>>>> ac2cdddeafa742e6db4c37a5d32f4040c35f85fd
                </form>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Description:</strong></p>
                    <p class="text-sm">' . nl2br(htmlspecialchars(!empty($stage['description']) ? $stage['description'] : 'No description')) . '</p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Result:</strong></p>
                    <p class="text-sm">' . nl2br(htmlspecialchars(!empty($stage['result']) ? $stage['result'] : 'No result')) . '</p>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-4">
                    <p class="mb-1"><strong>Start Date:</strong></p>
                    <p class="text-sm">' . ($stage['start_date'] ? date('M j, Y', strtotime($stage['start_date'])) : 'Not set') . '</p>
                </div>
                <div class="col-md-4">
                    <p class="mb-1"><strong>Expected End:</strong></p>
                    <p class="text-sm">' . ($stage['expected_end_date'] ? date('M j, Y', strtotime($stage['expected_end_date'])) : 'Not set') . '</p>
                </div>
                <div class="col-md-4">
                    <p class="mb-1"><strong>Actual End:</strong></p>
                    <p class="text-sm">' . ($stage['actual_end_date'] ? date('M j, Y', strtotime($stage['actual_end_date'])) : 'Not set') . '</p>
                </div>
            </div>
<<<<<<< HEAD
            ' . ($stage['file_path'] ? '<div class="mt-3"><a href="../' . htmlspecialchars($stage['file_path']) . '" target="_blank" class="btn btn-sm btn-primary mb-0">View Attached File</a></div>' : '') . '
=======
            ' . ($stage['file_path'] ? '<div class="mt-3"><a href="../' . htmlspecialchars($stage['file_path']) . '" target="_blank" class="btn btn-sm btn-outline-primary">View Attached File</a></div>' : '') . '
>>>>>>> ac2cdddeafa742e6db4c37a5d32f4040c35f85fd
        </div>
    </div>';
}

if (empty($existingStages)) {
    $stagesHtml = '<div class="text-center text-muted py-4">No stages added yet</div>';
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro Case Manager - Edit Case</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
    </aside>
    <main class="main-content position-relative border-radius-lg ">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="tables.php">Cases</a></li>
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="case-view.php?id={CASE_ID}">Case View</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Edit Case</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">{CASE_NUMBER} · Edit Case</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Case Information -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Case Information</h6>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="form_type" value="update_case">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Case Title <span class="text-danger">*</span></label>
                                        <input class="form-control" type="text" name="title" value="{CASE_TITLE}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Client <span class="text-danger">*</span></label>
                                        <select class="form-control" name="client_id" required>
                                            {CLIENT_OPTIONS}
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Assigned Lawyers</label>
                                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                                            {LAWYER_CHECKBOXES}
                                        </div>
                                        <small class="text-muted">Select one or more lawyers to assign to this case</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Status</label>
                                        <select class="form-control" name="status">
                                            <option value="open" {STATUS_OPEN}>Open</option>
                                            <option value="in_progress" {STATUS_IN_PROGRESS}>In Progress</option>
                                            <option value="closed" {STATUS_CLOSED}>Closed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Priority</label>
                                        <select class="form-control" name="priority">
                                            <option value="Normal" {PRIORITY_NORMAL}>Normal</option>
                                            <option value="High" {PRIORITY_HIGH}>High</option>
                                            <option value="Urgent" {PRIORITY_URGENT}>Urgent</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Category</label>
                                        <select class="form-control" name="category">
                                            <option value="Civil" {CATEGORY_CIVIL}>Civil</option>
                                            <option value="Criminal" {CATEGORY_CRIMINAL}>Criminal</option>
                                            <option value="Corporate" {CATEGORY_CORPORATE}>Corporate</option>
                                            <option value="Family" {CATEGORY_FAMILY}>Family</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Total Fees</label>
                                        <input class="form-control" type="text" value="{TOTAL_FEES}" readonly>
                                        <small class="text-muted">Auto-calculated from services</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Start Date</label>
                                        <input class="form-control" type="date" name="start_date" value="{START_DATE}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Expected Completion</label>
                                        <input class="form-control" type="date" name="expected_completion" value="{EXPECTED_COMPLETION}">
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Description</label>
                                        <textarea class="form-control" rows="4" name="description">{DESCRIPTION}</textarea>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <a href="case-view.php?id={CASE_ID}" class="btn btn-secondary">Back to View</a>
                                    <button class="btn btn-dark" type="submit">Update Case</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Services Management -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Services & Pricing</h6>
                                <button class="btn btn-sm btn-primary" onclick="showAddServiceModal()">Add Service</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="services-list">
                                {SERVICES_HTML}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Management -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Task Management</h6>
                                <button class="btn btn-sm btn-success" onclick="showAddTaskModal()">Add Task</button>
                            </div>
                        </div>
                        <div class="card-body">
                            {TASKS_HTML}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary of Events/Stages -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Summary of Events</h6>
                                <button class="btn btn-sm btn-success" onclick="showAddStageModal()">Add Stage</button>
                            </div>
                        </div>
                        <div class="card-body">
                            {STAGES_HTML}
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

    <!-- Add Service Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="form_type" value="add_service">
                        <div class="mb-3">
                            <label class="form-label">Service Name</label>
                            <input type="text" class="form-control" name="service_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Price</label>
                            <input type="number" class="form-control" name="service_price" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Task Modal -->
    <div class="modal fade" id="taskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskModalTitle">Add Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="form_type" value="add_task">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="task_title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign to Lawyer <span class="text-danger">*</span></label>
                                <select class="form-control" name="assigned_lawyer_id" required>
                                    <option value="">Select a lawyer</option>
                                    {LAWYER_OPTIONS}
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-control" name="task_priority">
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="task_description" rows="3" placeholder="Task description (optional)"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add/Edit Stage Modal -->
    <div class="modal fade" id="stageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="stageModalTitle">Add Stage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="form_type" value="save_stage">
                        <input type="hidden" name="stage_id" id="stage_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Stage Number</label>
                                <input type="number" class="form-control" name="stage_number" id="stage_number" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="stage_title" id="stage_title" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="stage_description" id="stage_description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Result</label>
                            <textarea class="form-control" name="stage_result" id="stage_result" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="stage_start_date" id="stage_start_date">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Expected End Date</label>
                                <input type="date" class="form-control" name="stage_expected_end_date" id="stage_expected_end_date">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Actual End Date</label>
                                <input type="date" class="form-control" name="stage_actual_end_date" id="stage_actual_end_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Attach File</label>
                            <input type="file" class="form-control" name="stage_file" id="stage_file" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                            <small class="text-muted">Optional: PDF, Word, TXT, or image files</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Stage</button>
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
        // No limit on lawyer selection - multiple lawyers can be selected

        function showAddServiceModal() {
            document.getElementById('addServiceModal').querySelector('form').reset();
            new bootstrap.Modal(document.getElementById('addServiceModal')).show();
        }

        function showAddTaskModal() {
            document.getElementById('taskModalTitle').textContent = 'Add Task';
            document.getElementById('taskModal').querySelector('form').reset();
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        function showAddStageModal() {
            document.getElementById('stageModalTitle').textContent = 'Add Stage';
            document.getElementById('stage_id').value = '';
            document.getElementById('stage_number').value = '{NEXT_STAGE_NUMBER}';
            document.getElementById('stageModal').querySelector('form').reset();
            document.getElementById('stage_number').value = '{NEXT_STAGE_NUMBER}';
            new bootstrap.Modal(document.getElementById('stageModal')).show();
        }

        function editStage(id, number, title, description, result, startDate, expectedEndDate, actualEndDate) {
            document.getElementById('stageModalTitle').textContent = 'Edit Stage';
            document.getElementById('stage_id').value = id;
            document.getElementById('stage_number').value = number;
            document.getElementById('stage_title').value = title;
            document.getElementById('stage_description').value = description || '';
            document.getElementById('stage_result').value = result || '';
            document.getElementById('stage_start_date').value = startDate || '';
            document.getElementById('stage_expected_end_date').value = expectedEndDate || '';
            document.getElementById('stage_actual_end_date').value = actualEndDate || '';
            new bootstrap.Modal(document.getElementById('stageModal')).show();
        }
    </script>
</body>
</html>
HTML;

// Template replacements
$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{CASE_ID}' => $caseId,
    '{CASE_NUMBER}' => $caseNumber,
    '{CASE_TITLE}' => htmlspecialchars($case['title']),
    '{CLIENT_OPTIONS}' => $clientOptions,
    '{LAWYER_CHECKBOXES}' => $lawyerCheckboxes,
    '{LAWYER_OPTIONS}' => $lawyerOptions,
    '{STATUS_OPEN}' => ($case['status'] === 'open') ? 'selected' : '',
    '{STATUS_IN_PROGRESS}' => ($case['status'] === 'in_progress') ? 'selected' : '',
    '{STATUS_CLOSED}' => ($case['status'] === 'closed') ? 'selected' : '',
    '{PRIORITY_NORMAL}' => ($case['priority'] === 'Normal') ? 'selected' : '',
    '{PRIORITY_HIGH}' => ($case['priority'] === 'High') ? 'selected' : '',
    '{PRIORITY_URGENT}' => ($case['priority'] === 'Urgent') ? 'selected' : '',
    '{CATEGORY_CIVIL}' => ($case['category'] === 'Civil') ? 'selected' : '',
    '{CATEGORY_CRIMINAL}' => ($case['category'] === 'Criminal') ? 'selected' : '',
    '{CATEGORY_CORPORATE}' => ($case['category'] === 'Corporate') ? 'selected' : '',
    '{CATEGORY_FAMILY}' => ($case['category'] === 'Family') ? 'selected' : '',
    '{TOTAL_FEES}' => formatCurrency(isset($case['estimated_fees']) ? $case['estimated_fees'] : 0),
    '{START_DATE}' => isset($case['start_date']) ? $case['start_date'] : '',
    '{EXPECTED_COMPLETION}' => isset($case['expected_completion']) ? $case['expected_completion'] : '',
    '{DESCRIPTION}' => htmlspecialchars(isset($case['description']) ? $case['description'] : ''),
    '{SERVICES_HTML}' => $servicesHtml,
    '{TASKS_HTML}' => $tasksHtml,
    '{STAGES_HTML}' => $stagesHtml,
    '{NEXT_STAGE_NUMBER}' => $nextStageNumber,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo $html;
?>
