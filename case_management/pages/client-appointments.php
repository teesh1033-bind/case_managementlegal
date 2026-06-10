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
$lawyerHasAvailability    = [];
try {
    $lawyerIds = array_map(fn($l) => (int) $l['id'], $availableLawyers);
    $maps = loadLawyerAvailabilityForBooking($pdo, $lawyerIds);
    $lawyerAvailabilityByDate = $maps['byDate'];
    $lawyerHasAvailability    = $maps['hasSchedule'];
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
                <button type="submit" class="btn-del" title="Delete">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
                    </svg>
                </button>
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
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px">
                    <button type="button" class="btn-det" onclick="viewAppointmentDetails(' . $aid . ')">Details</button>
                    ' . $deleteBtn . '
                </div>
            </td>
        </tr>';
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
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>

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

        /* ── Hero ───────────────────────────────────────────────────── */
        .ca-hero-card {
            background: var(--ca-gradient);
            border-radius: 20px;
            padding: 2rem 2.5rem;
            color: #fff;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .ca-hero-card::before {
            content: '';
            position: absolute;
            top: -50px; right: -50px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
        }
        .ca-hero-card::after {
            content: '';
            position: absolute;
            bottom: -70px; left: 60px;
            width: 140px; height: 140px;
            border-radius: 50%;
            background: rgba(255,255,255,.06);
        }
        .ca-hero-kicker {
            font-size: 11px; font-weight: 600;
            letter-spacing: .12em; text-transform: uppercase;
            opacity: .75; margin-bottom: .35rem;
        }
        .ca-hero-title { font-size: 22px; font-weight: 800; margin-bottom: .3rem; }
        .ca-hero-sub   { font-size: 13px; opacity: .75; margin-bottom: 1.5rem; }
        .ca-hero-pills { display: flex; gap: .85rem; flex-wrap: wrap; position: relative; z-index: 1; }
        .ca-stat-pill {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 12px;
            padding: .6rem 1.1rem;
        }
        .ca-stat-pill .num { font-size: 20px; font-weight: 700; line-height: 1; }
        .ca-stat-pill .lbl { font-size: 11px; opacity: .75; margin-top: 2px; }

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

        /* ── Action buttons ─────────────────────────────────────────── */
        .btn-det {
            padding: .3rem .8rem; border-radius: 8px;
            border: 1.5px solid var(--ca-primary); color: var(--ca-primary);
            font-size: 12px; font-weight: 600; background: none; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .btn-det:hover { background: var(--ca-primary); color: #fff; }
        .btn-del {
            width: 28px; height: 28px; border-radius: 7px;
            border: 1.5px solid #fca5a5; color: #dc2626;
            background: #fff; cursor: pointer; display: flex;
            align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .btn-del:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

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
            border: 1px solid #e9ecf3; overflow: hidden;
        }
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
        .ca-fld textarea {
            width: 100%; padding: .55rem .75rem;
            border: 1px solid var(--ca-field-border); border-radius: 10px;
            font-size: 13px; color: var(--ca-field-color); background: var(--ca-field-bg);
            outline: none; font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        body.legalpro-dark-mode.client-appointments-page .ca-fld input[type="date"] {
            color-scheme: dark;
        }
        .ca-fld select:focus,
        .ca-fld input[type="date"]:focus,
        .ca-fld textarea:focus {
            border-color: var(--ca-primary);
            box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
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
            cursor: not-allowed; user-select: none;
        }
        .ca-time-dd-opt.available {
            color: var(--ca-time-available-color);
            background: transparent;
            cursor: pointer;
        }
        .ca-time-dd-opt.available:hover {
            color: var(--ca-time-available-color);
            background: transparent;
        }
        .ca-time-dd-opt.selected,
        .ca-time-dd-opt.selected:hover {
            color: var(--ca-time-available-color);
            background: transparent;
            font-weight: 700;
        }

        .ca-avail-hint {
            display: flex; align-items: center; gap: 6px;
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
        .ca-detail-field p.lbl {
            font-size: 10.5px; font-weight: 700; letter-spacing: .08em;
            text-transform: uppercase; color: #94a3b8; margin: 0 0 3px;
        }
        .ca-detail-field p.val { font-size: 13px; color: #1e293b; font-weight: 500; margin: 0; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-appointments-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>

    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?= $clientPageNavbar ?>

        <div class="container-fluid py-4">

            <?= $messageHtml ?>

            <!-- Hero -------------------------------------------------------->
            <div class="ca-hero-card">
                <p class="ca-hero-kicker">Calendar</p>
                <h4 class="ca-hero-title">My appointments</h4>
                <p class="ca-hero-sub">Track meetings with your legal team and request new sessions below.</p>
                <div class="ca-hero-pills">
                    <div class="ca-stat-pill">
                        <div class="num"><?= $apptTotal ?></div>
                        <div class="lbl">Total</div>
                    </div>
                    <div class="ca-stat-pill">
                        <div class="num"><?= $apptPending ?></div>
                        <div class="lbl">Pending</div>
                    </div>
                    <div class="ca-stat-pill">
                        <div class="num"><?= $apptUpcoming ?></div>
                        <div class="lbl">Upcoming</div>
                    </div>
                </div>
            </div>

            <!-- Main layout ------------------------------------------------->
            <div class="ca-layout">

                <!-- Appointments table -->
                <div class="ca-panel">
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
                        <form method="POST" action="" onsubmit="return validateForm()">
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
                                <input type="date" name="appointment_date" id="appointment_date"
                                       min="<?= date('Y-m-d') ?>" required onchange="onDateChange()">
                                <div id="caDateAlert" class="ca-avail-alert info">Select a date to see available times.</div>
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
                                    <ul class="ca-time-dd-menu" id="caTimeMenu" role="listbox" aria-label="Available times">
                                        <?php
                                        $times = [
                                            '09:00' => '9:00', '10:00' => '10:00', '11:00' => '11:00',
                                            '12:00' => '12:00', '13:00' => '1:00', '14:00' => '2:00',
                                            '15:00' => '3:00', '16:00' => '4:00', '17:00' => '5:00',
                                        ];
                                        foreach ($times as $val => $lbl): ?>
                                        <li class="ca-time-dd-opt"
                                            role="option"
                                            data-time="<?= $val ?>"
                                            data-label="<?= $lbl ?>"
                                            aria-disabled="true"><?= $lbl ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <input type="hidden" name="appointment_time" id="appointment_time" value="">
                                </div>
                                <div class="ca-avail-hint">
                                    <div class="ca-avail-hint-dot"></div>
                                    Green times are open for booking
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

    <script>
    var lawyerAvailabilityByDate = <?= json_encode($lawyerAvailabilityByDate) ?>;
    var lawyerHasAvailability    = <?= json_encode($lawyerHasAvailability) ?>;
    var selectedTime             = null;
    var aptModalInstance         = null;

    function getAvailableSlots(lawyerId, dateVal) {
        var byDate = lawyerAvailabilityByDate[lawyerId] || lawyerAvailabilityByDate[String(lawyerId)] || {};
        return (byDate[dateVal] || []).filter(function(s) { return s.type === 'available'; });
    }

    function isAvailable(timeVal, slots) {
        if (!timeVal || !slots.length) return false;
        var t = timeVal.length === 5 ? timeVal + ':00' : timeVal;
        return slots.some(function(s) { return t >= s.start && t < s.end; });
    }

    function hasSchedule(lawyerId) {
        return !!(lawyerHasAvailability[lawyerId] || lawyerHasAvailability[String(lawyerId)]);
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
        document.querySelectorAll('.ca-time-dd-opt').forEach(function(opt) {
            opt.classList.toggle('selected', opt.getAttribute('data-time') === val && opt.classList.contains('available'));
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

    function onLawyerChange() {
        resetTimeSelect();
        onDateChange();
    }

    function onDateChange() {
        resetTimeSelect();
        setBookBtn(false);
        var lawyerId = document.getElementById('lawyer_id').value;
        var dateVal  = document.getElementById('appointment_date').value;
        var trigger = document.getElementById('caTimeTrigger');
        if (!lawyerId) return;
        if (!dateVal) { showDateAlert('info', 'Select a date to see available times.'); return; }
        if (!hasSchedule(lawyerId)) {
            showDateAlert('warning', 'No available times on this date. Choose another date.');
            return;
        }
        var slots = getAvailableSlots(lawyerId, dateVal);
        if (!slots.length) {
            showDateAlert('warning', 'No available times on this date. Choose another date.');
            return;
        }
        hideDateAlert();
        var anyAvail = false;
        document.querySelectorAll('.ca-time-dd-opt').forEach(function(opt) {
            var t = opt.getAttribute('data-time');
            opt.classList.remove('selected');
            if (isAvailable(t, slots)) {
                opt.className = 'ca-time-dd-opt available';
                opt.setAttribute('aria-disabled', 'false');
                anyAvail = true;
            } else {
                opt.className = 'ca-time-dd-opt';
                opt.setAttribute('aria-disabled', 'true');
            }
        });
        trigger.disabled = !anyAvail;
        if (!anyAvail) showDateAlert('warning', 'No matching times available. Choose another date.');
    }

    function validateForm() {
        var lawyerId = document.getElementById('lawyer_id').value;
        var caseId   = document.getElementById('case_id').value;
        var dateVal  = document.getElementById('appointment_date').value;
        var timeVal  = document.getElementById('appointment_time').value;
        if (!lawyerId) { alert('Please select a lawyer.'); return false; }
        if (!caseId)   { alert('Please select a case.');   return false; }
        if (!dateVal)  { alert('Please select a date.');   return false; }
        if (!timeVal)  { alert('Please select a time slot.'); return false; }
        if (!hasSchedule(lawyerId)) { alert('No available times on this date.'); return false; }
        var slots = getAvailableSlots(lawyerId, dateVal);
        if (!slots.length || !isAvailable(timeVal, slots)) {
            alert('Selected time is not available. Please choose another time.');
            return false;
        }
        return true;
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
            var opt = e.target.closest('.ca-time-dd-opt.available');
            if (!opt) return;
            selectTime(opt.getAttribute('data-time'), opt.getAttribute('data-label') || opt.textContent.trim());
        });

        document.addEventListener('click', function(e) {
            if (!document.getElementById('caTimeDd').contains(e.target)) {
                closeTimeMenu();
            }
        });

        onLawyerChange();
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