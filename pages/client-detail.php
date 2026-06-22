<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/password-validation.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/client-financial.php';
require_once __DIR__ . '/../lib/mail.php';

function ensureClientProfileColumns(PDO $pdo) {
    static $ready = false;
    if ($ready) {
        return;
    }

    $columns = [
        'client_type' => "VARCHAR(20) NOT NULL DEFAULT 'Individual' AFTER phone",
        'address' => 'TEXT NULL AFTER client_type',
        'business_name' => 'VARCHAR(255) NULL AFTER address',
        'business_address' => 'TEXT NULL AFTER business_name',
        'brn' => 'VARCHAR(100) NULL AFTER business_address',
        'business_description' => 'TEXT NULL AFTER brn',
    ];

    foreach ($columns as $name => $definition) {
        try {
            $pdo->query("ALTER TABLE clients ADD COLUMN `$name` $definition");
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }
    }

    $ready = true;
}

ensureClientProfileColumns($pdo);

$message = '';
$messageType = '';
$updatePasswordErrorHtml = '';
$updateConfirmErrorHtml = '';
$createPasswordErrorHtml = '';
$createConfirmErrorHtml = '';
$updatePasswordInvalidClass = '';
$updateConfirmInvalidClass = '';
$createPasswordInvalidClass = '';
$createConfirmInvalidClass = '';
$showCreateUserFields = false;
$sendCredentialsEmail = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim(isset($_POST['first_name']) ? $_POST['first_name'] : '');
    $last_name = trim(isset($_POST['last_name']) ? $_POST['last_name'] : '');
    $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
    $phone = trim(isset($_POST['phone']) ? $_POST['phone'] : '');
    $address = trim(isset($_POST['address']) ? $_POST['address'] : '');
    $type = trim(isset($_POST['type']) ? $_POST['type'] : 'Individual');
    $business_name = trim(isset($_POST['business_name']) ? $_POST['business_name'] : '');
    $business_address = trim(isset($_POST['business_address']) ? $_POST['business_address'] : '');
    $brn = trim(isset($_POST['brn']) ? $_POST['brn'] : '');
    $business_description = trim(isset($_POST['business_description']) ? $_POST['business_description'] : '');
    $client_id = isset($_POST['client_id']) ? $_POST['client_id'] : null;

    if (!in_array($type, ['Individual', 'Corporate'], true)) {
        $type = 'Individual';
    }
    if ($type === 'Corporate') {
        $address = '';
    } else {
        $business_name = '';
        $business_address = '';
        $brn = '';
        $business_description = '';
    }

    // Handle user account updates
    $update_username = trim(isset($_POST['update_username']) ? $_POST['update_username'] : '');
    $update_password = isset($_POST['update_password']) ? $_POST['update_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    $createUser = isset($_POST['create_user_account']) && $_POST['create_user_account'] == '1';
    $sendCredentialsEmail = isset($_POST['send_credentials_email']) && $_POST['send_credentials_email'] === '1';
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

    if ($createUser) {
        $showCreateUserFields = true;
    }

    if (empty($first_name) || empty($last_name)) {
        $message = 'First name and last name are required.';
        $messageType = 'danger';
    } elseif ($type === 'Corporate' && $business_name === '') {
        $message = 'Business name is required for corporate clients.';
        $messageType = 'danger';
    } else {
        if (!empty($update_password) || !empty($confirm_password)) {
            $passwordCheck = legalpro_validate_optional_password_update($update_password, $confirm_password);
            if (!$passwordCheck['valid']) {
                $message = legalpro_password_form_message($passwordCheck);
                $messageType = 'danger';
                $updatePasswordErrorHtml = legalpro_password_field_error_html($passwordCheck['password_errors']);
                $updateConfirmErrorHtml = legalpro_password_field_error_html($passwordCheck['confirm_error']);
                $updatePasswordInvalidClass = legalpro_password_input_invalid_class($passwordCheck['password_errors']);
                $updateConfirmInvalidClass = legalpro_password_input_invalid_class($passwordCheck['confirm_error']);
            }
        }

        if ($messageType !== 'danger' && $createUser) {
            if (empty($username)) {
                $message = 'Username is required when creating a user account.';
                $messageType = 'danger';
            } else {
                $passwordCheck = legalpro_validate_password_pair($password, $password_confirm);
                if (!$passwordCheck['valid']) {
                    $message = legalpro_password_form_message($passwordCheck);
                    $messageType = 'danger';
                    $createPasswordErrorHtml = legalpro_password_field_error_html($passwordCheck['password_errors']);
                    $createConfirmErrorHtml = legalpro_password_field_error_html($passwordCheck['confirm_error']);
                    $createPasswordInvalidClass = legalpro_password_input_invalid_class($passwordCheck['password_errors']);
                    $createConfirmInvalidClass = legalpro_password_input_invalid_class($passwordCheck['confirm_error']);
                }
            }
        }

        if ($messageType !== 'danger') {
        try {
            if ($client_id) {
                // Update existing client
                $stmt = $pdo->prepare("UPDATE clients SET first_name = ?, last_name = ?, email = ?, phone = ?, client_type = ?, address = ?, business_name = ?, business_address = ?, brn = ?, business_description = ? WHERE id = ?");
                $stmt->execute([
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $type,
                    $address !== '' ? $address : null,
                    $business_name !== '' ? $business_name : null,
                    $business_address !== '' ? $business_address : null,
                    $brn !== '' ? $brn : null,
                    $business_description !== '' ? $business_description : null,
                    $client_id,
                ]);

                // Update user account if username/password provided and client has a user account
                $stmt = $pdo->prepare("SELECT user_id FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $clientData = $stmt->fetch();

                if ($clientData && $clientData['user_id'] && (!empty($update_username) || !empty($update_password))) {
                    $updateFields = [];
                    $updateValues = [];

                    if (!empty($update_username)) {
                        // Check if username is already taken by another user
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                        $stmt->execute([$update_username, $clientData['user_id']]);
                        if ($stmt->fetch()) {
                            $message = 'Username already exists. Please choose a different username.';
                            $messageType = 'danger';
                        } else {
                            $updateFields[] = "username = ?";
                            $updateValues[] = $update_username;
                        }
                    }

                    if (!empty($update_password) && $messageType !== 'danger') {
                        $hashedPassword = password_hash($update_password, PASSWORD_DEFAULT);
                        $updateFields[] = "password = ?";
                        $updateValues[] = $hashedPassword;
                    }

                    if (!empty($updateFields) && $messageType !== 'danger') {
                        $updateValues[] = $clientData['user_id'];
                        $stmt = $pdo->prepare("UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?");
                        $stmt->execute($updateValues);
                        $message = 'Client and user account updated successfully!';
                    } else {
                        $message = 'Client updated successfully!';
                    }
                } else {
                    $message = 'Client updated successfully!';
                }

                $messageType = 'success';

                $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch();
            } else {
                // Insert new client
                $stmt = $pdo->prepare("INSERT INTO clients (first_name, last_name, email, phone, client_type, address, business_name, business_address, brn, business_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $first_name,
                    $last_name,
                    $email,
                    $phone,
                    $type,
                    $address !== '' ? $address : null,
                    $business_name !== '' ? $business_name : null,
                    $business_address !== '' ? $business_address : null,
                    $brn !== '' ? $brn : null,
                    $business_description !== '' ? $business_description : null,
                ]);
                $newClientId = $pdo->lastInsertId();

                if ($createUser && !empty($username) && !empty($password)) {
                    try {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $userStmt = $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'client')");
                        $userStmt->execute([$username, $hashedPassword, $email]);
                        $newUserId = $pdo->lastInsertId();

                        // Link user account to client
                        $linkStmt = $pdo->prepare("UPDATE clients SET user_id = ? WHERE id = ?");
                        $linkStmt->execute([$newUserId, $newClientId]);

                        $message = 'Client added successfully with user account!';
                        $redirectType = 'success';

                        if ($sendCredentialsEmail) {
                            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $message .= ' Email non envoyé : indiquez une adresse email valide pour le client.';
                                $redirectType = 'warning';
                            } else {
                                $mailResult = legalpro_send_client_credentials_email(
                                    $email,
                                    $first_name,
                                    $last_name,
                                    $username,
                                    $password
                                );
                                if ($mailResult['ok']) {
                                    $message .= ' Identifiants envoyés à ' . $email . '.';
                                } else {
                                    $message .= ' ' . $mailResult['message'];
                                    $redirectType = 'warning';
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        $message = 'Client added successfully! However, user account creation failed: ' . htmlspecialchars($e->getMessage());
                        $messageType = 'warning';
                        $redirectType = 'warning';
                    }
                } else {
                    $message = 'Client added successfully!';
                    $redirectType = 'success';
                }

                if ($messageType !== 'warning') {
                    $messageType = $redirectType ?? 'success';
                }
                header('Location: clients.php?msg=' . urlencode($message) . '&type=' . urlencode($messageType));
                exit;
            }
        } catch (PDOException $e) {
            $message = 'Error saving client: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
        }
    }
}

// Get client data if editing
$client = null;
$userData = null;
$client_id = isset($_GET['id']) ? $_GET['id'] : null;
if ($client_id) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();

    // Get user data if client has a user account
    if ($client && $client['user_id']) {
        $stmt = $pdo->prepare("SELECT username, email FROM users WHERE id = ?");
        $stmt->execute([$client['user_id']]);
        $userData = $stmt->fetch();
    }
}

// Get message from URL if redirected
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Fetch linked cases for this client
$linkedCases = [];
$linkedCasesRows = '';
if ($client_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                u.username AS lawyer_username
            FROM cases c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.client_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$client_id]);
        $linkedCases = $stmt->fetchAll();
    } catch (PDOException $e) {
        $linkedCases = [];
    }
    
    if (empty($linkedCases)) {
        $linkedCasesRows = '<tr><td colspan="4" class="text-center py-3 text-muted">No cases found for this client.</td></tr>';
    } else {
        foreach ($linkedCases as $case) {
            $caseId = (int)$case['id'];
            $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
            $title = htmlspecialchars($case['title']);
            $lawyerName = isset($case['lawyer_username']) && $case['lawyer_username'] ? htmlspecialchars($case['lawyer_username']) : 'Unassigned';
            
            $status = isset($case['status']) ? strtolower($case['status']) : 'open';
            $statusLabel = ucfirst(str_replace('_', ' ', $status));
            $badgeClass = 'bg-gradient-info';
            if ($status === 'in_progress') {
                $badgeClass = 'bg-gradient-warning';
            } elseif ($status === 'closed') {
                $badgeClass = 'bg-gradient-success';
            }
            
            // JSON encode case data for modal
            $caseDataJson = htmlspecialchars(json_encode([
                'id' => $caseId,
                'case_number' => $caseNumber,
                'title' => $case['title'],
                'client_id' => $case['client_id'],
                'client_name' => trim(($client ? $client['first_name'] : '') . ' ' . ($client ? $client['last_name'] : '')),
                'user_id' => isset($case['user_id']) ? $case['user_id'] : '',
                'lawyer_name' => $lawyerName,
                'description' => isset($case['description']) ? $case['description'] : '',
                'status' => $status,
                'priority' => isset($case['priority']) ? $case['priority'] : 'Normal',
                'category' => isset($case['category']) ? $case['category'] : 'Civil',
                'estimated_fees' => isset($case['estimated_fees']) ? $case['estimated_fees'] : '',
                'start_date' => isset($case['start_date']) && $case['start_date'] ? date('Y-m-d', strtotime($case['start_date'])) : '',
                'expected_completion' => isset($case['expected_completion']) && $case['expected_completion'] ? date('Y-m-d', strtotime($case['expected_completion'])) : ''
            ]), ENT_QUOTES);
            
            $linkedCasesRows .= '
            <tr>
                <td>' . $caseNumber . ' · ' . $title . '</td>
                <td><span class="badge badge-sm ' . $badgeClass . '">' . $statusLabel . '</span></td>
                <td class="text-center">' . $lawyerName . '</td>
                <td class="text-end">
                    <a href="tables.php?case_id=' . $caseId . '" class="text-secondary text-xs font-weight-bold">Open</a>
                </td>
            </tr>';
        }
    }
} else {
    $linkedCasesRows = '<tr><td colspan="4" class="text-center py-3 text-muted">Save the client first to view linked cases.</td></tr>';
}

