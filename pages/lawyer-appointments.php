<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/appointment_availability.php';
require_once __DIR__ . '/../lib/case_lawyers.php';
require_once __DIR__ . '/../lib/portal_list_ui.php';
require_once __DIR__ . '/../lib/appointment_list_ui.php';
require_once __DIR__ . '/../lib/portal_calendar_events.php';
require_once __DIR__ . '/../inc/portal-calendar-studio.php';

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
                    try {
                        $bookingResult = legalpro_with_locked_lawyer_booking($pdo, function () use (
                            $pdo,
                            $lawyerId,
                            $appointmentDate,
                            $appointmentTime,
                            $durationMinutes,
                            $caseId,
                            $caseRow,
                            $notes,
                            $newStatus
                        ) {
                            $capacity = legalpro_assert_lawyer_slot_capacity_for_booking(
                                $pdo,
                                $lawyerId,
                                $appointmentDate,
                                $appointmentTime,
                                $durationMinutes
                            );
                            if (!$capacity['ok']) {
                                return $capacity;
                            }

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

                            return ['ok' => true];
                        });

                        if (empty($bookingResult['ok'])) {
                            $message = $bookingResult['message'] ?? 'The selected time is not available.';
                            $messageType = 'danger';
                        } else {
                            $message = $newStatus === 'accepted'
                                ? 'Appointment scheduled and confirmed with your client.'
                                : 'Appointment created and sent to your client for review.';
                            $messageType = 'success';
                        }
                    } catch (PDOException $e) {
                        $message = 'Error creating appointment: ' . htmlspecialchars($e->getMessage());
                        $messageType = 'danger';
                    }
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
                        try {
                            $bookingResult = legalpro_with_locked_lawyer_booking($pdo, function () use (
                                $pdo,
                                $lawyerId,
                                $newDate,
                                $newTime,
                                $durationMinutes,
                                $appointmentId,
                                $appointment,
                                $rescheduleNotes,
                                $newStatus
                            ) {
                                $capacity = legalpro_assert_lawyer_slot_capacity_for_booking(
                                    $pdo,
                                    $lawyerId,
                                    $newDate,
                                    $newTime,
                                    $durationMinutes,
                                    $appointmentId
                                );
                                if (!$capacity['ok']) {
                                    return $capacity;
                                }

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

                                return ['ok' => true];
                            });

                            if (empty($bookingResult['ok'])) {
                                $message = $bookingResult['message'] ?? 'The selected time is not available.';
                                $messageType = 'danger';
                            } else {
                                $message = 'Appointment rescheduled successfully.';
                                $messageType = 'success';
                            }
                        } catch (PDOException $e) {
                            $message = 'Unable to reschedule appointment: ' . htmlspecialchars($e->getMessage());
                            $messageType = 'danger';
                        }
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

    return '<a href="lawyer-case-view.php?id=' . $caseId . '" class="btn btn-sm btn-outline-primary mb-0">' . htmlspecialchars(lawyer_tf('appointments.case_link', 'Case')) . '</a>';
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

    $confirmAccept = htmlspecialchars(lawyer_tf('appointments.confirm_accept', 'Accept this appointment?'), ENT_QUOTES);
    $confirmReject = htmlspecialchars(lawyer_tf('appointments.confirm_reject', 'Reject this appointment?'), ENT_QUOTES);
    $confirmPending = htmlspecialchars(lawyer_tf('appointments.confirm_pending', 'Keep this appointment as pending?'), ENT_QUOTES);
    $lblAccept = htmlspecialchars(lawyer_tf('appointments.accept', 'Accept'));
    $lblReject = htmlspecialchars(lawyer_tf('appointments.reject', 'Reject'));
    $lblPending = htmlspecialchars(lawyer_tf('appointments.pending', 'Pending'));
    $lblReschedule = htmlspecialchars(lawyer_tf('appointments.reschedule_btn', 'Reschedule'));
    $lblLocked = htmlspecialchars(lawyer_tf('appointments.status_locked', 'Locked'));
    $lblRejected = htmlspecialchars(lawyer_tf('appointments.status_rejected', 'Rejected'));

    $html = '<div class="lawyer-appointment-actions">';

    if ($status === 'accepted') {
        $html .= '<span class="ca-status-pill ca-status-pill--done">' . $lblLocked . '</span>';
        $html .= '</div>';
        return $html;
    }

    if ($status === 'rejected') {
        $html .= '<span class="ca-status-pill ca-status-pill--declined">' . $lblRejected . '</span>';
        $html .= '</div>';
        return $html;
    }

    if ($status === 'pending') {
        $html .= '
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="accept">
            <button type="submit" class="btn btn-sm btn-success mb-0" onclick="return confirm(\'' . $confirmAccept . '\')">' . $lblAccept . '</button>
        </form>
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="reject">
            <button type="submit" class="btn btn-sm btn-danger mb-0" onclick="return confirm(\'' . $confirmReject . '\')">' . $lblReject . '</button>
        </form>
        <button type="button" class="btn btn-sm btn-primary mb-0"
            onclick="openRescheduleModal(' . $rescheduleOnclickArgs . ')">
            ' . $lblReschedule . '
        </button>';
        $html .= '</div>';
        return $html;
    }

    // Accepted (non-locked path) or other statuses: allow moving back to pending / reject / reschedule.
    $html .= '
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="accept">
            <button type="submit" class="btn btn-sm btn-success mb-0" onclick="return confirm(\'' . $confirmAccept . '\')">' . $lblAccept . '</button>
        </form>
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="reject">
            <button type="submit" class="btn btn-sm btn-danger mb-0" onclick="return confirm(\'' . $confirmReject . '\')">' . $lblReject . '</button>
        </form>
        <form method="post" class="lawyer-appointment-actions__form">
            <input type="hidden" name="appointment_id" value="' . $id . '">
            <input type="hidden" name="appointment_action" value="pending">
            <button type="submit" class="btn btn-sm btn-warning mb-0" onclick="return confirm(\'' . $confirmPending . '\')">' . $lblPending . '</button>
        </form>
        <button type="button" class="btn btn-sm btn-primary mb-0"
            onclick="openRescheduleModal(' . $rescheduleOnclickArgs . ')">
            ' . $lblReschedule . '
        </button>';

    $html .= '</div>';

    return $html;
}

