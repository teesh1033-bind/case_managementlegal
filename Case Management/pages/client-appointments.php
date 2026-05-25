<?php
session_start();
require_once __DIR__ . '/../inc/db.php';


// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

// AJAX: appointment details for modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'appointment_details') {
    header('Content-Type: application/json; charset=utf-8');

    $appointmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($appointmentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid appointment ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                a.*,
                c.title AS case_title,
                CONCAT(l.first_name, ' ', l.last_name) AS lawyer_name
            FROM appointments a
            LEFT JOIN cases c ON c.id = a.case_id
            LEFT JOIN lawyers l ON l.id = a.lawyer_id
            WHERE a.id = ? AND a.client_id = ?
        ");
        $stmt->execute([$appointmentId, $client_id]);
        $apt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$apt) {
            http_response_code(404);
            echo json_encode(['error' => 'Appointment not found']);
            exit;
        }

        $startsAt = strtotime($apt['starts_at']);
        $endsAt = !empty($apt['ends_at']) ? strtotime($apt['ends_at']) : null;
        $now = time();

        $statusLabel = ucfirst($apt['status'] ?? 'unknown');
        $statusClass = 'secondary';
        if (($apt['status'] ?? '') === 'pending') {
            $statusLabel = 'Pending approval';
            $statusClass = 'warning';
        } elseif (($apt['status'] ?? '') === 'accepted') {
            if ($startsAt > $now) {
                $statusLabel = 'Upcoming';
                $statusClass = 'info';
            } elseif ($endsAt && $endsAt < $now) {
                $statusLabel = 'Completed';
                $statusClass = 'success';
            } else {
                $statusLabel = 'In progress';
                $statusClass = 'primary';
            }
        } elseif (($apt['status'] ?? '') === 'rejected') {
            $statusLabel = 'Rejected';
            $statusClass = 'danger';
        }

        echo json_encode([
            'id' => (int) $apt['id'],
            'case_title' => $apt['case_title'] ?: 'Appointment',
            'lawyer_name' => $apt['lawyer_name'] ?: 'TBD',
            'starts_at' => date('M j, Y g:i A', $startsAt),
            'ends_at' => $endsAt ? date('M j, Y g:i A', $endsAt) : null,
            'status' => $apt['status'],
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
            'notes' => trim((string) ($apt['notes'] ?? '')),
            'requested_at' => !empty($apt['created_at']) ? date('M j, Y g:i A', strtotime($apt['created_at'])) : null,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not load appointment details']);
    }
    exit;
}

$message = '';
$messageType = '';