$clientFinancialId = $client_id ? (int) $client_id : 0;
$clientFinancialSummary = legalpro_get_client_financial_summary($pdo, $clientFinancialId);
$financialSummaryHtml = legalpro_render_client_financial_summary_html($clientFinancialSummary, $clientFinancialId);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>Argon Dashboard - Client Detail</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	<link href="../assets/css/legalpro-admin-portal.css?v=25" rel="stylesheet" />
	<link href="../assets/css/dashboard-enhancements.css?v=10" rel="stylesheet" />
	<link href="../assets/css/legalpro-finance-pages.css?v=5" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-client-detail-page">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
		<div class="sidenav-header">
			<i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
			<a class="navbar-brand m-0" href="../pages/dashboard.html">
				<img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="Argon logo">
				<span class="ms-1 font-weight-bold">Argon Dashboard</span>
			</a>
		</div>
		<hr class="horizontal dark mt-0">
		<div class="collapse navbar-collapse  w-auto " id="sidenav-collapse-main">
			<ul class="navbar-nav">
				<li class="nav-item"><a class="nav-link" href="../pages/clients.html"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-circle-08 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Clients</span></a></li>
			</ul>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl " id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="../pages/clients.html">Clients</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Client Detail</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">Client Detail</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			<div class="row">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-header pb-0">
							<div class="d-flex align-items-center">
								<p class="mb-0">Client Information</p>
							</div>
						</div>
						<div class="card-body">
							{MESSAGE}
							<form method="POST" action="">
								<input type="hidden" name="client_id" value="{CLIENT_ID}">
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">First Name <span class="text-danger">*</span></label>
											<input class="form-control" type="text" name="first_name" placeholder="First name" value="{FIRST_NAME}" required>
										</div>
									</div>
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Last Name <span class="text-danger">*</span></label>
											<input class="form-control" type="text" name="last_name" placeholder="Last name" value="{LAST_NAME}" required>
										</div>
									</div>
								</div>
								<p class="text-sm text-muted mb-3" id="corporate-contact-hint" style="display: none;">For corporate clients, first and last name are the main contact person at the company.</p>
								<div class="row">
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Email</label>
											<input class="form-control" type="email" name="email" placeholder="email@example.com" value="{EMAIL}">
										</div>
									</div>
									<div class="col-md-6">
										<div class="form-group">
											<label class="form-control-label">Phone</label>
											<input class="form-control" type="text" name="phone" placeholder="+1 555 0123" value="{PHONE}">
										</div>
									</div>
								</div>
								<div class="form-group">
									<label class="form-control-label">Type</label>
									<select class="form-control" name="type" id="client_type" onchange="toggleClientTypeFields()">
										<option value="Individual" {TYPE_INDIVIDUAL}>Individual</option>
										<option value="Corporate" {TYPE_CORPORATE}>Corporate</option>
									</select>
								</div>
								<div id="individual-fields" class="client-type-panel">
									<div class="form-group">
										<label class="form-control-label">Address</label>
										<input class="form-control" type="text" name="address" id="client_address" placeholder="Street, City, Country" value="{ADDRESS}">
									</div>
								</div>
								<div id="corporate-fields" class="client-type-panel legalpro-form-panel border rounded p-3" style="display: none;">
									<h6 class="text-sm mb-3">Business Details</h6>
									<div class="form-group">
										<label class="form-control-label">Business Name <span class="text-danger">*</span></label>
										<input class="form-control" type="text" name="business_name" id="business_name" placeholder="Registered company name" value="{BUSINESS_NAME}">
									</div>
									<div class="form-group">
										<label class="form-control-label">Business Address</label>
										<input class="form-control" type="text" name="business_address" id="business_address" placeholder="Street, City, Country" value="{BUSINESS_ADDRESS}">
									</div>
									<div class="form-group">
										<label class="form-control-label">BRN</label>
										<input class="form-control" type="text" name="brn" id="brn" placeholder="Business Registration Number" value="{BRN}">
									</div>
									<div class="form-group mb-0">
										<label class="form-control-label">Business Description</label>
										<textarea class="form-control" name="business_description" id="business_description" rows="3" placeholder="Brief description of the business">{BUSINESS_DESCRIPTION}</textarea>
									</div>
								</div>

								<!-- User Account Update Section (only show for existing clients with user accounts) -->
								{USER_ACCOUNT_UPDATE_SECTION}

								<!-- User Account Creation Section (only show for new clients) -->
								{NEW_USER_ACCOUNT_SECTION}

								<div class="mt-4">
									<button type="submit" class="btn btn-primary btn-sm">Save Client</button>
									<a href="clients.php" class="btn btn-outline-secondary btn-sm ms-2">Cancel</a>
								</div>
							</form>
							<hr class="horizontal dark">
							<p class="text-uppercase text-sm">Linked Cases</p>
							<div class="table-responsive">
								<table class="table align-items-center">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center opacity-7">Lawyer</th>
											<th></th>
										</tr>
									</thead>
									<tbody>
										{LINKED_CASES_ROWS}
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card">
						<div class="card-header pb-0">
							<h6>Actions</h6>
						</div>
						<div class="card-body">
							<a href="case-new.php?client_id={CLIENT_ID}" class="btn btn-outline-dark w-100 mb-2">Register New Case</a>
							<a href="appointments.html" class="btn btn-outline-dark w-100">Book Appointment</a>
						</div>
					</div>
				</div>
			</div>
			{FINANCIAL_SUMMARY_SECTION}
			<footer class="footer pt-3  ">
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
	<script src="../assets/js/legalpro-password-validation.js?v=1"></script>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var clientForm = document.querySelector('form[method="POST"]');
			if (clientForm && window.LegalProPassword) {
				LegalProPassword.attachClientDetailForm(clientForm);
			}
			toggleClientTypeFields();
			{SHOW_CREATE_USER_FIELDS}
		});

		function toggleClientTypeFields() {
			var typeSelect = document.getElementById('client_type');
			var individualFields = document.getElementById('individual-fields');
			var corporateFields = document.getElementById('corporate-fields');
			var addressField = document.getElementById('client_address');
			var businessNameField = document.getElementById('business_name');
			var contactHint = document.getElementById('corporate-contact-hint');

			if (!typeSelect || !individualFields || !corporateFields) {
				return;
			}

			var isCorporate = typeSelect.value === 'Corporate';
			individualFields.style.display = isCorporate ? 'none' : 'block';
			corporateFields.style.display = isCorporate ? 'block' : 'none';
			if (contactHint) {
				contactHint.style.display = isCorporate ? 'block' : 'none';
			}

			if (addressField) {
				addressField.required = false;
			}
			if (businessNameField) {
				businessNameField.required = isCorporate;
			}
		}

		function toggleUserAccountFields() {
			const checkbox = document.getElementById('create_user_account');
			const fields = document.getElementById('user_account_fields');
			const usernameField = document.querySelector('input[name="username"]');
			const passwordField = document.querySelector('input[name="password"]');
			const confirmField = document.querySelector('input[name="password_confirm"]');
			const sendEmailField = document.getElementById('send_credentials_email');

			if (checkbox.checked) {
				fields.style.display = 'block';
				usernameField.required = true;
				passwordField.required = true;
				if (confirmField) {
					confirmField.required = true;
				}
				if (sendEmailField) {
					sendEmailField.disabled = false;
				}
			} else {
				fields.style.display = 'none';
				usernameField.required = false;
				passwordField.required = false;
				if (confirmField) {
					confirmField.required = false;
				}
				if (sendEmailField) {
					sendEmailField.checked = false;
					sendEmailField.disabled = true;
				}
			}
		}
	</script>
