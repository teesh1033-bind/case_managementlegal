<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/appointment_availability.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id   = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

// ── Status meta helper ────────────────────────────────────────────────────────
function clientAppointmentStatusMeta(string $status, int $startsAt, ?int $endsAt): array
{
    $now = time();
    if ($status === 'pending') {
        return ['key' => 'pending', 'label' => 'Pending', 'pill' => 'b-pending'];
    }
    if ($status === 'accepted') {
        if ($endsAt && $endsAt < $now) {
            return ['key' => 'completed', 'label' => 'Completed', 'pill' => 'b-done'];
        }
        if ($startsAt <= $now && (!$endsAt || $endsAt >= $now)) {
            return ['key' => 'in_progress', 'label' => 'In progress', 'pill' => 'b-inprogress'];
        }
        return ['key' => 'upcoming', 'label' => 'Upcoming', 'pill' => 'b-upcoming'];
    }
    if ($status === 'rejected') {
        return ['key' => 'rejected', 'label' => 'Rejected', 'pill' => 'b-declined'];
    }
    return ['key' => 'unknown', 'label' => ucfirst($status ?: 'Unknown'), 'pill' => 'b-muted'];
}

function clientAppointmentStatusBadge(array $meta): string
{
    return '<span class="ca-badge ' . htmlspecialchars($meta['pill']) . '">'
         . '<span class="ca-badge-dot"></span>'
         . htmlspecialchars($meta['label'])
         . '</span>';
}

// ── AJAX: appointment details ─────────────────────────────────────────────────
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
            SELECT a.*,
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
        $endsAt   = !empty($apt['ends_at']) ? strtotime($apt['ends_at']) : null;
        $meta     = clientAppointmentStatusMeta(strtolower((string) ($apt['status'] ?? '')), $startsAt, $endsAt);
        echo json_encode([
            'id'           => (int) $apt['id'],
            'case_title'   => $apt['case_title'] ?: 'Appointment',
            'lawyer_name'  => $apt['lawyer_name'] ?: 'TBD',
            'starts_at'    => date('M j, Y g:i A', $startsAt),
            'ends_at'      => $endsAt ? date('M j, Y g:i A', $endsAt) : null,
            'status'       => $apt['status'],
            'status_label' => $meta['label'],
            'status_pill'  => $meta['pill'],
            'notes'        => trim((string) ($apt['notes'] ?? '')),
            'requested_at' => !empty($apt['created_at']) ? date('M j, Y g:i A', strtotime($apt['created_at'])) : null,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not load appointment details']);
    }
    exit;
}

$message     = '';
$messageType = '';

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'book';

    if ($action === 'delete') {
        $appointment_id = (int) ($_POST['appointment_id'] ?? 0);
        if ($appointment_id) {
            try {
                $stmt = $pdo->prepare("SELECT id, status FROM appointments WHERE id = ? AND client_id = ?");
                $stmt->execute([$appointment_id, $client_id]);
                $appointment = $stmt->fetch();
                if ($appointment && $appointment['status'] === 'rejected') {
                    $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ? AND client_id = ? AND status = 'rejected'");
                    $stmt->execute([$appointment_id, $client_id]);
                    $message     = 'Rejected appointment removed.';
                    $messageType = 'success';
                } else {
                    $message     = 'Appointment not found or cannot be deleted.';
                    $messageType = 'danger';
                }
            } catch (PDOException $e) {
                $message     = 'Error deleting appointment.';
                $messageType = 'danger';
            }
        }
    } else {
        $case_id          = (int) ($_POST['case_id'] ?? 0);
        $lawyer_id        = (int) ($_POST['lawyer_id'] ?? 0);
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $appointment_time = trim($_POST['appointment_time'] ?? '');
        $notes            = trim($_POST['notes'] ?? '');

        if (!$lawyer_id || !$case_id || !$appointment_date || !$appointment_time) {
            $message     = 'Please fill in all required fields.';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM cases WHERE id = ? AND client_id = ?");
                $stmt->execute([$case_id, $client_id]);
                if (!$stmt->fetch()) {
                    $message = 'Invalid case selected.';
                    $messageType = 'danger';
                } else {
                    $availabilityResult = validateLawyerBookingAvailability($pdo, $lawyer_id, $appointment_date, $appointment_time);
                    if (!$availabilityResult['ok']) {
                        $message     = $availabilityResult['message'];
                        $messageType = 'danger';
                    } else {
                        $startDateTime = $appointment_date . ' ' . $appointment_time . ':00';
                        $endDateTime   = date('Y-m-d H:i:s', strtotime($startDateTime . ' +1 hour'));
                        $stmt = $pdo->prepare("INSERT INTO appointments (client_id, case_id, lawyer_id, starts_at, ends_at, notes, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                        $stmt->execute([$client_id, $case_id, $lawyer_id, $startDateTime, $endDateTime, $notes]);
                        $appointmentId = (int) $pdo->lastInsertId();
                        syncAppointmentAvailabilitySlot($pdo, [
                            'id'        => $appointmentId,
                            'lawyer_id' => $lawyer_id,
                            'starts_at' => $startDateTime,
                            'ends_at'   => $endDateTime,
                            'status'    => 'pending',
                        ]);
                        $message     = 'Appointment request submitted. Waiting for lawyer approval.';
                        $messageType = 'success';
                    }
                }
            } catch (PDOException $e) {
                $message     = 'Error booking appointment.';
                $messageType = 'danger';
            }
        }
    }
}

// ── Data loading ──────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id, title FROM cases WHERE client_id = ? ORDER BY title ASC");
    $stmt->execute([$client_id]);
    $clientCases = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT a.*, c.title AS case_title, CONCAT(l.first_name, ' ', l.last_name) AS lawyer_name
        FROM appointments a
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN lawyers l ON l.id = a.lawyer_id
        WHERE a.client_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$client_id]);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $message      = 'Error loading appointments.';
    $messageType  = 'danger';
    $clientCases  = [];
    $appointments = [];
}

$apptTotal   = count($appointments);
$apptPending = 0;
$apptUpcoming = 0;
foreach ($appointments as $_apt) {
    if (($_apt['status'] ?? '') === 'pending')  $apptPending++;
    if (($_apt['status'] ?? '') === 'accepted' && !empty($_apt['starts_at']) && strtotime($_apt['starts_at']) > time())  $apptUpcoming++;
}

require_once __DIR__ . '/../lib/client-portal-page-ui.php';

$heroHtml = client_portal_render_hero([
    'kicker' => 'Calendar',
    'title' => 'My appointments',
    'subtitle' => 'Track meetings with your legal team and request new sessions below.',
    'show_date' => true,
    'aria_label' => 'Appointments overview',
    'stats' => [
        ['num' => (string) $apptTotal, 'lbl' => 'Total'],
        ['num' => (string) $apptPending, 'lbl' => 'Pending'],
        ['num' => (string) $apptUpcoming, 'lbl' => 'Upcoming'],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => 'Dashboard', 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-cases.php', 'label' => 'My cases', 'icon' => 'briefcase'],
    ],
]);

// ── Available lawyers ─────────────────────────────────────────────────────────
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
    if (empty($availableLawyers)) {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM lawyers WHERE is_active = 1 ORDER BY first_name, last_name");
        $availableLawyers = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $availableLawyers = [];
}

// ── Availability maps ─────────────────────────────────────────────────────────
$lawyerAvailabilityByDate = [];
$lawyerAvailabilityByDay    = [];
$lawyerHasAvailability    = [];
$lawyerWorkingHours       = [];
$lawyerHasWorkingHours    = [];
try {
    $lawyerIds = array_map(fn($l) => (int) $l['id'], $availableLawyers);
    $allActiveLawyerIds = $pdo->query('SELECT id FROM lawyers WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
    $lawyerIds = array_values(array_unique(array_merge(
        $lawyerIds,
        array_map('intval', $allActiveLawyerIds ?: [])
    )));
    $maps = loadLawyerAvailabilityForBooking($pdo, $lawyerIds);
    $lawyerAvailabilityByDate = $maps['byDate'];
    $lawyerAvailabilityByDay  = $maps['byDay'];
    $lawyerHasAvailability    = $maps['hasSchedule'];
    $lawyerWorkingHours       = $maps['workingHours'] ?? [];
    $lawyerHasWorkingHours    = $maps['hasWorkingHours'] ?? [];
} catch (PDOException $e) {}

// ── Build selects ─────────────────────────────────────────────────────────────
$caseOptions = '<option value="">Select a case</option>';
foreach ($clientCases as $c) {
    $caseOptions .= '<option value="' . $c['id'] . '">' . htmlspecialchars($c['title']) . '</option>';
}

$lawyerOptions = '<option value="">Select a lawyer</option>';
foreach ($availableLawyers as $l) {
    $lawyerOptions .= '<option value="' . $l['id'] . '">' . htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) . '</option>';
}

