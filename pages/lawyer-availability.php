<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/appointment_availability.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}


$lawyerId = $_SESSION['lawyer_id'];
$message = '';
$messageType = '';
$daysOfWeek = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

try {
    $pdo->query("ALTER TABLE lawyer_time_slots ADD COLUMN slot_date DATE NULL AFTER day_of_week");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false && stripos($e->getMessage(), 'duplicate column name') === false) {
        // Continue; save errors will show a detailed message if the schema is unavailable.
    }
}

// Handle time slot management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_slot']) || isset($_POST['add_slot'])) {
        $slotId = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
        $slotDate = isset($_POST['slot_date']) ? trim($_POST['slot_date']) : '';
        $startTime = isset($_POST['start_time']) ? $_POST['start_time'] : '';
        $endTime = isset($_POST['end_time']) ? $_POST['end_time'] : '';
        $slotType = isset($_POST['slot_type']) ? $_POST['slot_type'] : 'available';
        $timestamp = $slotDate !== '' ? strtotime($slotDate) : false;
        $dayOfWeek = $timestamp !== false ? strtolower(date('l', $timestamp)) : '';

        if ($timestamp === false || !in_array($dayOfWeek, $daysOfWeek, true) || empty($startTime) || empty($endTime) || !in_array($slotType, ['available', 'unavailable'], true)) {
            $message = 'Please provide a valid date, time range, and availability status.';
            $messageType = 'danger';
        } elseif (strtotime($startTime) >= strtotime($endTime)) {
            $message = 'End time must be after start time.';
            $messageType = 'danger';
        } else {
            try {
                if ($slotId > 0) {
                    $checkStmt = $pdo->prepare("SELECT id FROM lawyer_time_slots WHERE id = ? AND lawyer_id = ?");
                    $checkStmt->execute([$slotId, $lawyerId]);
                    if (!$checkStmt->fetch()) {
                        $message = 'Time slot not found or access denied.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE lawyer_time_slots
                            SET day_of_week = ?, slot_date = ?, start_time = ?, end_time = ?, slot_type = ?
                            WHERE id = ? AND lawyer_id = ?
                        ");
                        $stmt->execute([$dayOfWeek, $slotDate, $startTime, $endTime, $slotType, $slotId, $lawyerId]);
                        $message = 'Time slot updated successfully!';
                        $messageType = 'success';
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO lawyer_time_slots (lawyer_id, day_of_week, slot_date, start_time, end_time, slot_type) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$lawyerId, $dayOfWeek, $slotDate, $startTime, $endTime, $slotType]);
                    $message = 'Time slot added successfully!';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Error saving time slot: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif (isset($_POST['delete_slot'])) {
        $slotId = (int)$_POST['slot_id'];

        try {
            $checkStmt = $pdo->prepare('SELECT appointment_id FROM lawyer_time_slots WHERE id = ? AND lawyer_id = ?');
            $checkStmt->execute([$slotId, $lawyerId]);
            $slotRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$slotRow) {
                $message = 'Time slot not found or access denied.';
                $messageType = 'danger';
            } else {
                if (!empty($slotRow['appointment_id'])) {
                    $appointmentId = (int) $slotRow['appointment_id'];
                    $apptStmt = $pdo->prepare("UPDATE appointments SET status = 'rejected' WHERE id = ? AND lawyer_id = ?");
                    $apptStmt->execute([$appointmentId, $lawyerId]);
                }

                $stmt = $pdo->prepare('DELETE FROM lawyer_time_slots WHERE id = ? AND lawyer_id = ?');
                $stmt->execute([$slotId, $lawyerId]);

                $message = !empty($slotRow['appointment_id'])
                    ? 'Appointment block removed and the appointment was marked as rejected.'
                    : 'Time slot deleted successfully!';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Error deleting time slot: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Fetch current time slots
$timeSlots = [];
try {
    backfillLawyerAppointmentAvailability($pdo, $lawyerId);

    $stmt = $pdo->prepare("SELECT * FROM lawyer_time_slots WHERE lawyer_id = ? ORDER BY COALESCE(slot_date, '9999-12-31'), day_of_week, slot_order, start_time");
    $stmt->execute([$lawyerId]);
    $timeSlots = $stmt->fetchAll();
} catch (PDOException $e) {
    $timeSlots = [];
}

$messageHtml = '';
if ($message) {
    $successClass = ($messageType === 'success') ? ' text-white' : '';
    $closeClass = ($messageType === 'success') ? ' btn-close-white' : '';
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show' . $successClass . '" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close' . $closeClass . '" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

$availabilityEvents = [];
foreach ($timeSlots as $slot) {
    $slotDate = isset($slot['slot_date']) ? $slot['slot_date'] : '';
    if (empty($slotDate)) {
        continue;
    }

    $slotType = $slot['slot_type'] === 'available' ? 'available' : 'unavailable';
    $startTime = substr($slot['start_time'], 0, 5);
    $endTime = substr($slot['end_time'], 0, 5);
    $appointmentId = isset($slot['appointment_id']) ? (int) $slot['appointment_id'] : 0;
    $isAppointment = $appointmentId > 0;
    $timeLabel = lawyer_portal_format_time_range($slot['start_time'], $slot['end_time']);
    $statusKey = $isAppointment ? 'unavailable' : $slotType;
    $startLabel = lawyer_portal_format_time($slot['start_time']);
    if ($isAppointment) {
        $title = lawyer_tf('availability.unavailable_appt', 'Unavailable — Appointment :time', ['time' => $timeLabel]);
        $shortLabel = lawyer_tf('availability.appt_short', 'Appt · :time', ['time' => $startLabel]);
    } else {
        $slotKey = $slotType === 'available' ? 'availability.available_slot' : 'availability.unavailable_slot';
        $fallback = ($slotType === 'available' ? 'Available' : 'Unavailable') . ' — :time';
        $title = lawyer_tf($slotKey, $fallback, ['time' => $timeLabel]);
        $typeLabel = $slotType === 'available'
            ? lawyer_tf('availability.legend_available', 'Available')
            : lawyer_tf('availability.legend_unavailable', 'Unavailable');
        $shortLabel = $typeLabel . ' · ' . $startLabel;
    }
    $availabilityEvents[] = [
        'id' => (string)$slot['id'],
        'title' => $title,
        'start' => $slotDate . 'T' . $startTime . ':00',
        'end' => $slotDate . 'T' . $endTime . ':00',
        'backgroundColor' => 'transparent',
        'borderColor' => 'transparent',
        'textColor' => '#344767',
        'extendedProps' => [
            'slotId' => (int)$slot['id'],
            'day' => $slot['day_of_week'],
            'slotDate' => $slotDate,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'slotType' => $slotType,
            'statusKey' => $statusKey,
            'shortLabel' => $shortLabel,
            'timeLabel' => $timeLabel,
            'isAppointment' => $isAppointment,
            'appointmentId' => $appointmentId,
            'readOnly' => $isAppointment
        ]
    ];
}

function buildAvailabilityTimeSelectOptions(string $minTime = '06:00', string $maxTime = '22:00'): string
{
    $html = '<option value="">' . htmlspecialchars(lawyer_tf('availability.select_time', 'Select time')) . '</option>';
    $startMinutes = (int) substr($minTime, 0, 2) * 60 + (int) substr($minTime, 3, 2);
    $endMinutes = (int) substr($maxTime, 0, 2) * 60 + (int) substr($maxTime, 3, 2);
    $step = 30;

    for ($minutes = $startMinutes; $minutes <= $endMinutes; $minutes += $step) {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $value = sprintf('%02d:%02d', $hours, $mins);
        $label = lawyer_portal_format_time($value);
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label) . '</option>';
    }

    return $html;
}

$availabilityTimeOptions = buildAvailabilityTimeSelectOptions();

$pageTitle = lawyer_tf('availability.manage_title', 'Manage My Availability');
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle);
$availI18nJson = json_encode([
    'dateLocale' => lawyer_portal_js_date_locale(),
    'fcLocale' => lawyer_portal_fc_locale(),
    'days' => lawyer_portal_day_names(),
    'fcButtons' => lawyer_portal_fc_button_text(),
    'noSlots' => lawyer_tf('availability.no_slots', 'No slots set'),
    'noMatchSearch' => lawyer_tf('availability.no_match_search', 'No availability matches your search.'),
    'slotLabel' => lawyer_tf('availability.slot_label', 'Slot'),
    'addSlot' => lawyer_tf('availability.add_slot', 'Add Time Slot'),
    'editSlot' => lawyer_tf('availability.edit_slot', 'Edit Time Slot'),
    'saveSlot' => lawyer_tf('availability.save_slot', 'Save Slot'),
    'updateSlot' => lawyer_tf('availability.update_slot', 'Update Slot'),
    'deleteSlot' => lawyer_tf('availability.delete_slot', 'Delete slot'),
    'confirmDeleteSlot' => lawyer_tf('availability.confirm_delete_slot', 'Delete this time slot?'),
    'confirmDeleteAppt' => lawyer_tf('availability.confirm_delete_appt', 'Remove this appointment block? The linked appointment will be marked as rejected.'),
    'confirmRemoveApptBlock' => lawyer_tf('availability.confirm_delete_appt', 'Remove this appointment block? The linked appointment will be marked as rejected.'),
    'available' => lawyer_tf('availability.legend_available', 'Available'),
    'unavailable' => lawyer_tf('availability.legend_unavailable', 'Unavailable'),
    'use24h' => getLawyerPortalLocale() === 'fr',
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

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
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <style>
        #availabilityCalendar {
            min-height: 480px;
        }
        #availabilityWeekCalendar {
            min-height: 0;
        }
        .availability-month-panel {
            margin-top: 0.15rem;
        }
        .availability-hero {
            background: linear-gradient(140deg, rgba(45, 63, 111, 0.08), rgba(111, 127, 210, 0.12));
            border: 1px solid #dbe4f7;
            border-radius: 1rem;
            padding: 1rem;
        }
        .availability-fallback-calendar {
            border: 1px solid #e9ecef;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .availability-fallback-header,
        .availability-fallback-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(120px, 1fr));
        }
        .availability-fallback-header > div {
            background: #f6f8fc;
            border-right: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
            color: #344767;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.75rem;
            text-transform: uppercase;
        }
        .availability-fallback-day-name {
            display: block;
        }
        .availability-fallback-day-date {
            color: #67748e;
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 0.2rem;
            text-transform: none;
        }
        .availability-fallback-toolbar {
            align-items: center;
            background: #f6f8fc;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
            padding: 0.75rem 1rem;
        }
        .availability-week-nav {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .availability-week-nav .btn-primary {
            color: #fff !important;
        }
        .availability-week-nav .btn-primary:hover,
        .availability-week-nav .btn-primary:focus {
            color: #fff !important;
        }
        .availability-fallback-week-label {
            color: #344767;
            font-size: 0.875rem;
            font-weight: 700;
            min-width: 10rem;
            text-align: center;
        }
        .availability-fallback-day {
            cursor: pointer;
            min-height: 5.5rem;
            border-right: 1px solid #e9ecef;
            padding: 0.75rem;
        }
        .availability-fallback-day:hover {
            background: #fafbfe;
        }
        .availability-fallback-event {
            align-items: flex-start;
            border-radius: 0.45rem;
            color: #fff;
            cursor: pointer;
            display: flex;
            font-size: 0.75rem;
            font-weight: 700;
            gap: 0.35rem;
            justify-content: space-between;
            margin-bottom: 0.4rem;
            padding: 0.35rem 0.45rem;
        }
        .availability-fallback-event-label {
            flex: 1;
            line-height: 1.3;
            min-width: 0;
        }
        .availability-fallback-event-delete {
            background: rgba(255, 255, 255, 0.25);
            border: 0;
            border-radius: 0.25rem;
            color: #fff;
            cursor: pointer;
            flex-shrink: 0;
            font-size: 0.85rem;
            font-weight: 700;
            line-height: 1;
            padding: 0.1rem 0.35rem;
        }
        .availability-fallback-event-delete:hover {
            background: rgba(255, 255, 255, 0.45);
        }
        .availability-view-toggle {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-bottom: 1rem;
        }
        .availability-view-toggle__btn {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            color: #344767;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.4rem 0.9rem;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .availability-view-toggle__btn:hover,
        .availability-view-toggle__btn:focus {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35);
            color: var(--legalpro-theme-primary, #5e72e4);
            outline: none;
        }
        .availability-view-toggle__btn.is-active {
            background: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
            border-color: transparent;
            color: #fff;
        }
        body.legalpro-dark-mode.lawyer-availability-page .availability-view-toggle__btn {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.14);
            color: #f8f9fc;
        }
        body.lawyer-availability-page .legalpro-navbar-search {
            display: none !important;
        }
        .lawyer-availability-page .dashboard-calendar-hub__head {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .lawyer-availability-page .la-avail-search-wrap {
            position: relative;
            width: 100%;
        }
        .lawyer-availability-page .la-avail-search-wrap--featured {
            padding: .9rem 1rem 1rem;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12) 0%, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04) 100%);
            border: 1px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            box-shadow: 0 6px 22px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .lawyer-availability-page .la-avail-search-label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: #5e72e4;
            margin-bottom: .55rem;
        }
        .lawyer-availability-page .la-avail-search-field {
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
        .lawyer-availability-page .la-avail-search-field:focus-within {
            border-color: #5e72e4;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.18), 0 4px 16px rgba(15, 23, 42, 0.1);
            transform: translateY(-1px);
        }
        .lawyer-availability-page .la-avail-search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
            color: #5e72e4;
            flex-shrink: 0;
        }
        .lawyer-availability-page .la-avail-search-field svg {
            width: 18px;
            height: 18px;
            color: currentColor;
            flex-shrink: 0;
        }
        .lawyer-availability-page .la-avail-search-input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            font-family: inherit;
        }
        .lawyer-availability-page .la-avail-search-input::placeholder {
            color: #64748b;
            font-weight: 500;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-availability-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}
        <div class="container-fluid py-4">
            {$message}

            <div class="row">
                <div class="col-12">
                    <div class="dashboard-calendar-hub">
                        <div class="dashboard-calendar-hub__head">
                            <div class="la-availability-hub__intro">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                    <div>
                                        <h6 class="text-capitalize mb-0 font-weight-bold dashboard-calendar-hub__title">{LBL_CALENDAR_TITLE}</h6>
                                        <p class="text-sm mb-0 text-muted">{LBL_CALENDAR_SUB}</p>
                                        <div class="dashboard-legend-pills mt-2">
                                            <span class="dashboard-legend-pill dashboard-legend-pill--completed"><i></i> {LBL_AVAILABLE}</span>
                                            <span class="dashboard-legend-pill dashboard-legend-pill--cancelled"><i></i> {LBL_UNAVAILABLE}</span>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm bg-gradient-primary mb-0" id="addSlotBtn">
                                        <i class="fas fa-plus me-1"></i>{LBL_ADD_SLOT}
                                    </button>
                                </div>
                            </div>
                            <div class="la-avail-search-wrap la-avail-search-wrap--featured">
                                <label class="la-avail-search-label" for="laAvailSearchInput">{LBL_SEARCH_AVAIL}</label>
                                <div class="la-avail-search-field">
                                    <span class="la-avail-search-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
                                            <circle cx="11" cy="11" r="7"></circle>
                                            <path d="M20 20l-3-3"></path>
                                        </svg>
                                    </span>
                                    <input type="search" id="laAvailSearchInput" class="la-avail-search-input"
                                           placeholder="{PH_SEARCH_AVAIL}" autocomplete="off">
                                    <button type="button" class="lp-lawyer-search-reset-btn" data-lawyer-search-reset="laAvailSearchInput" aria-label="{LBL_RESET_SEARCH}">{LBL_RESET}</button>
                                </div>
                            </div>
                        </div>
                        <div class="dashboard-calendar-hub__body">
                            <div class="availability-view-toggle" role="tablist" aria-label="Calendar view">
                                <button type="button" class="availability-view-toggle__btn is-active" data-availability-view="week" role="tab" aria-selected="true">{LBL_WEEK}</button>
                                <button type="button" class="availability-view-toggle__btn" data-availability-view="month" role="tab" aria-selected="false">{LBL_MONTH}</button>
                            </div>
                            <div id="availabilityWeekPanel">
                                <div class="availability-week-nav">
                                    <button type="button" class="btn btn-primary btn-sm mb-0 text-white" id="prevWeekBtn">{LBL_PREV_WEEK}</button>
                                    <span class="availability-fallback-week-label mb-0" id="weekRangeLabel"></span>
                                    <button type="button" class="btn btn-primary btn-sm mb-0 text-white" id="nextWeekBtn">{LBL_NEXT_WEEK}</button>
                                </div>
                                <div id="availabilityWeekCalendar"></div>
                            </div>
                            <div id="availabilityMonthPanel" class="availability-month-panel" hidden>
                                <div id="availabilityCalendar"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="availabilityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="availabilityModalTitle">{LBL_ADD_SLOT}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="save_slot" value="1">
                        <input type="hidden" name="slot_id" id="slot_id" value="">
                        <input type="hidden" name="day_of_week" id="day_of_week" value="">
                        <div class="mb-3">
                            <label class="form-control-label">{LBL_DATE}</label>
                            <input type="date" class="form-control" name="slot_date" id="slot_date" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-control-label">{LBL_START_TIME}</label>
                                <select class="form-control form-select" name="start_time" id="start_time" required>
                                    {AVAILABILITY_TIME_OPTIONS}
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-control-label">{LBL_END_TIME}</label>
                                <select class="form-control form-select" name="end_time" id="end_time" required>
                                    {AVAILABILITY_TIME_OPTIONS}
                                </select>
                            </div>
                        </div>
                        <div class="mb-0">
                            <label class="form-control-label">{LBL_STATUS}</label>
                            <select class="form-control" name="slot_type" id="slot_type" required>
                                <option value="available">{OPT_AVAILABLE}</option>
                                <option value="unavailable">{OPT_UNAVAILABLE}</option>
                            </select>
                            <small class="text-muted d-block mt-2">{LBL_STATUS_HELP}</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{LBL_CANCEL}</button>
                        <button type="submit" class="btn btn-primary" id="availabilitySaveButton">{LBL_SAVE_SLOT}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" action="" id="deleteSlotForm" class="d-none">
        <input type="hidden" name="delete_slot" value="1">
        <input type="hidden" name="slot_id" id="delete_slot_id" value="">
    </form>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    {FC_LOCALE_SCRIPT}
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        var availI18n = {AVAIL_I18N_JSON};
        var availabilityEvents = {AVAILABILITY_EVENTS_JSON};
        var availabilitySearchQuery = '';
        var availabilityCalendarInstance = null;
        var availabilityActiveView = 'week';
        var fallbackWeekStartIso = null;
        var availabilityMonthInitialized = false;

        function eventMatchesAvailabilitySearch(event, query) {
            if (!query) {
                return true;
            }
            var props = event.extendedProps || {};
            var haystack = [
                event.title || '',
                props.day || '',
                props.slotDate || '',
                props.startTime || '',
                props.endTime || '',
                props.slotType || '',
                props.isAppointment ? 'appointment unavailable' : ''
            ].join(' ').toLowerCase();
            return haystack.indexOf(query) !== -1;
        }

        function applyAvailabilityPageSearch(query) {
            availabilitySearchQuery = String(query || '').trim().toLowerCase();
            if (availabilityActiveView === 'week') {
                renderAvailabilityWeekCalendar();
            } else {
                refreshAvailabilityMonthEvents();
            }
        }

        function getFilteredAvailabilityEvents() {
            return availabilityEvents.filter(function (event) {
                return eventMatchesAvailabilitySearch(event, availabilitySearchQuery);
            });
        }

        function refreshAvailabilityMonthEvents() {
            if (!availabilityCalendarInstance) {
                return;
            }
            availabilityCalendarInstance.removeAllEvents();
            getFilteredAvailabilityEvents().forEach(function (event) {
                availabilityCalendarInstance.addEvent(event);
            });
        }

        function parseIsoDate(iso) {
            var parts = iso.split('-').map(Number);
            return new Date(parts[0], parts[1] - 1, parts[2]);
        }

        function dayNameFromDate(date) {
            return ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][date.getDay()];
        }

        function getWeekStart(date) {
            var d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
            d.setDate(d.getDate() - d.getDay());
            return d;
        }

        function dateToInputValue(date) {
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        function addDaysToIso(iso, days) {
            var date = parseIsoDate(iso);
            date.setDate(date.getDate() + days);
            return dateToInputValue(date);
        }

        function formatWeekRangeLabel(weekStartIso) {
            var weekStart = parseIsoDate(weekStartIso);
            var weekEnd = parseIsoDate(addDaysToIso(weekStartIso, 6));
            var locale = availI18n.dateLocale || 'en-US';
            var startStr = weekStart.toLocaleDateString(locale, { month: 'short', day: 'numeric' });
            var endStr = weekEnd.toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric' });
            return startStr + ' – ' + endStr;
        }

        function getCurrentWeekStartIso() {
            if (!fallbackWeekStartIso) {
                fallbackWeekStartIso = dateToInputValue(getWeekStart(new Date()));
            }
            return fallbackWeekStartIso;
        }

        function shiftFallbackWeek(deltaWeeks) {
            fallbackWeekStartIso = addDaysToIso(getCurrentWeekStartIso(), deltaWeeks * 7);
            renderAvailabilityWeekCalendar();
        }

        function formatAvailabilityTimeLabel(timeValue) {
            if (!timeValue) {
                return '';
            }
            var parts = String(timeValue).split(':');
            var hours = parseInt(parts[0], 10);
            var minutes = parts[1] || '00';
            if (availI18n.use24h) {
                return String(hours).padStart(2, '0') + ':' + minutes;
            }
            var period = hours >= 12 ? 'PM' : 'AM';
            var displayHours = hours % 12;
            if (displayHours === 0) {
                displayHours = 12;
            }
            return displayHours + ':' + minutes + ' ' + period;
        }

        function ensureAvailabilityTimeOption(selectEl, timeValue) {
            if (!timeValue || !selectEl || selectEl.querySelector('option[value="' + timeValue + '"]')) {
                return;
            }
            var option = document.createElement('option');
            option.value = timeValue;
            option.textContent = formatAvailabilityTimeLabel(timeValue);
            selectEl.appendChild(option);
        }

        function refreshAvailabilityEndTimeOptions(preservedEnd) {
            var startSelect = document.getElementById('start_time');
            var endSelect = document.getElementById('end_time');
            if (!startSelect || !endSelect) {
                return;
            }

            var startVal = startSelect.value;
            var firstValid = '';

            endSelect.querySelectorAll('option').forEach(function(option) {
                if (!option.value) {
                    option.disabled = false;
                    return;
                }
                var disabled = startVal !== '' && option.value <= startVal;
                option.disabled = disabled;
                if (!disabled && firstValid === '') {
                    firstValid = option.value;
                }
            });

            if (preservedEnd) {
                var match = endSelect.querySelector('option[value="' + preservedEnd + '"]');
                if (match && !match.disabled) {
                    endSelect.value = preservedEnd;
                    return;
                }
            }

            if (!endSelect.value || endSelect.options[endSelect.selectedIndex].disabled) {
                endSelect.value = firstValid || '';
            }
        }

        function openAvailabilityModal(day, slotId, slotDate, startTime, endTime, slotType) {
            document.getElementById('availabilityModalTitle').textContent = slotId ? availI18n.editSlot : availI18n.addSlot;
            document.getElementById('availabilitySaveButton').textContent = slotId ? availI18n.updateSlot : availI18n.saveSlot;
            document.getElementById('slot_id').value = slotId || '';
            document.getElementById('slot_date').value = slotDate || '';
            var resolvedDay = day || (slotDate ? dayNameFromDate(parseIsoDate(slotDate)) : '');
            document.getElementById('day_of_week').value = resolvedDay;
            var startSelect = document.getElementById('start_time');
            var endSelect = document.getElementById('end_time');
            ensureAvailabilityTimeOption(startSelect, startTime || '');
            ensureAvailabilityTimeOption(endSelect, endTime || '');
            startSelect.value = startTime || '';
            refreshAvailabilityEndTimeOptions(endTime || '');
            document.getElementById('slot_type').value = slotType || 'unavailable';
            new bootstrap.Modal(document.getElementById('availabilityModal')).show();
        }

        function openAvailabilityModalFromDate(dateStr) {
            openAvailabilityModal(dayNameFromDate(parseIsoDate(dateStr)), '', dateStr);
        }

        function deleteAvailabilitySlot(slotId, isAppointment) {
            if (!slotId) {
                return;
            }
            var confirmMessage = isAppointment
                ? availI18n.confirmDeleteAppt
                : availI18n.confirmDeleteSlot;
            if (!confirm(confirmMessage)) {
                return;
            }
            document.getElementById('delete_slot_id').value = slotId;
            document.getElementById('deleteSlotForm').submit();
        }

        function escapeHtmlAvailability(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function availabilityStatusKey(props) {
            if (props.isAppointment) {
                return 'unavailable';
            }
            return props.slotType === 'available' ? 'available' : 'unavailable';
        }

        function renderAvailabilityMonthEvent(arg) {
            var props = arg.event.extendedProps || {};
            var statusKey = props.statusKey || availabilityStatusKey(props);
            var label = props.shortLabel || arg.timeText || arg.event.title || availI18n.slotLabel;
            var wrap = document.createElement('div');
            wrap.className = 'dashboard-cal-event availability-cal-event';
            wrap.innerHTML =
                '<span class="dashboard-cal-event__dot dashboard-cal-event__dot--' + escapeHtmlAvailability(statusKey) + '" aria-hidden="true"></span>' +
                '<span class="dashboard-cal-event__text availability-cal-event__text availability-cal-event__text--' + escapeHtmlAvailability(statusKey) + '">' +
                    escapeHtmlAvailability(label) +
                '</span>';
            return { domNodes: [wrap] };
        }

        function initAvailabilityMonthCalendar() {
            if (availabilityMonthInitialized) {
                refreshAvailabilityMonthEvents();
                return;
            }

            var calendarEl = document.getElementById('availabilityCalendar');
            if (!calendarEl || typeof FullCalendar === 'undefined') {
                return;
            }

            availabilityCalendarInstance = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: availI18n.fcLocale,
                height: 'auto',
                firstDay: 0,
                navLinks: true,
                nowIndicator: true,
                fixedWeekCount: false,
                dayMaxEvents: 4,
                moreLinkClick: 'popover',
                eventTimeFormat: availI18n.use24h
                    ? { hour: '2-digit', minute: '2-digit', hour12: false }
                    : { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
                displayEventTime: true,
                displayEventEnd: false,
                buttonText: availI18n.fcButtons,
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: ''
                },
                events: getFilteredAvailabilityEvents(),
                eventContent: renderAvailabilityMonthEvent,
                dateClick: function (info) {
                    openAvailabilityModalFromDate(info.dateStr);
                },
                eventClick: function (info) {
                    info.jsEvent.preventDefault();
                    var props = info.event.extendedProps || {};
                    var slotId = props.slotId || info.event.id;
                    if (props.readOnly) {
                        if (slotId && confirm(availI18n.confirmRemoveApptBlock)) {
                            deleteAvailabilitySlot(slotId, true);
                        }
                        return;
                    }
                    openAvailabilityModal(
                        props.day,
                        slotId,
                        props.slotDate,
                        props.startTime,
                        props.endTime,
                        props.slotType
                    );
                },
                dayCellDidMount: function (info) {
                    if (window.legalproMountCalendarDayCell) {
                        window.legalproMountCalendarDayCell(info);
                    }
                },
                eventDidMount: function (info) {
                    var props = info.event.extendedProps || {};
                    var tip = info.event.title || props.timeLabel || '';
                    if (tip) {
                        info.el.setAttribute('title', tip);
                    }
                    if (window.legalproMountCalendarEventClickable) {
                        window.legalproMountCalendarEventClickable(info);
                    }
                }
            });

            availabilityCalendarInstance.render();
            availabilityMonthInitialized = true;
        }

        function renderAvailabilityWeekCalendar() {
            var calendarEl = document.getElementById('availabilityWeekCalendar');
            var weekLabelEl = document.getElementById('weekRangeLabel');
            if (!calendarEl) {
                return;
            }

            var weekStartIso = getCurrentWeekStartIso();
            if (weekLabelEl) {
                weekLabelEl.textContent = formatWeekRangeLabel(weekStartIso);
            }

            var dayNames = availI18n.days || ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var visibleDays = [];
            var filteredEvents = getFilteredAvailabilityEvents();

            dayNames.forEach(function (dayName, dayIndex) {
                var dayDateIso = addDaysToIso(weekStartIso, dayIndex);
                var dayKey = dayNameFromDate(parseIsoDate(dayDateIso));
                var eventsForDay = filteredEvents.filter(function (event) {
                    var props = event.extendedProps || {};
                    var eventDate = props.slotDate || (event.start ? event.start.split('T')[0] : '');
                    return eventDate === dayDateIso;
                });
                var dayHaystack = (dayName + ' ' + dayKey + ' ' + dayDateIso).toLowerCase();
                var dayMatches = !availabilitySearchQuery
                    || dayHaystack.indexOf(availabilitySearchQuery) !== -1
                    || eventsForDay.length > 0;
                if (!dayMatches) {
                    return;
                }
                visibleDays.push({
                    dayName: dayName,
                    dayDateIso: dayDateIso,
                    dayKey: dayKey,
                    dayHaystack: dayHaystack,
                    events: eventsForDay
                });
            });

            if (availabilitySearchQuery && visibleDays.length === 0) {
                calendarEl.innerHTML = '<div class="text-center text-muted py-4">' + escapeHtmlAvailability(availI18n.noMatchSearch) + '</div>';
                return;
            }

            if (!availabilitySearchQuery) {
                visibleDays = dayNames.map(function (dayName, dayIndex) {
                    var dayDateIso = addDaysToIso(weekStartIso, dayIndex);
                    var dayKey = dayNameFromDate(parseIsoDate(dayDateIso));
                    var eventsForDay = filteredEvents.filter(function (event) {
                        var props = event.extendedProps || {};
                        var eventDate = props.slotDate || (event.start ? event.start.split('T')[0] : '');
                        return eventDate === dayDateIso;
                    });
                    return {
                        dayName: dayName,
                        dayDateIso: dayDateIso,
                        dayKey: dayKey,
                        dayHaystack: (dayName + ' ' + dayKey + ' ' + dayDateIso).toLowerCase(),
                        events: eventsForDay
                    };
                });
            }

            var columnCount = visibleDays.length;
            var gridStyle = 'grid-template-columns:repeat(' + columnCount + ',minmax(120px,1fr));';
            var html = '<div class="availability-fallback-calendar">';
            html += '<div class="availability-fallback-header" style="' + gridStyle + '">';

            visibleDays.forEach(function (day) {
                var dayDate = parseIsoDate(day.dayDateIso);
                var dateLabel = dayDate.toLocaleDateString(availI18n.dateLocale || 'en-US', { month: 'short', day: 'numeric' });
                html += '<div><span class="availability-fallback-day-name">' + day.dayName + '</span>';
                html += '<span class="availability-fallback-day-date">' + dateLabel + '</span></div>';
            });

            html += '</div><div class="availability-fallback-grid" style="' + gridStyle + '">';
            visibleDays.forEach(function (day) {
                html += '<div class="availability-fallback-day" data-date="' + day.dayDateIso + '" data-search="' + day.dayHaystack + '">';
                if (day.events.length === 0) {
                    html += '<p class="text-sm text-muted mb-0">' + escapeHtmlAvailability(availI18n.noSlots) + '</p>';
                } else {
                    day.events.forEach(function (event) {
                        var props = event.extendedProps || {};
                        var slotId = props.slotId || event.id || '';
                        var bgColor = props.slotType === 'available' ? '#2dce89' : '#f5365c';
                        html += '<div class="availability-fallback-event" style="background-color:' + bgColor + '"';
                        html += ' data-day="' + (props.day || '') + '"';
                        html += ' data-slot-id="' + slotId + '"';
                        html += ' data-slot-date="' + (props.slotDate || '') + '"';
                        html += ' data-start-time="' + (props.startTime || '') + '"';
                        html += ' data-end-time="' + (props.endTime || '') + '"';
                        html += ' data-slot-type="' + (props.slotType || 'available') + '"';
                        html += ' data-read-only="' + (props.readOnly ? '1' : '0') + '"';
                        html += '><span class="availability-fallback-event-label">' + event.title + '</span>';
                        if (slotId) {
                            html += '<button type="button" class="availability-fallback-event-delete" data-slot-id="' + slotId + '"';
                            if (props.isAppointment) {
                                html += ' data-is-appointment="1"';
                            }
                            html += ' title="' + escapeHtmlAvailability(availI18n.deleteSlot) + '" aria-label="' + escapeHtmlAvailability(availI18n.deleteSlot) + '">&times;</button>';
                        }
                        html += '</div>';
                    });
                }
                html += '</div>';
            });
            html += '</div></div>';
            calendarEl.innerHTML = html;
        }

        function setAvailabilityView(view) {
            availabilityActiveView = view === 'month' ? 'month' : 'week';
            var weekPanel = document.getElementById('availabilityWeekPanel');
            var monthPanel = document.getElementById('availabilityMonthPanel');
            if (weekPanel) {
                weekPanel.hidden = availabilityActiveView !== 'week';
            }
            if (monthPanel) {
                monthPanel.hidden = availabilityActiveView !== 'month';
            }

            document.querySelectorAll('[data-availability-view]').forEach(function (btn) {
                var isActive = btn.getAttribute('data-availability-view') === availabilityActiveView;
                btn.classList.toggle('is-active', isActive);
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            if (availabilityActiveView === 'week') {
                renderAvailabilityWeekCalendar();
            } else {
                initAvailabilityMonthCalendar();
                if (availabilityCalendarInstance) {
                    availabilityCalendarInstance.updateSize();
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var slotDateInput = document.getElementById('slot_date');
            if (slotDateInput) {
                slotDateInput.addEventListener('change', function() {
                    if (this.value) {
                        document.getElementById('day_of_week').value = dayNameFromDate(parseIsoDate(this.value));
                    }
                });
            }

            document.getElementById('prevWeekBtn').addEventListener('click', function() {
                shiftFallbackWeek(-1);
            });
            document.getElementById('nextWeekBtn').addEventListener('click', function() {
                shiftFallbackWeek(1);
            });
            document.getElementById('start_time').addEventListener('change', function() {
                refreshAvailabilityEndTimeOptions('');
            });

            document.getElementById('addSlotBtn').addEventListener('click', function() {
                openAvailabilityModal();
            });

            document.getElementById('availabilityWeekCalendar').addEventListener('click', function(event) {
                var deleteBtn = event.target.closest('.availability-fallback-event-delete');
                if (deleteBtn) {
                    event.stopPropagation();
                    deleteAvailabilitySlot(
                        deleteBtn.getAttribute('data-slot-id'),
                        deleteBtn.getAttribute('data-is-appointment') === '1'
                    );
                    return;
                }

                var eventEl = event.target.closest('.availability-fallback-event');
                if (eventEl) {
                    event.stopPropagation();
                    if (eventEl.getAttribute('data-read-only') === '1') {
                        return;
                    }
                    openAvailabilityModal(
                        eventEl.getAttribute('data-day'),
                        eventEl.getAttribute('data-slot-id'),
                        eventEl.getAttribute('data-slot-date'),
                        eventEl.getAttribute('data-start-time'),
                        eventEl.getAttribute('data-end-time'),
                        eventEl.getAttribute('data-slot-type')
                    );
                    return;
                }

                var dayEl = event.target.closest('.availability-fallback-day');
                if (dayEl && dayEl.getAttribute('data-date')) {
                    openAvailabilityModalFromDate(dayEl.getAttribute('data-date'));
                }
            });

            document.querySelectorAll('[data-availability-view]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setAvailabilityView(btn.getAttribute('data-availability-view'));
                });
            });

            var searchInput = document.getElementById('laAvailSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    applyAvailabilityPageSearch(searchInput.value);
                });
            }

            setAvailabilityView('week');
            applyAvailabilityPageSearch('');
        });
    </script>
