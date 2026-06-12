<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/case_lawyers.php';
require_once __DIR__ . '/../lib/appointment_availability.php';
require_once __DIR__ . '/../inc/availability-date-picker.php';

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

$formData = [
    'appointment_id' => '',
    'case_id' => '',
    'client_name' => '',
    'lawyer_id' => '',
    'date' => '',
    'time' => '',
    'duration_minutes' => '60',
    'notes' => ''
];

try {
    $pdo->query("ALTER TABLE appointments ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER ends_at");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'save') {
    $appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
    $caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
    $lawyerId = isset($_POST['lawyer_id']) ? (int)$_POST['lawyer_id'] : 0;
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $time = isset($_POST['time']) ? trim($_POST['time']) : '';
    $durationMinutes = isset($_POST['duration_minutes']) ? (int) $_POST['duration_minutes'] : 60;
    if (!in_array($durationMinutes, [30, 60], true)) {
        $durationMinutes = 60;
    }
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    $clientId = 0;
    $clientName = '';
    $assignedCaseLawyerIds = [];
    if ($caseId) {
        $caseStmt = $pdo->prepare("SELECT c.client_id, cl.first_name, cl.last_name FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id = ?");
        $caseStmt->execute([$caseId]);
        $caseInfo = $caseStmt->fetch();
        if ($caseInfo) {
            $clientId = $caseInfo['client_id'];
            $clientName = trim($caseInfo['first_name'] . ' ' . $caseInfo['last_name']);
        }

        $caseLawyerStmt = $pdo->prepare("
            SELECT cl.lawyer_id
            FROM case_lawyers cl
            INNER JOIN lawyers l ON l.id = cl.lawyer_id
            WHERE cl.case_id = ? AND l.is_active = 1
            ORDER BY cl.is_primary DESC, cl.assigned_at ASC
        ");
        $caseLawyerStmt->execute([$caseId]);
        $assignedCaseLawyerIds = array_values(array_unique(array_map('intval', array_column($caseLawyerStmt->fetchAll(), 'lawyer_id'))));
    }

    $formData = [
        'appointment_id' => $appointmentId ? $appointmentId : '',
        'case_id' => $caseId,
        'client_name' => $clientName,
        'lawyer_id' => $lawyerId,
        'date' => $date,
        'time' => $time,
        'duration_minutes' => (string) $durationMinutes,
        'notes' => $notes
    ];

    $dateTime = null;
    $availabilityResult = ['ok' => false];

    if (empty($caseId) || empty($lawyerId) || empty($date) || empty($time)) {
        $message = 'Case, lawyer, date, and time are required.';
        $messageType = 'danger';
    } else {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
        if (!$dateTime) {
            $message = 'Invalid date or time format.';
            $messageType = 'danger';
        } else {
            $now = new DateTime();
            // Inputs are minute-level; normalize current time to minute precision.
            $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
            if ($dateTime < $now) {
                $message = 'Appointment date and time cannot be in the past.';
                $messageType = 'danger';
            } else {
                $excludeAppointmentId = $appointmentId > 0 ? $appointmentId : null;
                $availabilityResult = validateLawyerBookingAvailability($pdo, $lawyerId, $date, $time, $excludeAppointmentId, $durationMinutes);
                if (empty($availabilityResult['ok'])) {
                    $message = $availabilityResult['message'] ?? 'No available times on this date. Choose another date.';
                    $messageType = 'danger';
                }
            }
        }

        $availabilityOk = isset($availabilityResult) && !empty($availabilityResult['ok']);
        if ($messageType !== 'danger' && $dateTime && $availabilityOk) {
            $startsAt = $dateTime->format('Y-m-d H:i:s');
            $endsAt = (clone $dateTime)->modify('+' . $durationMinutes . ' minutes')->format('Y-m-d H:i:s');

            try {
                if ($appointmentId) {
                    $lawyerCheck = $pdo->prepare("SELECT id FROM lawyers WHERE id = ? AND is_active = 1");
                    $lawyerCheck->execute([$lawyerId]);
                    if (!$lawyerCheck->fetch()) {
                        $message = 'Please select a valid lawyer from the list.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
                        $stmt->execute([$appointmentId]);
                        $oldAppointment = $stmt->fetch();

                        if ($oldAppointment && strtolower((string) ($oldAppointment['status'] ?? 'pending')) === 'accepted') {
                            $message = 'Accepted appointments cannot be modified.';
                            $messageType = 'danger';
                        }

                        if ($messageType !== 'danger') {
                            $previousStatus = strtolower((string) ($oldAppointment['status'] ?? 'pending'));
                            if ($previousStatus === '') {
                                $previousStatus = 'pending';
                            }
                            $updatedStatus = $previousStatus;
                            // Rejected appointments must go back to pending so the assigned lawyer
                            // can accept or reject (including when admin picks a different lawyer).
                            if ($previousStatus === 'rejected') {
                                $updatedStatus = 'pending';
                            }

                            $stmt = $pdo->prepare("
                                UPDATE appointments
                                SET client_id = ?, case_id = ?, lawyer_id = ?, starts_at = ?, ends_at = ?, notes = ?, status = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$clientId, $caseId, $lawyerId, $startsAt, $endsAt, $notes, $updatedStatus, $appointmentId]);

                            if ($oldAppointment && (int) $oldAppointment['lawyer_id'] !== $lawyerId) {
                                removeAppointmentAvailabilitySlot($pdo, $appointmentId, (int) $oldAppointment['lawyer_id']);
                            }

                            syncAppointmentAvailabilitySlot($pdo, [
                                'id' => $appointmentId,
                                'lawyer_id' => $lawyerId,
                                'starts_at' => $startsAt,
                                'ends_at' => $endsAt,
                                'status' => $updatedStatus,
                            ]);

                            if ($oldAppointment) {
                                CaseEvents::trackAppointmentUpdated($caseId, $oldAppointment, [
                                    'client_id' => $clientId,
                                    'case_id' => $caseId,
                                    'lawyer_id' => $lawyerId,
                                    'starts_at' => $startsAt,
                                    'ends_at' => $endsAt,
                                    'notes' => $notes,
                                    'status' => $updatedStatus
                                ]);
                            }

                            $msg = $previousStatus === 'rejected'
                                ? 'Appointment reassigned. The lawyer must accept or reject this request.'
                                : 'Appointment updated successfully.';
                        }
                    }
                } else {
                    $lawyerCheck = $pdo->prepare("SELECT id FROM lawyers WHERE id = ? AND is_active = 1");
                    $lawyerCheck->execute([$lawyerId]);
                    if (!$lawyerCheck->fetch()) {
                        $message = 'Please select a valid lawyer from the list.';
                        $messageType = 'danger';
                    } elseif (!empty($assignedCaseLawyerIds) && !in_array($lawyerId, $assignedCaseLawyerIds, true)) {
                        $message = 'Please select a lawyer assigned to this case.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([$clientId, $caseId, $lawyerId, $startsAt, $endsAt, $notes]);
                        $newAppointmentId = (int) $pdo->lastInsertId();

                        syncAppointmentAvailabilitySlot($pdo, [
                            'id' => $newAppointmentId,
                            'lawyer_id' => $lawyerId,
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                            'status' => 'pending',
                        ]);

                        ensureLawyerAssignedToCase($pdo, $caseId, $lawyerId);

                        CaseEvents::trackAppointmentCreated($caseId, [
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                            'notes' => $notes
                        ]);

                        $msg = 'Appointment booked successfully.';
                    }
                }

                if ($messageType !== 'danger') {
                    header('Location: appointments.php?msg=' . urlencode($msg) . '&type=success');
                    exit;
                }
            } catch (PDOException $e) {
                $message = 'Error saving appointment: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    }
}

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
        if (strtolower((string) ($appointment['status'] ?? 'pending')) === 'accepted') {
            header('Location: appointments.php?msg=' . urlencode('Accepted appointments cannot be edited.') . '&type=danger');
            exit;
        }
        $startsAt = $appointment['starts_at'] ? new DateTime($appointment['starts_at']) : null;
        $endsAt = !empty($appointment['ends_at']) ? new DateTime($appointment['ends_at']) : null;
        $inferredDuration = 60;
        if ($startsAt && $endsAt) {
            $diffMinutes = (int) round(($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60);
            if ($diffMinutes > 0 && $diffMinutes <= 45) {
                $inferredDuration = 30;
            }
        }
        $clientName = trim((isset($appointment['first_name']) ? $appointment['first_name'] : '') . ' ' . (isset($appointment['last_name']) ? $appointment['last_name'] : ''));
        $formData = [
            'appointment_id' => $appointment['id'],
            'case_id' => $appointment['case_id'],
            'client_name' => $clientName,
            'lawyer_id' => $appointment['lawyer_id'],
            'date' => $startsAt ? $startsAt->format('Y-m-d') : '',
            'time' => $startsAt ? $startsAt->format('H:i') : '',
            'duration_minutes' => (string) $inferredDuration,
            'notes' => $appointment['notes']
        ];
    } else {
        $message = 'Appointment not found.';
        $messageType = 'danger';
    }
}

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

$caseLawyersMap = [];
$casePrimaryLawyerMap = [];
try {
    $caseLawyerRows = $pdo->query("
        SELECT cl.case_id, cl.lawyer_id
        FROM case_lawyers cl
        INNER JOIN lawyers l ON l.id = cl.lawyer_id AND l.is_active = 1
        ORDER BY cl.case_id, cl.is_primary DESC, cl.assigned_at ASC
    ")->fetchAll();

    foreach ($caseLawyerRows as $row) {
        $caseId = (int) $row['case_id'];
        $lawyerId = (int) $row['lawyer_id'];
        if (!isset($caseLawyersMap[$caseId])) {
            $caseLawyersMap[$caseId] = [];
            $casePrimaryLawyerMap[$caseId] = $lawyerId;
        }
        $caseLawyersMap[$caseId][] = $lawyerId;
    }
} catch (PDOException $e) {
    $caseLawyersMap = [];
    $casePrimaryLawyerMap = [];
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

$lawyerAvailabilityByDate = [];
$lawyerAvailabilityByDay = [];
$lawyerHasSchedule = [];
$lawyerWorkingHours = [];
$lawyerHasWorkingHours = [];
try {
    $lawyerIdsForAvailability = array_map(static function ($lawyer) {
        return (int) $lawyer['id'];
    }, $lawyersList);
    $availabilityMaps = loadLawyerAvailabilityForBooking($pdo, $lawyerIdsForAvailability);
    $lawyerAvailabilityByDate = $availabilityMaps['byDate'];
    $lawyerAvailabilityByDay = $availabilityMaps['byDay'];
    $lawyerHasSchedule = $availabilityMaps['hasSchedule'];
    $lawyerWorkingHours = $availabilityMaps['workingHours'] ?? [];
    $lawyerHasWorkingHours = $availabilityMaps['hasWorkingHours'] ?? [];
} catch (PDOException $e) {
    $lawyerAvailabilityByDate = [];
    $lawyerAvailabilityByDay = [];
    $lawyerHasSchedule = [];
    $lawyerWorkingHours = [];
    $lawyerHasWorkingHours = [];
}

$lawyerOptionsCatalog = [];
$lawyerLabelMap = [];
foreach ($lawyersList as $lawyer) {
    $label = trim(preg_replace('/\s+/u', ' ', ($lawyer['first_name'] ?? '') . ' ' . ($lawyer['last_name'] ?? '')));
    if ($label === '') {
        $label = 'Lawyer #' . (int) $lawyer['id'];
    }
    $lawyerLabelMap[(int) $lawyer['id']] = $label;
    $lawyerOptionsCatalog[] = [
        'id' => (int) $lawyer['id'],
        'label' => $label,
    ];
}

$lawyerOptions = '<option value="">Select lawyer</option>';
foreach ($lawyerOptionsCatalog as $entry) {
    $selected = ((int) $formData['lawyer_id'] === (int) $entry['id']) ? ' selected' : '';
    $lawyerOptions .= '<option value="' . (int) $entry['id'] . '"' . $selected . '>'
        . htmlspecialchars($entry['label']) . '</option>';
}

$caseOptions = '<option value="">Select case</option>';
foreach ($casesList as $case) {
    $selected = ((int)$formData['case_id'] === (int)$case['id']) ? ' selected' : '';
    $caseId = (int) $case['id'];
    $assignedLawyerIds = isset($caseLawyersMap[$caseId]) ? $caseLawyersMap[$caseId] : [];
    $primaryLawyerId = isset($casePrimaryLawyerMap[$caseId]) ? (int) $casePrimaryLawyerMap[$caseId] : '';
    $caseOptions .= '<option value="' . $caseId . '"'
        . ' data-client="' . htmlspecialchars($case['client_name']) . '"'
        . ' data-lawyer-id="' . $primaryLawyerId . '"'
        . ' data-lawyer-ids="' . htmlspecialchars(implode(',', $assignedLawyerIds)) . '"'
        . $selected . '>' . htmlspecialchars($case['case_display']) . '</option>';
}

$isEditing = !empty($formData['appointment_id']);
$editingAppointmentStatus = '';
if ($isEditing) {
    if (isset($appointment) && is_array($appointment)) {
        $editingAppointmentStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
    } else {
        $statusStmt = $pdo->prepare('SELECT status FROM appointments WHERE id = ?');
        $statusStmt->execute([(int) $formData['appointment_id']]);
        $editingAppointmentStatus = strtolower((string) ($statusStmt->fetchColumn() ?: 'pending'));
    }
}
$isRejectedReassign = $isEditing && $editingAppointmentStatus === 'rejected';
$formTitle = $isRejectedReassign ? 'Reassign Appointment' : ($isEditing ? 'Update Appointment' : 'Book Appointment');
$pageTitle = $isRejectedReassign ? 'Reassign Appointment' : ($isEditing ? 'Edit Appointment' : 'New Appointment');
$submitLabel = $isRejectedReassign ? 'Assign & send to lawyer' : ($isEditing ? 'Save Changes' : 'Submit Request');
$rejectedReassignNoticeHtml = '';
if ($isRejectedReassign) {
    $rejectedReassignNoticeHtml = '<div class="alert alert-info py-2 mb-3" role="alert">'
        . '<i class="ni ni-info-16"></i> This appointment was rejected. Choose a lawyer and time, then save. '
        . 'The assigned lawyer will receive it as <strong>pending</strong> and can accept or reject.</div>';
}
$cancelLink = '<a href="appointments.php" class="btn btn-outline-secondary btn-sm mb-0" title="Back to appointments"><i class="ni ni-bold-left me-1"></i> Back to list</a>';
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
	<title>LegalPro Case Manager - {PAGE_TITLE}</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	{AVAILABILITY_DATE_PICKER_HEAD}
	<style>
		#appointment_time option.lp-time-unavailable,
		#appointment_time option:disabled {
			color: #94a3b8;
			text-decoration: line-through;
		}
		#lawyer_select option,
		#case_select option,
		#appointment_time option {
			padding: 0.2rem 0.5rem;
		}
	</style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
		<div class="sidenav-header">
			<a class="navbar-brand m-0" href="dashboard.php">LegalPro</a>
		</div>
	</aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<nav aria-label="breadcrumb">
					<ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
						<li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="appointments.php">Appointments</a></li>
						<li class="breadcrumb-item text-sm text-white active" aria-current="page">{PAGE_TITLE}</li>
					</ol>
					<h6 class="font-weight-bolder text-white mb-0">{PAGE_TITLE}</h6>
				</nav>
			</div>
		</nav>
		<div class="container-fluid py-4">
			{MESSAGE}

			<div class="row justify-content-center">
				<div class="col-lg-8">
					<div class="card" id="appointment-form">
						<div class="card-header pb-0 pt-3">
							<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
								<div class="d-flex align-items-center">
									<div class="icon icon-shape icon-md bg-gradient-dark shadow text-center border-radius-md me-3">
										<i class="ni ni-calendar-grid-58 text-white text-lg opacity-10"></i>
									</div>
									<div>
										<h6 class="mb-0">{FORM_TITLE}</h6>
										<p class="text-xs text-muted mb-0">Fill in the details below</p>
									</div>
								</div>
								{CANCEL_EDIT_LINK}
							</div>
						</div>
						<div class="card-body pt-3">
							{REJECTED_REASSIGN_NOTICE}
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
									<select class="form-control" name="lawyer_id" id="lawyer_select" required>
										{LAWYER_OPTIONS}
									</select>
									<small class="text-muted">One lawyer receives this appointment and can accept or reject it.</small>
								</div>

								<div class="row">
									<div class="col-md-4">
										<div class="form-group mb-3">
											<label class="form-control-label text-sm font-weight-bold">Date <span class="text-danger">*</span></label>
											<div class="legalpro-date-picker-wrap">
												<input class="form-control" type="text" name="date" id="appointment_date" value="{DATE_VALUE}" placeholder="Select date" required readonly>
											</div>
											<small class="text-muted">Crossed-out dates have no available times for the selected lawyer.</small>
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group mb-3">
											<label class="form-control-label text-sm font-weight-bold">Time category <span class="text-danger">*</span></label>
											<select class="form-control" name="duration_minutes" id="appointment_duration" required>
												<option value="60"{DURATION_60_SELECTED}>1 hour</option>
												<option value="30"{DURATION_30_SELECTED}>30 minutes</option>
											</select>
											<small class="text-muted">30 min uses slots every half hour (e.g. 9:00–9:30).</small>
										</div>
									</div>
									<div class="col-md-4">
										<div class="form-group mb-3">
											<label class="form-control-label text-sm font-weight-bold">Time <span class="text-danger">*</span></label>
											<select class="form-control" name="time" id="appointment_time" required>
												<option value="">Select time</option>
											</select>
										</div>
									</div>
								</div>
								<small class="text-muted d-block mb-3">Unavailable times cannot be selected.</small>
								<div id="availabilityMessage" class="mb-3" style="display: none;"></div>
								<small class="text-muted d-block mb-3">Appointments can only be booked when the lawyer has published availability for the selected date. Unavailable blocks and existing appointments are excluded.</small>

								<div class="form-group mb-4">
									<label class="form-control-label text-sm font-weight-bold">Notes</label>
									<textarea class="form-control" rows="3" name="notes" placeholder="Brief reason for appointment or additional details...">{NOTES_VALUE}</textarea>
								</div>

								<div class="d-flex gap-2">
									<button class="btn btn-dark btn-sm mb-0" type="submit" id="submitAppointmentBtn" disabled>
										<i class="ni ni-check-bold me-1"></i> {SUBMIT_LABEL}
									</button>
								</div>
							</form>
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
		const lawyerOptionsCatalog = {LAWYER_OPTIONS_CATALOG_JSON};
		const lawyerAvailabilityByDate = {LAWYER_AVAILABILITY_BY_DATE_JSON};
		const lawyerAvailabilityByDay = {LAWYER_AVAILABILITY_BY_DAY_JSON};
		const lawyerHasSchedule = {LAWYER_HAS_SCHEDULE_JSON};
		const lawyerWorkingHours = {LAWYER_WORKING_HOURS_JSON};
		const lawyerHasWorkingHours = {LAWYER_HAS_WORKING_HOURS_JSON};
		const initialAppointmentTime = '{TIME_VALUE}';
		const initialDurationMinutes = parseInt('{DURATION_MINUTES}', 10) || 60;
		const NO_AVAILABILITY_ON_DATE_MSG = 'No available times on this date. Choose another date.';
		const SLOT_DAY_START_MINUTES = 9 * 60;
		const SLOT_DAY_END_MINUTES = 17 * 60 + 30;

		document.addEventListener('DOMContentLoaded', function() {
			var caseSelect = document.getElementById('case_select');
			var clientDisplay = document.getElementById('client_display');
			var lawyerSelect = document.getElementById('lawyer_select');

			function filterLawyerOptions(allowedLawyerIds, keepCurrentValue) {
				if (!lawyerSelect || !Array.isArray(lawyerOptionsCatalog)) {
					return;
				}

				var currentValue = keepCurrentValue ? lawyerSelect.value : '';
				var hasAllowedLawyers = allowedLawyerIds.length > 0;

				lawyerSelect.innerHTML = '';
				var placeholder = document.createElement('option');
				placeholder.value = '';
				placeholder.textContent = 'Select lawyer';
				lawyerSelect.appendChild(placeholder);

				lawyerOptionsCatalog.forEach(function(entry) {
					var id = String(entry.id);
					var isAllowed = !hasAllowedLawyers
						|| allowedLawyerIds.indexOf(id) !== -1
						|| (keepCurrentValue && id === currentValue);
					if (!isAllowed) {
						return;
					}

					var option = document.createElement('option');
					option.value = id;
					option.textContent = entry.label;
					if (keepCurrentValue && id === currentValue) {
						option.selected = true;
					}
					lawyerSelect.appendChild(option);
				});
			}

			function syncCaseDependentFields(updateLawyer) {
				if (!caseSelect) {
					return;
				}

				var selectedOption = caseSelect.options[caseSelect.selectedIndex];
				if (selectedOption && selectedOption.value) {
					if (clientDisplay) {
						clientDisplay.value = selectedOption.getAttribute('data-client') || '';
					}

					var lawyerIdsRaw = selectedOption.getAttribute('data-lawyer-ids') || '';
					var allowedLawyerIds = lawyerIdsRaw ? lawyerIdsRaw.split(',').filter(Boolean) : [];
					var isEditing = Boolean(document.querySelector('input[name="appointment_id"]') && document.querySelector('input[name="appointment_id"]').value);
					// While editing, keep all active lawyers available so a rejected
					// appointment can be reassigned to another lawyer quickly.
					filterLawyerOptions(isEditing ? [] : allowedLawyerIds, !updateLawyer);

					if (updateLawyer && lawyerSelect) {
						var primaryLawyerId = selectedOption.getAttribute('data-lawyer-id') || '';
						lawyerSelect.value = primaryLawyerId || allowedLawyerIds[0] || '';
					}
				} else {
					if (clientDisplay) {
						clientDisplay.value = '';
					}
					filterLawyerOptions([], false);
					if (updateLawyer && lawyerSelect) {
						lawyerSelect.value = '';
					}
				}
			}

			if (caseSelect) {
				caseSelect.addEventListener('change', function() {
					syncCaseDependentFields(true);
					refreshAppointmentDatePicker();
					renderTimeOptions();
				});

				if (caseSelect.value) {
					syncCaseDependentFields(false);
				}
			}

            var dateInput = document.getElementById('appointment_date');
            var durationSelect = document.getElementById('appointment_duration');
            var timeInput = document.getElementById('appointment_time');
            var appointmentForm = document.getElementById('appointmentForm');
            var availabilityMessage = document.getElementById('availabilityMessage');

            function getDurationMinutes() {
                var value = durationSelect ? parseInt(durationSelect.value, 10) : 60;
                return value === 30 ? 30 : 60;
            }

            function getStandardSlotTimes(durationMinutes) {
                var times = [];
                var lastStart = durationMinutes === 30 ? SLOT_DAY_END_MINUTES : SLOT_DAY_END_MINUTES - 30;
                for (var t = SLOT_DAY_START_MINUTES; t <= lastStart; t += durationMinutes) {
                    var h = Math.floor(t / 60);
                    var m = t % 60;
                    times.push(String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0'));
                }
                return times;
            }

            function addDurationToTime(timeValue, durationMinutes) {
                var normalized = timeValue.length === 5 ? timeValue : timeValue.slice(0, 5);
                var parts = normalized.split(':');
                var total = parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10) + durationMinutes;
                if (total >= 24 * 60) {
                    total = 24 * 60 - 1;
                }
                var h = Math.floor(total / 60);
                var m = total % 60;
                return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':00';
            }

            function formatSlotRangeLabel(startValue, durationMinutes) {
                if (durationMinutes === 60) {
                    return formatTimeLabel(startValue);
                }
                var endHm = addDurationToTime(startValue.length === 5 ? startValue + ':00' : startValue, durationMinutes).slice(0, 5);
                return formatTimeLabel(startValue) + ' \u2013 ' + formatTimeLabel(endHm);
            }

            function lawyerHasPublishedSchedule(lawyerId) {
                return !!(lawyerHasSchedule[lawyerId] || lawyerHasSchedule[String(lawyerId)]);
            }

            function lawyerHasWorkingHoursConfig(lawyerId) {
                return !!(lawyerHasWorkingHours[lawyerId] || lawyerHasWorkingHours[String(lawyerId)]);
            }

            function getWorkingHoursForDate(lawyerId, dateValue) {
                var schedule = lawyerWorkingHours[lawyerId] || lawyerWorkingHours[String(lawyerId)] || {};
                return schedule[getDayOfWeekFromDate(dateValue)] || null;
            }

            function isWithinWorkingHours(timeValue, lawyerId, dateValue, durationMinutes) {
                if (!lawyerHasWorkingHoursConfig(lawyerId)) {
                    return true;
                }
                var day = getWorkingHoursForDate(lawyerId, dateValue);
                if (!day || !day.enabled) {
                    return false;
                }
                var startTime = normalizeTimeValue(timeValue);
                var endTime = addDurationToTime(startTime, durationMinutes);
                return startTime >= day.start && endTime <= day.end;
            }

            function getDayOfWeekFromDate(dateValue) {
                var days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                var date = new Date(dateValue + 'T00:00:00');
                return days[date.getDay()];
            }

            function timeToMinutes(timeValue) {
                if (!timeValue) {
                    return -1;
                }
                var parts = String(timeValue).split(':');
                return parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
            }

            function getSlotsForLawyerAndDate(lawyerId, dateValue) {
                var byDate = lawyerAvailabilityByDate[lawyerId] || lawyerAvailabilityByDate[String(lawyerId)] || {};
                var slots = byDate[dateValue] ? byDate[dateValue].slice() : [];
                var byDay = lawyerAvailabilityByDay[lawyerId] || lawyerAvailabilityByDay[String(lawyerId)] || {};
                var dayKey = getDayOfWeekFromDate(dateValue);
                if (byDay[dayKey]) {
                    slots = slots.concat(byDay[dayKey]);
                }
                return slots;
            }

            function lawyerHasAvailabilityOnDate(lawyerId, dateValue) {
                var byDate = lawyerAvailabilityByDate[lawyerId] || lawyerAvailabilityByDate[String(lawyerId)] || {};
                return (byDate[dateValue] || []).some(function(slot) {
                    return slot.type === 'available';
                });
            }

            function isLawyerDateUnavailable(dateObj) {
                if (!(dateObj instanceof Date) || isNaN(dateObj.getTime()) || typeof LegalproAvailabilityDatePicker === 'undefined') {
                    return true;
                }

                var dateValue = LegalproAvailabilityDatePicker.formatDate(dateObj);
                if (!dateValue) {
                    return true;
                }

                var now = nowParts();
                if (dateValue < now.date) {
                    return true;
                }

                if (lawyerHasWorkingHoursConfig(lawyerId)) {
                    var dayHours = getWorkingHoursForDate(lawyerId, dateValue);
                    if (!dayHours || !dayHours.enabled) {
                        return true;
                    }
                } else if (!lawyerHasPublishedSchedule(lawyerId) || !lawyerHasAvailabilityOnDate(lawyerId, dateValue)) {
                    return true;
                }

                var slots = getSlotsForLawyerAndDate(lawyerId, dateValue);
                var durationMinutes = getDurationMinutes();
                var published = lawyerHasPublishedSchedule(lawyerId);

                return !getStandardSlotTimes(durationMinutes).some(function(slotValue) {
                    return isTimeSlotBookable(slotValue, lawyerId, dateValue, slots, published, durationMinutes);
                });
                var lawyerId = lawyerSelect ? lawyerSelect.value : '';
                if (!lawyerId) {
                    return false;
                }

                if (!lawyerHasPublishedSchedule(lawyerId)) {
                    return false;
                }

                return !lawyerHasAvailabilityOnDate(lawyerId, dateValue);
            }

            function appointmentDatePickerOptions() {
                return {
                    minDate: 'today',
                    isUnavailable: isLawyerDateUnavailable,
                    onChange: function() {
                        renderTimeOptions();
                    }
                };
            }

            function initAppointmentDatePicker() {
                if (!dateInput || typeof LegalproAvailabilityDatePicker === 'undefined') {
                    return;
                }

                LegalproAvailabilityDatePicker.create(dateInput, appointmentDatePickerOptions());
            }

            function refreshAppointmentDatePicker() {
                if (!dateInput || typeof LegalproAvailabilityDatePicker === 'undefined') {
                    return;
                }

                var pickerOptions = appointmentDatePickerOptions();
                if (LegalproAvailabilityDatePicker.instances[dateInput.id]) {
                    LegalproAvailabilityDatePicker.clearIfUnavailable(dateInput, isLawyerDateUnavailable);
                    LegalproAvailabilityDatePicker.refresh(dateInput, pickerOptions);
                } else {
                    LegalproAvailabilityDatePicker.create(dateInput, pickerOptions);
                }
                renderTimeOptions();
            }

            function setSubmitEnabled(enabled) {
                var submitBtn = document.getElementById('submitAppointmentBtn');
                if (!submitBtn) {
                    return;
                }
                submitBtn.disabled = !enabled;
                submitBtn.title = enabled ? '' : 'Select a lawyer, date, and available time before booking.';
            }

            function normalizeTimeValue(timeValue) {
                if (!timeValue) {
                    return '';
                }
                return timeValue.length === 5 ? timeValue + ':00' : timeValue;
            }

            function rangesOverlapMinutes(startA, endA, startB, endB) {
                return startA < endB && endA > startB;
            }

            function isBlockedByUnavailable(timeValue, slots, durationMinutes) {
                var startMinutes = timeToMinutes(timeValue);
                if (startMinutes < 0) {
                    return false;
                }
                var endMinutes = startMinutes + durationMinutes;
                return slots.some(function(slot) {
                    if (slot.type !== 'unavailable') {
                        return false;
                    }
                    return rangesOverlapMinutes(
                        startMinutes,
                        endMinutes,
                        timeToMinutes(slot.start),
                        timeToMinutes(slot.end)
                    );
                });
            }

            function isWithinAvailable(timeValue, slots, durationMinutes) {
                var startMinutes = timeToMinutes(timeValue);
                if (startMinutes < 0) {
                    return false;
                }
                var endMinutes = startMinutes + durationMinutes;
                return slots.some(function(slot) {
                    return slot.type === 'available'
                        && startMinutes >= timeToMinutes(slot.start)
                        && endMinutes <= timeToMinutes(slot.end);
                });
            }

            function setAvailabilityMessage(html, visible) {
                if (!availabilityMessage) {
                    return;
                }
                availabilityMessage.style.display = visible ? 'block' : 'none';
                availabilityMessage.innerHTML = html || '';
            }

            function formatTimeLabel(timeValue) {
                var parts = timeValue.split(':');
                var hours = parseInt(parts[0], 10);
                var minutes = parts[1] || '00';
                var period = hours >= 12 ? 'PM' : 'AM';
                var displayHours = hours % 12;
                if (displayHours === 0) {
                    displayHours = 12;
                }
                return displayHours + ':' + minutes + ' ' + period;
            }

            function normalizeSelectTime(timeValue) {
                if (!timeValue) {
                    return '';
                }
                var parts = timeValue.split(':');
                if (parts.length < 2) {
                    return timeValue;
                }
                return parts[0].padStart(2, '0') + ':' + parts[1].padStart(2, '0');
            }

            function isTimeSlotBookable(timeValue, lawyerId, dateValue, slots, hasSchedule, durationMinutes) {
                if (!timeValue) {
                    return false;
                }
                var now = nowParts();
                if (dateValue === now.date && timeValue < now.time) {
                    return false;
                }
                if (!isWithinWorkingHours(timeValue, lawyerId, dateValue, durationMinutes)) {
                    return false;
                }
                if (isBlockedByUnavailable(timeValue, slots, durationMinutes)) {
                    return false;
                }
                var availableSlots = slots.filter(function(slot) { return slot.type === 'available'; });
                if (availableSlots.length > 0) {
                    return isWithinAvailable(timeValue, slots, durationMinutes);
                }
                if (hasSchedule && !lawyerHasWorkingHoursConfig(lawyerId)) {
                    return false;
                }
                return true;
            }

            function rebuildTimeSelectOptions(durationMinutes, preservedTime) {
                if (!timeInput) {
                    return preservedTime;
                }
                var previous = preservedTime || normalizeSelectTime(timeInput.value);
                timeInput.innerHTML = '<option value="">Select time</option>';
                getStandardSlotTimes(durationMinutes).forEach(function(slotValue) {
                    var option = document.createElement('option');
                    option.value = slotValue;
                    option.textContent = formatSlotRangeLabel(slotValue, durationMinutes);
                    option.className = 'time-option';
                    timeInput.appendChild(option);
                });
                if (previous && !timeInput.querySelector('option[value="' + previous + '"]')) {
                    var customOption = document.createElement('option');
                    customOption.value = previous;
                    customOption.textContent = formatSlotRangeLabel(previous, durationMinutes);
                    customOption.className = 'time-option';
                    timeInput.appendChild(customOption);
                }
                return previous;
            }

            function renderTimeOptions() {
                if (!timeInput) {
                    return;
                }

                var lawyerId = lawyerSelect ? lawyerSelect.value : '';
                var dateValue = dateInput ? dateInput.value : '';
                var durationMinutes = getDurationMinutes();
                var preservedTime = rebuildTimeSelectOptions(durationMinutes, normalizeSelectTime(timeInput.value || initialAppointmentTime));

                if (!lawyerId || !dateValue) {
                    setAvailabilityMessage(
                        lawyerId
                            ? '<div class="alert alert-info py-2 mb-0"><i class="ni ni-info-16"></i> Select a date to see available time slots.</div>'
                            : '',
                        !!lawyerId
                    );
                    return;
                }

                var slots = getSlotsForLawyerAndDate(lawyerId, dateValue);
                var hasSchedule = lawyerHasPublishedSchedule(lawyerId);
                var hasBookableSlot = false;

                if (lawyerHasWorkingHoursConfig(lawyerId)) {
                    var dayHours = getWorkingHoursForDate(lawyerId, dateValue);
                    if (!dayHours || !dayHours.enabled) {
                        timeInput.querySelectorAll('.time-option').forEach(function(option) {
                            if (option.value) {
                                option.disabled = true;
                                option.classList.add('lp-time-unavailable');
                            }
                        });
                        timeInput.value = '';
                        setAvailabilityMessage(
                            '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> This lawyer does not work on the selected day.</div>',
                            true
                        );
                        setSubmitEnabled(false);
                        return;
                    }
                } else if (hasSchedule && !lawyerHasAvailabilityOnDate(lawyerId, dateValue)) {
                    timeInput.querySelectorAll('.time-option').forEach(function(option) {
                        if (option.value) {
                            option.disabled = true;
                            option.classList.add('lp-time-unavailable');
                        }
                    });
                    timeInput.value = '';
                    setAvailabilityMessage(
                        '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> ' + NO_AVAILABILITY_ON_DATE_MSG + '</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return;
                }

                timeInput.querySelectorAll('.time-option').forEach(function(option) {
                    if (!option.value) {
                        return;
                    }

                    if (isTimeSlotBookable(option.value, lawyerId, dateValue, slots, hasSchedule, durationMinutes)) {
                        hasBookableSlot = true;
                    } else {
                        option.disabled = true;
                    }
                });

                if (preservedTime) {
                    timeInput.value = preservedTime;
                    var selectedOption = timeInput.options[timeInput.selectedIndex];
                    if (selectedOption && selectedOption.disabled) {
                        timeInput.value = '';
                    }
                }

                if (!hasBookableSlot) {
                    setAvailabilityMessage(
                        '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> ' + NO_AVAILABILITY_ON_DATE_MSG + '</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return;
                }

                validateLawyerAvailabilitySelection();
            }

            function validateLawyerAvailabilitySelection() {
                if (!lawyerSelect || !dateInput || !timeInput) {
                    return true;
                }

                var lawyerId = lawyerSelect.value;
                var dateValue = dateInput.value;
                var timeValue = timeInput.value;

                timeInput.setCustomValidity('');

                if (!lawyerId || !dateValue) {
                    setAvailabilityMessage('', false);
                    setSubmitEnabled(false);
                    return false;
                }

                var hasSchedule = lawyerHasPublishedSchedule(lawyerId);
                if (lawyerHasWorkingHoursConfig(lawyerId)) {
                    var dayHours = getWorkingHoursForDate(lawyerId, dateValue);
                    if (!dayHours || !dayHours.enabled) {
                        timeInput.setCustomValidity('This lawyer does not work on the selected day.');
                        setAvailabilityMessage(
                            '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> This lawyer does not work on the selected day.</div>',
                            true
                        );
                        setSubmitEnabled(false);
                        return false;
                    }
                } else if (hasSchedule && !lawyerHasAvailabilityOnDate(lawyerId, dateValue)) {
                    timeInput.setCustomValidity(NO_AVAILABILITY_ON_DATE_MSG);
                    setAvailabilityMessage(
                        '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> ' + NO_AVAILABILITY_ON_DATE_MSG + '</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return false;
                }

                if (!timeValue) {
                    setAvailabilityMessage(
                        '<div class="alert alert-info py-2 mb-0"><i class="ni ni-info-16"></i> Select an available time from the list.</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return false;
                }

                var selectedOption = timeInput.options[timeInput.selectedIndex];
                if (selectedOption && selectedOption.disabled) {
                    timeInput.setCustomValidity('The selected time is not available.');
                    setAvailabilityMessage(
                        '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> The selected time is not available. Choose another slot.</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return false;
                }

                var slots = getSlotsForLawyerAndDate(lawyerId, dateValue);
                var durationMinutes = getDurationMinutes();

                if (isBlockedByUnavailable(timeValue, slots, durationMinutes)) {
                    timeInput.setCustomValidity('This lawyer is unavailable at the selected time.');
                    setAvailabilityMessage(
                        '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> This lawyer is unavailable at the selected time. Choose another slot.</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return false;
                }

                if (!isWithinWorkingHours(timeValue, lawyerId, dateValue, durationMinutes)) {
                    timeInput.setCustomValidity('Selected time is outside the lawyer\'s working hours.');
                    setAvailabilityMessage(
                        '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> Selected time is outside the lawyer\'s working hours.</div>',
                        true
                    );
                    setSubmitEnabled(false);
                    return false;
                }

                if (hasSchedule) {
                    var availableSlots = slots.filter(function(slot) { return slot.type === 'available'; });
                    if (availableSlots.length > 0 && !isWithinAvailable(timeValue, slots, durationMinutes)) {
                        timeInput.setCustomValidity('Selected time is outside the lawyer\'s available hours.');
                        setAvailabilityMessage(
                            '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> Selected time is outside the lawyer\'s published availability.</div>',
                            true
                        );
                        setSubmitEnabled(false);
                        return false;
                    }

                    if (availableSlots.length === 0 && !lawyerHasWorkingHoursConfig(lawyerId)) {
                        timeInput.setCustomValidity(NO_AVAILABILITY_ON_DATE_MSG);
                        setAvailabilityMessage(
                            '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> ' + NO_AVAILABILITY_ON_DATE_MSG + '</div>',
                            true
                        );
                        setSubmitEnabled(false);
                        return false;
                    }
                }

                setAvailabilityMessage('', false);
                setSubmitEnabled(true);
                return true;
            }

            function nowParts() {
                var now = new Date();
                var yyyy = now.getFullYear();
                var mm = String(now.getMonth() + 1).padStart(2, '0');
                var dd = String(now.getDate()).padStart(2, '0');
                var hh = String(now.getHours()).padStart(2, '0');
                var mi = String(now.getMinutes()).padStart(2, '0');
                return {
                    date: yyyy + '-' + mm + '-' + dd,
                    time: hh + ':' + mi
                };
            }

            function syncAppointmentMinDateTime() {
                if (!dateInput) {
                    return;
                }

                var now = nowParts();
                dateInput.setAttribute('min', now.date);

                if (timeInput && timeInput.value && dateInput.value === now.date && timeInput.value < now.time) {
                    timeInput.setCustomValidity('Appointment time cannot be in the past.');
                } else if (timeInput) {
                    timeInput.setCustomValidity('');
                }
            }

            if (lawyerSelect) {
                lawyerSelect.addEventListener('change', function() {
                    if (timeInput) {
                        timeInput.value = '';
                    }
                    if (dateInput && typeof LegalproAvailabilityDatePicker !== 'undefined') {
                        LegalproAvailabilityDatePicker.rebuild(dateInput, appointmentDatePickerOptions());
                    }
                    refreshAppointmentDatePicker();
                });
            }

            if (durationSelect) {
                durationSelect.addEventListener('change', function() {
                    if (timeInput) {
                        timeInput.value = '';
                    }
                    refreshAppointmentDatePicker();
                });
            }

            if (dateInput && timeInput) {
                dateInput.addEventListener('change', function() {
                    syncAppointmentMinDateTime();
                    renderTimeOptions();
                });
                timeInput.addEventListener('change', function() {
                    syncAppointmentMinDateTime();
                    validateLawyerAvailabilitySelection();
                });
                if (durationSelect && initialDurationMinutes) {
                    durationSelect.value = String(initialDurationMinutes);
                }
                initAppointmentDatePicker();
                refreshAppointmentDatePicker();
            }

            if (appointmentForm && dateInput && timeInput) {
                appointmentForm.addEventListener('submit', function(event) {
                    syncAppointmentMinDateTime();
                    var now = nowParts();
                    if (dateInput.value && timeInput.value && dateInput.value === now.date && timeInput.value < now.time) {
                        event.preventDefault();
                        timeInput.setCustomValidity('Appointment time cannot be in the past.');
                        timeInput.reportValidity();
                        return;
                    }

                    if (!validateLawyerAvailabilitySelection()) {
                        event.preventDefault();
                        timeInput.reportValidity();
                        return;
                    }

                    timeInput.setCustomValidity('');
                });
            }
		});
	</script>
	{AVAILABILITY_DATE_PICKER_FOOT}
</body>
</html>
HTML;

ob_start();
legalpro_render_availability_date_picker_assets();
legalpro_render_availability_date_picker_styles();
$availabilityDatePickerHead = ob_get_clean();

ob_start();
legalpro_render_availability_date_picker_script();
$availabilityDatePickerFoot = ob_get_clean();

$html = str_replace('{AVAILABILITY_DATE_PICKER_HEAD}', $availabilityDatePickerHead, $html);
$html = str_replace('{AVAILABILITY_DATE_PICKER_FOOT}', $availabilityDatePickerFoot, $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars($pageTitle), $html);
$html = str_replace('{FORM_TITLE}', htmlspecialchars($formTitle), $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{REJECTED_REASSIGN_NOTICE}', $rejectedReassignNoticeHtml, $html);
$html = str_replace('{APPOINTMENT_ID}', htmlspecialchars($formData['appointment_id']), $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($formData['client_name']), $html);
$html = str_replace('{LAWYER_OPTIONS}', $lawyerOptions, $html);
$html = str_replace('{LAWYER_OPTIONS_CATALOG_JSON}', json_encode($lawyerOptionsCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), $html);
$html = str_replace('{DATE_VALUE}', htmlspecialchars($formData['date']), $html);
$html = str_replace('{TIME_VALUE}', htmlspecialchars($formData['time']), $html);
$durationMinutesForm = (int) ($formData['duration_minutes'] ?? 60);
if (!in_array($durationMinutesForm, [30, 60], true)) {
    $durationMinutesForm = 60;
}
$html = str_replace('{DURATION_MINUTES}', (string) $durationMinutesForm, $html);
$html = str_replace('{DURATION_60_SELECTED}', $durationMinutesForm === 60 ? ' selected' : '', $html);
$html = str_replace('{DURATION_30_SELECTED}', $durationMinutesForm === 30 ? ' selected' : '', $html);
$html = str_replace('{LAWYER_AVAILABILITY_BY_DATE_JSON}', json_encode($lawyerAvailabilityByDate), $html);
$html = str_replace('{LAWYER_AVAILABILITY_BY_DAY_JSON}', json_encode($lawyerAvailabilityByDay), $html);
$html = str_replace('{LAWYER_HAS_SCHEDULE_JSON}', json_encode($lawyerHasSchedule), $html);
$html = str_replace('{LAWYER_WORKING_HOURS_JSON}', json_encode($lawyerWorkingHours), $html);
$html = str_replace('{LAWYER_HAS_WORKING_HOURS_JSON}', json_encode($lawyerHasWorkingHours), $html);
$html = str_replace('{NOTES_VALUE}', htmlspecialchars($formData['notes']), $html);
$html = str_replace('{SUBMIT_LABEL}', htmlspecialchars($submitLabel), $html);
$html = str_replace('{CANCEL_EDIT_LINK}', $cancelLink, $html);

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
