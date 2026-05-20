<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Ensure appointments table has a status column (adds once, ignored afterwards)
try {
    $pdo->query("ALTER TABLE appointments ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER ends_at");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}

$formData = [
    'appointment_id' => '',
    'case_id' => '',
    'client_name' => '',
    'lawyer_id' => '',
    'date' => '',
    'time' => '',
    'notes' => ''
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';

    if ($formType === 'save') {
        $appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
        $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
        $lawyerId = isset($_POST['lawyer_id']) ? (int)$_POST['lawyer_id'] : 0;
        $date = isset($_POST['date']) ? trim($_POST['date']) : '';
        $time = isset($_POST['time']) ? trim($_POST['time']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

        // Get client_id from the selected case
        $clientId = 0;
        $clientName = '';
        if ($caseId) {
            $caseStmt = $pdo->prepare("SELECT c.client_id, cl.first_name, cl.last_name FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id = ?");
            $caseStmt->execute([$caseId]);
            $caseInfo = $caseStmt->fetch();
            if ($caseInfo) {
                $clientId = $caseInfo['client_id'];
                $clientName = trim($caseInfo['first_name'] . ' ' . $caseInfo['last_name']);
            }
        }

        $formData = [
            'appointment_id' => $appointmentId ? $appointmentId : '',
            'case_id' => $caseId,
            'client_name' => $clientName,
            'lawyer_id' => $lawyerId,
            'date' => $date,
            'time' => $time,
            'notes' => $notes
        ];

        if (empty($caseId) || empty($lawyerId) || empty($date) || empty($time)) {
            $message = 'Case, lawyer, date, and time are required.';
            $messageType = 'danger';
        } else {
            $lawyerCheck = $pdo->prepare("SELECT id FROM lawyers WHERE id = ? AND is_active = 1");
            $lawyerCheck->execute([$lawyerId]);
            if (!$lawyerCheck->fetch()) {
                $message = 'Please select a valid lawyer from the list.';
                $messageType = 'danger';
            } else {
            $dateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
            if (!$dateTime) {
                $message = 'Invalid date or time format.';
                $messageType = 'danger';
            } else {
                $startsAt = $dateTime->format('Y-m-d H:i:s');
                $endsAt = $dateTime->modify('+1 hour')->format('Y-m-d H:i:s');

                try {
                    if ($appointmentId) {
                        // Get old appointment data for tracking
                        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
                        $stmt->execute([$appointmentId]);
                        $oldAppointment = $stmt->fetch();

                        $stmt = $pdo->prepare("
                            UPDATE appointments 
                            SET client_id = ?, case_id = ?, lawyer_id = ?, starts_at = ?, ends_at = ?, notes = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$clientId, $caseId, $lawyerId, $startsAt, $endsAt, $notes, $appointmentId]);

                        // Track appointment update
                        if ($oldAppointment) {
                            CaseEvents::trackAppointmentUpdated($caseId, $oldAppointment, [
                                'client_id' => $clientId,
                                'case_id' => $caseId,
                                'lawyer_id' => $lawyerId,
                                'starts_at' => $startsAt,
                                'ends_at' => $endsAt,
                                'notes' => $notes,
                                'status' => $oldAppointment['status']
                            ]);
                        }

                        $msg = 'Appointment updated successfully.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([$clientId, $caseId, $lawyerId, $startsAt, $endsAt, $notes]);

                        // Track appointment creation
                        CaseEvents::trackAppointmentCreated($caseId, [
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                            'notes' => $notes
                        ]);

                        $msg = 'Appointment booked successfully.';
                    }

                    header('Location: appointments.php?msg=' . urlencode($msg) . '&type=success');
                    exit;
                } catch (PDOException $e) {
                    $message = 'Error saving appointment: ' . htmlspecialchars($e->getMessage());
                    $messageType = 'danger';
                }
            }
            }
        }
    } elseif ($formType === 'status') {
        $appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : '';
        $allowedStatuses = ['pending', 'approved', 'rejected'];

        if ($appointmentId && in_array($status, $allowedStatuses, true)) {
            try {
                // Get old status for tracking
                $stmt = $pdo->prepare("SELECT case_id, status FROM appointments WHERE id = ?");
                $stmt->execute([$appointmentId]);
                $appointmentData = $stmt->fetch();

                $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
                $stmt->execute([$status, $appointmentId]);

                // Track status change
                if ($appointmentData && $appointmentData['status'] != $status) {
                    CaseEvents::trackAppointmentUpdated($appointmentData['case_id'], [
                        'status' => $appointmentData['status']
                    ], [
                        'status' => $status
                    ]);
                }

                $label = ucfirst($status);
                header('Location: appointments.php?msg=' . urlencode('Appointment marked as ' . strtolower($label) . '.') . '&type=success');
                exit;
            } catch (PDOException $e) {
                $message = 'Unable to update status: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid appointment status update.';
            $messageType = 'danger';
        }
    }

    // Handle appointment deletion
    if (isset($_POST['delete_appointment'])) {
        $appointmentId = (int)$_POST['appointment_id'];

        try {
            // Get appointment details for tracking before deletion
            $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
            $stmt->execute([$appointmentId]);
            $appointment = $stmt->fetch();

            if ($appointment) {
                // Delete the appointment
                $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
                $stmt->execute([$appointmentId]);

                // Track deletion in case events
                CaseEvents::trackAppointmentDeleted($appointment['case_id'], [
                    'appointment_date' => $appointment['starts_at'],
                    'lawyer_id' => $appointment['lawyer_id']
                ]);

                $message = 'Appointment deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Appointment not found.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Unable to delete appointment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Pre-fill form if editing via GET (unless POST already populated it)
if (empty($formData['appointment_id']) && isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT a.*, c.title as case_title, c.id as case_id,
               cl.first_name, cl.last_name
        FROM appointments a
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN clients cl ON cl.id = c.client_id
        WHERE a.id = ?
    ");
    $stmt->execute([$editId]);
    $appointment = $stmt->fetch();

    if ($appointment) {
        $startsAt = $appointment['starts_at'] ? new DateTime($appointment['starts_at']) : null;
        $clientName = trim((isset($appointment['first_name']) ? $appointment['first_name'] : '') . ' ' . (isset($appointment['last_name']) ? $appointment['last_name'] : ''));
        $formData = [
            'appointment_id' => $appointment['id'],
            'case_id' => $appointment['case_id'],
            'client_name' => $clientName,
            'lawyer_id' => $appointment['lawyer_id'],
            'date' => $startsAt ? $startsAt->format('Y-m-d') : '',
            'time' => $startsAt ? $startsAt->format('H:i') : '',
            'notes' => $appointment['notes']
        ];
    } else {
        $message = 'Appointment not found.';
        $messageType = 'danger';
    }
}

// Fetch dropdown data
try {
    $casesList = $pdo->query("
        SELECT
            c.id,
            c.title,
            CONCAT('C-', LPAD(c.id, 4, '0'), ' · ', c.title) as case_display,
            cl.first_name,
            cl.last_name,
            CONCAT(cl.first_name, ' ', cl.last_name) as client_name
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        ORDER BY c.title
    ")->fetchAll();
} catch (PDOException $e) {
    $casesList = [];
    if (!$message) {
        $message = 'Unable to load cases list: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

try {
    $lawyersList = $pdo->query("
        SELECT l.id, l.first_name, l.last_name, u.username
        FROM lawyers l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.is_active = 1
        ORDER BY l.last_name, l.first_name
    ")->fetchAll();
} catch (PDOException $e) {
    $lawyersList = [];
    if (!$message) {
        $message = 'Unable to load lawyers list: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

// Fetch appointment rows (only accepted appointments)
try {
    $stmt = $pdo->query("
        SELECT 
            a.*,
            cs.title AS case_title,
            cs.id AS case_id,
            CONCAT('C-', LPAD(cs.id, 4, '0'), ' · ', cs.title) as case_display,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name,
            CONCAT(l.first_name, ' ', l.last_name) AS lawyer_name
        FROM appointments a
        LEFT JOIN cases cs ON cs.id = a.case_id
        LEFT JOIN clients cl ON cl.id = cs.client_id
        LEFT JOIN lawyers l ON l.id = a.lawyer_id
        ORDER BY a.starts_at DESC
    ");
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $appointments = [];
    if (!$message) {
        $message = 'Unable to load appointments: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

// Build select options
$caseOptions = '<option value="">Select case</option>';
foreach ($casesList as $case) {
    $selected = ((int)$formData['case_id'] === (int)$case['id']) ? ' selected' : '';
    $caseOptions .= '<option value="' . (int)$case['id'] . '" data-client="' . htmlspecialchars($case['client_name']) . '"' . $selected . '>' . htmlspecialchars($case['case_display']) . '</option>';
}

$lawyerOptions = '<option value="">Select lawyer</option>';
foreach ($lawyersList as $lawyer) {
    $label = trim($lawyer['first_name'] . ' ' . $lawyer['last_name']);
    if (!empty($lawyer['username'])) {
        $label .= ' (' . $lawyer['username'] . ')';
    }
    $selected = ((int)$formData['lawyer_id'] === (int)$lawyer['id']) ? ' selected' : '';
    $lawyerOptions .= '<option value="' . (int)$lawyer['id'] . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
}

// Build appointment table rows
$appointmentsRows = '';
if (empty($appointments)) {
    $appointmentsRows = '<tr><td colspan="5" class="text-center py-5">
        <div class="text-center">
            <i class="ni ni-calendar-grid-58 text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-0">No appointments booked yet.</p>
            <p class="text-xs text-muted mb-0">Use the form on the left to book your first appointment.</p>
        </div>
    </td></tr>';
} else {
    foreach ($appointments as $appointment) {
        // Determine display based on available data
        $caseDisplay = isset($appointment['case_display']) && !empty($appointment['case_display'])
            ? $appointment['case_display']
            : 'No Case Assigned';

        $clientFirstName = isset($appointment['client_first_name']) ? $appointment['client_first_name'] : '';
        $clientLastName = isset($appointment['client_last_name']) ? $appointment['client_last_name'] : '';
        $clientName = trim($clientFirstName . ' ' . $clientLastName);
        $clientName = $clientName ? $clientName : 'Unknown Client';
        $lawyerName = $appointment['lawyer_name'] ? $appointment['lawyer_name'] : 'Unassigned';

        $startsAt = $appointment['starts_at'] ? date('m/d/y · H:i', strtotime($appointment['starts_at'])) : 'TBD';
        $status = isset($appointment['status']) ? strtolower($appointment['status']) : 'pending';
        switch ($status) {
            case 'accepted':
                $badgeClass = 'bg-gradient-success';
                $statusText = 'Accepted';
                break;
            case 'rejected':
                $badgeClass = 'bg-gradient-danger';
                $statusText = 'Rejected';
                break;
            default:
                $badgeClass = 'bg-gradient-warning';
                $statusText = 'Pending';
                break;
        }

        $appointmentsRows .= '
        <tr>
            <td class="ps-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md me-2">
                        <i class="ni ni-folder-17 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="text-sm mb-0">' . htmlspecialchars($caseDisplay) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($clientName) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-sm font-weight-bold mb-0">' . htmlspecialchars($lawyerName) . '</p>
                <p class="text-xs text-muted mb-0">Lawyer</p>
            </td>
            <td class="text-center">
                <p class="text-sm font-weight-bold mb-0">' . htmlspecialchars($startsAt) . '</p>
            </td>
            <td class="text-center">
                <span class="badge ' . $badgeClass . ' badge-sm">' . $statusText . '</span>
            </td>
            <td class="text-end pe-3">
                <div class="d-flex gap-1 justify-content-end">
                    <a href="javascript:void(0)" class="btn btn-sm btn-dark mb-0" title="Edit" onclick="window.location.href=\'appointments.php?id=' . (int)$appointment['id'] . '#appointment-form\'; return false;">
                        <i class="ni ni-ruler-pencil"></i>
                    </a>
                    <a href="javascript:void(0)" class="btn btn-sm btn-danger mb-0" title="Delete" onclick="deleteAppointment(' . (int)$appointment['id'] . ', \'' . addslashes($caseDisplay) . '\'); return false;">
                        <i class="ni ni-fat-remove"></i>
                    </a>
                </div>
            </td>
        </tr>';
    }
}

// Render message block
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$isEditing = !empty($formData['appointment_id']);
$formTitle = $isEditing ? 'Update Appointment' : 'Book Appointment';
$submitLabel = $isEditing ? 'Save Changes' : 'Submit Request';
$cancelLink = $isEditing ? '<a href="appointments.php" class="btn btn-outline-secondary btn-sm mb-0" title="Cancel editing"><i class="ni ni-fat-remove me-1"></i> Cancel</a>' : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>LegalPro Case Manager - Appointments</title>
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
		<div class="sidenav-header">
			<i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
			<a class="navbar-brand m-0" href="../pages/dashboard.php">
				<img src="../assets/img/logo-ct-dark.png" width="26" height="26" class="navbar-brand-img h-100" alt="LegalPro logo">
				<span class="ms-1 font-weight-bold">LegalPro Case Manager</span>
			</a>
		</div>
		<hr class="horizontal dark mt-0">
		<div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
			<ul class="navbar-nav">
				<li class="nav-item"><a class="nav-link" href="../pages/dashboard.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-tv-2 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Dashboard</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/tables.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-collection text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Cases</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/clients.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-circle-08 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Clients</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/staff.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-badge text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Staff</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/billing.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-credit-card text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Finance</span></a></li>
				<li class="nav-item"><a class="nav-link active" href="../pages/appointments.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-time-alarm text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Appointments</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/reports.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chart-bar-32 text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Reports</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/settings.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-settings text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Settings</span></a></li>
				<li class="nav-item"><a class="nav-link" href="../pages/chatbot.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-chat-round text-dark text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Chatbot</span></a></li>
			</ul>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">Appointments</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">Appointments</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			{MESSAGE}
			
			<!-- Page Header with Stats -->
			<div class="row mb-4">
				<div class="col-12">
					<div class="card">
						<div class="card-body p-3">
							<div class="row align-items-center">
								<div class="col-lg-8">
									<h5 class="mb-0">Appointment Management</h5>
									<p class="text-sm text-muted mb-0">Schedule and manage client appointments</p>
								</div>
								<div class="col-lg-4 text-end">
									<a href="#appointment-form" class="btn btn-dark btn-sm mb-0" onclick="document.getElementById('appointment-form').scrollIntoView({behavior: 'smooth'});">
										<i class="ni ni-fat-add me-1"></i> New Appointment
									</a>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Main Content: Two Column Layout -->
			<div class="row">
				<!-- Left Column: Booking Form -->
				<div class="col-lg-4 mb-4">
					<div class="card h-100" id="appointment-form">
						<div class="card-header pb-0 pt-3">
							<div class="d-flex align-items-center">
								<div class="icon icon-shape icon-md bg-gradient-dark shadow text-center border-radius-md me-3">
									<i class="ni ni-calendar-grid-58 text-white text-lg opacity-10"></i>
								</div>
								<div>
									<h6 class="mb-0">{FORM_TITLE}</h6>
									<p class="text-xs text-muted mb-0">Fill in the details below</p>
								</div>
							</div>
						</div>
						<div class="card-body pt-3">
							<form method="post" id="appointmentForm">
								<input type="hidden" name="form_type" value="save">
								<input type="hidden" name="appointment_id" value="{APPOINTMENT_ID}">
								
							<div class="form-group mb-3">
									<label class="form-control-label text-sm font-weight-bold">Case <span class="text-danger">*</span></label>
									<select class="form-control" name="case_id" id="case_select" required>
										{CASE_OPTIONS}
								</select>
							</div>

							<div class="form-group mb-3">
									<label class="form-control-label text-sm font-weight-bold">Client</label>
									<input class="form-control" type="text" id="client_display" value="{CLIENT_NAME}" readonly>
									<small class="text-muted">Automatically populated based on selected case</small>
							</div>
								
							<div class="form-group mb-3">
									<label class="form-control-label text-sm font-weight-bold">Lawyer / Staff <span class="text-danger">*</span></label>
									<select class="form-control" name="lawyer_id" required>
										{LAWYER_OPTIONS}
								</select>
							</div>
								
							<div class="row">
									<div class="col-6">
									<div class="form-group mb-3">
											<label class="form-control-label text-sm font-weight-bold">Date <span class="text-danger">*</span></label>
											<input class="form-control" type="date" name="date" value="{DATE_VALUE}" required>
									</div>
								</div>
									<div class="col-6">
									<div class="form-group mb-3">
											<label class="form-control-label text-sm font-weight-bold">Time <span class="text-danger">*</span></label>
											<input class="form-control" type="time" name="time" value="{TIME_VALUE}" required>
									</div>
								</div>
							</div>
								
								<div class="form-group mb-4">
									<label class="form-control-label text-sm font-weight-bold">Notes</label>
									<textarea class="form-control" rows="3" name="notes" placeholder="Brief reason for appointment or additional details...">{NOTES_VALUE}</textarea>
								</div>
								
								<div class="d-flex gap-2">
									<button class="btn btn-dark btn-sm mb-0 flex-fill" type="submit">
										<i class="ni ni-check-bold me-1"></i> {SUBMIT_LABEL}
									</button>
									{CANCEL_EDIT_LINK}
							</div>
							</form>
						</div>
					</div>
				</div>

				<!-- Right Column: Appointments Schedule -->
				<div class="col-lg-8">
					<div class="card">
						<div class="card-header pb-0 pt-3">
							<div class="d-flex justify-content-between align-items-center">
								<div class="d-flex align-items-center">
									<div class="icon icon-shape icon-md bg-gradient-primary shadow text-center border-radius-md me-3">
										<i class="ni ni-time-alarm text-white text-lg opacity-10"></i>
									</div>
							<div>
										<h6 class="mb-0">All Appointments</h6>
										<p class="text-xs text-muted mb-0">View and manage scheduled appointments</p>
									</div>
								</div>
							</div>
						</div>
						<div class="card-body px-0 pt-0 pb-2">
							<div class="table-responsive">
								<table class="table align-items-center mb-0">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Case & Client</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Lawyer / Staff</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Date & Time</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Status</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end pe-3">Actions</th>
										</tr>
									</thead>
									<tbody>
										{APPOINTMENT_ROWS}
									</tbody>
								</table>
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
	<script src="../assets/js/spa-nav.js"></script>
	<script>
		// Handle case selection - auto-populate client name
		document.addEventListener('DOMContentLoaded', function() {
			var caseSelect = document.getElementById('case_select');
			var clientDisplay = document.getElementById('client_display');

			if (caseSelect && clientDisplay) {
				caseSelect.addEventListener('change', function() {
					var selectedOption = this.options[this.selectedIndex];
					if (selectedOption && selectedOption.value) {
						var clientName = selectedOption.getAttribute('data-client') || '';
						clientDisplay.value = clientName;
					} else {
						clientDisplay.value = '';
					}
				});

				// Trigger change event on page load to populate client for existing selection
				if (caseSelect.value) {
					caseSelect.dispatchEvent(new Event('change'));
				}
			}
		});

		// Handle edit functionality - scroll to form when id parameter is present
		(function() {
			var urlParams = new URLSearchParams(window.location.search);
			var editId = urlParams.get('id');
			if (editId) {
				// Wait for page to load, then scroll to form
				setTimeout(function() {
					var formElement = document.getElementById('appointment-form');
					if (formElement) {
						formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
						// Highlight the form briefly
						formElement.style.transition = 'box-shadow 0.3s ease';
						formElement.style.boxShadow = '0 0 20px rgba(0, 123, 255, 0.5)';
						setTimeout(function() {
							formElement.style.boxShadow = '';
						}, 2000);
					}
				}, 100);
			}
		})();

		// Handle appointment deletion
		function deleteAppointment(appointmentId, caseDisplay) {
			if (confirm('Are you sure you want to delete the appointment for "' + caseDisplay + '"?\n\nThis action cannot be undone.')) {
				// Create a form to submit the delete request
				var form = document.createElement('form');
				form.method = 'POST';
				form.innerHTML = '<input type="hidden" name="delete_appointment" value="1"><input type="hidden" name="appointment_id" value="' + appointmentId + '">';
				document.body.appendChild(form);
				form.submit();
			}
		}
	</script>
</body>
</html>
HTML;

$html = str_replace('{FORM_TITLE}', htmlspecialchars($formTitle), $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{APPOINTMENT_ID}', htmlspecialchars($formData['appointment_id']), $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($formData['client_name']), $html);
$html = str_replace('{LAWYER_OPTIONS}', $lawyerOptions, $html);
$html = str_replace('{DATE_VALUE}', htmlspecialchars($formData['date']), $html);
$html = str_replace('{TIME_VALUE}', htmlspecialchars($formData['time']), $html);
$html = str_replace('{NOTES_VALUE}', htmlspecialchars($formData['notes']), $html);
$html = str_replace('{SUBMIT_LABEL}', htmlspecialchars($submitLabel), $html);
$html = str_replace('{CANCEL_EDIT_LINK}', $cancelLink, $html);
$html = str_replace('{APPOINTMENT_ROWS}', $appointmentsRows, $html);

// rewrite internal links from .html to .php (fallback if any remain)
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

