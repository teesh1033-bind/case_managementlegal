<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/appointment_availability.php';
require_once __DIR__ . '/../lib/case_lawyers.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];

$message = '';
$messageType = '';

if (isset($_GET['msg'])) {
    $message = urldecode((string) $_GET['msg']);
    $messageType = isset($_GET['type']) ? (string) $_GET['type'] : 'info';
}

// Handle new appointment creation by the lawyer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_action']) && $_POST['appointment_action'] === 'create') {
    $caseId = (int) ($_POST['case_id'] ?? 0);
    $appointmentDate = trim((string) ($_POST['appointment_date'] ?? ''));
    $appointmentTime = trim((string) ($_POST['appointment_time'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $durationMinutes = (int) ($_POST['duration_minutes'] ?? 60);
    $durationMinutes = in_array($durationMinutes, [30, 60], true) ? $durationMinutes : 60;
    $newStatus = strtolower(trim((string) ($_POST['appointment_status'] ?? 'accepted')));
    if (!in_array($newStatus, ['pending', 'accepted'], true)) {
        $newStatus = 'accepted';
    }

    if ($caseId <= 0 || $appointmentDate === '' || $appointmentTime === '') {
        $message = 'Please select a case, date, and time.';
        $messageType = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT c.id, c.client_id
                FROM cases c
                INNER JOIN case_lawyers cl ON cl.case_id = c.id AND cl.lawyer_id = ?
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmt->execute([$lawyerId, $caseId]);
            $caseRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$caseRow) {
                $message = 'Case not found or you do not have access to it.';
                $messageType = 'danger';
            } else {
                $availabilityCheck = validateLawyerBookingAvailability(
                    $pdo,
                    $lawyerId,
                    $appointmentDate,
                    $appointmentTime,
                    null,
                    $durationMinutes
                );

                if (!$availabilityCheck['ok']) {
                    $message = $availabilityCheck['message'] ?? 'The selected time is not available.';
                    $messageType = 'danger';
                } else {
                    $startsAt = $appointmentDate . ' ' . (preg_match('/^\d{2}:\d{2}$/', $appointmentTime) ? $appointmentTime . ':00' : $appointmentTime);
                    $endsAt = date('Y-m-d H:i:s', strtotime($startsAt . ' +' . $durationMinutes . ' minutes'));
                    $clientId = (int) ($caseRow['client_id'] ?? 0);

                    $stmt = $pdo->prepare("
                        INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$clientId > 0 ? $clientId : null, $caseId, $lawyerId, $startsAt, $endsAt, $notes, $newStatus]);
                    $appointmentId = (int) $pdo->lastInsertId();

                    syncAppointmentAvailabilitySlot($pdo, [
                        'id' => $appointmentId,
                        'lawyer_id' => $lawyerId,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'status' => $newStatus,
                    ]);

                    ensureLawyerAssignedToCase($pdo, $caseId, $lawyerId);

                    $message = $newStatus === 'accepted'
                        ? 'Appointment scheduled and confirmed with your client.'
                        : 'Appointment created and sent to your client for review.';
                    $messageType = 'success';
                }
            }
        } catch (PDOException $e) {
            $message = 'Error creating appointment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }

    if ($message !== '') {
        header('Location: lawyer-appointments.php?msg=' . urlencode($message) . '&type=' . urlencode($messageType));
        exit;
    }
}

// Handle appointment status updates and rescheduling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_action'])) {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $action = (string) $_POST['appointment_action'];
    $allowedActions = ['accept', 'reject', 'pending', 'reschedule'];

    if ($appointmentId && in_array($action, $allowedActions, true)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ? AND lawyer_id = ?");
            $stmt->execute([$appointmentId, $lawyerId]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appointment) {
                $message = 'Appointment not found or you do not have permission to update it.';
                $messageType = 'danger';
            } elseif (in_array(strtolower((string) ($appointment['status'] ?? 'pending')), ['accepted', 'approved'], true)) {
                $message = 'Accepted appointments cannot be modified.';
                $messageType = 'danger';
            } elseif (strtolower((string) ($appointment['status'] ?? 'pending')) === 'rejected'
                && in_array($action, ['accept', 'reject', 'pending', 'reschedule'], true)) {
                $message = 'Rejected appointments cannot be changed.';
                $messageType = 'danger';
            } elseif ($action === 'reschedule') {
                $newDate = trim((string) ($_POST['reschedule_date'] ?? ''));
                $newTime = trim((string) ($_POST['reschedule_time'] ?? ''));
                $rescheduleNotes = trim((string) ($_POST['reschedule_notes'] ?? ''));
                $newStatus = strtolower(trim((string) ($_POST['reschedule_status'] ?? 'pending')));

                if (!in_array($newStatus, ['pending', 'accepted'], true)) {
                    $newStatus = 'pending';
                }

                if ($newDate === '' || $newTime === '') {
                    $message = 'Please provide a new date and time to reschedule.';
                    $messageType = 'danger';
                } else {
                    $durationMinutes = lawyerAppointmentDurationMinutes($appointment);
                    $availabilityCheck = validateLawyerBookingAvailability(
                        $pdo,
                        $lawyerId,
                        $newDate,
                        $newTime,
                        $appointmentId,
                        $durationMinutes
                    );

                    if (!$availabilityCheck['ok']) {
                        $message = $availabilityCheck['message'] ?? 'The selected time is not available.';
                        $messageType = 'danger';
                    } else {
                        $startsAt = $newDate . ' ' . (preg_match('/^\d{2}:\d{2}$/', $newTime) ? $newTime . ':00' : $newTime);
                        $endsAt = date('Y-m-d H:i:s', strtotime($startsAt . ' +' . $durationMinutes . ' minutes'));
                        $notes = (string) ($appointment['notes'] ?? '');
                        if ($rescheduleNotes !== '') {
                            $notes = trim(($notes !== '' ? $notes . "\n\n" : '') . '[Rescheduled by lawyer] ' . $rescheduleNotes);
                        }

                        $stmt = $pdo->prepare("
                            UPDATE appointments
                            SET starts_at = ?, ends_at = ?, status = ?, notes = ?
                            WHERE id = ? AND lawyer_id = ?
                        ");
                        $stmt->execute([$startsAt, $endsAt, $newStatus, $notes, $appointmentId, $lawyerId]);

                        syncAppointmentAvailabilitySlot($pdo, [
                            'id' => $appointmentId,
                            'lawyer_id' => $lawyerId,
                            'starts_at' => $startsAt,
                            'ends_at' => $endsAt,
                            'status' => $newStatus,
                        ]);

                        $message = 'Appointment rescheduled successfully.';
                        $messageType = 'success';
                    }
                }
            } else {
                $statusMap = [
                    'accept' => 'accepted',
                    'reject' => 'rejected',
                    'pending' => 'pending',
                ];
                $status = $statusMap[$action];
                $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND lawyer_id = ?");
                $stmt->execute([$status, $appointmentId, $lawyerId]);

                if ($status === 'rejected') {
                    removeAppointmentAvailabilitySlot($pdo, $appointmentId);
                } else {
                    syncAppointmentAvailabilitySlot($pdo, array_merge($appointment, ['status' => $status]));
                }

                if ($status === 'accepted' && !empty($appointment['case_id'])) {
                    ensureLawyerAssignedToCase($pdo, (int) $appointment['case_id'], $lawyerId);
                }

                $labelMap = [
                    'accept' => 'accepted',
                    'reject' => 'rejected',
                    'pending' => 'marked as pending',
                ];
                $message = 'Appointment ' . $labelMap[$action] . ' successfully.';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Error updating appointment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } else {
        $message = 'Invalid appointment action.';
        $messageType = 'danger';
    }

    if ($message !== '') {
        header('Location: lawyer-appointments.php?msg=' . urlencode($message) . '&type=' . urlencode($messageType));
        exit;
    }
}

$messageHtml = '';
if ($message !== '') {
    $successClass = ($messageType === 'success') ? ' text-white' : '';
    $closeClass = ($messageType === 'success') ? ' btn-close-white' : '';
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show' . $successClass . '" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close' . $closeClass . '" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

// Build query
$query = "
    SELECT a.*, c.title as case_title, c.id as case_id,
           cl.first_name, cl.last_name, cl.email
    FROM appointments a
    INNER JOIN cases c ON c.id = a.case_id
    INNER JOIN clients cl ON cl.id = c.client_id
    WHERE a.lawyer_id = ?
";

$params = [$lawyerId];

$query .= " ORDER BY a.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $appointments = [];
}

$lawyerCases = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, cl.first_name, cl.last_name
        FROM cases c
        INNER JOIN case_lawyers clw ON clw.case_id = c.id AND clw.lawyer_id = ?
        INNER JOIN clients cl ON cl.id = c.client_id
        ORDER BY c.title ASC
    ");
    $stmt->execute([$lawyerId]);
    $lawyerCases = $stmt->fetchAll();
} catch (PDOException $e) {
    $lawyerCases = [];
}

$lawyerCaseOptions = '<option value="">Select a case</option>';
foreach ($lawyerCases as $caseRow) {
    $lawyerCaseOptions .= '<option value="' . (int) $caseRow['id'] . '">'
        . htmlspecialchars($caseRow['title'] . ' — ' . $caseRow['first_name'] . ' ' . $caseRow['last_name'])
        . '</option>';
}

$rescheduleAvailabilityByDate = [];
$rescheduleHasSchedule = false;
try {
    $availabilityMaps = loadLawyerAvailabilityForBooking($pdo, [$lawyerId]);
    $rescheduleAvailabilityByDate = $availabilityMaps['byDate'][$lawyerId] ?? [];
    $rescheduleHasSchedule = !empty($availabilityMaps['hasSchedule'][$lawyerId]);
} catch (PDOException $e) {
    $rescheduleAvailabilityByDate = [];
    $rescheduleHasSchedule = false;
}

/**
 * Appointment length in minutes (30 or 60) from stored start/end.
 */
function lawyerAppointmentDurationMinutes(array $appointment): int
{
    $starts = strtotime((string) ($appointment['starts_at'] ?? ''));
    $ends = strtotime((string) ($appointment['ends_at'] ?? ''));
    if ($starts && $ends && $ends > $starts) {
        $minutes = (int) round(($ends - $starts) / 60);
        if ($minutes <= 30) {
            return 30;
        }
    }

    return 60;
}

/**
 * Case file link for a lawyer appointment row (own column for vertical alignment).
 */
function buildLawyerAppointmentCaseLink(array $appointment): string
{
    $caseId = (int) ($appointment['case_id'] ?? 0);
    if ($caseId <= 0) {
        return '<span class="text-muted text-xs">—</span>';
    }

    return '<a href="lawyer-case-view.php?id=' . $caseId . '" class="btn btn-sm btn-outline-primary mb-0">Case</a>';
}

/**
 * Build action buttons for a lawyer-managed appointment row.
 */
function buildLawyerAppointmentActions(array $appointment): string
{
    $id = (int) $appointment['id'];
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    if ($status === 'approved') {
        $status = 'accepted';
    }
    $dateVal = date('Y-m-d', strtotime($appointment['starts_at']));
    $timeVal = date('H:i', strtotime($appointment['starts_at']));
    $durationMinutes = lawyerAppointmentDurationMinutes($appointment);
    $defaultRescheduleStatus = ($status === 'accepted') ? 'accepted' : 'pending';
    $rescheduleOnclickArgs = $id . ', \''
        . htmlspecialchars($dateVal, ENT_QUOTES) . '\', \''
        . htmlspecialchars($timeVal, ENT_QUOTES) . '\', \''
        . htmlspecialchars($defaultRescheduleStatus, ENT_QUOTES) . '\', '
        . $durationMinutes;

    $html = '<div class="lawyer-appointment-actions">';

    if ($status === 'accepted') {
        $html .= '<span class="ca-status-pill ca-status-pill--done">Locked</span>';
        $html .= '</div>';
        return $html;
    }

    if ($status === 'rejected') {
        $html .= '<span class="ca-status-pill ca-status-pill--declined">Rejected</span>';
        $html .= '</div>';
        return $html;
    }

    if ($status === 'pending') {
        $html .= '
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="accept">
            <button type="submit" class="btn btn-sm btn-success mb-0" onclick="return confirm(\'Accept this appointment?\')">Accept</button>
        </form>
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="reject">
            <button type="submit" class="btn btn-sm btn-danger mb-0" onclick="return confirm(\'Reject this appointment?\')">Reject</button>
        </form>
        <button type="button" class="btn btn-sm btn-primary mb-0"
            onclick="openRescheduleModal(' . $rescheduleOnclickArgs . ')">
            Reschedule
        </button>';
        $html .= '</div>';
        return $html;
    }

    // Accepted (non-locked path) or other statuses: allow moving back to pending / reject / reschedule.
    $html .= '
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="accept">
            <button type="submit" class="btn btn-sm btn-success mb-0" onclick="return confirm(\'Accept this appointment?\')">Accept</button>
        </form>
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="reject">
            <button type="submit" class="btn btn-sm btn-danger mb-0" onclick="return confirm(\'Reject this appointment?\')">Reject</button>
        </form>
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="pending">
            <button type="submit" class="btn btn-sm btn-warning mb-0" onclick="return confirm(\'Keep this appointment as pending?\')">Pending</button>
        </form>
        <button type="button" class="btn btn-sm btn-primary mb-0"
            onclick="openRescheduleModal(' . $rescheduleOnclickArgs . ')">
            Reschedule
        </button>';

    $html .= '</div>';

    return $html;
}

$iconApptRow = legalpro_icon('calendar-clock');
$iconApptEmpty = legalpro_icon('calendar');
$iconCardHeader = legalpro_icon('calendar');

// Build appointments table HTML
$appointmentsTable = '';
if (empty($appointments)) {
    $appointmentsTable = '<tr><td colspan="7" class="border-0"><div class="text-center py-5 px-4">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconApptEmpty . '</div>
        <h5 class="font-weight-bolder mt-3 mb-2">No appointments found</h5>
        <p class="text-sm text-muted mb-0">No appointments found yet.</p>
    </div></td></tr>';
} else {
    foreach ($appointments as $appointment) {
        $appointmentDate = date('M d, Y', strtotime($appointment['starts_at']));
        $appointmentTime = date('g:i A', strtotime($appointment['starts_at']));
        $isToday = date('Y-m-d', strtotime($appointment['starts_at'])) === date('Y-m-d');

        $statusBadge = lawyer_appointment_status_badge($appointment);

        $rowClass = $appointment['status'] === 'rejected' ? 'table-danger' : ($isToday && $appointment['status'] === 'accepted' ? 'table-info' : '');

        $appointmentsTable .= '
        <tr id="apt-' . (int) $appointment['id'] . '" class="' . $rowClass . '">
            <td class="align-middle">
                <div class="d-flex align-items-center">
                    <div class="lawyer-appt-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconApptRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($appointment['case_title']) . '</h6>
                        <p class="text-xs text-muted mb-0">Case #' . htmlspecialchars($appointment['case_id']) . '</p>
                    </div>
                </div>
            </td>
            <td class="align-middle">
                <h6 class="mb-0 text-sm">' . htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) . '</h6>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($appointment['email']) . '</p>
            </td>
            <td class="align-middle text-center">
                <span class="text-sm font-weight-bold">' . htmlspecialchars($appointmentDate) . '</span>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($appointmentTime) . '</p>
            </td>
            <td class="align-middle">' . htmlspecialchars($appointment['notes'] ?: 'No notes') . '</td>
            <td class="align-middle text-center">' . $statusBadge . '</td>
            <td class="align-middle text-center lawyer-appointment-case-cell">' . buildLawyerAppointmentCaseLink($appointment) . '</td>
            <td class="align-middle text-end lp-table-actions lawyer-appointment-actions-cell">' . buildLawyerAppointmentActions($appointment) . '</td>
        </tr>';
    }
}

// Calendar events for FullCalendar hub
$appointmentCalendarEvents = [];
foreach ($appointments as $row) {
    if (empty($row['starts_at'])) {
        continue;
    }

    $status = strtolower((string) ($row['status'] ?? 'pending'));
    if ($status === 'approved') {
        $status = 'accepted';
    }
    $caseTitle = $row['case_title'] ?: 'Appointment';
    $clientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $clientEmail = trim((string) ($row['email'] ?? ''));
    $notes = trim((string) ($row['notes'] ?? ''));
    $startsLabel = date('M j, Y g:i A', strtotime($row['starts_at']));

    $appointmentCalendarEvents[] = [
        'id' => (string) $row['id'],
        'title' => $caseTitle,
        'start' => $row['starts_at'],
        'end' => !empty($row['ends_at']) ? $row['ends_at'] : null,
        'backgroundColor' => 'transparent',
        'borderColor' => 'transparent',
        'textColor' => '#344767',
        'extendedProps' => [
            'client' => $clientName !== '' ? $clientName : 'Client',
            'clientEmail' => $clientEmail,
            'notes' => $notes,
            'status' => $status,
            'statusLabel' => ucfirst($status),
            'appointmentId' => (int) $row['id'],
            'startsLabel' => $startsLabel,
            'searchHay' => strtolower(implode(' ', array_filter([
                $caseTitle,
                $clientName,
                $clientEmail,
                $status,
                $notes,
                $startsLabel,
                date('Y-m-d', strtotime($row['starts_at'])),
                date('m/d/Y', strtotime($row['starts_at'])),
                (string) ($row['case_id'] ?? ''),
                (string) $row['id'],
            ]))),
        ],
    ];
}

$appointmentCalendarEventsJson = json_encode(
    $appointmentCalendarEvents,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

$upcomingAppointmentsCalendarHtml = '';
$upcomingForCalendar = array_values(array_filter($appointments, function ($row) {
    if (empty($row['starts_at']) || strtotime($row['starts_at']) < time()) {
        return false;
    }
    $status = strtolower((string) ($row['status'] ?? 'pending'));

    return $status !== 'rejected';
}));
if (empty($upcomingForCalendar)) {
    $upcomingAppointmentsCalendarHtml = '<div class="dashboard-upcoming-empty">'
        . $iconApptEmpty
        . '<span>No upcoming appointments</span></div>';
} else {
    usort($upcomingForCalendar, function ($a, $b) {
        return strtotime($a['starts_at']) <=> strtotime($b['starts_at']);
    });
    foreach (array_slice($upcomingForCalendar, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'pending'));
        if ($status === 'approved') {
            $status = 'accepted';
        }
        $caseTitle = $row['case_title'] ?: 'Appointment';
        $clientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : 'Client';
        $hourLabel = date('g:i A', strtotime($row['starts_at']));
        $dayLabel = date('M j', strtotime($row['starts_at']));

        $upcomingAppointmentsCalendarHtml .= '
        <button type="button" class="dashboard-upcoming-item dashboard-upcoming-item--' . htmlspecialchars($status) . '" data-appointment-id="' . (int) $row['id'] . '">
            <span class="dashboard-upcoming-item__time">' . htmlspecialchars($hourLabel) . '<br><small style="font-weight:500;opacity:.8">' . htmlspecialchars($dayLabel) . '</small></span>
            <span class="flex-grow-1">
                <p class="dashboard-upcoming-item__title">' . htmlspecialchars($caseTitle) . '</p>
                <p class="dashboard-upcoming-item__sub">' . htmlspecialchars($clientName) . '</p>
            </span>
        </button>';
    }
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
    <title>LegalPro - My Appointments</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <style>
        .lawyer-appointments-page {
            --la-primary: var(--legalpro-theme-primary, #5e72e4);
        }
        .lawyer-appointment-actions {
            align-items: center;
            display: inline-flex;
            flex-wrap: nowrap;
            gap: 0.35rem;
            justify-content: flex-end;
            white-space: nowrap;
        }
        .lawyer-appointment-actions__form {
            display: inline-flex;
            margin: 0;
        }
        .lawyer-appointment-actions .btn {
            padding-left: 0.4rem;
            padding-right: 0.4rem;
            white-space: nowrap;
        }
        .lawyer-appointment-case-cell,
        .lawyer-appointment-actions-cell {
            vertical-align: middle;
            white-space: nowrap;
            width: 1%;
        }
        .lawyer-appointment-case-cell .btn {
            min-width: 4.25rem;
        }
        #reschedule_time option:disabled,
        #create_appointment_time option:disabled {
            color: #adb5bd;
        }
        .lawyer-appointments-page .dashboard-calendar-hub__head {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .lawyer-appointments-page .la-cal-search-wrap {
            position: relative;
            width: 100%;
        }
        .lawyer-appointments-page .la-cal-search-wrap--featured {
            padding: .9rem 1rem 1rem;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12) 0%, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04) 100%);
            border: 1px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            box-shadow: 0 6px 22px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .lawyer-appointments-page .la-cal-search-label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--la-primary);
            margin-bottom: .55rem;
        }
        .lawyer-appointments-page .la-cal-search-field {
            display: flex;
            align-items: center;
            gap: .7rem;
            background: #fff;
            border: 2px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.32);
            border-radius: 12px;
            padding: .7rem 1rem;
            transition: border-color .15s, box-shadow .15s, transform .15s;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.07);
        }
        .lawyer-appointments-page .la-cal-search-field:focus-within {
            border-color: var(--la-primary);
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.18), 0 4px 16px rgba(15, 23, 42, 0.1);
            transform: translateY(-1px);
        }
        .lawyer-appointments-page .la-cal-search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
            color: var(--la-primary);
            flex-shrink: 0;
        }
        .lawyer-appointments-page .la-cal-search-field svg {
            width: 18px;
            height: 18px;
            color: currentColor;
        }
        .lawyer-appointments-page .la-cal-search-input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            font-family: inherit;
        }
        .lawyer-appointments-page .la-cal-search-input::placeholder {
            color: #64748b;
            font-weight: 500;
        }
        .lawyer-appointments-page .la-cal-search-results {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            z-index: 30;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            max-height: 320px;
            overflow-y: auto;
            padding: .35rem;
        }
        .lawyer-appointments-page .la-cal-search-item {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            width: 100%;
            text-align: left;
            border: none;
            background: transparent;
            border-radius: 10px;
            padding: .65rem .75rem;
            cursor: pointer;
            font-family: inherit;
        }
        .lawyer-appointments-page .la-cal-search-item:hover,
        .lawyer-appointments-page .la-cal-search-item:focus-visible {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
            outline: none;
        }
        .lawyer-appointments-page .la-cal-search-item__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: .45rem;
            flex-shrink: 0;
        }
        .lawyer-appointments-page .la-cal-search-item__dot--pending { background: #fb6340; }
        .lawyer-appointments-page .la-cal-search-item__dot--accepted { background: #2dce89; }
        .lawyer-appointments-page .la-cal-search-item__dot--rejected { background: #f5365c; }
        .lawyer-appointments-page .la-cal-search-item__body { min-width: 0; flex: 1; }
        .lawyer-appointments-page .la-cal-search-item__title {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lawyer-appointments-page .la-cal-search-item__sub {
            font-size: 11.5px;
            color: #64748b;
            margin: 0;
        }
        .lawyer-appointments-page .la-cal-search-empty {
            padding: 1rem .75rem;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
        body.legalpro-dark-mode.lawyer-appointments-page .la-cal-search-wrap--featured {
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.2) 0%, rgba(61, 69, 92, 0.55) 100%);
            border-color: rgba(255, 255, 255, 0.12);
        }
        body.legalpro-dark-mode.lawyer-appointments-page .la-cal-search-label { color: #b8c4ff; }
        body.legalpro-dark-mode.lawyer-appointments-page .la-cal-search-field,
        body.legalpro-dark-mode.lawyer-appointments-page .la-cal-search-results {
            background: var(--lp-dark-surface-raised, #3d455c);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35);
        }
        body.legalpro-dark-mode.lawyer-appointments-page .la-cal-search-input { color: var(--lp-dark-text, #f8f9fc); }
        body.legalpro-dark-mode.lawyer-appointments-page .la-cal-search-item__title { color: var(--lp-dark-text, #f8f9fc); }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-appointments-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Appointments</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">My Appointments</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Appointments calendar -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-calendar-hub la-calendar-hub">
                        <div class="dashboard-calendar-hub__head">
                            <div class="la-calendar-hub__intro">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 w-100">
                                    <div>
                                        <h6 class="text-capitalize mb-0 font-weight-bold dashboard-calendar-hub__title">Appointments Calendar</h6>
                                        <p class="text-sm mb-0 text-muted">Use the search bar below to find appointments quickly, or click a calendar event</p>
                                        <div class="dashboard-legend-pills">
                                            <span class="dashboard-legend-pill dashboard-legend-pill--pending"><i></i> Pending</span>
                                            <span class="dashboard-legend-pill dashboard-legend-pill--accepted"><i></i> Accepted</span>
                                            <span class="dashboard-legend-pill dashboard-legend-pill--rejected"><i></i> Rejected</span>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-success btn-sm mb-0" onclick="openCreateAppointmentModal()">
                                        Schedule appointment
                                    </button>
                                </div>
                            </div>
                            <div class="la-cal-search-wrap la-cal-search-wrap--featured">
                                <label class="la-cal-search-label" for="laCalSearchInput">Search appointments</label>
                                <div class="la-cal-search-field">
                                    <span class="la-cal-search-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
                                            <circle cx="11" cy="11" r="7"></circle>
                                            <path d="M20 20l-3-3"></path>
                                        </svg>
                                    </span>
                                    <input type="search" id="laCalSearchInput" class="la-cal-search-input"
                                           placeholder="Search by matter, client, date, status…" autocomplete="off">
                                    <button type="button" class="lp-lawyer-search-reset-btn" data-lawyer-search-reset="laCalSearchInput" aria-label="Reset search">Reset</button>
                                </div>
                                <div class="la-cal-search-results" id="laCalSearchResults" hidden></div>
                            </div>
                        </div>
                        <div class="dashboard-calendar-hub__body">
                            <div class="dashboard-calendar-layout">
                                <div id="lawyerAppointmentsCalendar"></div>
                                <aside class="dashboard-upcoming-panel">
                                    <div class="dashboard-upcoming-panel__title">
                                        <span>Upcoming</span>
                                        <a href="#lawyerAppointmentsTable" class="text-xs text-primary font-weight-bold">View list</a>
                                    </div>
                                    <div class="dashboard-upcoming-list" id="lawyerUpcomingAppointmentsList">
                                        {UPCOMING_APPOINTMENTS_CALENDAR}
                                    </div>
                                </aside>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Appointments Table -->
            <div class="row" id="lawyerAppointmentsTable">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center">
                                <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary me-3">{ICON_CARD_HEADER}</div>
                                <div>
                                    <h6 class="mb-0">My Appointments</h6>
                                    <p class="text-xs text-muted mb-0">Schedule meetings with clients, accept requests, or reschedule</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Matter</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date & Time</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Notes</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                            <th class="text-end text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {APPOINTMENTS_TABLE}
                                    </tbody>
                                </table>
                            </div>
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
                            {COPYRIGHT_LINE}
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </main>

    <!-- Create appointment modal -->
    <div class="modal fade" id="createAppointmentModal" tabindex="-1" aria-labelledby="createAppointmentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" id="createAppointmentForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createAppointmentModalLabel">Schedule appointment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="appointment_action" value="create">
                        <div class="mb-3">
                            <label class="form-label" for="create_case_id">Case &amp; client</label>
                            <select class="form-select" name="case_id" id="create_case_id" required>
                                {LAWYER_CASE_OPTIONS}
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="create_appointment_date">Date</label>
                            <input type="date" class="form-control" name="appointment_date" id="create_appointment_date" min="{MIN_DATE}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="create_appointment_time">Time</label>
                            <select class="form-control" name="appointment_time" id="create_appointment_time" required>
                                <option value="">Select time</option>
                            </select>
                            <input type="hidden" id="create_duration_minutes" name="duration_minutes" value="60">
                            <small class="text-muted d-block mt-1">Unavailable times cannot be selected. If you have not set availability, standard business hours are open.</small>
                        </div>
                        <div id="createAvailabilityMessage" class="mb-3" style="display: none;"></div>
                        <div class="mb-3">
                            <label class="form-label" for="create_duration_select">Duration</label>
                            <select class="form-select" id="create_duration_select">
                                <option value="60" selected>1 hour</option>
                                <option value="30">30 minutes</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="create_appointment_status">Status</label>
                            <select class="form-select" name="appointment_status" id="create_appointment_status">
                                <option value="accepted" selected>Confirmed (accepted)</option>
                                <option value="pending">Pending client review</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="create_notes">Notes <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" name="notes" id="create_notes" rows="3" placeholder="Agenda or instructions for the client…"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary mb-0" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success mb-0" id="createAppointmentSubmit">Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reschedule modal -->
    <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" id="rescheduleForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="rescheduleModalLabel">Reschedule appointment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="appointment_action" value="reschedule">
                        <input type="hidden" name="appointment_id" id="reschedule_appointment_id" value="">
                        <div class="mb-3">
                            <label class="form-label">New date</label>
                            <input type="date" class="form-control" name="reschedule_date" id="reschedule_date" min="{MIN_DATE}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New time</label>
                            <select class="form-control" name="reschedule_time" id="reschedule_time" required>
                                <option value="">Select time</option>
                            </select>
                            <input type="hidden" id="reschedule_duration_minutes" name="reschedule_duration_minutes" value="60">
                            <small class="text-muted d-block mt-1">Unavailable times cannot be selected. If you have not set availability, standard business hours are open.</small>
                        </div>
                        <div id="rescheduleAvailabilityMessage" class="mb-3" style="display: none;"></div>
                        <div class="mb-3">
                            <label class="form-label">Status after reschedule</label>
                            <select class="form-select" name="reschedule_status" id="reschedule_status">
                                <option value="pending">Pending (client to review)</option>
                                <option value="accepted">Accepted (confirmed)</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Message to client <span class="text-muted">(optional)</span></label>
                            <textarea class="form-control" name="reschedule_notes" id="reschedule_notes" rows="3" placeholder="Reason for reschedule or instructions for the client…"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary mb-0" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary mb-0">Save new date &amp; time</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script>
        var lawyerAppointmentEvents = {APPOINTMENT_CALENDAR_EVENTS_JSON};
        var lawyerAppointmentsCalendar = null;

        function escapeHtmlLa(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function appointmentStatusKey(status) {
            var value = String(status || 'pending').toLowerCase();
            return value === 'approved' ? 'accepted' : value;
        }

        function focusLawyerAppointmentRow(id) {
            var row = document.getElementById('apt-' + id);
            if (!row) {
                return;
            }
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.add('table-warning');
            setTimeout(function() {
                row.classList.remove('table-warning');
            }, 2200);
        }

        function initLawyerAppointmentsCalendar() {
            var calendarEl = document.getElementById('lawyerAppointmentsCalendar');
            var upcomingList = document.getElementById('lawyerUpcomingAppointmentsList');
            if (!calendarEl || typeof FullCalendar === 'undefined') {
                initLawyerCalendarSearch();
                return;
            }

            function renderAppointmentEvent(arg) {
                var props = arg.event.extendedProps || {};
                var statusKey = appointmentStatusKey(props.status);
                var timeText = arg.timeText || '';
                var title = arg.event.title || 'Appointment';
                if (title.length > 22) {
                    title = title.slice(0, 19) + '...';
                }
                var wrap = document.createElement('div');
                wrap.className = 'dashboard-cal-event';
                wrap.innerHTML =
                    '<span class="dashboard-cal-event__dot dashboard-cal-event__dot--' + statusKey + '"></span>' +
                    '<span class="dashboard-cal-event__text">' + timeText + (timeText ? ' ' : '') + title + '</span>';
                return { domNodes: [wrap] };
            }

            if (upcomingList) {
                upcomingList.addEventListener('click', function(e) {
                    var btn = e.target.closest('[data-appointment-id]');
                    if (!btn) {
                        return;
                    }
                    focusLawyerAppointmentRow(parseInt(btn.getAttribute('data-appointment-id'), 10));
                });
            }

            lawyerAppointmentsCalendar = new FullCalendar.Calendar(calendarEl, {
                initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
                height: 'auto',
                firstDay: 1,
                navLinks: true,
                nowIndicator: true,
                fixedWeekCount: false,
                dayMaxEvents: 3,
                moreLinkClick: 'day',
                buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                dayHeaderFormat: { weekday: 'short' },
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listWeek'
                },
                events: lawyerAppointmentEvents,
                eventContent: renderAppointmentEvent,
                dateClick: function(info) {
                    if (window.legalproHandleCalendarDateClick) {
                        window.legalproHandleCalendarDateClick(info, function(event) {
                            var props = event.extendedProps || {};
                            focusLawyerAppointmentRow(props.appointmentId || parseInt(event.id, 10));
                        });
                    }
                },
                dayCellDidMount: function(info) {
                    if (window.legalproMountCalendarDayCell) {
                        window.legalproMountCalendarDayCell(info);
                    }
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    var props = info.event.extendedProps || {};
                    focusLawyerAppointmentRow(props.appointmentId || parseInt(info.event.id, 10));
                },
                eventDidMount: function(info) {
                    if (window.legalproMountCalendarEventClickable) {
                        window.legalproMountCalendarEventClickable(info);
                    }
                    var props = info.event.extendedProps || {};
                    var tip = info.event.title;
                    if (props.client) {
                        tip += '\nClient: ' + props.client;
                    }
                    info.el.setAttribute('title', tip);
                }
            });
            lawyerAppointmentsCalendar.render();
            initLawyerCalendarSearch();
        }

        function initLawyerCalendarSearch() {
            var input = document.getElementById('laCalSearchInput');
            var resultsEl = document.getElementById('laCalSearchResults');
            if (!input || !resultsEl) {
                return;
            }

            function hideResults() {
                resultsEl.hidden = true;
                resultsEl.innerHTML = '';
            }

            input.addEventListener('input', function() {
                var q = input.value.trim().toLowerCase();
                if (!q) {
                    hideResults();
                    return;
                }

                var matches = lawyerAppointmentEvents.filter(function(ev) {
                    var props = ev.extendedProps || {};
                    var hay = props.searchHay || ((ev.title || '') + ' ' + (props.client || '')).toLowerCase();
                    return hay.indexOf(q) !== -1;
                }).sort(function(a, b) {
                    return new Date(b.start).getTime() - new Date(a.start).getTime();
                });

                if (!matches.length) {
                    resultsEl.innerHTML = '<div class="la-cal-search-empty">No appointments match your search.</div>';
                    resultsEl.hidden = false;
                    return;
                }

                var html = '';
                matches.slice(0, 12).forEach(function(ev) {
                    var props = ev.extendedProps || {};
                    var statusKey = appointmentStatusKey(props.status);
                    html += '<button type="button" class="la-cal-search-item" data-appointment-id="' + escapeHtmlLa(props.appointmentId || ev.id) + '" data-start="' + escapeHtmlLa(ev.start || '') + '">' +
                        '<span class="la-cal-search-item__dot la-cal-search-item__dot--' + escapeHtmlLa(statusKey) + '" aria-hidden="true"></span>' +
                        '<span class="la-cal-search-item__body">' +
                            '<p class="la-cal-search-item__title">' + escapeHtmlLa(ev.title || 'Appointment') + '</p>' +
                            '<p class="la-cal-search-item__sub">' + escapeHtmlLa(props.startsLabel || '') + ' · ' + escapeHtmlLa(props.client || 'Client') + ' · ' + escapeHtmlLa(props.statusLabel || props.status || 'Pending') + '</p>' +
                        '</span>' +
                    '</button>';
                });
                resultsEl.innerHTML = html;
                resultsEl.hidden = false;
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideResults();
                    input.blur();
                }
            });

            resultsEl.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-appointment-id]');
                if (!btn) {
                    return;
                }
                var id = parseInt(btn.getAttribute('data-appointment-id'), 10);
                var start = btn.getAttribute('data-start');
                if (lawyerAppointmentsCalendar && start) {
                    lawyerAppointmentsCalendar.gotoDate(start);
                }
                focusLawyerAppointmentRow(id);
                hideResults();
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.la-cal-search-wrap')) {
                    hideResults();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', initLawyerAppointmentsCalendar);
    </script>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        const lawyerAvailabilityByDate = {LAWYER_AVAILABILITY_BY_DATE_JSON};
        const lawyerHasPublishedSchedule = {LAWYER_HAS_SCHEDULE_JSON};
        const NO_AVAILABILITY_ON_DATE_MSG = 'No available times on this date. Choose another date.';
        const SLOT_DAY_START_MINUTES = 9 * 60;
        const SLOT_DAY_END_MINUTES = 17 * 60 + 30;

        var rescheduleOriginalDate = '';
        var rescheduleOriginalTime = '';

        function openCreateAppointmentModal() {
            var form = document.getElementById('createAppointmentForm');
            if (form) {
                form.reset();
            }
            document.getElementById('create_duration_minutes').value = '60';
            if (typeof window.renderCreateTimeOptions === 'function') {
                window.renderCreateTimeOptions('');
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('createAppointmentModal')).show();
        }

        function openRescheduleModal(appointmentId, dateValue, timeValue, statusValue, durationMinutes) {
            document.getElementById('reschedule_appointment_id').value = appointmentId;
            document.getElementById('reschedule_date').value = dateValue;
            document.getElementById('reschedule_status').value = statusValue || 'pending';
            document.getElementById('reschedule_notes').value = '';
            document.getElementById('reschedule_duration_minutes').value = durationMinutes === 30 ? '30' : '60';
            rescheduleOriginalDate = dateValue || '';
            rescheduleOriginalTime = window.normalizeRescheduleSelectTime(timeValue);
            window.renderRescheduleTimeOptions(window.normalizeRescheduleSelectTime(timeValue));
            bootstrap.Modal.getOrCreateInstance(document.getElementById('rescheduleModal')).show();
        }

        (function() {
            var dateInput = document.getElementById('reschedule_date');
            var timeSelect = document.getElementById('reschedule_time');
            var durationInput = document.getElementById('reschedule_duration_minutes');
            var messageEl = document.getElementById('rescheduleAvailabilityMessage');
            var form = document.getElementById('rescheduleForm');
            var saveBtn = form ? form.querySelector('button[type="submit"]') : null;

            function getRescheduleDurationMinutes() {
                return durationInput && parseInt(durationInput.value, 10) === 30 ? 30 : 60;
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

            function normalizeRescheduleSelectTime(timeValue) {
                if (!timeValue) {
                    return '';
                }
                var parts = String(timeValue).split(':');
                return parts[0].padStart(2, '0') + ':' + (parts[1] || '00').padStart(2, '0');
            }

            function normalizeTimeValue(timeValue) {
                return timeValue.length === 5 ? timeValue + ':00' : timeValue;
            }

            function addDurationToTime(timeValue, durationMinutes) {
                var normalized = timeValue.length === 5 ? timeValue : timeValue.slice(0, 5);
                var parts = normalized.split(':');
                var total = parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10) + durationMinutes;
                if (total >= 24 * 60) {
                    total = 24 * 60 - 1;
                }
                return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0') + ':00';
            }

            function rangesOverlap(startA, endA, startB, endB) {
                return startA < endB && endA > startB;
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

            function formatSlotRangeLabel(startValue, durationMinutes) {
                if (durationMinutes === 60) {
                    return formatTimeLabel(startValue);
                }
                var endHm = addDurationToTime(startValue.length === 5 ? startValue + ':00' : startValue, durationMinutes).slice(0, 5);
                return formatTimeLabel(startValue) + ' \u2013 ' + formatTimeLabel(endHm);
            }

            function getSlotsForDate(dateValue) {
                return lawyerAvailabilityByDate[dateValue] ? lawyerAvailabilityByDate[dateValue].slice() : [];
            }

            function lawyerHasAvailabilityOnDate(dateValue) {
                return getSlotsForDate(dateValue).some(function(slot) {
                    return slot.type === 'available';
                });
            }

            function nowParts() {
                var now = new Date();
                return {
                    date: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0'),
                    time: String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0')
                };
            }

            function isBlockedByUnavailable(timeValue, slots, durationMinutes, dateValue, originalDate, originalTime) {
                var startTime = normalizeTimeValue(timeValue);
                var endTime = addDurationToTime(startTime, durationMinutes);
                var compareDate = originalDate || rescheduleOriginalDate;
                var compareTime = originalTime || rescheduleOriginalTime;
                var isSameAsOriginal = compareDate !== ''
                    && dateValue === compareDate
                    && normalizeRescheduleSelectTime(timeValue) === compareTime;

                return slots.some(function(slot) {
                    if (slot.type !== 'unavailable') {
                        return false;
                    }
                    if (!rangesOverlap(startTime, endTime, slot.start, slot.end)) {
                        return false;
                    }
                    return !isSameAsOriginal;
                });
            }

            function isWithinAvailable(timeValue, slots, durationMinutes) {
                var startTime = normalizeTimeValue(timeValue);
                var endTime = addDurationToTime(startTime, durationMinutes);
                return slots.some(function(slot) {
                    return slot.type === 'available' && startTime >= slot.start && endTime <= slot.end;
                });
            }

            function isTimeSlotBookable(timeValue, dateValue, slots, durationMinutes, originalDate, originalTime) {
                if (!timeValue) {
                    return false;
                }
                var now = nowParts();
                if (dateValue === now.date && timeValue < now.time) {
                    return false;
                }
                if (isBlockedByUnavailable(timeValue, slots, durationMinutes, dateValue, originalDate || '', originalTime || '')) {
                    return false;
                }
                if (!lawyerHasPublishedSchedule) {
                    return true;
                }
                if (!lawyerHasAvailabilityOnDate(dateValue)) {
                    return false;
                }
                return isWithinAvailable(timeValue, slots, durationMinutes);
            }

            function setRescheduleMessage(html, visible) {
                if (!messageEl) {
                    return;
                }
                messageEl.style.display = visible ? 'block' : 'none';
                messageEl.innerHTML = html || '';
            }

            function setSaveEnabled(enabled) {
                if (saveBtn) {
                    saveBtn.disabled = !enabled;
                }
            }

            window.renderRescheduleTimeOptions = function(preservedTime) {
                if (!timeSelect || !dateInput) {
                    return;
                }

                var dateValue = dateInput.value;
                var durationMinutes = getRescheduleDurationMinutes();
                var previous = preservedTime || normalizeRescheduleSelectTime(timeSelect.value);
                var slots = getSlotsForDate(dateValue);
                var hasBookable = false;

                timeSelect.innerHTML = '<option value="">Select time</option>';
                getStandardSlotTimes(durationMinutes).forEach(function(slotValue) {
                    var option = document.createElement('option');
                    option.value = slotValue;
                    option.textContent = formatSlotRangeLabel(slotValue, durationMinutes);
                    timeSelect.appendChild(option);
                });

                if (!dateValue) {
                    setRescheduleMessage(
                        '<div class="alert alert-info py-2 mb-0">Select a date to see your available times.</div>',
                        true
                    );
                    setSaveEnabled(false);
                    return;
                }

                if (lawyerHasPublishedSchedule && !lawyerHasAvailabilityOnDate(dateValue)) {
                    timeSelect.querySelectorAll('option').forEach(function(option) {
                        if (option.value) {
                            option.disabled = true;
                        }
                    });
                    timeSelect.value = '';
                    setRescheduleMessage(
                        '<div class="alert alert-warning py-2 mb-0">' + NO_AVAILABILITY_ON_DATE_MSG + '</div>',
                        true
                    );
                    setSaveEnabled(false);
                    return;
                }

                timeSelect.querySelectorAll('option').forEach(function(option) {
                    if (!option.value) {
                        return;
                    }
                    if (isTimeSlotBookable(option.value, dateValue, slots, durationMinutes, rescheduleOriginalDate, rescheduleOriginalTime)) {
                        option.disabled = false;
                        hasBookable = true;
                    } else {
                        option.disabled = true;
                    }
                });

                if (previous) {
                    var match = timeSelect.querySelector('option[value="' + previous + '"]');
                    timeSelect.value = match && !match.disabled ? previous : '';
                }

                if (!hasBookable) {
                    setRescheduleMessage(
                        '<div class="alert alert-warning py-2 mb-0">' + NO_AVAILABILITY_ON_DATE_MSG + '</div>',
                        true
                    );
                    setSaveEnabled(false);
                    return;
                }

                if (!timeSelect.value) {
                    setRescheduleMessage(
                        '<div class="alert alert-info py-2 mb-0">Select an available time from the list.</div>',
                        true
                    );
                    setSaveEnabled(false);
                    return;
                }

                setRescheduleMessage('', false);
                setSaveEnabled(true);
            };

            window.normalizeRescheduleSelectTime = normalizeRescheduleSelectTime;

            function renderLawyerTimeSelect(config) {
                var timeSelect = config.timeSelect;
                var dateInput = config.dateInput;
                var durationInput = config.durationInput;
                var messageEl = config.messageEl;
                var setEnabled = config.setEnabled;
                var preservedTime = config.preservedTime || '';
                var originalDate = config.originalDate || '';
                var originalTime = config.originalTime || '';

                if (!timeSelect || !dateInput) {
                    return;
                }

                var dateValue = dateInput.value;
                var durationMinutes = durationInput && parseInt(durationInput.value, 10) === 30 ? 30 : 60;
                var previous = preservedTime || normalizeRescheduleSelectTime(timeSelect.value);
                var slots = getSlotsForDate(dateValue);
                var hasBookable = false;

                timeSelect.innerHTML = '<option value="">Select time</option>';
                getStandardSlotTimes(durationMinutes).forEach(function(slotValue) {
                    var option = document.createElement('option');
                    option.value = slotValue;
                    option.textContent = formatSlotRangeLabel(slotValue, durationMinutes);
                    timeSelect.appendChild(option);
                });

                if (!dateValue) {
                    if (messageEl) {
                        messageEl.style.display = 'block';
                        messageEl.innerHTML = '<div class="alert alert-info py-2 mb-0">Select a date to see available times.</div>';
                    }
                    if (setEnabled) {
                        setEnabled(false);
                    }
                    return;
                }

                if (lawyerHasPublishedSchedule && !lawyerHasAvailabilityOnDate(dateValue)) {
                    timeSelect.querySelectorAll('option').forEach(function(option) {
                        if (option.value) {
                            option.disabled = true;
                        }
                    });
                    timeSelect.value = '';
                    if (messageEl) {
                        messageEl.style.display = 'block';
                        messageEl.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + NO_AVAILABILITY_ON_DATE_MSG + '</div>';
                    }
                    if (setEnabled) {
                        setEnabled(false);
                    }
                    return;
                }

                timeSelect.querySelectorAll('option').forEach(function(option) {
                    if (!option.value) {
                        return;
                    }
                    if (isTimeSlotBookable(option.value, dateValue, slots, durationMinutes, originalDate, originalTime)) {
                        option.disabled = false;
                        hasBookable = true;
                    } else {
                        option.disabled = true;
                    }
                });

                if (previous) {
                    var match = timeSelect.querySelector('option[value="' + previous + '"]');
                    timeSelect.value = match && !match.disabled ? previous : '';
                }

                if (!hasBookable) {
                    if (messageEl) {
                        messageEl.style.display = 'block';
                        messageEl.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + NO_AVAILABILITY_ON_DATE_MSG + '</div>';
                    }
                    if (setEnabled) {
                        setEnabled(false);
                    }
                    return;
                }

                if (!timeSelect.value) {
                    if (messageEl) {
                        messageEl.style.display = 'block';
                        messageEl.innerHTML = '<div class="alert alert-info py-2 mb-0">Select an available time from the list.</div>';
                    }
                    if (setEnabled) {
                        setEnabled(false);
                    }
                    return;
                }

                if (messageEl) {
                    messageEl.style.display = 'none';
                    messageEl.innerHTML = '';
                }
                if (setEnabled) {
                    setEnabled(true);
                }
            }

            window.renderCreateTimeOptions = function(preservedTime) {
                renderLawyerTimeSelect({
                    timeSelect: document.getElementById('create_appointment_time'),
                    dateInput: document.getElementById('create_appointment_date'),
                    durationInput: document.getElementById('create_duration_minutes'),
                    messageEl: document.getElementById('createAvailabilityMessage'),
                    setEnabled: function(enabled) {
                        var btn = document.getElementById('createAppointmentSubmit');
                        if (btn) {
                            btn.disabled = !enabled;
                        }
                    },
                    preservedTime: preservedTime || ''
                });
            };

            var createDateInput = document.getElementById('create_appointment_date');
            var createTimeSelect = document.getElementById('create_appointment_time');
            var createDurationSelect = document.getElementById('create_duration_select');
            var createDurationInput = document.getElementById('create_duration_minutes');
            var createForm = document.getElementById('createAppointmentForm');

            if (createDurationSelect && createDurationInput) {
                createDurationSelect.addEventListener('change', function() {
                    createDurationInput.value = createDurationSelect.value === '30' ? '30' : '60';
                    window.renderCreateTimeOptions(createTimeSelect ? createTimeSelect.value : '');
                });
            }

            if (createDateInput) {
                createDateInput.addEventListener('change', function() {
                    window.renderCreateTimeOptions('');
                });
            }

            if (createTimeSelect) {
                createTimeSelect.addEventListener('change', function() {
                    window.renderCreateTimeOptions(createTimeSelect.value);
                });
            }

            if (createForm) {
                createForm.addEventListener('submit', function(event) {
                    var dateValue = createDateInput ? createDateInput.value : '';
                    var timeValue = createTimeSelect ? createTimeSelect.value : '';
                    var selected = createTimeSelect ? createTimeSelect.options[createTimeSelect.selectedIndex] : null;

                    if (!dateValue || !timeValue || !selected || selected.disabled) {
                        event.preventDefault();
                        var messageEl = document.getElementById('createAvailabilityMessage');
                        if (messageEl) {
                            messageEl.style.display = 'block';
                            messageEl.innerHTML = '<div class="alert alert-warning py-2 mb-0">Please select an available time.</div>';
                        }
                    }
                });
            }

            if (dateInput) {
                dateInput.addEventListener('change', function() {
                    renderRescheduleTimeOptions('');
                });
            }

            if (timeSelect) {
                timeSelect.addEventListener('change', function() {
                    renderRescheduleTimeOptions(timeSelect.value);
                });
            }

            if (form) {
                form.addEventListener('submit', function(event) {
                    var dateValue = dateInput ? dateInput.value : '';
                    var timeValue = timeSelect ? timeSelect.value : '';
                    var selected = timeSelect ? timeSelect.options[timeSelect.selectedIndex] : null;

                    if (!dateValue || !timeValue || !selected || selected.disabled) {
                        event.preventDefault();
                        setRescheduleMessage(
                            '<div class="alert alert-warning py-2 mb-0">Please select an available time.</div>',
                            true
                        );
                        setSaveEnabled(false);
                    }
                });
            }
        })();
    </script>
</body>
</html>
HTML;

$replacements = [
    '{MESSAGE}' => $messageHtml,
    '{MIN_DATE}' => date('Y-m-d'),
    '{LAWYER_AVAILABILITY_BY_DATE_JSON}' => json_encode($rescheduleAvailabilityByDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
    '{LAWYER_HAS_SCHEDULE_JSON}' => $rescheduleHasSchedule ? 'true' : 'false',
    '{NAVIGATION}' => $navHtml,
    '{STATUS_TODAY}' => '',
    '{STATUS_PAST}' => '',
    '{APPOINTMENTS_TABLE}' => $appointmentsTable,
    '{UPCOMING_APPOINTMENTS_CALENDAR}' => $upcomingAppointmentsCalendarHtml,
    '{APPOINTMENT_CALENDAR_EVENTS_JSON}' => $appointmentCalendarEventsJson,
    '{ICON_CARD_HEADER}' => $iconCardHeader,
    '{LAWYER_CASE_OPTIONS}' => $lawyerCaseOptions,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
?>
