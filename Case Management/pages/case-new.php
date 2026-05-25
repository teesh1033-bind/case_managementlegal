<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Ensure cases table has all required columns
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN user_id INT NULL AFTER client_id");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN priority VARCHAR(50) DEFAULT 'Normal' AFTER status");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN category VARCHAR(50) DEFAULT 'Civil' AFTER priority");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN estimated_fees DECIMAL(10,2) DEFAULT 0.00 AFTER category");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN start_date DATE NULL AFTER estimated_fees");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN expected_completion DATE NULL AFTER start_date");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}

// Create or fix case_services table
try {
    // Check if table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'case_services'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Create the table
        $pdo->query("CREATE TABLE case_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT NOT NULL,
            service_name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        // Table exists, check its structure and fix it if needed
        $columns = $pdo->query("SHOW COLUMNS FROM case_services")->fetchAll(PDO::FETCH_COLUMN);
        
        // If table has wrong structure (e.g., has service_id), drop and recreate
        if (in_array('service_id', $columns) || !in_array('service_name', $columns)) {
            // Drop the table and recreate with correct structure
            $pdo->query("DROP TABLE IF EXISTS case_services");
            $pdo->query("CREATE TABLE case_services (
                id INT AUTO_INCREMENT PRIMARY KEY,
                case_id INT NOT NULL,
                service_name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            // Table structure is correct, just ensure all columns exist
            if (!in_array('service_name', $columns)) {
                $pdo->query("ALTER TABLE case_services ADD COLUMN service_name VARCHAR(255) NOT NULL AFTER case_id");
            }
            if (!in_array('price', $columns)) {
                $pdo->query("ALTER TABLE case_services ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER service_name");
            }
        }
    }
} catch (PDOException $e) {
    // Log error but don't stop execution - we'll handle it when trying to insert
    error_log("Error creating/fixing case_services table: " . $e->getMessage());
}

$formData = [
    'case_id' => '',
    'client_id' => '',
    'user_id' => '',
    'title' => '',
    'description' => '',
    'status' => 'open',
    'priority' => 'Normal',
    'category' => 'Civil',
    'estimated_fees' => '0.00',
    'start_date' => '',
    'expected_completion' => ''
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';
    
    if ($formType === 'save') {
        $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $clientUserId = null;
        $lawyerIds = isset($_POST['lawyer_ids']) ? $_POST['lawyer_ids'] : [];
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'open';
        $priority = isset($_POST['priority']) ? trim($_POST['priority']) : 'Normal';
        $category = isset($_POST['category']) ? trim($_POST['category']) : 'Civil';
        $startDate = isset($_POST['start_date']) ? trim($_POST['start_date']) : null;
        $expectedCompletion = isset($_POST['expected_completion']) ? trim($_POST['expected_completion']) : null;
        
        // Parse services from POST data
        $services = [];
        if (isset($_POST['services']) && is_array($_POST['services'])) {
            foreach ($_POST['services'] as $service) {
                $serviceName = isset($service['name']) ? trim($service['name']) : '';
                $servicePrice = isset($service['price']) ? floatval($service['price']) : 0.00;
                if (!empty($serviceName) && $servicePrice >= 0) {
                    $services[] = ['name' => $serviceName, 'price' => $servicePrice];
                }
            }
        }
        
        // Calculate total fees from services
        $estimatedFees = 0.00;
        foreach ($services as $service) {
            $estimatedFees += $service['price'];
        }
        
        if (empty($clientId) || empty($title)) {
            $message = 'Client and case title are required.';
            $messageType = 'danger';
        } else {
            // Portal user linked to this client (for cases.user_id — not a lawyer id)
            $stmt = $pdo->prepare("SELECT user_id FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $clientRow = $stmt->fetch();
            if ($clientRow && !empty($clientRow['user_id'])) {
                $clientUserId = (int) $clientRow['user_id'];
            }

            try {
                if ($caseId) {
                    // Update existing case
                    $stmt = $pdo->prepare("
                        UPDATE cases 
                        SET client_id = ?, user_id = ?, title = ?, description = ?, status = ?,
                            priority = ?, category = ?, estimated_fees = ?, start_date = ?, expected_completion = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $clientId, $clientUserId, $title, $description, $status,
                        $priority, $category, $estimatedFees, $startDate ?: null, $expectedCompletion ?: null, $caseId
                    ]);

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
                    
                    // Delete existing services and insert new ones
                    try {
                        $stmt = $pdo->prepare("DELETE FROM case_services WHERE case_id = ?");
                        $stmt->execute([$caseId]);
                        
                        if (!empty($services)) {
                            $stmt = $pdo->prepare("INSERT INTO case_services (case_id, service_name, price) VALUES (?, ?, ?)");
                            foreach ($services as $service) {
                                $stmt->execute([$caseId, $service['name'], $service['price']]);
                            }
                        }
                    } catch (PDOException $e) {
                        // If table doesn't exist or has wrong structure, try to fix it
                        if (stripos($e->getMessage(), "doesn't exist") !== false || 
                            stripos($e->getMessage(), "Unknown column") !== false ||
                            stripos($e->getMessage(), "doesn't have a default value") !== false) {
                            try {
                                // Drop and recreate table with correct structure
                                $pdo->query("DROP TABLE IF EXISTS case_services");
                                $pdo->query("CREATE TABLE case_services (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    case_id INT NOT NULL,
                                    service_name VARCHAR(255) NOT NULL,
                                    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                // Retry inserting services
                                if (!empty($services)) {
                                    $stmt = $pdo->prepare("INSERT INTO case_services (case_id, service_name, price) VALUES (?, ?, ?)");
                                    foreach ($services as $service) {
                                        $stmt->execute([$caseId, $service['name'], $service['price']]);
                                    }
                                }
                            } catch (PDOException $e2) {
                                // If still fails, log but don't stop - case is already updated
                                error_log("Error creating/inserting into case_services: " . $e2->getMessage());
                            }
                        } else {
                            throw $e;
                        }
                    }
                    
                    $msg = 'Case updated successfully.';
                } else {
                    // Insert new case
                    $stmt = $pdo->prepare("
                        INSERT INTO cases (client_id, user_id, title, description, status, priority, category, estimated_fees, start_date, expected_completion)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $clientId, $clientUserId, $title, $description, $status,
                        $priority, $category, $estimatedFees, $startDate ?: null, $expectedCompletion ?: null
                    ]);
                    $newCaseId = $pdo->lastInsertId();

                    // Add lawyer assignments for new case
                    if (!empty($lawyerIds)) {
                        $stmt = $pdo->prepare("INSERT INTO case_lawyers (case_id, lawyer_id, is_primary) VALUES (?, ?, ?)");
                        $isFirst = true;
                        foreach ($lawyerIds as $lawyerId) {
                            $stmt->execute([$newCaseId, $lawyerId, $isFirst ? 1 : 0]);
                            $isFirst = false;
                        }
                    }
                    
                    // Insert services
                    if (!empty($services)) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO case_services (case_id, service_name, price) VALUES (?, ?, ?)");
                            foreach ($services as $service) {
                                $stmt->execute([$newCaseId, $service['name'], $service['price']]);
                            }
                        } catch (PDOException $e) {
                            // If table doesn't exist or has wrong structure, try to fix it
                            if (stripos($e->getMessage(), "doesn't exist") !== false || 
                                stripos($e->getMessage(), "Unknown column") !== false ||
                                stripos($e->getMessage(), "doesn't have a default value") !== false) {
                                try {
                                    // Drop and recreate table with correct structure
                                    $pdo->query("DROP TABLE IF EXISTS case_services");
                                    $pdo->query("CREATE TABLE case_services (
                                        id INT AUTO_INCREMENT PRIMARY KEY,
                                        case_id INT NOT NULL,
                                        service_name VARCHAR(255) NOT NULL,
                                        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                        FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
                                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                    // Retry inserting services
                                    if (!empty($services)) {
                                        $stmt = $pdo->prepare("INSERT INTO case_services (case_id, service_name, price) VALUES (?, ?, ?)");
                                        foreach ($services as $service) {
                                            $stmt->execute([$newCaseId, $service['name'], $service['price']]);
                                        }
                                    }
                                } catch (PDOException $e2) {
                                    // If still fails, log but don't stop - case is already created
                                    error_log("Error creating/inserting into case_services: " . $e2->getMessage());
                                }
                            } else {
                                throw $e;
                            }
                        }
                    }
                    
                    $msg = 'Case created successfully.';
                }
                
                header('Location: tables.php?msg=' . urlencode($msg) . '&type=success');
                exit;
            } catch (PDOException $e) {
                $message = 'Error saving case: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
        
        // Keep form data for re-display on error
        $formData = [
            'case_id' => $caseId ?: '',
            'client_id' => $clientId,
            'user_id' => $clientUserId,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'category' => $category,
            'estimated_fees' => $estimatedFees,
            'start_date' => $startDate,
            'expected_completion' => $expectedCompletion
        ];
    }
}

// Pre-fill client when opening from client detail (?client_id=)
if (empty($formData['case_id']) && isset($_GET['client_id']) && ctype_digit((string) $_GET['client_id'])) {
    $formData['client_id'] = (int) $_GET['client_id'];
}

// Pre-fill form if editing via GET
$existingServices = [];
if (empty($formData['case_id']) && isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
    $stmt->execute([$editId]);
    $case = $stmt->fetch();
    
    if ($case) {
        $formData = [
            'case_id' => $case['id'],
            'client_id' => $case['client_id'],
            'user_id' => isset($case['user_id']) ? $case['user_id'] : '',
            'title' => $case['title'],
            'description' => isset($case['description']) ? $case['description'] : '',
            'status' => isset($case['status']) ? $case['status'] : 'open',
            'priority' => isset($case['priority']) ? $case['priority'] : 'Normal',
            'category' => isset($case['category']) ? $case['category'] : 'Civil',
            'estimated_fees' => isset($case['estimated_fees']) ? $case['estimated_fees'] : '',
            'start_date' => isset($case['start_date']) && $case['start_date'] ? date('Y-m-d', strtotime($case['start_date'])) : '',
            'expected_completion' => isset($case['expected_completion']) && $case['expected_completion'] ? date('Y-m-d', strtotime($case['expected_completion'])) : ''
        ];
        
        // Load existing services
        $stmt = $pdo->prepare("SELECT service_name, price FROM case_services WHERE case_id = ? ORDER BY id");
        $stmt->execute([$editId]);
        $existingServices = $stmt->fetchAll();

        // Load assigned lawyers
        $stmt = $pdo->prepare("SELECT lawyer_id FROM case_lawyers WHERE case_id = ? ORDER BY is_primary DESC, assigned_at ASC");
        $stmt->execute([$editId]);
        $assignedLawyersResult = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $formData['assigned_lawyers'] = $assignedLawyersResult;
    } else {
        $message = 'Case not found.';
        $messageType = 'danger';
    }
}

// Fetch dropdown data
try {
    $clientsList = $pdo->query("
        SELECT c.id, c.first_name, c.last_name, c.email, c.user_id, u.username
        FROM clients c
        LEFT JOIN users u ON u.id = c.user_id
        ORDER BY c.first_name, c.last_name
    ")->fetchAll();
} catch (PDOException $e) {
    $clientsList = [];
}

try {
    $lawyersList = $pdo->query("SELECT l.id, l.first_name, l.last_name FROM lawyers l WHERE l.is_active = 1 ORDER BY l.last_name, l.first_name")->fetchAll();
} catch (PDOException $e) {
    $lawyersList = [];
}

// Build select options
$clientOptions = '<option value="">Select existing client</option>';
foreach ($clientsList as $client) {
    $fullName = trim($client['first_name'] . ' ' . $client['last_name']);
    $selected = ((int)$formData['client_id'] === (int)$client['id']) ? ' selected' : '';
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
}

$lawyerCheckboxes = '';
$assignedLawyers = isset($formData['assigned_lawyers']) ? $formData['assigned_lawyers'] : [];

foreach ($lawyersList as $lawyer) {
    $label = $lawyer['first_name'] . ' ' . $lawyer['last_name'];
    $checked = in_array($lawyer['id'], $assignedLawyers) ? ' checked' : '';
    $lawyerCheckboxes .= '
    <div class="form-check">
        <input class="form-check-input lawyer-checkbox" type="checkbox" name="lawyer_ids[]" value="' . (int)$lawyer['id'] . '" id="lawyer_' . (int)$lawyer['id'] . '"' . $checked . '>
        <label class="form-check-label" for="lawyer_' . (int)$lawyer['id'] . '">
            ' . htmlspecialchars($label) . '
        </label>
    </div>';
}

// Render message block
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$isEditing = !empty($formData['case_id']);
$formTitle = $isEditing ? 'Update Case' : 'Register New Case';
$submitLabel = $isEditing ? 'Update Case' : 'Create Case';
$cancelLink = $isEditing ? '<a href="tables.php" class="btn btn-light me-2">Cancel</a>' : '<a href="tables.php" class="btn btn-light me-2">Cancel</a>';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>LegalPro Case Manager - {FORM_TITLE}</title>
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
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">{FORM_TITLE}</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">{FORM_TITLE}</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-header pb-0">
							<h6>Case Details</h6>
						</div>
						<div class="card-body">
							{MESSAGE}
							<form method="post">
								<input type="hidden" name="form_type" value="save">
								<input type="hidden" name="case_id" value="{CASE_ID}">
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Client <span class="text-danger">*</span></label>
											<select class="form-control" name="client_id" required>
												{CLIENT_OPTIONS}
											</select>
											<small class="text-xs"><a href="clients.php">Add new client</a></small>
										</div>
									</div>
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Assigned Lawyers</label>
											<div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
												{LAWYER_CHECKBOXES}
											</div>
										</div>
									</div>
								</div>
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Case Title <span class="text-danger">*</span></label>
											<input class="form-control" type="text" name="title" placeholder="e.g., Contract Dispute" value="{TITLE_VALUE}" required>
										</div>
									</div>
									<div class="col-md-3">
										<div class="form-group">
											<label class="form-control-label">Start Date</label>
											<input class="form-control" type="date" name="start_date" value="{START_DATE_VALUE}">
										</div>
									</div>
									<div class="col-md-3">
										<div class="form-group">
											<label class="form-control-label">Priority</label>
											<select class="form-control" name="priority">
												<option value="Normal"{PRIORITY_NORMAL}>Normal</option>
												<option value="High"{PRIORITY_HIGH}>High</option>
												<option value="Urgent"{PRIORITY_URGENT}>Urgent</option>
											</select>
										</div>
									</div>
								</div>
								<div class="row">
									<div class="col-md-4">
										<div class="form-group">
											<label class="form-control-label">Category</label>
											<select class="form-control" name="category">
												<option value="Civil"{CATEGORY_CIVIL}>Civil</option>
												<option value="Criminal"{CATEGORY_CRIMINAL}>Criminal</option>
												<option value="Corporate"{CATEGORY_CORPORATE}>Corporate</option>
												<option value="Family"{CATEGORY_FAMILY}>Family</option>
											</select>
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group">
											<label class="form-control-label">Total Fees</label>
											<input class="form-control" type="text" id="total_fees_display" readonly value="{TOTAL_FEES_DISPLAY}" style="background-color: #f8f9fa;">
											<input type="hidden" name="estimated_fees" id="total_fees_hidden" value="{ESTIMATED_FEES_VALUE}">
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group">
											<label class="form-control-label">Expected Completion</label>
											<input class="form-control" type="date" name="expected_completion" value="{EXPECTED_COMPLETION_VALUE}">
										</div>
									</div>
								</div>
								<div class="form-group">
									<label class="form-control-label">Status</label>
									<select class="form-control" name="status">
										<option value="open"{STATUS_OPEN}>Open</option>
										<option value="in_progress"{STATUS_IN_PROGRESS}>In Progress</option>
										<option value="closed"{STATUS_CLOSED}>Closed</option>
									</select>
								</div>
								<div class="form-group">
									<label class="form-control-label">Services & Fees</label>
									<div class="table-responsive">
										<table class="table table-bordered" id="services-table">
											<thead>
												<tr>
													<th style="width: 60%;">Service</th>
													<th style="width: 30%;">Price</th>
													<th style="width: 10%;">Action</th>
												</tr>
											</thead>
											<tbody id="services-tbody">
												<!-- Services rows will be added here dynamically -->
											</tbody>
											<tfoot>
												<tr>
													<td colspan="3">
														<button type="button" class="btn btn-sm btn-outline-primary" id="add-service-row">
															<i class="fas fa-plus"></i> Add Service
														</button>
													</td>
												</tr>
											</tfoot>
										</table>
									</div>
								</div>
								<div class="form-group">
									<label class="form-control-label">Description</label>
									<textarea class="form-control" rows="4" name="description" placeholder="Brief description of the case...">{DESCRIPTION_VALUE}</textarea>
								</div>
								<div class="d-flex">
									{CANCEL_LINK}
									<button type="submit" class="btn btn-primary">{SUBMIT_LABEL}</button>
								</div>
							</form>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card">
						<div class="card-header pb-0">
							<h6>Quick Actions</h6>
						</div>
						<div class="card-body">
							<a href="case-new.php" class="btn btn-dark w-100 mb-2">Register New Case</a>
							<a href="appointments.php" class="btn btn-outline-dark w-100">New Appointment</a>
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
	<script src="../assets/js/spa-nav.js"></script>
	<script>
		// Services management
		let serviceRowIndex = 0;
		const existingServices = {EXISTING_SERVICES_JSON};
		
		function addServiceRow(serviceName = '', price = '') {
			const tbody = document.getElementById('services-tbody');
			const row = document.createElement('tr');
			row.innerHTML = `
				<td>
					<input type="text" class="form-control form-control-sm service-name" 
						name="services[${serviceRowIndex}][name]" 
						placeholder="Enter service name" value="${escapeHtml(serviceName)}" required>
				</td>
				<td>
					<input type="number" class="form-control form-control-sm service-price" 
						name="services[${serviceRowIndex}][price]" 
						step="0.01" min="0" placeholder="0.00" value="${escapeHtml(price)}" required>
				</td>
				<td>
					<button type="button" class="btn btn-sm btn-danger remove-service-row">
						<i class="fas fa-trash"></i>
					</button>
				</td>
			`;
			tbody.appendChild(row);
			serviceRowIndex++;
			
			// Attach event listeners
			row.querySelector('.service-price').addEventListener('input', calculateTotal);
			row.querySelector('.remove-service-row').addEventListener('click', function() {
				row.remove();
				calculateTotal();
			});
		}
		
		function calculateTotal() {
			let total = 0;
			document.querySelectorAll('.service-price').forEach(function(input) {
				const value = parseFloat(input.value) || 0;
				total += value;
			});
			
			const currencySymbol = '{CURRENCY_SYMBOL}';
			document.getElementById('total_fees_display').value = currencySymbol + total.toFixed(2);
			document.getElementById('total_fees_hidden').value = total.toFixed(2);
		}
		
		function escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
		
		// Initialize services table
		document.addEventListener('DOMContentLoaded', function() {
			// Add existing services if editing
			if (existingServices && existingServices.length > 0) {
				existingServices.forEach(function(service) {
					addServiceRow(service.service_name || service.name, service.price || '');
				});
			} else {
				// Add one empty row by default
				addServiceRow();
			}
			
			// Add service button
			document.getElementById('add-service-row').addEventListener('click', function() {
				addServiceRow();
			});
			
			// Calculate total on page load
			calculateTotal();

			// No limit on lawyer selection - multiple lawyers can be selected
		});
	</script>
</body>
</html>
HTML;

$html = str_replace('{FORM_TITLE}', htmlspecialchars($formTitle), $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CASE_ID}', htmlspecialchars($formData['case_id']), $html);
$html = str_replace('{CLIENT_OPTIONS}', $clientOptions, $html);
$html = str_replace('{LAWYER_CHECKBOXES}', $lawyerCheckboxes, $html);
$html = str_replace('{TITLE_VALUE}', htmlspecialchars($formData['title']), $html);
$html = str_replace('{DESCRIPTION_VALUE}', htmlspecialchars($formData['description']), $html);
$html = str_replace('{START_DATE_VALUE}', htmlspecialchars($formData['start_date']), $html);
$html = str_replace('{EXPECTED_COMPLETION_VALUE}', htmlspecialchars($formData['expected_completion']), $html);
$html = str_replace('{ESTIMATED_FEES_VALUE}', htmlspecialchars($formData['estimated_fees']), $html);
$html = str_replace('{SUBMIT_LABEL}', htmlspecialchars($submitLabel), $html);
$html = str_replace('{CANCEL_LINK}', $cancelLink, $html);

// Services JSON for JavaScript
$servicesJson = json_encode($existingServices);
$html = str_replace('{EXISTING_SERVICES_JSON}', htmlspecialchars($servicesJson, ENT_QUOTES, 'UTF-8'), $html);

// Currency symbol for display
$currencySymbol = getCurrencySymbol();
$html = str_replace('{CURRENCY_SYMBOL}', htmlspecialchars($currencySymbol), $html);

// Total fees display
$totalFeesValue = isset($formData['estimated_fees']) && $formData['estimated_fees'] !== '' ? floatval($formData['estimated_fees']) : 0.00;
$totalFeesDisplay = $currencySymbol . number_format($totalFeesValue, 2);
$html = str_replace('{TOTAL_FEES_DISPLAY}', htmlspecialchars($totalFeesDisplay), $html);

// Status selected
$html = str_replace('{STATUS_OPEN}', ($formData['status'] === 'open') ? ' selected' : '', $html);
$html = str_replace('{STATUS_IN_PROGRESS}', ($formData['status'] === 'in_progress') ? ' selected' : '', $html);
$html = str_replace('{STATUS_CLOSED}', ($formData['status'] === 'closed') ? ' selected' : '', $html);

// Priority selected
$html = str_replace('{PRIORITY_NORMAL}', ($formData['priority'] === 'Normal') ? ' selected' : '', $html);
$html = str_replace('{PRIORITY_HIGH}', ($formData['priority'] === 'High') ? ' selected' : '', $html);
$html = str_replace('{PRIORITY_URGENT}', ($formData['priority'] === 'Urgent') ? ' selected' : '', $html);

// Category selected
$html = str_replace('{CATEGORY_CIVIL}', ($formData['category'] === 'Civil') ? ' selected' : '', $html);
$html = str_replace('{CATEGORY_CRIMINAL}', ($formData['category'] === 'Criminal') ? ' selected' : '', $html);
$html = str_replace('{CATEGORY_CORPORATE}', ($formData['category'] === 'Corporate') ? ' selected' : '', $html);
$html = str_replace('{CATEGORY_FAMILY}', ($formData['category'] === 'Family') ? ' selected' : '', $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo $html;
?>