// ── Build appointment rows ────────────────────────────────────────────────────
$appointmentsRows = '';
if (empty($appointments)) {
    $appointmentsRows = '<tr><td colspan="5">
        <div class="ca-empty">
            <div class="ca-empty-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <path d="M16 2v4M8 2v4M3 10h18"/>
                </svg>
            </div>
            <h5>No appointments yet</h5>
            <p>Use the booking panel to request a time with your counsel.</p>
        </div>
    </td></tr>';
} else {
    foreach ($appointments as $apt) {
        $aid         = (int) $apt['id'];
        $caseTitle   = $apt['case_title'] ?: 'Appointment';
        $lawyerName  = $apt['lawyer_name'] ?: 'TBD';
        $displayDate = date('M j, Y', strtotime($apt['starts_at']));
        $displayTime = date('g:i A', strtotime($apt['starts_at']));
        $meta        = clientAppointmentStatusMeta(
            strtolower((string) ($apt['status'] ?? '')),
            strtotime($apt['starts_at']),
            !empty($apt['ends_at']) ? strtotime($apt['ends_at']) : null
        );
        $badge       = clientAppointmentStatusBadge($meta);
        $notesRaw    = trim((string) ($apt['notes'] ?? ''));
        $notesDisp   = $notesRaw === '' ? '—' : (strlen($notesRaw) > 52 ? htmlspecialchars(substr($notesRaw, 0, 52)) . '…' : htmlspecialchars($notesRaw));
        $isRejected  = ($meta['key'] === 'rejected');

        $deleteBtn = '';
        if ($isRejected) {
            $deleteBtn = '<form method="POST" style="display:inline" onsubmit="return confirm(\'Remove this rejected appointment?\')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="appointment_id" value="' . $aid . '">
                <button type="submit" class="btn-del cdoc-touch-btn">Delete</button>
            </form>';
        }

        $searchHay = strtolower(implode(' ', [
            $caseTitle,
            $lawyerName,
            $displayDate,
            $displayTime,
            $meta['label'],
            $notesRaw,
            'appointment',
            (string) $aid,
        ]));

        $appointmentsRows .= '<tr class="ca-row" data-search="' . htmlspecialchars($searchHay, ENT_QUOTES, 'UTF-8') . '">
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <div class="ca-apt-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <path d="M16 2v4M8 2v4M3 10h18"/>
                        </svg>
                    </div>
                    <div>
                        <p class="apt-title">' . htmlspecialchars($caseTitle) . '</p>
                        <p class="apt-date">' . htmlspecialchars($displayDate) . ' · ' . htmlspecialchars($displayTime) . '</p>
                    </div>
                </div>
            </td>
            <td><span class="lawyer-name">' . htmlspecialchars($lawyerName) . '</span></td>
            <td style="text-align:center">' . $badge . '</td>
            <td>
                <p class="apt-notes" title="' . htmlspecialchars($notesRaw) . '">' . $notesDisp . '</p>
            </td>
            <td>
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap">
                    <button type="button" class="btn-det cdoc-touch-btn" onclick="viewAppointmentDetails(' . $aid . ')">Details</button>
                    ' . $deleteBtn . '
                </div>
            </td>
        </tr>';
    }
}