</body>
</html>
HTML;

// Replace placeholders with actual values
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'danger') {
    $first_name = htmlspecialchars(isset($_POST['first_name']) ? trim($_POST['first_name']) : '');
    $last_name = htmlspecialchars(isset($_POST['last_name']) ? trim($_POST['last_name']) : '');
    $email = htmlspecialchars(isset($_POST['email']) ? trim($_POST['email']) : '');
    $phone = htmlspecialchars(isset($_POST['phone']) ? trim($_POST['phone']) : '');
    $address = htmlspecialchars(isset($_POST['address']) ? trim($_POST['address']) : '');
    $type = in_array(isset($_POST['type']) ? $_POST['type'] : 'Individual', ['Individual', 'Corporate'], true)
        ? $_POST['type']
        : 'Individual';
    $business_name = htmlspecialchars(isset($_POST['business_name']) ? trim($_POST['business_name']) : '');
    $business_address = htmlspecialchars(isset($_POST['business_address']) ? trim($_POST['business_address']) : '');
    $brn = htmlspecialchars(isset($_POST['brn']) ? trim($_POST['brn']) : '');
    $business_description = htmlspecialchars(isset($_POST['business_description']) ? trim($_POST['business_description']) : '');
    $client_id_value = isset($_POST['client_id']) ? (int) $_POST['client_id'] : '';
} else {
    $first_name = $client ? htmlspecialchars($client['first_name']) : '';
    $last_name = $client ? htmlspecialchars($client['last_name']) : '';
    $email = $client ? htmlspecialchars($client['email']) : '';
    $phone = $client ? htmlspecialchars($client['phone']) : '';
    $address = $client && !empty($client['address']) ? htmlspecialchars($client['address']) : '';
    $type = $client && !empty($client['client_type']) ? $client['client_type'] : 'Individual';
    if (!in_array($type, ['Individual', 'Corporate'], true)) {
        $type = 'Individual';
    }
    $business_name = $client && !empty($client['business_name']) ? htmlspecialchars($client['business_name']) : '';
    $business_address = $client && !empty($client['business_address']) ? htmlspecialchars($client['business_address']) : '';
    $brn = $client && !empty($client['brn']) ? htmlspecialchars($client['brn']) : '';
    $business_description = $client && !empty($client['business_description']) ? htmlspecialchars($client['business_description']) : '';
    $client_id_value = $client ? $client['id'] : '';
}

