<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/task_helpers.php';

function case_edit_pdo_error_message($prefix, PDOException $e) {
    $detail = $e->getMessage();
    if ((int)$e->getCode() === 23000 || strpos($detail, '1062') !== false) {
        if (stripos($detail, 'unique_case_stage') !== false) {
            return $prefix . 'A stage with this number already exists for this case. Please choose a different stage number.';
        }
    }
    return $prefix . $detail;
}

function case_edit_normalize_lawyer_ids($lawyerIds) {
    $normalized = [];
    foreach ((array)$lawyerIds as $lawyerId) {
        $lawyerId = (int)$lawyerId;
        if ($lawyerId > 0) {
            $normalized[$lawyerId] = $lawyerId;
        }
    }
    return array_values($normalized);
}

function case_edit_ensure_task_lawyers_table(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS task_lawyers (
            task_id INT NOT NULL,
            lawyer_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (task_id, lawyer_id),
            INDEX idx_task_lawyers_lawyer_id (lawyer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT IGNORE INTO task_lawyers (task_id, lawyer_id)
        SELECT id, assigned_lawyer_id
        FROM tasks
        WHERE assigned_lawyer_id IS NOT NULL AND assigned_lawyer_id > 0
    ");
}

function case_edit_set_task_lawyers(PDO $pdo, $taskId, array $lawyerIds) {
    $lawyerIds = case_edit_normalize_lawyer_ids($lawyerIds);
    $pdo->prepare("DELETE FROM task_lawyers WHERE task_id = ?")->execute([(int)$taskId]);

    if (!empty($lawyerIds)) {
        $insertStmt = $pdo->prepare("INSERT INTO task_lawyers (task_id, lawyer_id) VALUES (?, ?)");
        foreach ($lawyerIds as $lawyerId) {
            $insertStmt->execute([(int)$taskId, $lawyerId]);
        }
    }

    $primaryLawyerId = !empty($lawyerIds) ? $lawyerIds[0] : null;
    $pdo->prepare("UPDATE tasks SET assigned_lawyer_id = ? WHERE id = ?")->execute([$primaryLawyerId, (int)$taskId]);
}

function case_edit_get_task_lawyer_ids(PDO $pdo, $taskId) {
    $stmt = $pdo->prepare("SELECT lawyer_id FROM task_lawyers WHERE task_id = ? ORDER BY lawyer_id");
    $stmt->execute([(int)$taskId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function case_edit_load_tasks(PDO $pdo, $caseId) {
    $stmt = $pdo->prepare("
        SELECT t.*,
               GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) ORDER BY l.last_name, l.first_name SEPARATOR ', ') as assigned_lawyer_names,
               u.username as created_by_username
        FROM tasks t
        LEFT JOIN task_lawyers tl ON tl.task_id = t.id
        LEFT JOIN lawyers l ON l.id = tl.lawyer_id
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.case_id = ?
        GROUP BY t.id
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([(int)$caseId]);
    return $stmt->fetchAll();
}

try {
    case_edit_ensure_task_lawyers_table($pdo);
    ensure_task_support_schema($pdo);
} catch (PDOException $e) {
    // Keep the page usable; task saves will show a detailed error if the table is unavailable.
}

$message = '';
$messageType = '';
$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$allowedCaseStatuses = ['active', 'pending', 'under_review', 'closed'];

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
    $message = 'Error loading case: ' . $e->getMessage();
    $messageType = 'danger';
}

// Load tasks for this case
$tasks = [];
try {
    $tasks = case_edit_load_tasks($pdo, $caseId);
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
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : 'active';
        if ($status === 'in_progress') {
            $status = 'active';
        }
        if ($status === 'open') {
            $status = 'active';
        }
        if (!in_array($status, $allowedCaseStatuses, true)) {
            $status = 'active';
        }
        $priority = isset($_POST['priority']) ? $_POST['priority'] : 'Normal';
        $category = resolveSubmittedCaseCategory('Civil');
        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : '';
        $expectedCompletion = isset($_POST['expected_completion']) ? $_POST['expected_completion'] : '';
        $hasInvalidExpectedDate = !empty($startDate) && !empty($expectedCompletion)
            && strtotime($expectedCompletion) <= strtotime($startDate);

        // cases.user_id = client's portal user (not lawyer — lawyers use case_lawyers)
        $clientUserId = null;
        $stmt = $pdo->prepare("SELECT user_id FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $clientRow = $stmt->fetch();
        if ($clientRow && !empty($clientRow['user_id'])) {
            $clientUserId = (int) $clientRow['user_id'];
        }

        if ($hasInvalidExpectedDate) {
            $message = 'Expected completion date must be later than the start date.';
            $messageType = 'danger';
            $case['title'] = $title;
            $case['client_id'] = $clientId;
            $case['description'] = $description;
            $case['status'] = $status;
            $case['priority'] = $priority;
            $case['category'] = $category;
            $case['start_date'] = $startDate ?: null;
            $case['expected_completion'] = $expectedCompletion ?: null;
        } elseif (empty($title) || empty($clientId)) {
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
                $message = 'Error updating case: ' . $e->getMessage();
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
                $message = 'Error adding service: ' . $e->getMessage();
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
                $message = 'Error deleting service: ' . $e->getMessage();
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
        $hasInvalidStageExpectedDate = !empty($startDate) && !empty($expectedEndDate)
            && strtotime($expectedEndDate) <= strtotime($startDate);
        $hasInvalidStageActualDate = !empty($startDate) && !empty($actualEndDate)
            && strtotime($actualEndDate) < strtotime($startDate);

        if ($hasInvalidStageExpectedDate) {
            $message = 'Stage expected end date must be later than stage start date.';
            $messageType = 'danger';
        } elseif ($hasInvalidStageActualDate) {
            $message = 'Stage actual end date cannot be earlier than stage start date.';
            $messageType = 'danger';
        } elseif (empty($title) || empty($stageNumber)) {
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
                $dupStmt = $pdo->prepare("SELECT id FROM case_stages WHERE case_id = ? AND stage_number = ? AND id != ? LIMIT 1");
                $dupStmt->execute([$caseId, $stageNumber, $stageId]);
                if ($dupStmt->fetch()) {
                    $message = 'A stage with number ' . $stageNumber . ' already exists for this case. Please choose a different stage number.';
                    $messageType = 'danger';
                } else {
                    if ($stageId) {
                        if ($filePath) {
                            $stmt = $pdo->prepare("
                                UPDATE case_stages SET
                                    stage_number = ?, title = ?, description = ?, result = ?, file_path = ?,
                                    start_date = ?, expected_end_date = ?, actual_end_date = ?
                                WHERE id = ? AND case_id = ?
                            ");
                            $stmt->execute([$stageNumber, $title, $description, $result, $filePath, $startDate ? $startDate : null, $expectedEndDate ? $expectedEndDate : null, $actualEndDate ? $actualEndDate : null, $stageId, $caseId]);
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE case_stages SET
                                    stage_number = ?, title = ?, description = ?, result = ?,
                                    start_date = ?, expected_end_date = ?, actual_end_date = ?
                                WHERE id = ? AND case_id = ?
                            ");
                            $stmt->execute([$stageNumber, $title, $description, $result, $startDate ? $startDate : null, $expectedEndDate ? $expectedEndDate : null, $actualEndDate ? $actualEndDate : null, $stageId, $caseId]);
                        }
                        $msg = 'Stage updated successfully.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO case_stages (case_id, stage_number, title, description, result, file_path, start_date, expected_end_date, actual_end_date)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$caseId, $stageNumber, $title, $description, $result, $filePath, $startDate ? $startDate : null, $expectedEndDate ? $expectedEndDate : null, $actualEndDate ? $actualEndDate : null]);
                        $msg = 'Stage added successfully.';
                    }

                    $message = $msg;
                    $messageType = 'success';

                    $stmt = $pdo->prepare("SELECT * FROM case_stages WHERE case_id = ? ORDER BY stage_number");
                    $stmt->execute([$caseId]);
                    $existingStages = $stmt->fetchAll();
                }
            } catch (PDOException $e) {
                $message = case_edit_pdo_error_message('Error saving stage: ', $e);
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
                $message = 'Error deleting stage: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif ($formType === 'save_task') {
        $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
        $taskTitle = trim(isset($_POST['task_title']) ? $_POST['task_title'] : '');
        $assignedLawyerIds = case_edit_normalize_lawyer_ids(isset($_POST['assigned_lawyer_ids']) ? $_POST['assigned_lawyer_ids'] : []);
        $taskPriority = isset($_POST['task_priority']) ? $_POST['task_priority'] : 'medium';
        $dueDate = trim(isset($_POST['due_date']) ? $_POST['due_date'] : '');
        $taskDescription = trim(isset($_POST['task_description']) ? $_POST['task_description'] : '');
        $taskStatus = isset($_POST['task_status']) ? $_POST['task_status'] : 'pending';

        if (!in_array($taskPriority, ['low', 'medium', 'high'], true)) {
            $taskPriority = 'medium';
        }
        if (!in_array($taskStatus, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
            $taskStatus = 'pending';
        }

        if ($taskTitle === '' || empty($assignedLawyerIds)) {
            $message = 'Task title and at least one assigned lawyer are required.';
            $messageType = 'danger';
        } else {
            try {
                if ($taskId > 0) {
                    $stmt = $pdo->prepare("SELECT status, title FROM tasks WHERE id = ? AND case_id = ?");
                    $stmt->execute([$taskId, $caseId]);
                    $existingTask = $stmt->fetch();

                    if (!$existingTask) {
                        $message = 'Task not found.';
                        $messageType = 'danger';
                    } else {
                        $oldStatus = $existingTask['status'];
                        if ($taskStatus === 'completed') {
                            $stmt = $pdo->prepare("
                                UPDATE tasks
                                SET title = ?, description = ?, priority = ?, due_date = ?, status = ?, completed_at = NOW()
                                WHERE id = ? AND case_id = ?
                            ");
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE tasks
                                SET title = ?, description = ?, priority = ?, due_date = ?, status = ?, completed_at = NULL
                                WHERE id = ? AND case_id = ?
                            ");
                        }
                        $stmt->execute([$taskTitle, $taskDescription, $taskPriority, $dueDate ?: null, $taskStatus, $taskId, $caseId]);
                        case_edit_set_task_lawyers($pdo, $taskId, $assignedLawyerIds);

                        $message = 'Task updated successfully!';
                        $messageType = 'success';

                        CaseEvents::trackTaskUpdated($caseId, $taskId, $oldStatus, $taskStatus, $taskTitle);
                        if ($taskStatus === 'completed' && $oldStatus !== 'completed') {
                            CaseEvents::trackTaskCompleted($caseId, ['title' => $taskTitle, 'status' => $taskStatus]);
                        }
                    }
                } else {
                    $primaryLawyerId = $assignedLawyerIds[0];
                    $stmt = $pdo->prepare("INSERT INTO tasks (case_id, assigned_lawyer_id, title, description, priority, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, NULL)");
                    $stmt->execute([$caseId, $primaryLawyerId, $taskTitle, $taskDescription, $taskPriority, $dueDate ?: null]);
                    $newTaskId = (int)$pdo->lastInsertId();
                    case_edit_set_task_lawyers($pdo, $newTaskId, $assignedLawyerIds);

                    $message = 'Task added successfully!';
                    $messageType = 'success';

                    CaseEvents::trackTaskCreated($caseId, [
                        'title' => $taskTitle,
                        'description' => $taskDescription
                    ]);
                }

                if ($messageType === 'success') {
                    $tasks = case_edit_load_tasks($pdo, $caseId);
                }
            } catch (PDOException $e) {
                $message = 'Error saving task: ' . $e->getMessage();
                $messageType = 'danger';
            }
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
                    $pdo->prepare("DELETE FROM task_lawyers WHERE task_id = ?")->execute([$taskId]);
                    $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND case_id = ?");
                    $stmt->execute([$taskId, $caseId]);

                    $message = 'Task "' . $taskData['title'] . '" deleted successfully!';
                    $messageType = 'success';

                    CaseEvents::trackTaskDeleted($caseId, $taskData['title']);
                    $tasks = case_edit_load_tasks($pdo, $caseId);
                } else {
                    $message = 'Task not found or access denied.';
                    $messageType = 'danger';
                }
            } catch (PDOException $e) {
                $message = 'Error deleting task: ' . $e->getMessage();
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
    $clientOptions .= '<option value="' . (int)$client['id'] . '"' . $selected . '>' . htmlspecialchars($fullName) . '</option>';
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

// Build lawyer checkboxes for task modal
$taskLawyerCheckboxes = '';
if (!empty($lawyers)) {
    foreach ($lawyers as $lawyer) {
        $taskLawyerCheckboxes .= '
        <div class="form-check">
            <input class="form-check-input task-lawyer-checkbox" type="checkbox" name="assigned_lawyer_ids[]" value="' . (int)$lawyer['id'] . '" id="task_lawyer_' . (int)$lawyer['id'] . '">
            <label class="form-check-label" for="task_lawyer_' . (int)$lawyer['id'] . '">
                ' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '
            </label>
        </div>';
    }
} else {
    $taskLawyerCheckboxes = '<p class="text-sm text-muted mb-0">No active lawyers available.</p>';
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
            <form method="post" class="d-inline mb-0" onsubmit="return confirm(\'Delete this service?\');">
                <input type="hidden" name="form_type" value="delete_service">
                <input type="hidden" name="service_id" value="' . (int)$service['id'] . '">
                <button class="btn btn-sm btn-danger mb-0" type="submit">Delete</button>
            </form>
        </div>
    </div>';
}

if (empty($existingServices)) {
    $servicesHtml = '<div class="text-center text-muted py-3">No services added yet</div>';
}

$offeredServices = getOfferedServices();
if (empty($offeredServices)) {
    $offeredServiceFieldHtml = '<input type="text" class="form-control" name="service_name" required placeholder="Enter service name">';
    $offeredServiceFieldHint = '<small class="text-muted"><a href="settings.php">Add services in Settings</a> to enable quick-select here.</small>';
} else {
    $offeredServiceOptions = '<option value="">Select service...</option>';
    foreach ($offeredServices as $serviceName) {
        $offeredServiceOptions .= '<option value="' . htmlspecialchars($serviceName) . '">' . htmlspecialchars($serviceName) . '</option>';
    }
    $offeredServiceOptions .= '<option value="__other__">Other...</option>';
    $offeredServiceFieldHtml = '
        <select class="form-select" id="case_service_select">' . $offeredServiceOptions . '</select>
        <input type="text" class="form-control mt-2 d-none" id="case_service_custom" placeholder="Enter custom service name">
        <input type="hidden" name="service_name" id="case_service_name" required>';
    $offeredServiceFieldHint = '<small class="text-muted">Choose from your firm\'s offered services, or pick Other for a custom name.</small>';
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
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Lawyer Comment</th>
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
        $lawyerName = htmlspecialchars(!empty($task['assigned_lawyer_names']) ? $task['assigned_lawyer_names'] : 'Unassigned');
        $taskLawyerIdsJs = htmlspecialchars(json_encode(case_edit_get_task_lawyer_ids($pdo, (int)$task['id'])), ENT_QUOTES, 'UTF-8');
        $taskTitleJs = htmlspecialchars(json_encode($task['title']), ENT_QUOTES, 'UTF-8');
        $taskDescriptionJs = htmlspecialchars(json_encode((string)$task['description']), ENT_QUOTES, 'UTF-8');
        $taskPriorityJs = htmlspecialchars(json_encode($task['priority']), ENT_QUOTES, 'UTF-8');
        $taskStatusJs = htmlspecialchars(json_encode($task['status']), ENT_QUOTES, 'UTF-8');
        $taskDueDateJs = htmlspecialchars(json_encode((string)$task['due_date']), ENT_QUOTES, 'UTF-8');
        $taskCommentJs = htmlspecialchars(json_encode((string)($task['task_comment'] ?? '')), ENT_QUOTES, 'UTF-8');
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
            <td><span class="badge badge-sm ' . $statusClass . '">' . $statusBadge . '</span></td>
            <td><span class="badge badge-sm ' . $priorityClass . '">' . $priorityBadge . '</span></td>
            <td class="text-sm">' . $dueDate . '</td>
            <td>' . $taskCommentCell . '</td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <button
                        type="button"
                        class="btn btn-sm btn-primary mb-0"
                        onclick="showEditTaskModal(' . (int)$task['id'] . ', ' . $taskLawyerIdsJs . ', ' . $taskTitleJs . ', ' . $taskDescriptionJs . ', ' . $taskPriorityJs . ', ' . $taskStatusJs . ', ' . $taskDueDateJs . ', ' . $taskCommentJs . ')"
                    >Edit</button>
                    <form method="post" class="mb-0" onsubmit="return confirm(\'Are you sure you want to delete this task? This will remove it from the assigned lawyer\\\'s task list.\')">
                        <input type="hidden" name="form_type" value="delete_task">
                        <input type="hidden" name="task_id" value="' . $task['id'] . '">
                        <button type="submit" class="btn btn-sm btn-danger mb-0">Delete</button>
                    </form>
                </div>
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
    $stageFileJs = htmlspecialchars(json_encode((string)(isset($stage['file_path']) ? $stage['file_path'] : '')), ENT_QUOTES, 'UTF-8');
    $stagesHtml .= '
    <div class="card mb-3 border">
        <div class="card-header py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <h6 class="mb-0">Stage ' . (int)$stage['stage_number'] . ': ' . htmlspecialchars($stage['title']) . '</h6>
                    <p class="text-xs text-muted mb-0 mt-1">Event timeline summary</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary mb-0" onclick="editStage(' . (int)$stage['id'] . ', ' . (int)$stage['stage_number'] . ', \'' . addslashes($stage['title']) . '\', \'' . addslashes($stage['description']) . '\', \'' . addslashes($stage['result']) . '\', \'' . (!empty($stage['start_date']) ? $stage['start_date'] : '') . '\', \'' . (!empty($stage['expected_end_date']) ? $stage['expected_end_date'] : '') . '\', \'' . (!empty($stage['actual_end_date']) ? $stage['actual_end_date'] : '') . '\', ' . $stageFileJs . ')">Edit</button>
                    <form method="post" class="mb-0" onsubmit="return confirm(\'Delete this stage?\');">
                        <input type="hidden" name="form_type" value="delete_stage">
                        <input type="hidden" name="stage_id" value="' . (int)$stage['id'] . '">
                        <button class="btn btn-sm btn-danger mb-0" type="submit">Delete</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-lg-7">
                    <div class="mb-3">
                        <p class="text-xs text-uppercase text-muted mb-1">Description</p>
                        <p class="text-sm mb-0">' . nl2br(htmlspecialchars(!empty($stage['description']) ? $stage['description'] : 'No description')) . '</p>
                    </div>
                    <div>
                        <p class="text-xs text-uppercase text-muted mb-1">Result</p>
                        <p class="text-sm mb-0">' . nl2br(htmlspecialchars(!empty($stage['result']) ? $stage['result'] : 'No result')) . '</p>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="border rounded px-3 py-2 h-100">
                                <p class="text-xs text-uppercase text-muted mb-1">Start Date</p>
                                <p class="text-sm mb-0">' . ($stage['start_date'] ? date('M j, Y', strtotime($stage['start_date'])) : 'Not set') . '</p>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="border rounded px-3 py-2 h-100">
                                <p class="text-xs text-uppercase text-muted mb-1">Expected End</p>
                                <p class="text-sm mb-0">' . ($stage['expected_end_date'] ? date('M j, Y', strtotime($stage['expected_end_date'])) : 'Not set') . '</p>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="border rounded px-3 py-2 h-100">
                                <p class="text-xs text-uppercase text-muted mb-1">Actual End</p>
                                <p class="text-sm mb-0">' . ($stage['actual_end_date'] ? date('M j, Y', strtotime($stage['actual_end_date'])) : 'Not set') . '</p>
                            </div>
                        </div>
                    </div>
                    ' . ($stage['file_path'] ? '<div class="mt-3"><a href="../' . htmlspecialchars($stage['file_path']) . '" target="_blank" class="btn btn-sm btn-outline-primary w-100">View Attached File</a></div>' : '') . '
                </div>
            </div>
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
    <link href="../assets/css/case-detail-tabs.css?v=4" rel="stylesheet" />
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
                                        <label class="form-control-label text-sm font-weight-bold">Client <span class="text-danger">*</span></label>
                                        <select class="form-control" name="client_id" required>
                                            {CLIENT_OPTIONS}
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Case Title <span class="text-danger">*</span></label>
                                        <input class="form-control" type="text" name="title" value="{CASE_TITLE}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Status</label>
                                        <select class="form-control" name="status">
                                            <option value="active" {STATUS_ACTIVE}>Active</option>
                                            <option value="pending" {STATUS_PENDING}>Pending</option>
                                            <option value="under_review" {STATUS_UNDER_REVIEW}>Under Review</option>
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
                                        {CATEGORY_FIELD}
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Total Fees</label>
                                        <input class="form-control" type="text" value="{TOTAL_FEES}" readonly>
                                        <small class="text-muted">Auto-calculated from services</small>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Assigned Lawyers</label>
                                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                                            {LAWYER_CHECKBOXES}
                                        </div>
                                        <small class="text-muted">Select one or more lawyers to assign to this case</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Start Date</label>
                                        <input class="form-control" id="case_start_date" type="date" name="start_date" value="{START_DATE}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-control-label text-sm font-weight-bold">Expected Completion</label>
                                        <input class="form-control" id="case_expected_completion" type="date" name="expected_completion" value="{EXPECTED_COMPLETION}">
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
                                {COPYRIGHT_LINE}
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
                            <label class="form-label">Service</label>
                            {OFFERED_SERVICE_FIELD}
                            <div class="mt-1">{OFFERED_SERVICE_FIELD_HINT}</div>
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
                <form method="post" id="taskForm">
                    <div class="modal-body">
                        <input type="hidden" name="form_type" value="save_task">
                        <input type="hidden" name="task_id" id="task_id" value="">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="task_title" id="task_title" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assign to Lawyer(s) <span class="text-danger">*</span></label>
                                <div class="border rounded p-2" style="max-height: 160px; overflow-y: auto;">
                                    {TASK_LAWYER_CHECKBOXES}
                                </div>
                                <small class="text-muted">Select one or more lawyers for this task.</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-control" name="task_priority" id="task_priority">
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3" id="task_status_wrap" style="display: none;">
                                <label class="form-label">Status</label>
                                <select class="form-control" name="task_status" id="task_status">
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date" id="task_due_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="task_description" id="task_description" rows="3" placeholder="Task description (optional)"></textarea>
                        </div>
                        <div class="mb-0" id="task_comment_admin_wrap" style="display: none;">
                            <label class="form-label">Commentaire avocat</label>
                            <div id="task_comment_admin"></div>
                            <small class="text-muted">Ajouté par l'avocat lors de la modification de la tâche (lecture seule).</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="taskSubmitBtn">Add Task</button>
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
                            <div id="stage_current_file" class="text-sm text-primary mt-2" style="display: none;"></div>
                            <small class="text-muted d-block mt-1">Optional: PDF, Word, TXT, or image files. Choose a new file to replace the current attachment.</small>
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
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        // No limit on lawyer selection - multiple lawyers can be selected

        function showAddServiceModal() {
            document.getElementById('addServiceModal').querySelector('form').reset();
            new bootstrap.Modal(document.getElementById('addServiceModal')).show();
        }

        function setTaskLawyerSelections(lawyerIds) {
            var selected = {};
            (lawyerIds || []).forEach(function(id) {
                selected[String(id)] = true;
            });
            document.querySelectorAll('.task-lawyer-checkbox').forEach(function(checkbox) {
                checkbox.checked = !!selected[checkbox.value];
            });
        }

        function renderTaskCommentAdminHtml(comment) {
            var text = String(comment || '').trim();
            if (!text) {
                return '<span class="text-muted">—</span>';
            }
            var escaped = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/\n/g, '<br>');
            return '<div class="admin-task-comment"><span class="admin-task-comment__label">Commentaire avocat</span><p class="admin-task-comment__text mb-0">' + escaped + '</p></div>';
        }

        function showAddTaskModal() {
            document.getElementById('taskModalTitle').textContent = 'Add Task';
            document.getElementById('taskSubmitBtn').textContent = 'Add Task';
            document.getElementById('task_id').value = '';
            document.getElementById('task_status_wrap').style.display = 'none';
            document.getElementById('task_comment_admin_wrap').style.display = 'none';
            document.getElementById('taskForm').reset();
            document.getElementById('task_id').value = '';
            document.getElementById('task_priority').value = 'medium';
            setTaskLawyerSelections([]);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        function showEditTaskModal(taskId, lawyerIds, title, description, priority, status, dueDate, taskComment) {
            document.getElementById('taskModalTitle').textContent = 'Edit Task';
            document.getElementById('taskSubmitBtn').textContent = 'Update Task';
            document.getElementById('task_id').value = taskId;
            setTaskLawyerSelections(lawyerIds || []);
            document.getElementById('task_title').value = title || '';
            document.getElementById('task_description').value = description || '';
            document.getElementById('task_priority').value = priority || 'medium';
            document.getElementById('task_status').value = status || 'pending';
            document.getElementById('task_due_date').value = dueDate || '';
            document.getElementById('task_status_wrap').style.display = '';
            document.getElementById('task_comment_admin_wrap').style.display = '';
            document.getElementById('task_comment_admin').innerHTML = renderTaskCommentAdminHtml(taskComment);
            new bootstrap.Modal(document.getElementById('taskModal')).show();
        }

        function stageFileNameFromPath(filePath) {
            if (!filePath) {
                return '';
            }
            var parts = String(filePath).replace(/\\/g, '/').split('/');
            var fileName = parts[parts.length - 1] || filePath;
            return fileName.replace(/^\d+_/, '');
        }

        function setStageFileDisplay(filePath, isNewSelection) {
            var el = document.getElementById('stage_current_file');
            if (!el) {
                return;
            }
            var fileName = stageFileNameFromPath(filePath);
            if (!fileName) {
                el.textContent = '';
                el.style.display = 'none';
                return;
            }
            el.textContent = (isNewSelection ? 'Selected file: ' : 'Current file: ') + fileName;
            el.style.display = 'block';
        }

        function showAddStageModal() {
            document.getElementById('stageModalTitle').textContent = 'Add Stage';
            document.getElementById('stage_id').value = '';
            document.getElementById('stage_number').value = '{NEXT_STAGE_NUMBER}';
            document.getElementById('stageModal').querySelector('form').reset();
            document.getElementById('stage_number').value = '{NEXT_STAGE_NUMBER}';
            setStageFileDisplay('', false);
            new bootstrap.Modal(document.getElementById('stageModal')).show();
        }

        function editStage(id, number, title, description, result, startDate, expectedEndDate, actualEndDate, filePath) {
            document.getElementById('stageModalTitle').textContent = 'Edit Stage';
            document.getElementById('stage_id').value = id;
            document.getElementById('stage_number').value = number;
            document.getElementById('stage_title').value = title;
            document.getElementById('stage_description').value = description || '';
            document.getElementById('stage_result').value = result || '';
            document.getElementById('stage_start_date').value = startDate || '';
            document.getElementById('stage_expected_end_date').value = expectedEndDate || '';
            document.getElementById('stage_actual_end_date').value = actualEndDate || '';
            document.getElementById('stage_file').value = '';
            setStageFileDisplay(filePath || '', false);
            new bootstrap.Modal(document.getElementById('stageModal')).show();
        }

        document.addEventListener('DOMContentLoaded', function() {
            var caseStartInput = document.getElementById('case_start_date');
            var caseExpectedInput = document.getElementById('case_expected_completion');
            var caseUpdateForm = document.querySelector('form input[name="form_type"][value="update_case"]')?.closest('form');
            var stageStartInput = document.getElementById('stage_start_date');
            var stageExpectedInput = document.getElementById('stage_expected_end_date');
            var stageActualInput = document.getElementById('stage_actual_end_date');
            var stageForm = document.querySelector('#stageModal form');

            function syncCaseExpectedMin() {
                if (!caseStartInput || !caseExpectedInput) {
                    return;
                }
                if (!caseStartInput.value) {
                    caseExpectedInput.removeAttribute('min');
                    caseExpectedInput.setCustomValidity('');
                    return;
                }
                var minDate = new Date(caseStartInput.value + 'T00:00:00');
                minDate.setDate(minDate.getDate() + 1);
                caseExpectedInput.setAttribute('min', minDate.toISOString().slice(0, 10));
                if (caseExpectedInput.value && caseExpectedInput.value <= caseStartInput.value) {
                    caseExpectedInput.setCustomValidity('Expected completion date must be later than start date.');
                } else {
                    caseExpectedInput.setCustomValidity('');
                }
            }

            if (caseStartInput && caseExpectedInput) {
                caseStartInput.addEventListener('change', syncCaseExpectedMin);
                caseExpectedInput.addEventListener('input', syncCaseExpectedMin);
                syncCaseExpectedMin();
            }
            if (caseUpdateForm && caseExpectedInput) {
                caseUpdateForm.addEventListener('submit', function(event) {
                    syncCaseExpectedMin();
                    if (!caseExpectedInput.checkValidity()) {
                        event.preventDefault();
                        caseExpectedInput.reportValidity();
                    }
                });
            }

            function syncStageExpectedMin() {
                if (!stageStartInput || !stageExpectedInput) {
                    return;
                }
                if (!stageStartInput.value) {
                    stageExpectedInput.removeAttribute('min');
                    stageExpectedInput.setCustomValidity('');
                    return;
                }
                var minDate = new Date(stageStartInput.value + 'T00:00:00');
                minDate.setDate(minDate.getDate() + 1);
                stageExpectedInput.setAttribute('min', minDate.toISOString().slice(0, 10));
                if (stageExpectedInput.value && stageExpectedInput.value <= stageStartInput.value) {
                    stageExpectedInput.setCustomValidity('Expected end date must be later than start date.');
                } else {
                    stageExpectedInput.setCustomValidity('');
                }
            }

            function syncStageActualMin() {
                if (!stageStartInput || !stageActualInput) {
                    return;
                }
                if (!stageStartInput.value) {
                    stageActualInput.removeAttribute('min');
                    stageActualInput.setCustomValidity('');
                    return;
                }
                stageActualInput.setAttribute('min', stageStartInput.value);
                if (stageActualInput.value && stageActualInput.value < stageStartInput.value) {
                    stageActualInput.setCustomValidity('Actual end date cannot be earlier than start date.');
                } else {
                    stageActualInput.setCustomValidity('');
                }
            }

            if (stageStartInput && stageExpectedInput) {
                stageStartInput.addEventListener('change', syncStageExpectedMin);
                stageExpectedInput.addEventListener('input', syncStageExpectedMin);
                syncStageExpectedMin();
            }
            if (stageStartInput && stageActualInput) {
                stageStartInput.addEventListener('change', syncStageActualMin);
                stageActualInput.addEventListener('input', syncStageActualMin);
                syncStageActualMin();
            }
            if (stageForm && stageExpectedInput) {
                stageForm.addEventListener('submit', function(event) {
                    syncStageExpectedMin();
                    syncStageActualMin();
                    if (!stageExpectedInput.checkValidity()) {
                        event.preventDefault();
                        stageExpectedInput.reportValidity();
                        return;
                    }
                    if (stageActualInput && !stageActualInput.checkValidity()) {
                        event.preventDefault();
                        stageActualInput.reportValidity();
                    }
                });
            }

            var stageFileInput = document.getElementById('stage_file');
            if (stageFileInput) {
                stageFileInput.addEventListener('change', function() {
                    if (this.files && this.files.length > 0) {
                        setStageFileDisplay(this.files[0].name, true);
                    }
                });
            }

            var serviceSelect = document.getElementById('case_service_select');
            if (serviceSelect) {
                var serviceCustom = document.getElementById('case_service_custom');
                var serviceHidden = document.getElementById('case_service_name');
                var serviceForm = serviceSelect.closest('form');

                function syncCaseServiceName() {
                    if (serviceSelect.value === '__other__') {
                        serviceCustom.classList.remove('d-none');
                        serviceHidden.value = serviceCustom.value.trim();
                    } else {
                        serviceCustom.classList.add('d-none');
                        serviceCustom.value = '';
                        serviceHidden.value = serviceSelect.value;
                    }
                }

                serviceSelect.addEventListener('change', syncCaseServiceName);
                serviceCustom.addEventListener('input', syncCaseServiceName);

                if (serviceForm) {
                    serviceForm.addEventListener('submit', function(event) {
                        syncCaseServiceName();
                        if (!serviceHidden.value.trim()) {
                            event.preventDefault();
                            alert('Please select or enter a service name.');
                        }
                    });
                }

                var addServiceModal = document.getElementById('addServiceModal');
                if (addServiceModal) {
                    addServiceModal.addEventListener('show.bs.modal', function() {
                        serviceSelect.value = '';
                        serviceCustom.value = '';
                        serviceCustom.classList.add('d-none');
                        serviceHidden.value = '';
                    });
                }
            }

        });
    </script>
</body>
</html>
HTML;

// Template replacements
$categoryFieldHtml = buildCaseCategoryFieldHtml(isset($case['category']) ? $case['category'] : 'Civil', 'Civil');
$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{CASE_ID}' => $caseId,
    '{CASE_NUMBER}' => $caseNumber,
    '{CASE_TITLE}' => htmlspecialchars($case['title']),
    '{CLIENT_OPTIONS}' => $clientOptions,
    '{LAWYER_CHECKBOXES}' => $lawyerCheckboxes,
    '{TASK_LAWYER_CHECKBOXES}' => $taskLawyerCheckboxes,
    '{STATUS_ACTIVE}' => in_array((string) $case['status'], ['active', 'in_progress', 'open'], true) ? 'selected' : '',
    '{STATUS_PENDING}' => ($case['status'] === 'pending') ? 'selected' : '',
    '{STATUS_UNDER_REVIEW}' => ($case['status'] === 'under_review') ? 'selected' : '',
    '{STATUS_CLOSED}' => ($case['status'] === 'closed') ? 'selected' : '',
    '{PRIORITY_NORMAL}' => ($case['priority'] === 'Normal') ? 'selected' : '',
    '{PRIORITY_HIGH}' => ($case['priority'] === 'High') ? 'selected' : '',
    '{PRIORITY_URGENT}' => ($case['priority'] === 'Urgent') ? 'selected' : '',
    '{CATEGORY_FIELD}' => $categoryFieldHtml,
    '{TOTAL_FEES}' => formatCurrency(isset($case['estimated_fees']) ? $case['estimated_fees'] : 0),
    '{START_DATE}' => isset($case['start_date']) ? $case['start_date'] : '',
    '{EXPECTED_COMPLETION}' => isset($case['expected_completion']) ? $case['expected_completion'] : '',
    '{DESCRIPTION}' => htmlspecialchars(isset($case['description']) ? $case['description'] : ''),
    '{SERVICES_HTML}' => $servicesHtml,
    '{OFFERED_SERVICE_FIELD}' => $offeredServiceFieldHtml,
    '{OFFERED_SERVICE_FIELD_HINT}' => $offeredServiceFieldHint,
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

echo legalpro_apply_copyright_line($html);
?>