</body>
</html>
HTML;

$lawyerName = isset($_SESSION['lawyer_name']) ? $_SESSION['lawyer_name'] : 'Lawyer';

$html = str_replace('{$message}', $messageHtml, $html);
$html = str_replace('{NAVIGATION}', $navHtml, $html);
$html = str_replace('{HTML_LANG}', lawyer_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars($pageTitle), $html);
$html = str_replace('{BREADCRUMB_NAVBAR}', $breadcrumbNavbar, $html);
$html = str_replace('{LBL_CALENDAR_TITLE}', htmlspecialchars(lawyer_tf('availability.calendar_title', 'Availability Calendar')), $html);
$html = str_replace('{LBL_CALENDAR_SUB}', htmlspecialchars(lawyer_tf('availability.calendar_sub', 'Week view shows your detailed schedule; switch to month for the full calendar overview')), $html);
$html = str_replace('{LBL_AVAILABLE}', htmlspecialchars(lawyer_tf('availability.legend_available', 'Available')), $html);
$html = str_replace('{LBL_UNAVAILABLE}', htmlspecialchars(lawyer_tf('availability.legend_unavailable', 'Unavailable')), $html);
$html = str_replace('{LBL_ADD_SLOT}', htmlspecialchars(lawyer_tf('availability.add_slot', 'Add Time Slot')), $html);
$html = str_replace('{LBL_SEARCH_AVAIL}', htmlspecialchars(lawyer_tf('availability.search_label', 'Search availability')), $html);
$html = str_replace('{PH_SEARCH_AVAIL}', htmlspecialchars(lawyer_tf('availability.search_placeholder', 'Search by day, date, time, available, unavailable…')), $html);
$html = str_replace('{LBL_RESET}', htmlspecialchars(lawyer_tf('calendar.reset', 'Reset')), $html);
$html = str_replace('{LBL_RESET_SEARCH}', htmlspecialchars(lawyer_tf('calendar.reset_search', 'Reset search')), $html);
$html = str_replace('{LBL_WEEK}', htmlspecialchars(lawyer_tf('availability.week', 'Week')), $html);
$html = str_replace('{LBL_MONTH}', htmlspecialchars(lawyer_tf('availability.month', 'Month')), $html);
$html = str_replace('{LBL_PREV_WEEK}', htmlspecialchars(lawyer_tf('availability.prev_week', 'Previous Week')), $html);
$html = str_replace('{LBL_NEXT_WEEK}', htmlspecialchars(lawyer_tf('availability.next_week', 'Next Week')), $html);
$html = str_replace('{LBL_DATE}', htmlspecialchars(lawyer_tf('availability.date', 'Date')), $html);
$html = str_replace('{LBL_START_TIME}', htmlspecialchars(lawyer_tf('availability.start_time', 'Start Time')), $html);
$html = str_replace('{LBL_END_TIME}', htmlspecialchars(lawyer_tf('availability.end_time', 'End Time')), $html);
$html = str_replace('{LBL_STATUS}', htmlspecialchars(lawyer_tf('availability.status_label', 'Availability Status')), $html);
$html = str_replace('{OPT_AVAILABLE}', htmlspecialchars(lawyer_tf('availability.opt_available', 'Available for appointments')), $html);
$html = str_replace('{OPT_UNAVAILABLE}', htmlspecialchars(lawyer_tf('availability.opt_unavailable', 'Unavailable / break')), $html);
$html = str_replace('{LBL_STATUS_HELP}', htmlspecialchars(lawyer_tf('availability.status_help', 'Use Available to open a time range for appointments, or Unavailable for breaks and blocked time.')), $html);
$html = str_replace('{LBL_CANCEL}', htmlspecialchars(lawyer_tf('common.cancel', 'Cancel')), $html);
$html = str_replace('{LBL_SAVE_SLOT}', htmlspecialchars(lawyer_tf('availability.save_slot', 'Save Slot')), $html);
$html = str_replace('{AVAIL_I18N_JSON}', $availI18nJson, $html);
$html = str_replace('{FC_LOCALE_SCRIPT}', lawyer_portal_fc_locale_script(), $html);
$html = str_replace('{$lawyerName}', htmlspecialchars($lawyerName), $html);
$html = str_replace('{AVAILABILITY_EVENTS_JSON}', json_encode($availabilityEvents), $html);
$html = str_replace('{AVAILABILITY_TIME_OPTIONS}', $availabilityTimeOptions, $html);
$html = str_replace('{PORTAL_THEME_BODY_CLASS}', legalpro_portal_theme_body_class(), $html);

echo $html;
?>