$iconApptRow = legalpro_icon('calendar-clock');
$iconApptEmpty = legalpro_icon('calendar');
$iconCardHeader = legalpro_icon('calendar');

$appointmentsCount = count($appointments);
$appointmentsCountLabel = $appointmentsCount === 1
    ? lawyer_tf('appointments.count_one', '1 appointment')
    : lawyer_tf('appointments.count_many', ':count appointments', ['count' => $appointmentsCount]);

// Build appointments table HTML
$appointmentsTable = '';
if (empty($appointments)) {
    $appointmentsTable = '<tr><td colspan="7" class="border-0"><div class="text-center py-5 px-4">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconApptEmpty . '</div>
        <h5 class="font-weight-bolder mt-3 mb-2">' . htmlspecialchars(lawyer_tf('appointments.empty_title', 'No appointments found')) . '</h5>
        <p class="text-sm text-muted mb-0">' . htmlspecialchars(lawyer_tf('appointments.empty_sub_table', 'No appointments found yet.')) . '</p>
    </div></td></tr>';
} else {
    foreach ($appointments as $appointment) {
        $actionsHtml = '<div class="legalpro-admin-list-row__actions lp-appt-list-actions lawyer-appointment-actions-cell">'
            . buildLawyerAppointmentActions($appointment)
            . '</div>';
        $appointmentsTable .= legalpro_render_portal_appointment_table_row(
            $appointment,
            $actionsHtml,
            ['person_header' => 'client', 'include_calendar' => true, 'row_id_prefix' => 'apt-']
        );
    }
}

