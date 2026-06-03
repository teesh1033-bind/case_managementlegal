<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/appointment_availability.php';


// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

function clientAppointmentStatusMeta(string $status, int $startsAt, ?int $endsAt): array
{
    $now = time();
    if ($status === 'pending') {
        return ['key' => 'pending', 'label' => 'Pending approval', 'class' => 'warning'];
    }
    if ($status === 'accepted') {
        if ($startsAt > $now) {
            return ['key' => 'upcoming', 'label' => 'Upcoming', 'class' => 'info'];
        }
        if ($endsAt && $endsAt < $now) {
            return ['key' => 'completed', 'label' => 'Completed', 'class' => 'success'];
        }
        return ['key' => 'in_progress', 'label' => 'In progress', 'class' => 'primary'];
    }
    if ($status === 'rejected') {
        return ['key' => 'rejected', 'label' => 'Rejected', 'class' => 'danger'];
    }
    return ['key' => 'unknown', 'label' => ucfirst($status ?: 'unknown'), 'class' => 'secondary'];
}

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
            WHERE a.client_id = ? AND a.id = ?
        ");
        $stmt->execute([$client_id, $appointmentId]);
        $apt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$apt) {
            http_response_code(404);
            echo json_encode(['error' => 'Appointment not found']);
            exit;
        }

        $startsAt = strtotime($apt['starts_at']);
        $endsAt = !empty($apt['ends_at']) ? strtotime($apt['ends_at']) : null;
        $meta = clientAppointmentStatusMeta(strtolower((string) ($apt['status'] ?? '')), $startsAt, $endsAt);

        echo json_encode([
            'id' => (int) $apt['id'],
            'case_title' => $apt['case_title'] ?: 'Appointment',
            'lawyer_name' => $apt['lawyer_name'] ?: 'TBD',
            'starts_at' => date('M j, Y g:i A', $startsAt),
            'ends_at' => $endsAt ? date('M j, Y g:i A', $endsAt) : null,
            'status' => $apt['status'],
            'status_label' => $meta['label'],
            'status_class' => $meta['class'],
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
                $availabilityResult = validateLawyerBookingAvailability($pdo, $lawyer_id, $appointment_date, $appointment_time);

                if (!$availabilityResult['ok']) {
                    $message = $availabilityResult['message'];
                    $messageType = 'danger';
                } else {
                    $startDateTime = $appointment_date . ' ' . $appointment_time . ':00';
                    $endDateTime = date('Y-m-d H:i:s', strtotime($startDateTime . ' +1 hour')); // Assume 1 hour duration

                    $stmt = $pdo->prepare("INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                    $result = $stmt->execute([$client_id, $case_id, $lawyer_id, $startDateTime, $endDateTime, $notes]);

                    if ($result) {
                        $appointmentId = (int) $pdo->lastInsertId();
                        syncAppointmentAvailabilitySlot($pdo, [
                            'id' => $appointmentId,
                            'lawyer_id' => $lawyer_id,
                            'starts_at' => $startDateTime,
                            'ends_at' => $endDateTime,
                            'status' => 'pending',
                        ]);
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

$messageHtml = '';
if ($message) {
    $successClass = ($messageType === 'success') ? ' text-white' : '';
    $closeClass = ($messageType === 'success') ? ' btn-close-white' : '';
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show' . $successClass . '" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close' . $closeClass . '" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

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

        $statusMeta = clientAppointmentStatusMeta(
            strtolower((string) ($apt['status'] ?? '')),
            strtotime($apt['starts_at']),
            !empty($apt['ends_at']) ? strtotime($apt['ends_at']) : null
        );
        $statusBadge = '<span class="badge badge-sm bg-gradient-' . htmlspecialchars($statusMeta['class']) . '">' . htmlspecialchars($statusMeta['label']) . '</span>';

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
                <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 12rem;" title="' . htmlspecialchars($lawyerName) . '">' . htmlspecialchars($lawyerName) . '</p>
            </td>
            <td class="align-middle text-center">
                ' . $statusBadge . '
            </td>
            <td>
                <p class="text-xs text-secondary mb-0 text-truncate" style="max-width: 11rem;" title="' . htmlspecialchars($notesRaw) . '">' . $notesDisp . '</p>
            </td>
            <td class="align-middle text-end pe-4">
                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-primary mb-0" onclick="viewAppointmentDetails(' . $aid . ')">Details</button>
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

$lawyerAvailabilityByDate = [];
$lawyerHasAvailability = [];
try {
    $lawyerIdsForAvailability = array_map(static function ($lawyer) {
        return (int) $lawyer['id'];
    }, $availableLawyers);
    $availabilityMaps = loadLawyerAvailabilityForBooking($pdo, $lawyerIdsForAvailability);
    $lawyerAvailabilityByDate = $availabilityMaps['byDate'];
    $lawyerHasAvailability = $availabilityMaps['hasSchedule'];
} catch (PDOException $e) {
    $lawyerAvailabilityByDate = [];
    $lawyerHasAvailability = [];
}

// Build lawyer options
$lawyerOptions = '<option value="">Select a lawyer</option>';
foreach ($availableLawyers as $lawyer) {
    $lawyerOptions .= '<option value="' . $lawyer['id'] . '">' . htmlspecialchars($lawyer['first_name'] . ' ' . $lawyer['last_name']) . '</option>';
}

require_once __DIR__ . '/../inc/client-portal-navbar.php';
$clientPageNavbar = legalpro_render_client_page_navbar('Appointments', 'Appointments', 'Search appointments…');

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
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>

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
        .client-appointments-page #bookAppointmentBtn {
            transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .client-appointments-page #bookAppointmentBtn:hover,
        .client-appointments-page #bookAppointmentBtn:focus {
            background-color: #324cdd !important;
            border-color: #324cdd !important;
            box-shadow: 0 0.4rem 1rem rgba(50, 76, 221, 0.22);
        }
        .client-appointments-page .ca-appt-row .btn-outline-primary {
            transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
        }
        .client-appointments-page .ca-appt-row .btn-outline-primary:hover,
        .client-appointments-page .ca-appt-row .btn-outline-primary:focus {
            background-color: #324cdd !important;
            border-color: #324cdd !important;
            color: #fff !important;
            box-shadow: 0 0.35rem 0.9rem rgba(50, 76, 221, 0.2);
        }
        .client-appointments-page .ca-panel .card-header .btn-outline-primary {
            transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
        }
        .client-appointments-page .ca-panel .card-header .btn-outline-primary:hover,
        .client-appointments-page .ca-panel .card-header .btn-outline-primary:focus {
            background-color: #324cdd !important;
            border-color: #324cdd !important;
            color: #fff !important;
            box-shadow: 0 0.35rem 0.9rem rgba(50, 76, 221, 0.2);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-appointments-page">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>
    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}
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
                            <h5 class="text-dark">Book appointment</h5>
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
                                    <small class="text-muted">Only green times can be booked. If your lawyer is unavailable, pick another date.</small>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-control-label">Notes <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Topics you want to cover…"></textarea>
                                </div>
                                <button type="submit" id="bookAppointmentBtn" class="btn btn-primary w-100 mb-0 font-weight-bold border-radius-lg">Book appointment</button>
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
        const lawyerAvailabilityByDate = {$lawyerAvailabilityByDateJson};
        const lawyerHasAvailability = {$lawyerHasAvailabilityJson};
        const NO_AVAILABILITY_ON_DATE_MSG = 'No available times on this date. Choose another date.';
        let appointmentModalInstance = null;

        function lawyerHasPublishedSchedule(lawyerId) {
            return !!(lawyerHasAvailability[lawyerId] || lawyerHasAvailability[String(lawyerId)]);
        }

        function getDayOfWeekFromDate(dateValue) {
            const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            const date = new Date(dateValue + 'T00:00:00');
            return days[date.getDay()];
        }

        function getSlotsForLawyerAndDate(lawyerId, dateValue) {
            const byDate = lawyerAvailabilityByDate[lawyerId] || lawyerAvailabilityByDate[String(lawyerId)] || {};
            return (byDate[dateValue] || []).slice();
        }

        function getAvailableSlotsForDate(lawyerId, dateValue) {
            return getSlotsForLawyerAndDate(lawyerId, dateValue).filter(function(slot) {
                return slot.type === 'available';
            });
        }

        function lawyerHasAvailabilityOnDate(lawyerId, dateValue) {
            return getAvailableSlotsForDate(lawyerId, dateValue).length > 0;
        }

        function isTimeWithinAvailableSlots(timeValue, availableSlots) {
            if (!timeValue || !availableSlots.length) {
                return false;
            }
            const optionTime = timeValue.length === 5 ? timeValue + ':00' : timeValue;
            return availableSlots.some(function(slot) {
                return optionTime >= slot.start && optionTime < slot.end;
            });
        }

        function setBookButtonEnabled(enabled) {
            const btn = document.getElementById('bookAppointmentBtn');
            if (!btn) {
                return;
            }
            btn.disabled = !enabled;
            btn.title = enabled ? '' : 'Select a date and time when your lawyer is available';
        }

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
            const timeOptions = timeSelect.querySelectorAll('.time-option');

            timeOptions.forEach(function(option) {
                option.classList.remove('text-success', 'font-weight-bold');
                option.disabled = false;
            });

            dateMessageDiv.style.display = 'none';
            dateMessageDiv.innerHTML = '';
            setBookButtonEnabled(true);

            if (!lawyerId) {
                setBookButtonEnabled(false);
                return;
            }

            if (!dateInput.value) {
                dateMessageDiv.style.display = 'block';
                dateMessageDiv.innerHTML = '<div class="alert alert-info py-2 mb-0"><i class="ni ni-info-16"></i> Select a date to see available times.</div>';
                setBookButtonEnabled(false);
                return;
            }

            if (!lawyerHasPublishedSchedule(lawyerId) || !lawyerHasAvailabilityOnDate(lawyerId, dateInput.value)) {
                timeOptions.forEach(function(option) {
                    if (option.value) {
                        option.disabled = true;
                    }
                });
                timeSelect.value = '';
                dateMessageDiv.style.display = 'block';
                dateMessageDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> ' + NO_AVAILABILITY_ON_DATE_MSG + '</div>';
                setBookButtonEnabled(false);
                return;
            }

            const availableSlots = getAvailableSlotsForDate(lawyerId, dateInput.value);

            if (availableSlots.length === 0) {
                timeOptions.forEach(function(option) {
                    if (option.value) {
                        option.disabled = true;
                    }
                });
                timeSelect.value = '';
                dateMessageDiv.style.display = 'block';
                dateMessageDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> No available times on this date. Choose another date.</div>';
                setBookButtonEnabled(false);
                return;
            }

            let hasAvailableTimes = false;
            timeOptions.forEach(function(option) {
                if (!option.value) {
                    return;
                }

                if (isTimeWithinAvailableSlots(option.value, availableSlots)) {
                    option.classList.add('text-success', 'font-weight-bold');
                    option.disabled = false;
                    hasAvailableTimes = true;
                } else {
                    option.disabled = true;
                }
            });

            const selected = timeSelect.options[timeSelect.selectedIndex];
            if (selected && selected.disabled) {
                timeSelect.value = '';
            }

            if (!hasAvailableTimes) {
                dateMessageDiv.style.display = 'block';
                dateMessageDiv.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="ni ni-info-16"></i> No available times on this date. Choose another date.</div>';
                setBookButtonEnabled(false);
                return;
            }

            if (!timeSelect.value || (selected && selected.disabled)) {
                setBookButtonEnabled(false);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('lawyer_id').addEventListener('change', loadLawyerAvailability);
            document.getElementById('appointment_date').addEventListener('change', loadTimeAvailability);
            document.getElementById('appointment_time').addEventListener('change', function() {
                const lawyerId = document.getElementById('lawyer_id').value;
                const dateValue = document.getElementById('appointment_date').value;
                const timeValue = document.getElementById('appointment_time').value;

                if (!lawyerId || !dateValue || !timeValue) {
                    setBookButtonEnabled(false);
                    return;
                }

                if (!lawyerHasPublishedSchedule(lawyerId)) {
                    setBookButtonEnabled(false);
                    return;
                }

                if (!lawyerHasAvailabilityOnDate(lawyerId, dateValue)) {
                    setBookButtonEnabled(false);
                    return;
                }
                const availableSlots = getAvailableSlotsForDate(lawyerId, dateValue);
                setBookButtonEnabled(isTimeWithinAvailableSlots(timeValue, availableSlots));
            });
            loadLawyerAvailability();
        });

        function validateAppointmentForm() {
            const lawyerId = document.getElementById('lawyer_id').value;
            const caseId = document.getElementById('case_id').value;
            const dateInput = document.getElementById('appointment_date').value;
            const timeSelect = document.getElementById('appointment_time');
            const selectedTime = timeSelect.value;
            const selectedOption = timeSelect.querySelector('option[value="' + selectedTime + '"]');

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

            if (selectedOption && selectedOption.disabled) {
                alert('The selected time is not available. Please reschedule to another date or choose an available time.');
                return false;
            }

            if (!lawyerHasPublishedSchedule(lawyerId)) {
                alert('No available times on this date. Choose another date.');
                return false;
            }

            if (!lawyerHasAvailabilityOnDate(lawyerId, dateInput)) {
                alert(NO_AVAILABILITY_ON_DATE_MSG);
                return false;
            }

            const availableSlots = getAvailableSlotsForDate(lawyerId, dateInput);

            if (availableSlots.length === 0) {
                alert(NO_AVAILABILITY_ON_DATE_MSG);
                return false;
            }

            if (!isTimeWithinAvailableSlots(selectedTime, availableSlots)) {
                alert('Your lawyer is not available at the selected time. Please reschedule to another date or choose an available time slot.');
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
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{APPOINTMENTS_ROWS}', $appointmentsRows, $html);
$html = str_replace('{CASE_OPTIONS}', $caseOptions, $html);
$html = str_replace('{LAWYER_OPTIONS}', $lawyerOptions, $html);
$html = str_replace('{$lawyerAvailabilityByDateJson}', json_encode($lawyerAvailabilityByDate), $html);
$html = str_replace('{$lawyerHasAvailabilityJson}', json_encode($lawyerHasAvailability), $html);
$html = str_replace('{MIN_DATE}', date('Y-m-d'), $html);
$html = str_replace('{APPT_TOTAL}', (string) $apptTotal, $html);
$html = str_replace('{APPT_PENDING}', (string) $apptPending, $html);
$html = str_replace('{APPT_UPCOMING}', (string) $apptUpcoming, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
?>