// Handle user account sections
$userAccountUpdateSection = '';
$newUserAccountSection = '';

if ($client_id && $client && $client['user_id'] && $userData) {
    // Existing client with user account - show update section
    $userAccountUpdateSection = '
    <div class="mt-4">
        <h6>Update User Account</h6>
        <p class="text-sm text-muted mb-2">Update the client\'s login credentials. Leave password fields empty to keep current values.</p>
        <div class="legalpro-form-panel border rounded p-3 mt-2">
        ' . legalpro_password_requirements_html() . '
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">Username</label>
                    <input class="form-control" type="text" name="update_username" placeholder="New username" value="' . htmlspecialchars($userData['username']) . '">
                    <small class="form-text text-muted">Current: ' . htmlspecialchars($userData['username']) . '</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">New Password</label>
                    <input class="form-control' . $updatePasswordInvalidClass . '" type="password" name="update_password" placeholder="New password" minlength="8" maxlength="128" autocomplete="new-password">
                    <small class="form-text text-muted">Leave empty to keep current password</small>
                    ' . $updatePasswordErrorHtml . '
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">Confirm New Password</label>
                    <input class="form-control' . $updateConfirmInvalidClass . '" type="password" name="confirm_password" placeholder="Confirm new password" minlength="8" maxlength="128" autocomplete="new-password">
                    ' . $updateConfirmErrorHtml . '
                </div>
            </div>
        </div>
        <div class="alert alert-warning mt-3 mb-0">
            <strong>Important:</strong> Updating username or password will invalidate the client\'s current login credentials. They will need to use the new credentials to login.
        </div>
        </div>
    </div>';
} elseif (!$client_id) {
    // New client - show creation section
    $createUserChecked = $showCreateUserFields ? ' checked' : '';
    $createUserDisplay = $showCreateUserFields ? 'block' : 'none';
    $sendCredentialsEmailChecked = ($_SERVER['REQUEST_METHOD'] !== 'POST' || $sendCredentialsEmail) ? ' checked' : '';
    $newUserAccountSection = '
    <div class="mt-4">
        <div class="form-check">
            <input class="form-check-input legalpro-client-user-check" type="checkbox" id="create_user_account" name="create_user_account" value="1"' . $createUserChecked . ' onchange="toggleUserAccountFields()">
            <label class="form-check-label" for="create_user_account">
                Create user account for client login
            </label>
        </div>
    </div>

    <div id="user_account_fields" style="display: ' . $createUserDisplay . ';" class="mt-3 p-3 border rounded legalpro-form-panel">
        <h6>User Account Details</h6>
        ' . legalpro_password_requirements_html() . '
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">Username <span class="text-danger">*</span></label>
                    <input class="form-control" type="text" name="username" placeholder="Username for login" value="">
                    <small class="form-text text-muted">Client will use this to login to their portal</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">Password <span class="text-danger">*</span></label>
                    <input class="form-control' . $createPasswordInvalidClass . '" type="password" name="password" placeholder="Password" minlength="8" maxlength="128" autocomplete="new-password">
                    ' . $createPasswordErrorHtml . '
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label class="form-control-label">Confirm Password <span class="text-danger">*</span></label>
                    <input class="form-control' . $createConfirmInvalidClass . '" type="password" name="password_confirm" placeholder="Confirm password" minlength="8" maxlength="128" autocomplete="new-password">
                    ' . $createConfirmErrorHtml . '
                </div>
            </div>
        </div>
        <div class="form-check mt-3">
            <input class="form-check-input legalpro-client-user-check" type="checkbox" id="send_credentials_email" name="send_credentials_email" value="1"' . $sendCredentialsEmailChecked . '>
            <label class="form-check-label" for="send_credentials_email">
                Envoyer les identifiants par email (nom, utilisateur, mot de passe)
            </label>
            <small class="form-text text-muted d-block">Nécessite l\'email du client ci-dessus et la configuration Gmail dans Paramètres → Email.</small>
        </div>
    </div>';
}