// Calendar events for FullCalendar hub
$appointmentCalendarEvents = legalpro_portal_build_appointment_calendar_events($appointments, [
    'subtitle_field' => 'client',
    'subtitle_fallback' => lawyer_tf('common.client', 'Client'),
    'title_fallback' => lawyer_tf('common.appointment', 'Appointment'),
    'extend_props' => static function (array $row, array $props): array {
        $status = strtolower((string) ($props['status'] ?? 'pending'));
        $startsAt = (string) ($row['starts_at'] ?? '');

        return array_merge($props, [
            'clientEmail' => trim((string) ($row['email'] ?? '')),
            'statusLabel' => lawyer_appointment_status_label($status),
            'durationMinutes' => lawyerAppointmentDurationMinutes($row),
            'rescheduleDate' => $startsAt !== '' ? date('Y-m-d', strtotime($startsAt)) : '',
            'rescheduleTime' => $startsAt !== '' ? date('H:i', strtotime($startsAt)) : '',
            'rescheduleStatus' => ($status === 'accepted') ? 'accepted' : 'pending',
            'canReschedule' => !in_array($status, ['accepted', 'rejected'], true),
            'startsLabel' => $startsAt !== '' ? lawyer_portal_format_datetime($startsAt) : '',
        ]);
    },
]);

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
        . '<span>' . htmlspecialchars(lawyer_tf('dashboard.no_appts_title', 'No upcoming appointments')) . '</span></div>';
} else {
    usort($upcomingForCalendar, function ($a, $b) {
        return strtotime($a['starts_at']) <=> strtotime($b['starts_at']);
    });
    foreach (array_slice($upcomingForCalendar, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'pending'));
        if ($status === 'approved') {
            $status = 'accepted';
        }
        $caseTitle = $row['case_title'] ?: lawyer_tf('common.appointment', 'Appointment');
        $clientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : lawyer_tf('common.client', 'Client');
        $hourLabel = lawyer_portal_format_time(date('H:i', strtotime($row['starts_at'])));
        if (getLawyerPortalLocale() === 'fr') {
            $months = ['', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
            $ts = strtotime($row['starts_at']);
            $dayLabel = date('j', $ts) . ' ' . ($months[(int) date('n', $ts)] ?? date('M', $ts));
        } else {
            $dayLabel = date('M j', strtotime($row['starts_at']));
        }

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

$pageTitle = lawyer_tf('appointments.page_title', 'My Appointments');
$lawyerApptUpcoming = count($upcomingForCalendar);
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle, [], [
    'subtitle' => $lawyerApptUpcoming . ' ' . strtolower(lawyer_tf('appointments.upcoming', 'upcoming')),
]);
$apptI18nJson = json_encode([
    'dateLocale' => lawyer_portal_js_date_locale(),
    'fcLocale' => lawyer_portal_fc_locale(),
    'fcButtons' => lawyer_portal_fc_button_text(),
    'pending' => lawyer_appointment_status_label('pending'),
    'accepted' => lawyer_appointment_status_label('accepted'),
    'rejected' => lawyer_appointment_status_label('rejected'),
    'client' => lawyer_tf('common.client', 'Client'),
    'appointment' => lawyer_tf('common.appointment', 'Appointment'),
    'noMatchSearch' => lawyer_tf('appointments.no_match_search', 'No appointments match your search.'),
    'use24h' => getLawyerPortalLocale() === 'fr',
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$calendarStudioHtml = legalpro_render_portal_schedule_hub_calendar([
    'calendar_id' => 'lawyerAppointmentsCalendar',
    'add_onclick' => 'openCreateAppointmentModal()',
    'add_title' => lawyer_tf('appointments.schedule', 'Schedule appointment'),
    'add_aria' => lawyer_tf('appointments.schedule', 'Schedule appointment'),
    'legend' => legalpro_portal_appointment_calendar_legend(static function (string $key, string $label): string {
        $map = [
            'scheduled' => lawyer_tf('appointments.status_scheduled', 'Scheduled'),
            'confirmed' => lawyer_tf('appointments.status_confirmed', 'Confirmed'),
            'rescheduled' => lawyer_tf('appointments.status_rescheduled', 'Rescheduled'),
            'past' => lawyer_tf('appointments.status_past', 'Past'),
            'completed' => lawyer_tf('appointments.status_completed', 'Completed'),
            'cancelled' => lawyer_tf('appointments.status_cancelled', 'Cancelled'),
        ];

        return $map[$key] ?? $label;
    }),
], 'lawyerAppointmentsCalendarHub');

$appointmentsListSection = legalpro_render_portal_schedule_hub_list_section(
    'lawyerAppointmentsTable',
    lawyer_tf('appointments.list_title', 'Appointment List'),
    $appointmentsCountLabel,
    'lawyerAppointmentsSearchInput',
    lawyer_tf('appointments.search_placeholder_list', 'Search by matter, client, date, or status…'),
    lawyer_tf('appointments.search_label', 'Search appointments'),
    'lawyerAppointmentsStatusFilter',
    legalpro_portal_appointment_status_options(),
    'lawyerAppointmentsTableBody',
    $appointmentsTable,
    'lawyerAppointmentsFilterEmpty',
    [
        'header_labels' => [
            'datetime' => lawyer_tf('appointments.col_datetime', 'Date & Time'),
            'title' => lawyer_tf('appointments.col_matter', 'Title'),
            'person' => lawyer_tf('appointments.col_client', 'Client'),
            'case' => lawyer_tf('common.case', 'Case'),
            'status' => lawyer_tf('appointments.col_status', 'Status'),
            'calendar' => lawyer_tf('appointments.col_calendar', 'Calendar'),
            'actions' => lawyer_tf('common.actions', 'Actions'),
        ],
        'empty_message' => lawyer_tf('appointments.no_match_filters', 'No appointments match your filters.'),
        'pagination_aria' => lawyer_tf('appointments.pagination_aria', 'Appointments pagination'),
    ]
);

$calendarSectionHtml = $calendarStudioHtml;
$appointmentsListSectionWrapped = $appointmentsListSection;

$listFilterScript = legalpro_portal_list_filter_script(
    'lawyerAppointmentsSearchInput',
    'lawyerAppointmentsTableBody',
    'lawyerAppointmentsFilterEmpty',
    '.legalpro-admin-list-row',
    'lawyerAppointmentsStatusFilter'
);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <style>
        .lawyer-appointments-page {
            --la-primary: var(--legalpro-theme-primary, #0077b6);
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
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-appointments-page lp-schedule-hub-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}

        <div class="container-fluid py-4">
            {MESSAGE}

            {CALENDAR_SECTION}

            {APPOINTMENTS_LIST_SECTION}
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
                        <button type="submit" class="btn btn-primary mb-0" id="createAppointmentSubmit">Schedule</button>
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

    <!-- Appointment detail modal -->
    <div class="modal fade" id="lawyerAppointmentModal" tabindex="-1" aria-hidden="true" style="z-index:99999;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,var(--legalpro-theme-primary,#0077b6) 0%,#004e77 100%);">
                    <h6 class="modal-title text-white font-weight-bold" id="lawyerApptModalTitle">Appointment</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label>Client</label>
                            <p id="lawyerApptModalClient" class="mb-0"></p>
                        </div>
                        <div class="col-6">
                            <label>Status</label>
                            <p id="lawyerApptModalStatus" class="mb-0"></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Scheduled time</label>
                        <p id="lawyerApptModalTime" class="mb-0"></p>
                    </div>
                    <div class="mb-3">
                        <label>Notes</label>
                        <div id="lawyerApptModalNotes" class="appointment-modal-notes p-3 rounded"></div>
                    </div>
                    <button type="button" id="lawyerApptModalRescheduleBtn" class="btn btn-sm bg-gradient-primary appointment-modal-edit-btn w-100 mb-0" style="display:none;">Reschedule appointment</button>
                    <a id="lawyerApptModalCaseLink" href="#" class="btn btn-sm btn-outline-primary w-100 mb-0 mt-2" style="display:none;">View case</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    {FC_LOCALE_SCRIPT}
    <script>
        var apptI18n = {APPT_I18N_JSON};
        var lawyerAppointmentEvents = {APPOINTMENT_CALENDAR_EVENTS_JSON};
        var lawyerAppointmentsCalendar = null;

        function escapeHtmlLa(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function focusLawyerAppointmentRow(id) {
            var row = document.getElementById('apt-' + id);
            var wrap = document.querySelector('#lawyerAppointmentsTable [data-lp-admin-paginate]');
            if (wrap && window.LegalproAdminTablePagination && row) {
                window.LegalproAdminTablePagination.focusRow(wrap, row);
            } else if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function initLawyerAppointmentsCalendar() {
            if (typeof LegalproCalendarStudio === 'undefined') {
                return;
            }
            lawyerAppointmentsCalendar = LegalproCalendarStudio.mountScheduleHub({
                mode: 'appointment',
                calendarEl: '#lawyerAppointmentsCalendar',
                events: lawyerAppointmentEvents,
                displayLabels: {
                    scheduled: apptI18n.statusScheduled || 'Scheduled',
                    confirmed: apptI18n.statusConfirmed || 'Confirmed',
                    rescheduled: apptI18n.statusRescheduled || 'Rescheduled',
                    past: apptI18n.statusPast || 'Past',
                    completed: apptI18n.statusCompleted || 'Completed',
                    cancelled: apptI18n.statusCancelled || 'Cancelled'
                },
                agendaEmptyText: apptI18n.noAgenda || 'No appointments this month',
                agendaIdAttr: 'data-appointment-id',
                scheduleActionLabel: apptI18n.schedule || 'Schedule appointment',
                viewActionLabel: apptI18n.viewDetails || 'View appointment',
                onAgendaItemClick: function(id, match) {
                    if (match) {
                        openLawyerAppointmentModal(match);
                    } else {
                        openLawyerAppointmentModalById(parseInt(id, 10));
                    }
                    focusLawyerAppointmentRow(id);
                },
                onDateClick: function(ymd) {
                    openCreateAppointmentModal(ymd);
                },
                onEventClick: function(event) {
                    openLawyerAppointmentModal(event);
                    focusLawyerAppointmentRow(event.id);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            initLawyerAppointmentsCalendar();
        });

        function fmtLawyerApptDT(value) {
            if (!value) {
                return '—';
            }
            var d = value instanceof Date ? value : new Date(value);
            if (isNaN(d.getTime())) {
                return '—';
            }
            var dd = String(d.getDate()).padStart(2, '0');
            var mm = String(d.getMonth() + 1).padStart(2, '0');
            var yy = d.getFullYear();
            var h = String(d.getHours()).padStart(2, '0');
            var mi = String(d.getMinutes()).padStart(2, '0');
            return dd + '/' + mm + '/' + yy + ' at ' + h + ':' + mi;
        }

        function openLawyerAppointmentModal(event) {
            if (!event) {
                return;
            }
            var p = event.extendedProps || {};
            document.getElementById('lawyerApptModalTitle').textContent = event.title || 'Appointment';
            document.getElementById('lawyerApptModalClient').textContent = p.client || '—';
            document.getElementById('lawyerApptModalStatus').textContent = p.statusLabel || p.status || apptI18n.pending;
            document.getElementById('lawyerApptModalNotes').textContent = p.notes || 'No notes added.';

            var start = event.start instanceof Date ? event.start : new Date(event.start);
            var timeText = fmtLawyerApptDT(start);
            if (event.end) {
                var end = event.end instanceof Date ? event.end : new Date(event.end);
                timeText += ' — ' + fmtLawyerApptDT(end);
            }
            document.getElementById('lawyerApptModalTime').textContent = timeText;

            var rescheduleBtn = document.getElementById('lawyerApptModalRescheduleBtn');
            var caseLink = document.getElementById('lawyerApptModalCaseLink');
            if (p.canReschedule) {
                rescheduleBtn.style.display = '';
                rescheduleBtn.onclick = function() {
                    var detailModal = bootstrap.Modal.getInstance(document.getElementById('lawyerAppointmentModal'));
                    if (detailModal) {
                        detailModal.hide();
                    }
                    openRescheduleModal(
                        p.appointmentId || parseInt(event.id, 10),
                        p.rescheduleDate || '',
                        p.rescheduleTime || '',
                        p.rescheduleStatus || 'pending',
                        p.durationMinutes || 60
                    );
                };
            } else {
                rescheduleBtn.style.display = 'none';
                rescheduleBtn.onclick = null;
            }

            if (p.caseId) {
                caseLink.style.display = '';
                caseLink.href = 'lawyer-case-view.php?id=' + encodeURIComponent(p.caseId);
            } else {
                caseLink.style.display = 'none';
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('lawyerAppointmentModal')).show();
        }

        function openLawyerAppointmentModalById(id) {
            var match = lawyerAppointmentEvents.find(function(ev) {
                return String(ev.id) === String(id);
            });
            if (!match) {
                return;
            }
            openLawyerAppointmentModal({
                title: match.title,
                start: match.start,
                end: match.end,
                id: match.id,
                extendedProps: match.extendedProps || {}
            });
        }

        function removeLawyerDayEventPicker() {
            var existing = document.querySelector('.legalpro-cal-day-picker');
            if (existing) {
                existing.remove();
            }
        }

        function showLawyerDayEventPicker(events, clickEvent, onSelect) {
            removeLawyerDayEventPicker();
            if (!events || !events.length) {
                return;
            }
            if (events.length === 1) {
                onSelect(events[0]);
                return;
            }

            var picker = document.createElement('div');
            picker.className = 'legalpro-cal-day-picker';
            picker.setAttribute('role', 'menu');

            var html = '<div class="legalpro-cal-day-picker__head">Select an appointment</div><ul class="legalpro-cal-day-picker__list">';
            events.forEach(function(ev, index) {
                var time = '';
                if (ev.start) {
                    var start = ev.start instanceof Date ? ev.start : new Date(ev.start);
                    time = String(start.getHours()).padStart(2, '0') + ':' + String(start.getMinutes()).padStart(2, '0');
                }
                html += '<li><button type="button" class="legalpro-cal-day-picker__item" data-index="' + index + '">';
                html += '<span class="legalpro-cal-day-picker__time">' + escapeHtmlLa(time) + '</span>';
                html += '<span class="legalpro-cal-day-picker__title">' + escapeHtmlLa(ev.title || 'Appointment') + '</span>';
                html += '</button></li>';
            });
            html += '</ul>';
            picker.innerHTML = html;
            document.body.appendChild(picker);

            var rect = picker.getBoundingClientRect();
            var left = Math.min(clickEvent.clientX, window.innerWidth - rect.width - 12);
            var top = Math.min(clickEvent.clientY, window.innerHeight - rect.height - 12);
            picker.style.left = Math.max(12, left) + 'px';
            picker.style.top = Math.max(12, top) + 'px';

            picker.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-index]');
                if (!btn) {
                    return;
                }
                var idx = parseInt(btn.getAttribute('data-index'), 10);
                removeLawyerDayEventPicker();
                onSelect(events[idx]);
            });

            setTimeout(function() {
                function outsideClick(e) {
                    if (!picker.contains(e.target)) {
                        removeLawyerDayEventPicker();
                        document.removeEventListener('click', outsideClick);
                    }
                }
                document.addEventListener('click', outsideClick);
            }, 0);
        }
    </script>
    {LIST_FILTER_SCRIPT}
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="../assets/js/appointment-slot-window.js?v=2"></script>
    <script>
        const lawyerAvailabilityByDate = {LAWYER_AVAILABILITY_BY_DATE_JSON};
        const lawyerHasPublishedSchedule = {LAWYER_HAS_SCHEDULE_JSON};
        const NO_AVAILABILITY_ON_DATE_MSG = 'No available times on this date. Choose another date.';

        var rescheduleOriginalDate = '';
        var rescheduleOriginalTime = '';

        function openCreateAppointmentModal(prefillDate) {
            var form = document.getElementById('createAppointmentForm');
            if (form) {
                form.reset();
            }
            document.getElementById('create_duration_minutes').value = '60';
            var dateInput = document.getElementById('create_appointment_date');
            if (dateInput && prefillDate) {
                dateInput.value = prefillDate;
            }
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

            function getStandardSlotTimes(durationMinutes, dateValue) {
                return LegalproAppointmentSlots.getStandardSlotTimes(durationMinutes, {
                    slots: dateValue ? getSlotsForDate(dateValue) : [],
                    hasPublishedSchedule: lawyerHasPublishedSchedule
                });
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
                getStandardSlotTimes(durationMinutes, dateValue).forEach(function(slotValue) {
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
                getStandardSlotTimes(durationMinutes, dateValue).forEach(function(slotValue) {
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
    '{HTML_LANG}' => lawyer_portal_html_lang(),
    '{PAGE_TITLE}' => htmlspecialchars($pageTitle),
    '{BREADCRUMB_NAVBAR}' => $breadcrumbNavbar,
    '{LBL_CALENDAR_TITLE}' => htmlspecialchars(lawyer_tf('appointments.calendar_title', 'Appointments Calendar')),
    '{LBL_SEARCH_APPTS}' => htmlspecialchars(lawyer_tf('appointments.search_label', 'Search appointments')),
    '{LBL_CALENDAR_SUB}' => htmlspecialchars(lawyer_tf('appointments.calendar_sub', 'Use the search bar below to find appointments quickly, or click a calendar event')),
    '{LBL_STATUS_PENDING}' => htmlspecialchars(lawyer_appointment_status_label('pending')),
    '{LBL_STATUS_ACCEPTED}' => htmlspecialchars(lawyer_appointment_status_label('accepted')),
    '{LBL_STATUS_REJECTED}' => htmlspecialchars(lawyer_appointment_status_label('rejected')),
    '{LBL_SCHEDULE}' => htmlspecialchars(lawyer_tf('appointments.schedule', 'Schedule appointment')),
    '{PH_SEARCH_CAL}' => htmlspecialchars(lawyer_tf('appointments.search_placeholder_cal', 'Search by matter, client, date, status…')),
    '{LBL_RESET}' => htmlspecialchars(lawyer_tf('calendar.reset', 'Reset')),
    '{LBL_RESET_SEARCH}' => htmlspecialchars(lawyer_tf('calendar.reset_search', 'Reset search')),
    '{LBL_UPCOMING}' => htmlspecialchars(lawyer_tf('appointments.upcoming', 'Upcoming')),
    '{LBL_VIEW_LIST}' => htmlspecialchars(lawyer_tf('appointments.view_list', 'View list')),
    '{COL_MATTER}' => htmlspecialchars(lawyer_tf('appointments.col_matter', 'Matter')),
    '{COL_CLIENT}' => htmlspecialchars(lawyer_tf('appointments.col_client', 'Client')),
    '{COL_DATETIME}' => htmlspecialchars(lawyer_tf('appointments.col_datetime', 'Date & Time')),
    '{COL_NOTES}' => htmlspecialchars(lawyer_tf('appointments.col_notes', 'Notes')),
    '{COL_STATUS}' => htmlspecialchars(lawyer_tf('appointments.col_status', 'Status')),
    '{COL_CASE}' => htmlspecialchars(lawyer_tf('common.case', 'Case')),
    '{COL_ACTIONS}' => htmlspecialchars(lawyer_tf('common.actions', 'Actions')),
    '{APPT_I18N_JSON}' => $apptI18nJson,
    '{FC_LOCALE_SCRIPT}' => lawyer_portal_fc_locale_script(),
    '{CALENDAR_SECTION}' => $calendarSectionHtml,
    '{APPOINTMENTS_LIST_SECTION}' => $appointmentsListSectionWrapped,
    '{LIST_FILTER_SCRIPT}' => $listFilterScript,
    '{MESSAGE}' => $messageHtml,
    '{MIN_DATE}' => date('Y-m-d'),
    '{LAWYER_AVAILABILITY_BY_DATE_JSON}' => json_encode($rescheduleAvailabilityByDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
    '{LAWYER_HAS_SCHEDULE_JSON}' => $rescheduleHasSchedule ? 'true' : 'false',
    '{NAVIGATION}' => $navHtml,
    '{STATUS_TODAY}' => '',
    '{STATUS_PAST}' => '',
    '{APPOINTMENTS_TABLE}' => $appointmentsTable,
    '{APPOINTMENTS_COUNT_LABEL}' => $appointmentsCountLabel,
    '{UPCOMING_APPOINTMENTS_CALENDAR}' => $upcomingAppointmentsCalendarHtml,
    '{APPOINTMENT_CALENDAR_EVENTS_JSON}' => $appointmentCalendarEventsJson,
    '{ICON_CARD_HEADER}' => $iconCardHeader,
    '{LAWYER_CASE_OPTIONS}' => $lawyerCaseOptions,
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
?>