// Handle appointment deletion and booking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'book';

    if ($action === 'delete') {
        // Handle appointment deletion
        $appointment_id = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

        if ($appointment_id) {
            try {
                // Verify the appointment belongs to this client and is rejected
                $stmt = $pdo->prepare("SELECT id, status FROM appointments WHERE id = ? AND client_id = ?");
                $stmt->execute([$appointment_id, $client_id]);
                $appointment = $stmt->fetch();

                if ($appointment && $appointment['status'] === 'rejected') {
                    // Delete the rejected appointment
                    $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ? AND client_id = ? AND status = 'rejected'");
                    $result = $stmt->execute([$appointment_id, $client_id]);

                    if ($result) {
                        $message = 'Rejected appointment deleted successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to delete appointment.';
                        $messageType = 'danger';
                    }
                } else {
                    $message = 'Appointment not found or cannot be deleted.';
                    $messageType = 'danger';
                }
            } catch (PDOException $e) {
                $message = 'Error deleting appointment: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } else {
        // Handle appointment booking
        $case_id = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
        $lawyer_id = isset($_POST['lawyer_id']) ? (int)$_POST['lawyer_id'] : 0;
        $appointment_date = trim($_POST['appointment_date']);
        $appointment_time = trim($_POST['appointment_time']);
        $notes = trim($_POST['notes']);

    if (empty($lawyer_id) || empty($case_id) || empty($appointment_date) || empty($appointment_time)) {
        $message = 'Please select lawyer, case, date and time for the appointment.';
        $messageType = 'danger';
    } else {
        try {
            // Verify the case belongs to this client
            $stmt = $pdo->prepare("SELECT id FROM cases WHERE id = ? AND client_id = ?");
            $stmt->execute([$case_id, $client_id]);
            if (!$stmt->fetch()) {
                $message = 'Invalid case selected.';
                $messageType = 'danger';
            } else {
                // Check if the lawyer is available at the requested time
                $availabilityCheckPassed = true;

                if (!empty($lawyer_id)) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM lawyer_time_slots
                        WHERE lawyer_id = ? AND slot_type = 'available'
                    ");
                    $stmt->execute([$lawyer_id]);
                    $hasAvailabilitySlots = (int) $stmt->fetchColumn() > 0;

                    if ($hasAvailabilitySlots) {
                        $dayOfWeek = strtolower(date('l', strtotime($appointment_date)));
                        $requestedTime = $appointment_time . ':00';

                        $stmt = $pdo->prepare("
                            SELECT * FROM lawyer_time_slots
                            WHERE lawyer_id = ? AND day_of_week = ? AND slot_type = 'available'
                            AND start_time <= ? AND end_time > ?
                            ORDER BY start_time
                        ");
                        $stmt->execute([$lawyer_id, $dayOfWeek, $requestedTime, $requestedTime]);
                        $availability = $stmt->fetch();

                        if (!$availability) {
                            $availabilityCheckPassed = false;
                            $message = 'Lawyer not available at the selected date and time. Please choose a different time.';
                            $messageType = 'danger';
                        }
                    }
                }

                if ($availabilityCheckPassed) {
                    $startDateTime = $appointment_date . ' ' . $appointment_time . ':00';
                    $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +1 hour')); // Assume 1 hour duration

                    $stmt = $pdo->prepare("INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                    $result = $stmt->execute([$client_id, $case_id, $lawyer_id, $startDateTime, $endDateTime, $notes]);

                    if ($result) {
                        $appointmentId = $pdo->lastInsertId();
                        $message = 'Appointment request submitted successfully. Waiting for lawyer approval.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to save appointment. Please try again.';
                        $messageType = 'danger';
                    }
                }
            }
        } catch (PDOException $e) {
            $message = 'Error booking appointment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}
}

try {
    // Get client's cases for appointment booking
    $stmt = $pdo->prepare("SELECT id, title FROM cases WHERE client_id = ? ORDER BY title ASC");
    $stmt->execute([$client_id]);
    $clientCases = $stmt->fetchAll();

    // Get all appointments for this client
    $stmt = $pdo->prepare("
        SELECT
            a.*,
            c.title as case_title,
            CONCAT(l.first_name, ' ', l.last_name) as lawyer_name
        FROM appointments a
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN lawyers l ON l.id = a.lawyer_id
        WHERE a.client_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$client_id]);
    $appointments = $stmt->fetchAll();

} catch (PDOException $e) {
    $message = 'Error loading appointments: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $clientCases = [];
    $appointments = [];
}

$apptTotal = count($appointments);
$apptPending = 0;
$apptUpcoming = 0;
foreach ($appointments as $_apt) {
    if (($_apt['status'] ?? '') === 'pending') {
        $apptPending++;
    }
    if (($_apt['status'] ?? '') === 'accepted' && !empty($_apt['starts_at']) && strtotime($_apt['starts_at']) > time()) {
        $apptUpcoming++;
    }
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

// Build appointments table rows
$appointmentsRows = '';
if (empty($appointments)) {
    $appointmentsRows = '<tr><td colspan="5" class="border-0">
        <div class="text-center py-5 px-4">
            <div class="ca-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                <i class="ni ni-calendar-grid-58 text-primary text-lg opacity-10" aria-hidden="true"></i>
            </div>
            <h5 class="font-weight-bolder mt-4 mb-2">No appointments yet</h5>
            <p class="text-sm text-muted mb-4 mx-auto" style="max-width: 22rem;">Use the booking panel to request a time with your counsel. Pending requests appear here until they are accepted.</p>
        </div>
    </td></tr>';
} else {
    foreach ($appointments as $apt) {
        $aid = (int) $apt['id'];
        $appointmentDate = date('M j, Y', strtotime($apt['starts_at']));
        $appointmentTime = date('g:i A', strtotime($apt['starts_at']));
        $lawyerName = $apt['lawyer_name'] ?: 'TBD';

        $statusBadge = '';
        switch ($apt['status']) {
            case 'pending':
                $statusBadge = '<span class="badge badge-sm bg-gradient-warning">Pending</span>';
                break;
            case 'accepted':
                if (strtotime($apt['starts_at']) > time()) {
                    $statusBadge = '<span class="badge badge-sm bg-gradient-info">Upcoming</span>';
                } elseif (strtotime($apt['ends_at']) < time()) {
                    $statusBadge = '<span class="badge badge-sm bg-gradient-success">Completed</span>';
                } else {
                    $statusBadge = '<span class="badge badge-sm bg-gradient-primary">In progress</span>';
                }
                break;
            case 'rejected':
                $statusBadge = '<span class="badge badge-sm bg-gradient-danger">Rejected</span>';
                break;
            default:
                $statusBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars($apt['status']) . '</span>';
        }

        $notesRaw = isset($apt['notes']) ? trim((string) $apt['notes']) : '';
        $notesDisp = $notesRaw === '' ? '—' : (strlen($notesRaw) > 64 ? htmlspecialchars(substr($notesRaw, 0, 64)) . '…' : htmlspecialchars($notesRaw));

        $appointmentsRows .= '<tr class="ca-appt-row">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="ca-appt-icon icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md flex-shrink-0">
                        <i class="ni ni-time-alarm text-white text-xs opacity-10" aria-hidden="true"></i>
                    </div>
                    <div class="min-width-0">
                        <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 14rem;">' . htmlspecialchars($apt['case_title'] ?: 'Appointment') . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($appointmentDate) . ' · ' . htmlspecialchars($appointmentTime) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 9rem;" title="' . htmlspecialchars($lawyerName) . '">' . htmlspecialchars($lawyerName) . '</p>
            </td>
            <td class="align-middle text-center">
                ' . $statusBadge . '
            </td>
            <td>
                <p class="text-xs text-secondary mb-0 text-truncate" style="max-width: 11rem;" title="' . htmlspecialchars($notesRaw) . '">' . $notesDisp . '</p>
            </td>
            <td class="align-middle text-end pe-4">
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-primary mb-0" onclick="viewAppointmentDetails(' . $aid . ')">Details</button>';
        if ($apt['status'] === 'rejected') {
            $appointmentsRows .= '
                    <form method="POST" class="d-inline" onsubmit="return confirm(\'Delete this rejected appointment request permanently?\')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="appointment_id" value="' . $aid . '">
                        <button type="submit" class="btn btn-sm btn-outline-danger mb-0" title="Delete rejected appointment">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>';
        }
        $appointmentsRows .= '
                </div>
            </td>
        </tr>';
    }
}

// Fetch available lawyers (those assigned to client's cases)
$availableLawyers = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT l.id, l.first_name, l.last_name
        FROM lawyers l
        INNER JOIN case_lawyers cl ON cl.lawyer_id = l.id
        INNER JOIN cases c ON c.id = cl.case_id
        WHERE c.client_id = ? AND l.is_active = 1
        ORDER BY l.first_name, l.last_name
    ");
    $stmt->execute([$client_id]);
    $availableLawyers = $stmt->fetchAll();

    // If no lawyers assigned to cases, show all active lawyers with a note
    if (empty($availableLawyers)) {
        try {
            $stmt = $pdo->query("SELECT id, first_name, last_name FROM lawyers WHERE is_active = 1 ORDER BY first_name, last_name");
            $availableLawyers = $stmt->fetchAll();
            if (!empty($availableLawyers)) {
                $message = 'No lawyers are currently assigned to your cases. The lawyers shown below are available in the system, but you may need to contact the administrator to assign one to your case.';
                $messageType = 'info';
            } else {
                $message = 'No active lawyers found in the system. Please contact the administrator.';
                $messageType = 'warning';
            }
        } catch (PDOException $e) {
            $availableLawyers = [];
            $message = 'Error loading lawyers. Please contact the administrator.';
            $messageType = 'danger';
        }
    }
} catch (PDOException $e) {
    $availableLawyers = [];
}

// Build case options for appointment booking
$caseOptions = '<option value="">Select a case</option>';
foreach ($clientCases as $case) {
    $caseOptions .= '<option value="' . $case['id'] . '">' . htmlspecialchars($case['title']) . '</option>';
}

// Build lawyer availability data
$lawyerAvailability = [];
foreach ($availableLawyers as $lawyer) {
    $lawyerAvailability[$lawyer['id']] = [];

    try {
        $stmt = $pdo->prepare("SELECT * FROM lawyer_time_slots WHERE lawyer_id = ? ORDER BY day_of_week, start_time");
        $stmt->execute([$lawyer['id']]);
        $slots = $stmt->fetchAll();

        foreach ($slots as $slot) {
            $dayKey = strtolower($slot['day_of_week']);
            if (!isset($lawyerAvailability[$lawyer['id']][$dayKey])) {
                $lawyerAvailability[$lawyer['id']][$dayKey] = [];
            }
            $lawyerAvailability[$lawyer['id']][$dayKey][] = [
                'start' => $slot['start_time'],
                'end' => $slot['end_time'],
                'type' => $slot['slot_type']
            ];
        }
    } catch (PDOException $e) {
        // Continue without availability data
    }
}

// Build lawyer options
$lawyerOptions = '<option value="">Select a lawyer</option>';
foreach ($availableLawyers as $lawyer) {
    $lawyerOptions .= '<option value="' . $lawyer['id'] . '">' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '</option>';
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - My Appointments</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />

    <style>
        .client-appointments-page { --ca-radius: 1.15rem; }
        .client-appointments-page .ca-hero {
            border-radius: var(--ca-radius);
            background: #fff;
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-appointments-page .ca-hero .ca-hero-kicker {
            letter-spacing: 0.12em;
            color: #5e72e4;
            opacity: 1;
        }
        .client-appointments-page .ca-hero .ca-hero-title {
            color: #344767;
        }
        .client-appointments-page .ca-hero .ca-hero-text {
            color: #67748e;
        }
        .client-appointments-page .ca-hero-pill {
            background: #f8f9fe;
            border-radius: 0.75rem;
            padding: 0.55rem 0.9rem;
            border: 1px solid rgba(94, 114, 228, 0.15);
            min-width: 5.5rem;
            text-align: center;
        }
        .client-appointments-page .ca-hero-pill .ca-hero-pill-label {
            color: #67748e;
        }
        .client-appointments-page .ca-hero-pill .ca-hero-pill-value {
            color: #344767;
        }
        .client-appointments-page .ca-panel {
            border-radius: var(--ca-radius);
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 0.25rem 1.1rem rgba(52, 71, 103, 0.07);
            overflow: hidden;
        }
        .client-appointments-page .ca-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.1rem 1.25rem 0.9rem;
        }
        .client-appointments-page .ca-panel .card-header h5 {
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .client-appointments-page .ca-book .card-body {
            background: linear-gradient(180deg, rgba(94, 114, 228, 0.04) 0%, transparent 40%);
        }
        .client-appointments-page .ca-panel .table thead th {
            font-size: 0.65rem;
            letter-spacing: 0.06em;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            background: rgba(248, 249, 250, 0.95);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-appointments-page .ca-appt-row td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: middle;
        }
        .client-appointments-page .ca-appt-row:hover td {
            background: rgba(94, 114, 228, 0.04);
        }
        .client-appointments-page .ca-appt-icon {
            width: 2.35rem;
            height: 2.35rem;
        }
        .client-appointments-page .min-width-0 { min-width: 0; }
        .client-appointments-page .ca-empty-icon {
            width: 4rem;
            height: 4rem;
        }
        .client-appointments-page .time-option {
            transition: all 0.2s ease;
        }
        .client-appointments-page .time-option.text-success.font-weight-bold {
            background-color: rgba(25, 135, 84, 0.1);
            border-left: 3px solid #19a463;
        }
        .client-appointments-page .time-option:disabled {
            color: #6c757d !important;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal client-appointments-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="client-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
            </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="client-dashboard.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-cases.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Cases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="client-appointments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-calendar-grid-58 text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Appointments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-court-tracking.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-collection text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Court Tracking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-payments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-credit-card text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Payments</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidenav-footer position-absolute bottom-0 w-100">
            <div class="text-center">
                <p class="text-xs text-muted mb-1">Logged in as</p>
                <p class="text-sm font-weight-bold mb-2">{CLIENT_NAME}</p>
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-white" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Appointments</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-white">Appointments</h5>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search appointments…" value="" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-white font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, {CLIENT_NAME}</span>
                            </a>
                        </li>
                        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
                                <div class="sidenav-toggler-inner">
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                </div>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        <!-- End Navbar -->
        <div class="container-fluid py-4">
            {MESSAGE}

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card ca-hero mb-0">
                        <div class="card-body p-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-4">
                            <div>
                                <p class="ca-hero-kicker text-xs text-uppercase font-weight-bold mb-1">Calendar</p>
                                <h4 class="ca-hero-title font-weight-bolder mb-1">Meetings with your legal team</h4>
                                <p class="ca-hero-text text-sm mb-0" style="max-width: 32rem;">Track requests, confirmations, and past sessions. Book a new slot from the panel on the right.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-3 justify-content-lg-end">
                                <div class="ca-hero-pill">
                                    <p class="ca-hero-pill-label text-xs mb-0">Total</p>
                                    <p class="ca-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;">{APPT_TOTAL}</p>
                                </div>
                                <div class="ca-hero-pill">
                                    <p class="ca-hero-pill-label text-xs mb-0">Pending</p>
                                    <p class="ca-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;">{APPT_PENDING}</p>
                                </div>
                                <div class="ca-hero-pill">
                                    <p class="ca-hero-pill-label text-xs mb-0">Upcoming</p>
                                    <p class="ca-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;">{APPT_UPCOMING}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="card ca-panel mb-4 mb-lg-0">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">Your appointments</h5>
                                <p class="text-sm text-muted mb-0">Newest activity first.</p>
                            </div>
                            <a href="client-dashboard.php" class="btn btn-sm btn-outline-primary mb-0">Dashboard</a>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Appointment</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Lawyer</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Notes</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 pe-4 text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {APPOINTMENTS_ROWS}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card ca-panel ca-book border-radius-lg sticky-lg-top" style="top: 1rem;">
                        <div class="card-header pb-0">
                            <h5 class="text-dark">Book a visit</h5>
                            <p class="text-sm text-muted mb-0">Pick counsel, matter, date, and time.</p>
                        </div>
                        <div class="card-body pt-3">
                            <form method="POST" action="" onsubmit="return validateAppointmentForm();">
                                <div class="form-group mb-3">
                                    <label class="form-control-label">Lawyer</label>
                                    <select class="form-control" name="lawyer_id" id="lawyer_id" required onchange="loadLawyerAvailability()">
                                        {LAWYER_OPTIONS}
                                    </select>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="form-control-label">Case</label>
                                    <select class="form-control" name="case_id" id="case_id" required>
                                        {CASE_OPTIONS}
                                    </select>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="form-control-label">Date</label>
                                    <input type="date" class="form-control" name="appointment_date" id="appointment_date" min="{MIN_DATE}" required onchange="loadTimeAvailability()">
                                    <div id="dateAvailabilityMessage" class="mt-2" style="display: none;"></div>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="form-control-label">Time</label>
                                    <select class="form-control" name="appointment_time" id="appointment_time" required>
                                        <option value="">Select time</option>
                                        <option value="09:00" class="time-option">9:00 AM</option>
                                        <option value="10:00" class="time-option">10:00 AM</option>
                                        <option value="11:00" class="time-option">11:00 AM</option>
                                        <option value="12:00" class="time-option">12:00 PM</option>
                                        <option value="13:00" class="time-option">1:00 PM</option>
                                        <option value="14:00" class="time-option">2:00 PM</option>
                                        <option value="15:00" class="time-option">3:00 PM</option>
                                        <option value="16:00" class="time-option">4:00 PM</option>
                                        <option value="17:00" class="time-option">5:00 PM</option>
                                    </select>
                                    <small class="text-muted">Green slots match the lawyer’s published availability.</small>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-control-label">Notes <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Topics you want to cover…"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 mb-0 font-weight-bold border-radius-lg">Request appointment</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Appointment Details Modal -->
    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-labelledby="appointmentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-radius-xl shadow-lg overflow-hidden">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-weight-bolder mb-0" id="appointmentModalLabel">Appointment details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="appointmentDetails">
                    <!-- Details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>

    <script>
        const lawyerAvailability = {$lawyerAvailabilityJson};
        let appointmentModalInstance = null;

        function escapeHtml(text) {
            const el = document.createElement('div');
            el.textContent = text == null ? '' : String(text);
            return el.innerHTML;
        }

        function getAppointmentModal() {
            const modalEl = document.getElementById('appointmentModal');
            if (!appointmentModalInstance) {
                appointmentModalInstance = new bootstrap.Modal(modalEl);
            }
            return appointmentModalInstance;
        }

        function renderAppointmentDetails(data) {
            const notesBlock = data.notes
                ? '<p class="text-sm mb-0">' + escapeHtml(data.notes) + '</p>'
                : '<p class="text-sm text-muted mb-0">No notes provided.</p>';

            return (
                '<div class="d-flex flex-column gap-3">' +
                    '<div class="d-flex justify-content-between align-items-start gap-2">' +
                        '<div>' +
                            '<p class="text-xs text-uppercase text-muted font-weight-bold mb-1">Matter</p>' +
                            '<h6 class="mb-0 font-weight-bold">' + escapeHtml(data.case_title) + '</h6>' +
                        '</div>' +
                        '<span class="badge bg-gradient-' + escapeHtml(data.status_class) + '">' + escapeHtml(data.status_label) + '</span>' +
                    '</div>' +
                    '<div class="row g-3">' +
                        '<div class="col-sm-6">' +
                            '<p class="text-xs text-uppercase text-muted font-weight-bold mb-1">Lawyer</p>' +
                            '<p class="text-sm font-weight-bold mb-0">' + escapeHtml(data.lawyer_name) + '</p>' +
                        '</div>' +
                        '<div class="col-sm-6">' +
                            '<p class="text-xs text-uppercase text-muted font-weight-bold mb-1">Requested</p>' +
                            '<p class="text-sm mb-0">' + escapeHtml(data.requested_at || '—') + '</p>' +
                        '</div>' +
                        '<div class="col-sm-6">' +
                            '<p class="text-xs text-uppercase text-muted font-weight-bold mb-1">Starts</p>' +
                            '<p class="text-sm mb-0">' + escapeHtml(data.starts_at) + '</p>' +
                        '</div>' +
                        '<div class="col-sm-6">' +
                            '<p class="text-xs text-uppercase text-muted font-weight-bold mb-1">Ends</p>' +
                            '<p class="text-sm mb-0">' + escapeHtml(data.ends_at || '—') + '</p>' +
                        '</div>' +
                    '</div>' +
                    '<div>' +
                        '<p class="text-xs text-uppercase text-muted font-weight-bold mb-1">Notes</p>' +
                        notesBlock +
                    '</div>' +
                '</div>'
            );
        }

        function viewAppointmentDetails(appointmentId) {
            const detailsEl = document.getElementById('appointmentDetails');
            detailsEl.innerHTML =
                '<div class="text-center py-4">' +
                    '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>' +
                    '<p class="text-sm text-muted mt-2 mb-0">Loading appointment…</p>' +
                '</div>';
            getAppointmentModal().show();

            fetch('client-appointments.php?ajax=appointment_details&id=' + encodeURIComponent(appointmentId), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function(response) {
                    return response.json().then(function(body) {
                        if (!response.ok) {
                            throw new Error(body.error || 'Could not load appointment');
                        }
                        return body;
                    });
                })
                .then(function(data) {
                    detailsEl.innerHTML = renderAppointmentDetails(data);
                })
                .catch(function(err) {
                    detailsEl.innerHTML =
                        '<div class="alert alert-danger mb-0 py-2">' +
                            '<i class="ni ni-bell-55"></i> ' + escapeHtml(err.message || 'Failed to load details.') +
                        '</div>';
                });
        }

        function loadLawyerAvailability() {
            document.getElementById('appointment_time').value = '';
            loadTimeAvailability();
        }

        function loadTimeAvailability() {
            const lawyerId = document.getElementById('lawyer_id').value;
            const dateInput = document.getElementById('appointment_date');
            const timeSelect = document.getElementById('appointment_time');
            const dateMessageDiv = document.getElementById('dateAvailabilityMessage');

            // Reset all time options to default state
            const timeOptions = timeSelect.querySelectorAll('.time-option');
            timeOptions.forEach(option => {
                option.classList.remove('text-success', 'font-weight-bold');
                option.disabled = false;
            });

            // Hide date message
            dateMessageDiv.style.display = 'none';
            dateMessageDiv.innerHTML = '';

            if (!lawyerId) {
                return;
            }

            if (!dateInput.value) {
                dateMessageDiv.style.display = 'block';
                dateMessageDiv.innerHTML = '<div class="alert alert-info py-2 mb-0"><i class="ni ni-info-16"></i> Select a date to see available times.</div>';
                return;
            }

            const date = new Date(dateInput.value + 'T00:00:00');
            const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            const dayOfWeek = days[date.getDay()];

            const lawyerSlots = lawyerAvailability[lawyerId] || lawyerAvailability[String(lawyerId)] || {};
            const daySlots = lawyerSlots[dayOfWeek] || [];
            const availableSlots = daySlots.filter(function(slot) { return slot.type === 'available'; });

                // If no availability slots are set up for this lawyer, allow all times
                // This ensures backward compatibility if time slots haven't been configured
                if (availableSlots.length === 0) {
                    // No availability slots configured - allow all times (backward compatibility)
                    dateMessageDiv.style.display = 'block';
                    dateMessageDiv.innerHTML = '<div class="alert alert-info py-2"><i class="ni ni-info-16"></i> Lawyer availability not configured - all times shown.</div>';

                    // Enable all time options
                    timeOptions.forEach(option => {
                        option.classList.remove('text-success', 'font-weight-bold');
                        option.disabled = false;
                    });
                    return;
                }

            // Enable and highlight available time slots
            let hasAvailableTimes = false;
            timeOptions.forEach(option => {
                if (option.value) {
                    const optionTime = option.value + ':00';
                    let isAvailable = false;

                    // Check if this time falls within any available slot
                    availableSlots.forEach(slot => {
                        if (optionTime >= slot.start && optionTime < slot.end) {
                            isAvailable = true;
                        }
                    });

                    if (isAvailable) {
                        option.classList.add('text-success', 'font-weight-bold');
                        option.disabled = false;
                        hasAvailableTimes = true;
                    } else {
                        option.disabled = true;
                    }
                }
            });

            const selected = timeSelect.options[timeSelect.selectedIndex];
            if (selected && selected.disabled) {
                timeSelect.value = '';
            }

            if (!hasAvailableTimes) {
                dateMessageDiv.style.display = 'block';
                dateMessageDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> No available times for the selected date.</div>';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('lawyer_id').addEventListener('change', loadLawyerAvailability);
            document.getElementById('appointment_date').addEventListener('change', loadTimeAvailability);
        });

        // Form validation before submission
        function validateAppointmentForm() {
            const lawyerId = document.getElementById('lawyer_id').value;
            const caseId = document.getElementById('case_id').value;
            const dateInput = document.getElementById('appointment_date').value;
            const timeSelect = document.getElementById('appointment_time');
            const selectedTime = timeSelect.value;
            const selectedOption = timeSelect.querySelector('option[value="' + selectedTime + '"]');

            // Check required fields
            if (!lawyerId) {
                alert('Please select a lawyer.');
                return false;
            }

            if (!caseId) {
                alert('Please select a case.');
                return false;
            }

            if (!dateInput) {
                alert('Please select an appointment date.');
                return false;
            }

            if (!selectedTime) {
                alert('Please select an appointment time.');
                return false;
            }

            // Check if selected time is disabled
            if (selectedOption && selectedOption.disabled) {
                alert('The selected time is not available. Please choose a different time.');
                return false;
            }

            return true;
        }
    </script>
</body>
</html>
HTML;

// Replace placeholders
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{APPOINTMENTS_ROWS}', $appointmentsRows, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{LAWYER_OPTIONS}', $lawyerOptions, $html);
$html = str_replace('{$lawyerAvailabilityJson}', json_encode($lawyerAvailability), $html);
$html = str_replace('{MIN_DATE}', date('Y-m-d'), $html);
$html = str_replace('{APPT_TOTAL}', (string) $apptTotal, $html);
$html = str_replace('{APPT_PENDING}', (string) $apptPending, $html);
$html = str_replace('{APPT_UPCOMING}', (string) $apptUpcoming, $html);

echo $html;
?>