$html = str_replace('{FIRST_NAME}', $first_name, $html);
$html = str_replace('{LAST_NAME}', $last_name, $html);
$html = str_replace('{EMAIL}', $email, $html);
$html = str_replace('{PHONE}', $phone, $html);
$html = str_replace('{ADDRESS}', $address, $html);
$html = str_replace('{BUSINESS_NAME}', $business_name, $html);
$html = str_replace('{BUSINESS_ADDRESS}', $business_address, $html);
$html = str_replace('{BRN}', $brn, $html);
$html = str_replace('{BUSINESS_DESCRIPTION}', $business_description, $html);
$html = str_replace('{CLIENT_ID}', $client_id_value, $html);
$html = str_replace('{TYPE_INDIVIDUAL}', $type === 'Individual' ? 'selected' : '', $html);
$html = str_replace('{TYPE_CORPORATE}', $type === 'Corporate' ? 'selected' : '', $html);
$html = str_replace('{USER_ACCOUNT_UPDATE_SECTION}', $userAccountUpdateSection, $html);
$html = str_replace('{NEW_USER_ACCOUNT_SECTION}', $newUserAccountSection, $html);
$html = str_replace('{LINKED_CASES_ROWS}', $linkedCasesRows, $html);
$html = str_replace('{FINANCIAL_SUMMARY_SECTION}', $financialSummaryHtml, $html);
$showCreateUserFieldsJs = $showCreateUserFields
    ? "if (typeof toggleUserAccountFields === 'function') { toggleUserAccountFields(); }"
    : '';
$html = str_replace('{SHOW_CREATE_USER_FIELDS}', $showCreateUserFieldsJs, $html);

// Add message display
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}
$html = str_replace('{MESSAGE}', $messageHtml, $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);
ob_start(); include __DIR__ . '/../inc/menunav.php'; $sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);
ob_start(); include __DIR__ . '/../inc/footer.php'; $footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);
echo legalpro_apply_copyright_line($html);
?>