// ── Calendar events (FullCalendar) ─────────────────────────────────────────
$appointmentCalendarEvents = [];
foreach ($appointments as $row) {
    if (empty($row['starts_at'])) {
        continue;
    }

    $status = strtolower((string) ($row['status'] ?? 'pending'));
    $caseTitle = $row['case_title'] ?: 'Appointment';
    $lawyerName = trim((string) ($row['lawyer_name'] ?? ''));
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
            'lawyer' => $lawyerName !== '' ? $lawyerName : 'TBD',
            'notes' => $notes,
            'status' => $status,
            'statusLabel' => ucfirst($status),
            'appointmentId' => (int) $row['id'],
            'startsLabel' => $startsLabel,
            'searchHay' => strtolower(implode(' ', array_filter([
                $caseTitle,
                $lawyerName,
                $status,
                $notes,
                $startsLabel,
                date('Y-m-d', strtotime($row['starts_at'])),
                date('m/d/Y', strtotime($row['starts_at'])),
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
        . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
        . '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>'
        . '</svg><span>No upcoming appointments</span></div>';
} else {
    usort($upcomingForCalendar, function ($a, $b) {
        return strtotime($a['starts_at']) <=> strtotime($b['starts_at']);
    });
    foreach (array_slice($upcomingForCalendar, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'pending'));
        $caseTitle = $row['case_title'] ?: 'Appointment';
        $lawyerName = trim((string) ($row['lawyer_name'] ?? ''));
        $lawyerName = $lawyerName !== '' ? $lawyerName : 'TBD';
        $hourLabel = date('g:i A', strtotime($row['starts_at']));
        $dayLabel = date('M j', strtotime($row['starts_at']));

        $upcomingAppointmentsCalendarHtml .= '
        <button type="button" class="dashboard-upcoming-item dashboard-upcoming-item--' . htmlspecialchars($status) . '" data-appointment-id="' . (int) $row['id'] . '">
            <span class="dashboard-upcoming-item__time">' . htmlspecialchars($hourLabel) . '<br><small style="font-weight:500;opacity:.8">' . htmlspecialchars($dayLabel) . '</small></span>
            <span class="flex-grow-1">
                <p class="dashboard-upcoming-item__title">' . htmlspecialchars($caseTitle) . '</p>
                <p class="dashboard-upcoming-item__sub">' . htmlspecialchars($lawyerName) . '</p>
            </span>
        </button>';
    }
}

// ── Message HTML ──────────────────────────────────────────────────────────────
$messageHtml = '';
if ($message) {
    $alertStyle = $messageType === 'success'
        ? 'background:#dcfce7;color:#166534;border:1px solid #86efac'
        : ($messageType === 'danger'
            ? 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5'
            : 'background:#fef9c3;color:#854d0e;border:1px solid #fde047');
    $messageHtml = '<div style="' . $alertStyle . ';border-radius:10px;padding:.75rem 1rem;font-size:13px;margin-bottom:1rem">'
                 . htmlspecialchars($message) . '</div>';
}

require_once __DIR__ . '/../inc/client-portal-navbar.php';
$clientPageNavbar = legalpro_render_client_page_navbar(
    'Appointments',
    'Appointments',
    'Search appointments…',
    legalpro_client_page_search_options('client-appointments.php')
);

ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro – My Appointments</title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <link href="../assets/css/client-portal-pages.css?v=1" rel="stylesheet" />
    <?php
    require_once __DIR__ . '/../inc/availability-date-picker.php';
    legalpro_render_availability_date_picker_assets();
    ?>

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body.client-appointments-page {
            background: #f0f2f8;
            --ca-primary: var(--legalpro-theme-primary, #5e72e4);
            --ca-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
            --ca-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
            --ca-primary-border: var(--lp-cases-accent-border, rgba(94, 114, 228, 0.35));
            --ca-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
            --ca-field-bg: #fff;
            --ca-field-color: #1e293b;
            --ca-field-border: #e2e8f0;
            --ca-field-disabled-bg: #f8fafc;
            --ca-field-muted: #94a3b8;
            --ca-time-available-color: #047857;
        }
        body.legalpro-dark-mode.client-appointments-page {
            --ca-field-bg: var(--lp-dark-input-bg, #2f3547);
            --ca-field-color: var(--lp-dark-text, #f8f9fc);
            --ca-field-border: var(--lp-dark-border-strong, rgba(255, 255, 255, 0.16));
            --ca-field-disabled-bg: #2a3040;
            --ca-field-muted: var(--lp-dark-text-subtle, #9aa8bc);
            --ca-time-available-color: #6ee7b7;
        }

        /* ── Layout ─────────────────────────────────────────────────── */
        .ca-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 1.25rem;
            align-items: start;
        }
        @media (max-width: 900px) { .ca-layout { grid-template-columns: 1fr; } }

        /* ── Table card ─────────────────────────────────────────────── */
        .ca-panel {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e9ecf3;
            overflow: hidden;
        }
        .ca-panel-hdr {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
        }
        .ca-panel-hdr h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
        .ca-panel-hdr p  { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }
        .ca-count {
            background: var(--ca-primary-soft); color: var(--ca-primary);
            font-size: 11px; font-weight: 700;
            padding: .2rem .65rem; border-radius: 99px;
        }
        .ca-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ca-table thead th {
            background: #f8fafc; color: #94a3b8;
            font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            padding: .7rem 1rem; border-bottom: 1px solid #f1f5f9; white-space: nowrap;
        }
        .ca-table thead th:first-child { padding-left: 1.5rem; }
        .ca-table thead th:last-child  { padding-right: 1.5rem; text-align: right; }
        .ca-table tbody tr { border-bottom: 1px solid #f8fafc; transition: background .1s; }
        .ca-table tbody tr:hover { background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04); }
        .ca-table tbody tr:last-child { border-bottom: none; }
        .ca-table tbody td { padding: .8rem 1rem; vertical-align: middle; }
        .ca-table tbody td:first-child { padding-left: 1.5rem; }
        .ca-table tbody td:last-child  { padding-right: 1.5rem; }

        /* ── Appointment cell ───────────────────────────────────────── */
        .ca-apt-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--ca-primary-soft);
            color: var(--ca-primary);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .apt-title {
            font-size: 13px; font-weight: 600; color: #1e293b;
            max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            margin: 0 0 1px;
        }
        .apt-date  { font-size: 11px; color: #94a3b8; margin: 0; }
        .lawyer-name { font-size: 12px; font-weight: 500; color: #334155; }
        .apt-notes { font-size: 12px; color: #94a3b8; max-width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin: 0; }

        /* ── Badges ─────────────────────────────────────────────────── */
        .ca-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: .22rem .65rem; border-radius: 99px;
            font-size: 11px; font-weight: 600;
        }
        .ca-badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .ca-badge.b-upcoming   { background: #dcfce7; color: #166534; }
        .ca-badge.b-upcoming   .ca-badge-dot { background: #16a34a; }
        .ca-badge.b-pending    { background: #fef9c3; color: #854d0e; }
        .ca-badge.b-pending    .ca-badge-dot { background: #ca8a04; }
        .ca-badge.b-done       { background: #f1f5f9; color: #475569; }
        .ca-badge.b-done       .ca-badge-dot { background: #94a3b8; }
        .ca-badge.b-declined   { background: #fee2e2; color: #991b1b; }
        .ca-badge.b-declined   .ca-badge-dot { background: #dc2626; }
        .ca-badge.b-inprogress { background: var(--ca-primary-soft); color: var(--ca-primary); }
        .ca-badge.b-inprogress .ca-badge-dot { background: var(--ca-primary); }
        .ca-badge.b-muted      { background: #f1f5f9; color: #64748b; }
        .ca-badge.b-muted      .ca-badge-dot { background: #94a3b8; }
        body.legalpro-dark-mode .ca-badge.b-upcoming {
            background: rgba(45, 206, 137, 0.18) !important;
            color: #b8f5d8 !important;
        }
        body.legalpro-dark-mode .ca-badge.b-upcoming .ca-badge-dot { background: #2dce89 !important; }
        body.legalpro-dark-mode .ca-badge.b-pending {
            background: rgba(251, 140, 64, 0.18) !important;
            color: #ffe0b8 !important;
        }
        body.legalpro-dark-mode .ca-badge.b-pending .ca-badge-dot { background: #fb8c40 !important; }
        body.legalpro-dark-mode .ca-badge.b-done,
        body.legalpro-dark-mode .ca-badge.b-muted {
            background: rgba(148, 163, 184, 0.2) !important;
            color: #e2e8f2 !important;
        }
        body.legalpro-dark-mode .ca-badge.b-done .ca-badge-dot,
        body.legalpro-dark-mode .ca-badge.b-muted .ca-badge-dot { background: #94a3b8 !important; }
        body.legalpro-dark-mode .ca-badge.b-declined {
            background: rgba(245, 54, 92, 0.22) !important;
            color: #ffc9d4 !important;
        }
        body.legalpro-dark-mode .ca-badge.b-declined .ca-badge-dot { background: #f5365c !important; }
        body.legalpro-dark-mode .ca-badge.b-inprogress {
            background: rgba(94, 114, 228, 0.2) !important;
            color: #d4dcff !important;
        }
        body.legalpro-dark-mode .ca-badge.b-inprogress .ca-badge-dot { background: var(--ca-primary, #5e72e4) !important; }

        /* ── Action buttons ─────────────────────────────────────────── */
        .btn-det {
            padding: .3rem .8rem; border-radius: 8px;
            border: 1.5px solid var(--ca-primary); color: var(--ca-primary);
            font-size: 12px; font-weight: 600; background: none; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .btn-det:hover { background: var(--ca-primary); color: #fff; }
        .btn-del {
            padding: .3rem .8rem; border-radius: 8px;
            border: 1.5px solid #dc2626; color: #fff;
            font-size: 12px; font-weight: 600; background: #dc2626; cursor: pointer;
            transition: background .15s, color .15s, border-color .15s;
        }
        .btn-del:hover { background: #b91c1c; color: #fff; border-color: #b91c1c; }

        /* ── Empty state ────────────────────────────────────────────── */
        .ca-empty { padding: 3.5rem 1.5rem; text-align: center; }
        .ca-empty-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: var(--ca-primary-soft);
            color: var(--ca-primary);
            display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;
        }
        .ca-empty h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: .35rem; }
        .ca-empty p  { font-size: 13px; color: #94a3b8; max-width: 22rem; margin: 0 auto; }

        /* ── Booking panel ──────────────────────────────────────────── */
        .ca-book-card {
            background: #fff; border-radius: 16px;
            border: 1px solid #e9ecf3; overflow: visible;
        }
        .ca-book-body { overflow: visible; }
        .ca-book-hdr {
            padding: 1.1rem 1.5rem; border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.06) 0%, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.03) 100%);
        }
        .ca-book-hdr h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
        .ca-book-hdr p  { font-size: 12px; color: #94a3b8; margin: 3px 0 0; }
        .ca-book-body   { padding: 1.25rem 1.5rem; }

        .ca-fld         { margin-bottom: 1rem; }
        .ca-fld label   {
            display: block; font-size: 10.5px; font-weight: 700;
            color: #64748b; letter-spacing: .08em; text-transform: uppercase;
            margin-bottom: .35rem;
        }
        .ca-fld select,
        .ca-fld input[type="date"],
        .ca-fld .ca-date-picker-wrap .flatpickr-input,
        .ca-fld textarea {
            width: 100%; padding: .55rem .75rem;
            border: 1px solid var(--ca-field-border); border-radius: 10px;
            font-size: 13px; color: var(--ca-field-color); background: var(--ca-field-bg);
            outline: none; font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        .ca-fld .ca-date-picker-wrap .flatpickr-input {
            cursor: pointer;
        }
        .ca-fld select:focus,
        .ca-fld input[type="date"]:focus,
        .ca-fld .ca-date-picker-wrap .flatpickr-input:focus,
        .ca-fld textarea:focus {
            border-color: var(--ca-primary);
            box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .ca-fld .ca-date-picker-wrap {
            width: 100%;
            position: relative;
        }
        .client-appointments-page .ca-date-picker-wrap .flatpickr-wrapper {
            width: 100%;
        }
        .client-appointments-page .flatpickr-calendar.legalpro-calendar-below.open {
            position: fixed !important;
            margin: 0 !important;
            transform: none !important;
        }

        /* Booking calendar — compact panel */
        .client-appointments-page .flatpickr-calendar {
            width: 268px !important;
            max-width: calc(100vw - 24px);
            background: var(--ca-field-bg);
            border: 1px solid var(--ca-field-border);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.14);
            font-family: inherit;
            font-size: 13px;
            line-height: 1.2;
            padding: 2px 0 6px;
        }
        .client-appointments-page .flatpickr-months {
            background: var(--ca-field-bg);
            padding: 4px 6px 0;
        }
        .client-appointments-page .flatpickr-months .flatpickr-month {
            height: 30px;
        }
        .client-appointments-page .flatpickr-months .flatpickr-prev-month,
        .client-appointments-page .flatpickr-months .flatpickr-next-month {
            height: 30px;
            padding: 6px 8px;
            top: 2px;
            color: var(--ca-field-color);
            fill: var(--ca-field-color);
        }
        .client-appointments-page .flatpickr-months .flatpickr-prev-month svg,
        .client-appointments-page .flatpickr-months .flatpickr-next-month svg {
            width: 12px;
            height: 12px;
        }
        .client-appointments-page .flatpickr-current-month {
            font-size: 14px;
            font-weight: 600;
            height: 30px;
            padding-top: 4px;
            left: 0;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .client-appointments-page .flatpickr-current-month .flatpickr-monthDropdown-months {
            appearance: auto;
            -webkit-appearance: auto;
            -moz-appearance: auto;
            background: var(--ca-field-bg);
            color: var(--ca-field-color) !important;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            line-height: 1.3;
            margin: 0;
            padding: 2px 20px 2px 6px;
            border: 1px solid var(--ca-field-border);
            border-radius: 6px;
            cursor: pointer;
            max-width: 118px;
        }
        .client-appointments-page .flatpickr-current-month .flatpickr-monthDropdown-months:hover {
            border-color: var(--ca-primary);
            background: var(--ca-field-bg);
        }
        .client-appointments-page .flatpickr-current-month .flatpickr-monthDropdown-months option {
            background: #fff;
            color: #1e293b;
            font-size: 14px;
            font-weight: 500;
            padding: 6px 10px;
        }
        .client-appointments-page .flatpickr-current-month input.cur-year {
            color: var(--ca-field-color) !important;
            background: var(--ca-field-bg);
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            padding: 2px 4px;
            border: 1px solid var(--ca-field-border);
            border-radius: 6px;
            width: 4.5em !important;
        }
        .client-appointments-page .flatpickr-current-month .numInputWrapper {
            width: 4.8em;
        }
        .client-appointments-page .flatpickr-weekdays {
            background: var(--ca-field-bg);
            height: 22px;
            margin-top: 2px;
        }
        .client-appointments-page span.flatpickr-weekday {
            color: var(--ca-field-muted);
            font-size: 11px;
            font-weight: 600;
        }
        .client-appointments-page .flatpickr-days,
        .client-appointments-page .dayContainer {
            width: 268px !important;
            min-width: 268px !important;
            max-width: 268px !important;
        }
        .client-appointments-page .flatpickr-day {
            color: var(--ca-field-color);
            max-width: 34px;
            height: 34px;
            line-height: 34px;
            font-size: 12.5px;
        }
        .client-appointments-page .flatpickr-day.prevMonthDay,
        .client-appointments-page .flatpickr-day.nextMonthDay {
            color: var(--ca-field-muted);
            opacity: 0.38;
            background: transparent !important;
        }
        .client-appointments-page .flatpickr-day.today {
            border-color: var(--ca-primary);
        }
        .client-appointments-page .flatpickr-day.selected,
        .client-appointments-page .flatpickr-day.selected:hover {
            background: var(--ca-primary);
            border-color: var(--ca-primary);
            color: #fff;
            opacity: 1;
        }
        .client-appointments-page .flatpickr-day:not(.flatpickr-disabled):not(.selected):not(.prevMonthDay):not(.nextMonthDay):hover {
            background: var(--ca-primary-soft);
            border-color: transparent;
        }
        /* Unavailable / past — faded like native disabled days */
        .client-appointments-page .flatpickr-day.flatpickr-disabled,
        .client-appointments-page .flatpickr-day.legalpro-day-unavailable,
        .client-appointments-page .flatpickr-day.flatpickr-disabled.legalpro-day-unavailable {
            text-decoration: none !important;
            color: var(--ca-field-muted) !important;
            background: transparent !important;
            border-color: transparent !important;
            box-shadow: none !important;
            opacity: 0.38;
            cursor: default;
            pointer-events: none;
        }
        .client-appointments-page .flatpickr-day.flatpickr-disabled:hover,
        .client-appointments-page .flatpickr-day.legalpro-day-unavailable:hover,
        .client-appointments-page .flatpickr-day.flatpickr-disabled:focus,
        .client-appointments-page .flatpickr-day.legalpro-day-unavailable:focus {
            background: transparent !important;
            border-color: transparent !important;
            color: var(--ca-field-muted) !important;
            opacity: 0.38;
        }
        body.legalpro-dark-mode.client-appointments-page .flatpickr-calendar.legalpro-flatpickr-dark,
        body.legalpro-dark-mode.client-appointments-page .flatpickr-calendar {
            background: var(--ca-field-bg);
            border-color: var(--ca-field-border);
        }
        body.legalpro-dark-mode.client-appointments-page .flatpickr-current-month .flatpickr-monthDropdown-months,
        body.legalpro-dark-mode.client-appointments-page .flatpickr-current-month input.cur-year {
            color: #f1f5f9 !important;
            background: #1e293b;
            border-color: rgba(255, 255, 255, 0.14);
        }
        body.legalpro-dark-mode.client-appointments-page .flatpickr-current-month .flatpickr-monthDropdown-months option {
            background: #1e293b;
            color: #f1f5f9;
        }
        body.legalpro-dark-mode.client-appointments-page .flatpickr-months .flatpickr-prev-month,
        body.legalpro-dark-mode.client-appointments-page .flatpickr-months .flatpickr-next-month {
            color: #e2e8f0;
            fill: #e2e8f0;
        }
        body.legalpro-dark-mode.client-appointments-page .flatpickr-day:not(.flatpickr-disabled):not(.prevMonthDay):not(.nextMonthDay):not(.selected) {
            color: #f1f5f9;
        }
        body.legalpro-dark-mode.client-appointments-page .flatpickr-day.flatpickr-disabled,
        body.legalpro-dark-mode.client-appointments-page .flatpickr-day.legalpro-day-unavailable,
        body.legalpro-dark-mode.client-appointments-page .flatpickr-day.prevMonthDay,
        body.legalpro-dark-mode.client-appointments-page .flatpickr-day.nextMonthDay {
            color: #64748b !important;
            opacity: 0.45;
        }
        .ca-fld textarea { resize: vertical; min-height: 72px; }

        .ca-fld select:disabled {
            background: var(--ca-field-disabled-bg);
            color: var(--ca-field-muted);
            cursor: not-allowed;
        }

        /* ── Time dropdown ──────────────────────────────────────────── */
        .ca-time-dd { position: relative; }
        .ca-time-dd-trigger {
            width: 100%; padding: .55rem .75rem;
            border: 1px solid var(--ca-field-border); border-radius: 10px;
            font-size: 13px; color: var(--ca-field-color); background: var(--ca-field-bg);
            outline: none; font-family: inherit; text-align: left;
            display: flex; align-items: center; justify-content: space-between; gap: .5rem;
            transition: border-color .15s, box-shadow .15s, background .15s, color .15s;
            cursor: pointer;
        }
        .ca-time-dd-trigger:disabled {
            background: var(--ca-field-disabled-bg);
            color: var(--ca-field-muted);
            cursor: not-allowed;
        }
        .ca-time-dd-trigger.has-value {
            color: var(--ca-time-available-color);
            font-weight: 600;
        }
        .ca-time-dd-trigger.open,
        .ca-time-dd-trigger:focus:not(:disabled) {
            border-color: var(--ca-primary);
            box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .ca-time-dd-chevron {
            width: 14px; height: 14px; flex-shrink: 0;
            color: var(--ca-field-muted); transition: transform .15s;
        }
        .ca-time-dd-trigger.open .ca-time-dd-chevron { transform: rotate(180deg); }
        .ca-time-dd-menu {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 20;
            background: var(--ca-field-bg);
            border: 1px solid var(--ca-field-border); border-radius: 10px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            max-height: 220px; overflow-y: auto; padding: 4px; margin: 0; list-style: none;
            display: none;
        }
        body.legalpro-dark-mode.client-appointments-page .ca-time-dd-menu {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }
        .ca-time-dd-menu.open { display: block; }
        .ca-time-dd-opt {
            padding: .5rem .65rem; border-radius: 8px;
            font-size: 13px; font-weight: 600; color: var(--ca-field-muted);
            cursor: not-allowed; user-select: none; opacity: .55;
        }
        .ca-time-dd-opt.bookable {
            color: var(--ca-time-available-color);
            background: transparent;
            cursor: pointer;
            opacity: 1;
            font-weight: 600;
        }
        .ca-time-dd-opt.bookable:hover {
            color: var(--ca-time-available-color);
            background: rgba(5, 150, 105, 0.12);
        }
        .ca-time-dd-opt.selected,
        .ca-time-dd-opt.selected:hover {
            color: var(--ca-time-available-color);
            background: rgba(5, 150, 105, 0.18);
            font-weight: 700;
            opacity: 1;
        }

        .ca-avail-hint {
            font-size: 11px; color: #64748b; margin-top: .5rem;
        }
        .ca-avail-hint-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #6ee7b7; flex-shrink: 0;
        }

        /* ── Availability alert ─────────────────────────────────────── */
        .ca-avail-alert {
            border-radius: 8px; padding: .55rem .8rem;
            font-size: 12px; margin-top: .5rem; display: none;
        }
        .ca-avail-alert.warning { background: #fef9c3; color: #854d0e; }
        .ca-avail-alert.info    { background: var(--ca-primary-soft); color: var(--ca-primary); }

        /* ── Book button ────────────────────────────────────────────── */
        .ca-book-btn {
            width: 100%; padding: .65rem 1rem;
            border-radius: 10px;
            background: var(--ca-gradient);
            color: #fff; font-size: 13px; font-weight: 700;
            border: none; cursor: pointer;
            transition: opacity .15s, transform .1s;
        }
        .ca-book-btn:hover  { opacity: .9; }
        .ca-book-btn:active { transform: scale(.98); }
        .ca-book-btn:disabled { opacity: .5; cursor: not-allowed; }

        /* ── Modal ──────────────────────────────────────────────────── */
        .ca-modal .modal-content {
            border-radius: 16px; border: 1px solid #e9ecf3;
            box-shadow: 0 20px 60px rgba(0,0,0,.12);
        }
        .ca-modal .modal-header { border-bottom: 1px solid #f1f5f9; }
        .ca-modal .modal-title  { font-size: 15px; font-weight: 700; color: #1e293b; }
        .ca-detail-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .ca-modal .ca-apt-detail-label,
        .ca-detail-field p.lbl {
            font-size: 10.5px; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: #8392ab; margin: 0 0 3px;
        }
        .ca-modal .ca-apt-detail-matter {
            font-size: 15px; font-weight: 700; color: #344767; margin: 0;
        }
        .ca-modal .ca-apt-detail-value,
        .ca-detail-field p.val {
            font-size: 13px; color: #344767; font-weight: 600; margin: 0;
        }
        .ca-modal .ca-apt-detail-value--empty { color: #8392ab; font-weight: 500; }
        body.legalpro-dark-mode .ca-modal .modal-title,
        body.legalpro-dark-mode .ca-modal .ca-apt-detail-matter,
        body.legalpro-dark-mode .ca-modal .ca-apt-detail-value,
        body.legalpro-dark-mode .ca-modal .ca-detail-field p.val {
            color: var(--lp-dark-text, #f8f9fc) !important;
        }
        body.legalpro-dark-mode .ca-modal .ca-apt-detail-label,
        body.legalpro-dark-mode .ca-modal .ca-detail-field p.lbl,
        body.legalpro-dark-mode .ca-modal .ca-apt-detail-value--empty {
            color: var(--lp-dark-text-muted, #a8b5cc) !important;
        }

        /* ── Appointments calendar (same hub as admin) ───────────────── */
        .client-appointments-page .ca-calendar-hub {
            margin-bottom: 1.5rem;
        }
        .client-appointments-page .ca-calendar-hub .dashboard-calendar-hub__title {
            color: var(--ca-primary);
            font-weight: 800;
        }
        .client-appointments-page .dashboard-calendar-hub__head {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .client-appointments-page .ca-cal-search-wrap {
            position: relative;
            width: 100%;
            flex-shrink: 0;
        }
        .client-appointments-page .ca-cal-search-wrap--featured {
            padding: .9rem 1rem 1rem;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12) 0%, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04) 100%);
            border: 1px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            box-shadow: 0 6px 22px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .client-appointments-page .ca-cal-search-label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--ca-primary);
            margin-bottom: .55rem;
        }
        .client-appointments-page .ca-cal-search-field {
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
        .client-appointments-page .ca-cal-search-field:focus-within {
            border-color: var(--ca-primary);
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.18), 0 4px 16px rgba(15, 23, 42, 0.1);
            transform: translateY(-1px);
        }
        .client-appointments-page .ca-cal-search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
            color: var(--ca-primary);
            flex-shrink: 0;
        }
        .client-appointments-page .ca-cal-search-field svg {
            width: 18px;
            height: 18px;
            color: currentColor;
            flex-shrink: 0;
        }
        .client-appointments-page .ca-cal-search-input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            font-family: inherit;
        }
        .client-appointments-page .ca-cal-search-input::placeholder {
            color: #64748b;
            font-weight: 500;
        }
        .client-appointments-page .ca-cal-search-results {
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
        .client-appointments-page .ca-cal-search-item {
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
            transition: background .12s;
        }
        .client-appointments-page .ca-cal-search-item:hover,
        .client-appointments-page .ca-cal-search-item:focus-visible {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
            outline: none;
        }
        .client-appointments-page .ca-cal-search-item__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: .45rem;
            flex-shrink: 0;
        }
        .client-appointments-page .ca-cal-search-item__dot--pending { background: #fb6340; }
        .client-appointments-page .ca-cal-search-item__dot--accepted { background: #2dce89; }
        .client-appointments-page .ca-cal-search-item__dot--rejected { background: #f5365c; }
        .client-appointments-page .ca-cal-search-item__body { min-width: 0; flex: 1; }
        .client-appointments-page .ca-cal-search-item__title {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .client-appointments-page .ca-cal-search-item__sub {
            font-size: 11.5px;
            color: #64748b;
            margin: 0;
        }
        .client-appointments-page .ca-cal-search-empty {
            padding: 1rem .75rem;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-wrap--featured {
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.2) 0%, rgba(61, 69, 92, 0.55) 100%);
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-label {
            color: #b8c4ff;
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-icon {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            color: #d4dcff;
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-field,
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-results {
            background: var(--ca-field-bg);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35);
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-field:focus-within {
            border-color: #9aaeff;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.22);
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-input {
            color: var(--ca-field-color);
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-input::placeholder {
            color: #94a3b8;
        }
        body.legalpro-dark-mode.client-appointments-page .ca-cal-search-item__title {
            color: var(--ca-field-color);
        }
        body.client-appointments-page .navbar-main .legalpro-navbar-search {
            min-width: min(100%, 340px);
        }
        body.client-appointments-page .navbar-main .legalpro-navbar-search .input-group {
            border: 2px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.3) !important;
            background: rgba(255, 255, 255, 0.96) !important;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.1);
            border-radius: 12px !important;
        }
        body.client-appointments-page .navbar-main .legalpro-navbar-search .input-group:focus-within {
            border-color: var(--ca-primary) !important;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.16), 0 4px 16px rgba(15, 23, 42, 0.1) !important;
        }
        body.client-appointments-page .navbar-main .legalpro-navbar-search .form-control,
        body.client-appointments-page .navbar-main .legalpro-navbar-search input[type="search"].form-control {
            font-size: 14px !important;
            font-weight: 600 !important;
        }
        body.client-appointments-page .navbar-main .legalpro-navbar-search .input-group-text {
            color: var(--ca-primary) !important;
        }
        body.legalpro-dark-mode.client-appointments-page .navbar-main .legalpro-navbar-search .input-group {
            background: var(--lp-dark-surface-raised, #3d455c) !important;
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35) !important;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-appointments-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>

    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?= $clientPageNavbar ?>

        <div class="container-fluid py-4">
            <div class="cp-page">

            <?= $messageHtml ?>

            <?= $heroHtml ?>

            <!-- Calendar hub (same as admin appointments) ----------------->
            <div class="dashboard-calendar-hub ca-calendar-hub">
                <div class="dashboard-calendar-hub__head">
                    <div class="ca-calendar-hub__intro">
                        <h6 class="text-capitalize mb-0 font-weight-bold dashboard-calendar-hub__title">Appointments Calendar</h6>
                        <p class="text-sm mb-0 text-muted">Use the search bar below to find appointments quickly, or click a calendar event</p>
                        <div class="dashboard-legend-pills">
                            <span class="dashboard-legend-pill dashboard-legend-pill--pending"><i></i> Pending</span>
                            <span class="dashboard-legend-pill dashboard-legend-pill--accepted"><i></i> Accepted</span>
                            <span class="dashboard-legend-pill dashboard-legend-pill--rejected"><i></i> Rejected</span>
                        </div>
                    </div>
                    <div class="ca-cal-search-wrap ca-cal-search-wrap--featured">
                        <label class="ca-cal-search-label" for="caCalSearchInput">Search appointments</label>
                        <div class="ca-cal-search-field">
                            <span class="ca-cal-search-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="M20 20l-3-3"></path>
                                </svg>
                            </span>
                            <input type="search" id="caCalSearchInput" class="ca-cal-search-input"
                                   placeholder="Search by matter, lawyer, date, status…" autocomplete="off">
                        </div>
                        <div class="ca-cal-search-results" id="caCalSearchResults" hidden></div>
                    </div>
                </div>
                <div class="dashboard-calendar-hub__body">
                    <div class="dashboard-calendar-layout">
                        <div id="clientAppointmentsCalendar"></div>
                        <aside class="dashboard-upcoming-panel">
                            <div class="dashboard-upcoming-panel__title">
                                <span>Upcoming</span>
                                <a href="#caAppointmentsTable" class="text-xs font-weight-bold" style="color:var(--ca-primary)">View list</a>
                            </div>
                            <div class="dashboard-upcoming-list" id="clientUpcomingAppointmentsList">
                                <?= $upcomingAppointmentsCalendarHtml ?>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>

            <!-- Main layout ------------------------------------------------->
            <div class="ca-layout">

                <!-- Appointments table -->
                <div class="ca-panel" id="caAppointmentsTable">
                    <div class="ca-panel-hdr">
                        <div>
                            <h5>Your appointments</h5>
                            <p>Newest activity first.</p>
                        </div>
                        <span class="ca-count" id="caCount"><?= $apptTotal ?> total</span>
                    </div>
                    <div class="table-responsive">
                        <table class="ca-table">
                            <thead>
                                <tr>
                                    <th>Appointment</th>
                                    <th>Lawyer</th>
                                    <th style="text-align:center">Status</th>
                                    <th>Notes</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?= $appointmentsRows ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Booking panel -->
                <div class="ca-book-card" style="position:sticky;top:1rem">
                    <div class="ca-book-hdr">
                        <h5>Book appointment</h5>
                        <p>Pick counsel, matter, date and time.</p>
                    </div>
                    <div class="ca-book-body">
                        <form method="POST" action="" onsubmit="return validateFormWrapper(event)">
                            <div class="ca-fld">
                                <label>Lawyer</label>
                                <select name="lawyer_id" id="lawyer_id" required onchange="onLawyerChange()">
                                    <?= $lawyerOptions ?>
                                </select>
                            </div>
                            <div class="ca-fld">
                                <label>Case</label>
                                <select name="case_id" id="case_id" required>
                                    <?= $caseOptions ?>
                                </select>
                            </div>
                            <div class="ca-fld">
                                <label>Date</label>
                                <div class="ca-date-picker-wrap">
                                    <input type="text" name="appointment_date" id="appointment_date"
                                           placeholder="Select date" autocomplete="off" readonly required>
                                </div>
                                <div id="caDateAlert" class="ca-avail-alert info">Select a lawyer, then choose an available date.</div>
                            </div>
                            <div class="ca-fld">
                                <label>Time</label>
                                <div class="ca-time-dd" id="caTimeDd">
                                    <button type="button" class="ca-time-dd-trigger" id="caTimeTrigger" disabled
                                            aria-haspopup="listbox" aria-expanded="false" aria-labelledby="caTimeLabel">
                                        <span id="caTimeLabel">Select a time</span>
                                        <svg class="ca-time-dd-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <polyline points="6 9 12 15 18 9"></polyline>
                                        </svg>
                                    </button>
                                    <ul class="ca-time-dd-menu" id="caTimeMenu" role="listbox" aria-label="Available times"></ul>
                                    <input type="hidden" name="appointment_time" id="appointment_time" value="">
                                </div>
                                <div class="ca-avail-hint" style="display:flex;align-items:center;gap:.4rem">
                                    <span class="ca-avail-hint-dot" aria-hidden="true"></span>
                                    Green times are available to book.
                                </div>
                            </div>
                            <div class="ca-fld">
                                <label>Notes
                                    <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span>
                                </label>
                                <textarea name="notes" placeholder="Topics you want to cover…"></textarea>
                            </div>
                            <button type="submit" id="caBookBtn" class="ca-book-btn" disabled>
                                Request appointment
                            </button>
                        </form>
                    </div>
                </div>

            </div><!-- /ca-layout -->
            </div><!-- /cp-page -->
        </div><!-- /container -->
    </main>

    <!-- Details modal -------------------------------------------------------->
    <div class="modal fade ca-modal" id="aptModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Appointment details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="aptModalBody">
                    <div class="text-center py-4">
                        <span class="spinner-border spinner-border-sm text-primary" role="status"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <?php legalpro_render_availability_date_picker_script(); ?>

    <script src="../assets/js/appointment-slot-window.js?v=2"></script>
    <script>
    var clientAppointmentEvents = <?= $appointmentCalendarEventsJson ?>;
    var clientAppointmentsCalendar = null;
    var lawyerAvailabilityByDate = <?= json_encode($lawyerAvailabilityByDate) ?>;
    var lawyerAvailabilityByDay  = <?= json_encode($lawyerAvailabilityByDay) ?>;
    var lawyerHasAvailability    = <?= json_encode($lawyerHasAvailability) ?>;
    var lawyerWorkingHours       = <?= json_encode($lawyerWorkingHours) ?>;
    var lawyerHasWorkingHours    = <?= json_encode($lawyerHasWorkingHours) ?>;
    var lawyerSlotsCache         = {};
    var selectedTime             = null;
    var aptModalInstance         = null;
    var APPOINTMENT_DURATION_MINUTES = 60;

    function getDurationMinutes() {
        return APPOINTMENT_DURATION_MINUTES;
    }

    function formatTimeLabel(timeVal) {
        var parts = timeVal.split(':');
        var hours = parseInt(parts[0], 10);
        var minutes = parts[1] || '00';
        var period = hours >= 12 ? 'PM' : 'AM';
        var displayHours = hours % 12;
        if (displayHours === 0) {
            displayHours = 12;
        }
        return displayHours + ':' + minutes + ' ' + period;
    }

    function getStandardSlotTimes(lawyerId, dateVal) {
        var published = lawyerId ? hasSchedule(lawyerId) : false;
        var dayHours = lawyerId && dateVal ? getWorkingHoursForDate(lawyerId, dateVal) : null;
        var slots = lawyerId && dateVal && published ? getSlotsForDate(lawyerId, dateVal) : [];
        return LegalproAppointmentSlots.getStandardSlotTimes(60, {
            slots: slots,
            hasPublishedSchedule: published,
            hasWorkingHours: lawyerId ? hasWorkingHoursConfig(lawyerId) : false,
            workingHoursDay: dayHours
        }, 30);
    }

    function buildTimeMenuOptions() {
        var menu = document.getElementById('caTimeMenu');
        if (!menu) {
            return;
        }
        menu.innerHTML = '';
        getStandardSlotTimes(null, null).forEach(function(val) {
            var li = document.createElement('li');
            li.className = 'ca-time-dd-opt';
            li.setAttribute('role', 'option');
            li.setAttribute('data-time', val);
            li.setAttribute('data-label', formatTimeLabel(val));
            li.setAttribute('aria-disabled', 'true');
            li.textContent = formatTimeLabel(val);
            menu.appendChild(li);
        });
    }

    function getDayOfWeekFromDate(dateVal) {
        var days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        return days[new Date(dateVal + 'T00:00:00').getDay()];
    }

    function dateHasExplicitSlots(lawyerId, dateVal) {
        var byDate = lawyerAvailabilityByDate[lawyerId] || lawyerAvailabilityByDate[String(lawyerId)] || {};
        return Array.isArray(byDate[dateVal]) && byDate[dateVal].length > 0;
    }

    function getSlotsForDate(lawyerId, dateVal) {
        var cacheKey = String(lawyerId) + '|' + dateVal;
        if (lawyerSlotsCache[cacheKey]) {
            return lawyerSlotsCache[cacheKey].slice();
        }

        var byDate = lawyerAvailabilityByDate[lawyerId] || lawyerAvailabilityByDate[String(lawyerId)] || {};
        if (dateHasExplicitSlots(lawyerId, dateVal)) {
            return byDate[dateVal].slice();
        }
        var slots = byDate[dateVal] ? byDate[dateVal].slice() : [];
        var byDay = lawyerAvailabilityByDay[lawyerId] || lawyerAvailabilityByDay[String(lawyerId)] || {};
        var dayKey = getDayOfWeekFromDate(dateVal);
        if (byDay[dayKey]) {
            slots = slots.concat(byDay[dayKey]);
        }
        return slots;
    }

    function fetchLawyerSlotsForDate(lawyerId, dateVal) {
        return fetch(
            'client-lawyer-availability-api.php?lawyer_id=' + encodeURIComponent(lawyerId)
                + '&date=' + encodeURIComponent(dateVal),
            { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }
        )
        .then(function(response) {
            return response.json().then(function(body) {
                if (!response.ok || !body.ok) {
                    throw new Error((body && body.error) ? body.error : 'Could not load availability');
                }
                return body;
            });
        })
        .then(function(data) {
            var cacheKey = String(lawyerId) + '|' + dateVal;
            lawyerSlotsCache[cacheKey] = data.slots || [];
            lawyerHasAvailability[lawyerId] = !!data.hasSchedule;
            lawyerHasAvailability[String(lawyerId)] = !!data.hasSchedule;
            lawyerHasWorkingHours[lawyerId] = !!data.hasWorkingHours;
            lawyerHasWorkingHours[String(lawyerId)] = !!data.hasWorkingHours;
            if (data.workingHours) {
                lawyerWorkingHours[lawyerId] = data.workingHours;
                lawyerWorkingHours[String(lawyerId)] = data.workingHours;
            }
            return data.slots || [];
        });
    }

    function getAvailableSlots(lawyerId, dateVal) {
        return getSlotsForDate(lawyerId, dateVal).filter(function(s) { return s.type === 'available'; });
    }

    function timeToMinutes(timeVal) {
        if (!timeVal) return -1;
        var parts = String(timeVal).split(':');
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
    }

    function isAvailable(timeVal, slots, startOnly) {
        if (!timeVal || !slots.length) {
            return false;
        }
        var startMinutes = timeToMinutes(timeVal);
        if (startOnly) {
            return slots.some(function(s) {
                return startMinutes >= timeToMinutes(s.start) && startMinutes <= timeToMinutes(s.end);
            });
        }
        var endMinutes = startMinutes + getDurationMinutes();
        return slots.some(function(s) {
            return startMinutes >= timeToMinutes(s.start) && endMinutes <= timeToMinutes(s.end);
        });
    }

    function isPastTime(dateVal, timeVal) {
        var now = new Date();
        var today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
        if (dateVal !== today) return false;
        var current = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        return timeVal < current;
    }

    function isBlockedByUnavailable(timeVal, slots) {
        if (!timeVal || !slots.length) return false;
        var startMinutes = timeToMinutes(timeVal);
        var endMinutes = startMinutes + getDurationMinutes();
        return slots.some(function(slot) {
            if (slot.type !== 'unavailable') return false;
            return startMinutes < timeToMinutes(slot.end) && endMinutes > timeToMinutes(slot.start);
        });
    }

    function hasSchedule(lawyerId) {
        return !!(lawyerHasAvailability[lawyerId] || lawyerHasAvailability[String(lawyerId)]);
    }

    function hasWorkingHoursConfig(lawyerId) {
        return !!(lawyerHasWorkingHours[lawyerId] || lawyerHasWorkingHours[String(lawyerId)]);
    }

    function getWorkingHoursForDate(lawyerId, dateVal) {
        var schedule = lawyerWorkingHours[lawyerId] || lawyerWorkingHours[String(lawyerId)] || {};
        return schedule[getDayOfWeekFromDate(dateVal)] || null;
    }

    function isWithinWorkingHours(lawyerId, dateVal, timeVal) {
        if (!hasWorkingHoursConfig(lawyerId)) {
            return true;
        }
        var day = getWorkingHoursForDate(lawyerId, dateVal);
        if (!day || !day.enabled) {
            return false;
        }
        var start = timeVal.length === 5 ? timeVal + ':00' : timeVal;
        var endParts = start.split(':');
        var endMinutes = parseInt(endParts[0], 10) * 60 + parseInt(endParts[1] || '0', 10) + getDurationMinutes();
        var end = String(Math.floor(endMinutes / 60)).padStart(2, '0') + ':' + String(endMinutes % 60).padStart(2, '0') + ':00';
        return start >= day.start && end <= day.end;
    }

    function isWithinBookableHours(lawyerId, dateVal, timeVal) {
        if (hasWorkingHoursConfig(lawyerId)) {
            return isWithinWorkingHours(lawyerId, dateVal, timeVal);
        }
        if (hasSchedule(lawyerId)) {
            return true;
        }
        return LegalproAppointmentSlots.isWithinDefaultBusinessHours(
            getDayOfWeekFromDate(dateVal),
            timeVal,
            getDurationMinutes()
        );
    }

    function isTimeBookable(lawyerId, dateVal, timeVal, allSlots, availableSlots, published) {
        if (!isWithinBookableHours(lawyerId, dateVal, timeVal)) {
            return false;
        }
        if (isPastTime(dateVal, timeVal) || isBlockedByUnavailable(timeVal, allSlots)) {
            return false;
        }
        if (published && availableSlots.length > 0) {
            return isAvailable(timeVal, availableSlots, true);
        }
        if (published && !hasWorkingHoursConfig(lawyerId)) {
            return false;
        }
        return true;
    }

    function setBookBtn(on) {
        document.getElementById('caBookBtn').disabled = !on;
    }

    function closeTimeMenu() {
        var trigger = document.getElementById('caTimeTrigger');
        var menu = document.getElementById('caTimeMenu');
        trigger.classList.remove('open');
        trigger.setAttribute('aria-expanded', 'false');
        menu.classList.remove('open');
    }

    function openTimeMenu() {
        var trigger = document.getElementById('caTimeTrigger');
        var menu = document.getElementById('caTimeMenu');
        trigger.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
        menu.classList.add('open');
    }

    function resetTimeSelect() {
        selectedTime = null;
        document.getElementById('appointment_time').value = '';
        var trigger = document.getElementById('caTimeTrigger');
        var label = document.getElementById('caTimeLabel');
        trigger.disabled = true;
        trigger.classList.remove('has-value');
        label.textContent = 'Select a time';
        closeTimeMenu();
        document.querySelectorAll('.ca-time-dd-opt').forEach(function(opt) {
            opt.className = 'ca-time-dd-opt';
            opt.setAttribute('aria-disabled', 'true');
        });
    }

    function selectTime(val, labelText) {
        var target = document.querySelector('.ca-time-dd-opt[data-time="' + val + '"]');
        if (!target || !target.classList.contains('bookable')) {
            return;
        }
        document.querySelectorAll('.ca-time-dd-opt').forEach(function(opt) {
            opt.classList.toggle('selected', opt.getAttribute('data-time') === val && opt.classList.contains('bookable'));
        });
        selectedTime = val;
        document.getElementById('appointment_time').value = val;
        document.getElementById('caTimeLabel').textContent = labelText;
        document.getElementById('caTimeTrigger').classList.add('has-value');
        closeTimeMenu();
        setBookBtn(true);
    }

    function showDateAlert(type, msg) {
        var el = document.getElementById('caDateAlert');
        el.className = 'ca-avail-alert ' + type;
        el.textContent = msg;
        el.style.display = 'block';
    }
    function hideDateAlert() {
        document.getElementById('caDateAlert').style.display = 'none';
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

    function dateHasBookableTimes(lawyerId, dateVal) {
        if (!lawyerId || !dateVal) {
            return false;
        }
        var allSlots = getSlotsForDate(lawyerId, dateVal);
        var published = hasSchedule(lawyerId);
        var availableSlots = getAvailableSlots(lawyerId, dateVal);
        var dayHours = getWorkingHoursForDate(lawyerId, dateVal);

        if (hasWorkingHoursConfig(lawyerId) && (!dayHours || !dayHours.enabled)) {
            return false;
        }
        if (!hasWorkingHoursConfig(lawyerId) && !published
            && !LegalproAppointmentSlots.isDefaultBusinessDayEnabled(getDayOfWeekFromDate(dateVal))) {
            return false;
        }
        if (published && !availableSlots.length && !hasWorkingHoursConfig(lawyerId)) {
            return false;
        }
        return getStandardSlotTimes(lawyerId, dateVal).some(function(t) {
            return isTimeBookable(lawyerId, dateVal, t, allSlots, availableSlots, published);
        });
    }

    function isDateUnavailable(dateValue) {
        if (!dateValue) {
            return false;
        }
        if (dateValue < nowParts().date) {
            return true;
        }
        var lawyerId = document.getElementById('lawyer_id').value;
        if (!lawyerId) {
            return false;
        }
        return !dateHasBookableTimes(lawyerId, dateValue);
    }

    function isLawyerDateUnavailable(dateObj) {
        if (!(dateObj instanceof Date) || isNaN(dateObj.getTime()) || typeof LegalproAvailabilityDatePicker === 'undefined') {
            return true;
        }
        return isDateUnavailable(LegalproAvailabilityDatePicker.formatDate(dateObj));
    }

    function appointmentDatePickerOptions() {
        return {
            minDate: 'today',
            isUnavailable: isLawyerDateUnavailable,
            positionBelow: true,
            onChange: function() {
                onDateChange();
            }
        };
    }

    function initAppointmentDatePicker() {
        var dateInput = document.getElementById('appointment_date');
        if (!dateInput || typeof LegalproAvailabilityDatePicker === 'undefined') {
            return;
        }
        LegalproAvailabilityDatePicker.create(dateInput, appointmentDatePickerOptions());
    }

    function onLawyerChange() {
        resetTimeSelect();
        lawyerSlotsCache = {};
        var dateInput = document.getElementById('appointment_date');
        if (dateInput && typeof LegalproAvailabilityDatePicker !== 'undefined') {
            LegalproAvailabilityDatePicker.rebuild(dateInput, appointmentDatePickerOptions());
        } else if (dateInput && dateInput.value && isDateUnavailable(dateInput.value)) {
            dateInput.value = '';
        }
        onDateChange();
    }

    function onDateChange() {
        resetTimeSelect();
        setBookBtn(false);
        var lawyerId = document.getElementById('lawyer_id').value;
        var dateVal  = document.getElementById('appointment_date').value;
        var trigger = document.getElementById('caTimeTrigger');
        if (!lawyerId) return;
        if (!dateVal) {
            showDateAlert('info', 'Select a date to see available times.');
            trigger.disabled = true;
            return;
        }
        if (isDateUnavailable(dateVal)) {
            document.getElementById('appointment_date').value = '';
            if (typeof LegalproAvailabilityDatePicker !== 'undefined') {
                var fp = LegalproAvailabilityDatePicker.instances.appointment_date;
                if (fp) fp.clear();
            }
            trigger.disabled = true;
            showDateAlert('warning', 'This date is not available. Please choose another date.');
            return;
        }

        trigger.disabled = true;
        showDateAlert('info', 'Loading available times…');

        fetchLawyerSlotsForDate(lawyerId, dateVal)
            .then(function(allSlots) {
                applyTimeAvailability(lawyerId, dateVal, allSlots);
            })
            .catch(function(err) {
                trigger.disabled = true;
                showDateAlert('warning', err.message || 'Could not load available times.');
            });
    }

    function applyTimeAvailability(lawyerId, dateVal, allSlots) {
        var trigger = document.getElementById('caTimeTrigger');
        var published = hasSchedule(lawyerId);
        var availableSlots = (allSlots || []).filter(function(s) { return s.type === 'available'; });
        var dayHours = getWorkingHoursForDate(lawyerId, dateVal);

        if (hasWorkingHoursConfig(lawyerId) && (!dayHours || !dayHours.enabled)) {
            trigger.disabled = true;
            showDateAlert('warning', 'This lawyer does not work on the selected day.');
            return;
        }

        if (!hasWorkingHoursConfig(lawyerId) && !published
            && !LegalproAppointmentSlots.isDefaultBusinessDayEnabled(getDayOfWeekFromDate(dateVal))) {
            trigger.disabled = true;
            showDateAlert('warning', 'This lawyer is available on weekdays between 9:00 AM and 5:00 PM.');
            return;
        }

        if (published && !availableSlots.length && !hasWorkingHoursConfig(lawyerId)) {
            trigger.disabled = true;
            showDateAlert('warning', 'No available times on this date. Choose another date.');
            return;
        }

        if (hasWorkingHoursConfig(lawyerId)) {
            showDateAlert('info', 'Times are limited to the lawyer\'s working hours.');
        } else if (!published) {
            showDateAlert('info', 'Standard business hours are 9:00 AM – 5:00 PM on weekdays.');
        } else {
            hideDateAlert();
        }

        var anyAvail = false;
        document.querySelectorAll('.ca-time-dd-opt').forEach(function(opt) {
            var t = opt.getAttribute('data-time');
            opt.classList.remove('selected');
            if (isTimeBookable(lawyerId, dateVal, t, allSlots, availableSlots, published)) {
                opt.className = 'ca-time-dd-opt bookable';
                opt.setAttribute('aria-disabled', 'false');
                anyAvail = true;
            } else {
                opt.className = 'ca-time-dd-opt';
                opt.setAttribute('aria-disabled', 'true');
            }
        });
        trigger.disabled = !anyAvail;
        if (!anyAvail) {
            showDateAlert('warning', 'No matching times available. Choose another date.');
        }
    }

    function validateForm() {
        var lawyerId = document.getElementById('lawyer_id').value;
        var caseId   = document.getElementById('case_id').value;
        var dateVal  = document.getElementById('appointment_date').value;
        var timeVal  = document.getElementById('appointment_time').value;
        if (!lawyerId) { alert('Please select a lawyer.'); return false; }
        if (!caseId)   { alert('Please select a case.');   return false; }
        if (!dateVal)  { alert('Please select a date.');   return false; }
        if (isDateUnavailable(dateVal)) {
            alert('The selected date is not available. Please choose another date.');
            return false;
        }
        if (!timeVal)  { alert('Please select a time slot.'); return false; }
        return fetchLawyerSlotsForDate(lawyerId, dateVal)
            .then(function(allSlots) {
                var published = hasSchedule(lawyerId);
                var availableSlots = allSlots.filter(function(s) { return s.type === 'available'; });
                if (!isTimeBookable(lawyerId, dateVal, timeVal, allSlots, availableSlots, published)) {
                    alert('Selected time is not available. Please choose another slot.');
                    return false;
                }
                return true;
            })
            .catch(function() {
                alert('Could not verify availability. Please try again.');
                return false;
            });
    }

    function validateFormWrapper(event) {
        event.preventDefault();
        validateForm().then(function(ok) {
            if (ok) {
                event.target.submit();
            }
        });
        return false;
    }

    function escapeHtml(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function getModal() {
        if (!aptModalInstance) aptModalInstance = new bootstrap.Modal(document.getElementById('aptModal'));
        return aptModalInstance;
    }

    function viewAppointmentDetails(id) {
        var body = document.getElementById('aptModalBody');
        body.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><p class="text-sm text-muted mt-2 mb-0">Loading…</p></div>';
        getModal().show();
        fetch('client-appointments.php?ajax=appointment_details&id=' + id, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function(r) {
            return r.json().then(function(b) {
                if (!r.ok) throw new Error(b.error || 'Could not load appointment');
                return b;
            });
        })
        .then(function(d) {
            body.innerHTML =
                '<div style="display:flex;flex-direction:column;gap:1rem">' +
                    '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem">' +
                        '<div>' +
                            '<p style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin:0 0 3px">Matter</p>' +
                            '<p style="font-size:15px;font-weight:700;color:#1e293b;margin:0">' + escapeHtml(d.case_title) + '</p>' +
                        '</div>' +
                        '<span class="ca-badge ' + escapeHtml(d.status_pill || 'b-muted') + '">' +
                            '<span class="ca-badge-dot"></span>' + escapeHtml(d.status_label) +
                        '</span>' +
                    '</div>' +
                    '<div class="ca-detail-row">' +
                        '<div class="ca-detail-field"><p class="lbl">Lawyer</p><p class="val">' + escapeHtml(d.lawyer_name) + '</p></div>' +
                        '<div class="ca-detail-field"><p class="lbl">Requested</p><p class="val">' + escapeHtml(d.requested_at || '—') + '</p></div>' +
                        '<div class="ca-detail-field"><p class="lbl">Starts</p><p class="val">' + escapeHtml(d.starts_at) + '</p></div>' +
                        '<div class="ca-detail-field"><p class="lbl">Ends</p><p class="val">' + escapeHtml(d.ends_at || '—') + '</p></div>' +
                    '</div>' +
                    '<div>' +
                        '<p style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin:0 0 4px">Notes</p>' +
                        (d.notes
                            ? '<p style="font-size:13px;color:#1e293b;margin:0">' + escapeHtml(d.notes) + '</p>'
                            : '<p style="font-size:13px;color:#94a3b8;margin:0">No notes provided.</p>') +
                    '</div>' +
                '</div>';
        })
        .catch(function(err) {
            body.innerHTML = '<div style="background:#fee2e2;color:#991b1b;border-radius:8px;padding:.75rem 1rem;font-size:13px">' + escapeHtml(err.message) + '</div>';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        buildTimeMenuOptions();
        initAppointmentDatePicker();
        var trigger = document.getElementById('caTimeTrigger');
        var menu = document.getElementById('caTimeMenu');

        trigger.addEventListener('click', function() {
            if (trigger.disabled) return;
            if (menu.classList.contains('open')) {
                closeTimeMenu();
            } else {
                openTimeMenu();
            }
        });

        menu.addEventListener('click', function(e) {
            var opt = e.target.closest('.ca-time-dd-opt.bookable');
            if (!opt) return;
            selectTime(opt.getAttribute('data-time'), opt.getAttribute('data-label') || opt.textContent.trim());
        });

        document.addEventListener('click', function(e) {
            if (!document.getElementById('caTimeDd').contains(e.target)) {
                closeTimeMenu();
            }
        });

        if (document.getElementById('lawyer_id').value) {
            onLawyerChange();
        }

        var calendarEl = document.getElementById('clientAppointmentsCalendar');
        var upcomingList = document.getElementById('clientUpcomingAppointmentsList');

        function appointmentStatusKey(status) {
            var value = String(status || 'pending').toLowerCase();
            return value === 'approved' ? 'accepted' : value;
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
                viewAppointmentDetails(parseInt(btn.getAttribute('data-appointment-id'), 10));
            });
        }

        if (calendarEl && typeof FullCalendar !== 'undefined') {
            clientAppointmentsCalendar = new FullCalendar.Calendar(calendarEl, {
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
                events: clientAppointmentEvents,
                eventContent: renderAppointmentEvent,
                dateClick: function(info) {
                    if (window.legalproHandleCalendarDateClick) {
                        window.legalproHandleCalendarDateClick(info, function(event) {
                            var props = event.extendedProps || {};
                            viewAppointmentDetails(props.appointmentId || parseInt(event.id, 10));
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
                    viewAppointmentDetails(props.appointmentId || parseInt(info.event.id, 10));
                },
                eventDidMount: function(info) {
                    if (window.legalproMountCalendarEventClickable) {
                        window.legalproMountCalendarEventClickable(info);
                    }
                    var props = info.event.extendedProps || {};
                    var tip = info.event.title;
                    if (props.lawyer) {
                        tip += '\nLawyer: ' + props.lawyer;
                    }
                    info.el.setAttribute('title', tip);
                }
            });
            clientAppointmentsCalendar.render();
            initClientCalendarSearch();
        }

        function initClientCalendarSearch() {
            var input = document.getElementById('caCalSearchInput');
            var resultsEl = document.getElementById('caCalSearchResults');
            if (!input || !resultsEl) {
                return;
            }

            function hideResults() {
                resultsEl.hidden = true;
                resultsEl.innerHTML = '';
            }

            function renderSearchResults(matches) {
                var query = input.value.trim();
                if (!query) {
                    hideResults();
                    return;
                }
                if (!matches.length) {
                    resultsEl.innerHTML = '<div class="ca-cal-search-empty">No appointments match your search.</div>';
                    resultsEl.hidden = false;
                    return;
                }

                var html = '';
                matches.slice(0, 12).forEach(function(ev) {
                    var props = ev.extendedProps || {};
                    var statusKey = appointmentStatusKey(props.status);
                    var title = ev.title || 'Appointment';
                    var when = props.startsLabel || '';
                    var lawyer = props.lawyer || 'TBD';
                    html += '<button type="button" class="ca-cal-search-item" data-appointment-id="' + escapeHtml(props.appointmentId || ev.id) + '" data-start="' + escapeHtml(ev.start || '') + '">' +
                        '<span class="ca-cal-search-item__dot ca-cal-search-item__dot--' + escapeHtml(statusKey) + '" aria-hidden="true"></span>' +
                        '<span class="ca-cal-search-item__body">' +
                            '<p class="ca-cal-search-item__title">' + escapeHtml(title) + '</p>' +
                            '<p class="ca-cal-search-item__sub">' + escapeHtml(when) + ' · ' + escapeHtml(lawyer) + ' · ' + escapeHtml(props.statusLabel || props.status || 'Pending') + '</p>' +
                        '</span>' +
                    '</button>';
                });
                resultsEl.innerHTML = html;
                resultsEl.hidden = false;
            }

            input.addEventListener('input', function() {
                var q = input.value.trim().toLowerCase();
                if (!q) {
                    hideResults();
                    return;
                }

                var matches = clientAppointmentEvents.filter(function(ev) {
                    var props = ev.extendedProps || {};
                    var hay = props.searchHay || ((ev.title || '') + ' ' + (props.lawyer || '')).toLowerCase();
                    return hay.indexOf(q) !== -1;
                });

                matches.sort(function(a, b) {
                    return new Date(b.start).getTime() - new Date(a.start).getTime();
                });

                renderSearchResults(matches);
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
                if (clientAppointmentsCalendar && start) {
                    clientAppointmentsCalendar.gotoDate(start);
                }
                viewAppointmentDetails(id);
                hideResults();
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.ca-cal-search-wrap')) {
                    hideResults();
                }
            });
        }
    });
    </script>
    <?= legalpro_render_client_page_search_script('.ca-table tbody .ca-row[data-search]', '#caCount', 'appointment', 'appointments', ' total') ?>
</body>
</html>
<?php
$html = ob_get_clean();
require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);
echo $html;