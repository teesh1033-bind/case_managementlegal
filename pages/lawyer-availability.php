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

$workingHours = getLawyerWorkingHours($pdo, $lawyerId);

// Handle time slot management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_working_hours'])) {
        $postedDays = [];
        foreach ($daysOfWeek as $day) {
            $postedDays[$day] = [
                'enabled' => isset($_POST['wh_enabled'][$day]),
                'start' => isset($_POST['wh_start'][$day]) ? trim((string) $_POST['wh_start'][$day]) : '09:00',
                'end' => isset($_POST['wh_end'][$day]) ? trim((string) $_POST['wh_end'][$day]) : '17:00',
            ];
        }

        try {
            saveLawyerWorkingHours($pdo, $lawyerId, $postedDays);
            $workingHours = getLawyerWorkingHours($pdo, $lawyerId);
            $message = 'Working hours saved successfully!';
            $messageType = 'success';
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        } catch (PDOException $e) {
            $message = 'Error saving working hours: ' . $e->getMessage();
            $messageType = 'danger';
        }
    } elseif (isset($_POST['save_slot']) || isset($_POST['add_slot'])) {
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
        } elseif (!isSlotWithinWorkingHours($workingHours, $dayOfWeek, $startTime, $endTime)) {
            $daySchedule = $workingHours[$dayOfWeek] ?? null;
            if (!$daySchedule || empty($daySchedule['enabled'])) {
                $message = 'You are not working on this day. Enable the day in your working hours first.';
            } else {
                $message = 'Time slots must stay within your working hours for this day.';
            }
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
    $timeLabel = date('g:i A', strtotime($slot['start_time'])) . ' - ' . date('g:i A', strtotime($slot['end_time']));
    $title = $isAppointment
        ? 'Unavailable - Appointment ' . $timeLabel
        : ucfirst($slotType) . ' - ' . $timeLabel;
    $availabilityEvents[] = [
        'id' => (string)$slot['id'],
        'title' => $title,
        'start' => $slotDate . 'T' . $startTime . ':00',
        'end' => $slotDate . 'T' . $endTime . ':00',
        'backgroundColor' => $slotType === 'available' ? '#2dce89' : '#f5365c',
        'borderColor' => $slotType === 'available' ? '#2dce89' : '#f5365c',
        'textColor' => '#ffffff',
        'extendedProps' => [
            'slotId' => (int)$slot['id'],
            'day' => $slot['day_of_week'],
            'slotDate' => $slotDate,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'slotType' => $slotType,
            'isAppointment' => $isAppointment,
            'appointmentId' => $appointmentId,
            'readOnly' => $isAppointment
        ]
    ];
}

function buildAvailabilityTimeSelectOptions(string $minTime = '06:00', string $maxTime = '22:00'): string
{
    $html = '<option value="">Select time</option>';
    $startMinutes = (int) substr($minTime, 0, 2) * 60 + (int) substr($minTime, 3, 2);
    $endMinutes = (int) substr($maxTime, 0, 2) * 60 + (int) substr($maxTime, 3, 2);
    $step = 30;

    for ($minutes = $startMinutes; $minutes <= $endMinutes; $minutes += $step) {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $value = sprintf('%02d:%02d', $hours, $mins);
        $label = date('g:i A', strtotime($value));
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label) . '</option>';
    }

    return $html;
}

function buildWorkingHoursRowHtml(string $day, array $schedule): string
{
    $label = ucfirst($day);
    $enabled = !empty($schedule['enabled']);
    $start = substr((string) ($schedule['start'] ?? '09:00:00'), 0, 5);
    $end = substr((string) ($schedule['end'] ?? '17:00:00'), 0, 5);
    $timeOptions = buildAvailabilityTimeSelectOptions();
    $startOptions = str_replace('value="' . htmlspecialchars($start, ENT_QUOTES, 'UTF-8') . '"', 'value="' . htmlspecialchars($start, ENT_QUOTES, 'UTF-8') . '" selected', $timeOptions);
    $endOptions = str_replace('value="' . htmlspecialchars($end, ENT_QUOTES, 'UTF-8') . '"', 'value="' . htmlspecialchars($end, ENT_QUOTES, 'UTF-8') . '" selected', $timeOptions);

    return '<tr>
        <td class="text-sm font-weight-bold text-capitalize">' . htmlspecialchars($label) . '</td>
        <td class="text-center">
            <label class="wh-day-switch" for="wh_enabled_' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . '" aria-label="Working on ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">
                <input class="wh-day-toggle" type="checkbox" name="wh_enabled[' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . ']" id="wh_enabled_' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . '" value="1"' . ($enabled ? ' checked' : '') . '>
                <span class="wh-day-switch__track" aria-hidden="true"></span>
            </label>
        </td>
        <td>
            <select class="form-control form-select wh-start-select" name="wh_start[' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . ']" data-day="' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . '">' . $startOptions . '</select>
        </td>
        <td>
            <select class="form-control form-select wh-end-select" name="wh_end[' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . ']" data-day="' . htmlspecialchars($day, ENT_QUOTES, 'UTF-8') . '">' . $endOptions . '</select>
        </td>
    </tr>';
}

$availabilityTimeOptions = buildAvailabilityTimeSelectOptions();
$workingHoursRowsHtml = '';
foreach ($daysOfWeek as $day) {
    $workingHoursRowsHtml .= buildWorkingHoursRowHtml($day, $workingHours[$day] ?? getDefaultWorkingHoursSchedule()[$day]);
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
    <title>LegalPro - My Availability</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <link rel="stylesheet" href="../assets/css/simple-calendar.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.10.1/main.min.css" />
    <style>
        #availabilityCalendar {
            min-height: 0;
        }
        .fc-event {
            cursor: pointer;
            font-weight: 700;
            border-radius: 0.45rem;
            padding: 2px 4px;
        }
        .fc .fc-prev-button,
        .fc .fc-next-button {
            background: #ffffff !important;
            border-color: #ffffff !important;
            color: #344767 !important;
        }
        .fc .fc-prev-button:hover,
        .fc .fc-next-button:hover,
        .fc .fc-prev-button:focus,
        .fc .fc-next-button:focus {
            background: #f8f9fa !important;
            border-color: #f8f9fa !important;
            color: #1f2b4d !important;
            box-shadow: none !important;
        }
        .fc .fc-prev-button .fc-icon,
        .fc .fc-next-button .fc-icon {
            color: #344767 !important;
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
        .working-hours-table th,
        .working-hours-table td {
            vertical-align: middle;
        }
        .wh-day-switch {
            align-items: center;
            cursor: pointer;
            display: inline-flex;
            margin: 0;
            position: relative;
        }
        .wh-day-switch input {
            height: 0;
            opacity: 0;
            position: absolute;
            width: 0;
        }
        .wh-day-switch__track {
            background: #cbd5e1;
            border-radius: 999px;
            display: inline-block;
            height: 24px;
            position: relative;
            transition: background-color 0.2s ease;
            width: 44px;
        }
        .wh-day-switch__track::after {
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.22);
            content: '';
            height: 18px;
            left: 3px;
            position: absolute;
            top: 3px;
            transition: transform 0.2s ease;
            width: 18px;
        }
        .wh-day-switch input:checked + .wh-day-switch__track {
            background: #22c55e;
        }
        .wh-day-switch input:checked + .wh-day-switch__track::after {
            transform: translateX(20px);
        }
        .wh-day-switch input:focus-visible + .wh-day-switch__track {
            outline: 2px solid rgba(34, 197, 94, 0.45);
            outline-offset: 2px;
        }
        .working-hours-band {
            background: #eef2ff;
            border: 1px dashed #5e72e4;
            border-radius: 0.5rem;
            color: #344767;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.65rem;
            padding: 0.35rem 0.5rem;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-availability-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Availability</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white">Manage My Availability</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <ul class="navbar-nav justify-content-end">
                        <!-- <li class="nav-item d-flex align-items-center">
                            <span class="text-sm text-white">
                                <i class="fa fa-user me-sm-1"></i>
                                Lawyer Portal
                            </span> 
                        </li> -->
                    </ul>
                </div>
            </div>
        </nav>
        <!-- End Navbar -->
        <div class="container-fluid py-4">
            {$message}

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Normal Working Hours</h6>
                            <p class="text-sm text-muted mb-0">Set your standard schedule (for example 9:00 AM to 5:00 PM). Time slots must stay inside these hours.</p>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="save_working_hours" value="1">
                                <div class="table-responsive">
                                    <table class="table align-items-center mb-3 working-hours-table">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Day</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Working</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">From</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">To</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {WORKING_HOURS_ROWS}
                                        </tbody>
                                    </table>
                                </div>
                                <button type="submit" class="btn btn-primary mb-0">Save Working Hours</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Time Slots Within Working Hours</h6>
                            <p class="text-sm text-muted mb-0">For each date, mark when you are available or unavailable inside your working hours.</p>
                        </div>
                        <div class="card-body">
                            <div class="availability-hero mb-4">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                    <div>
                                        <h6 class="mb-1">Availability Calendar</h6>
                                        <p class="text-sm text-muted mb-0">Click a day to add a slot, or click an existing slot to edit it. Outside working hours is never bookable.</p>
                                    </div>
                                    <button type="button" class="btn btn-primary mb-0" id="addSlotBtn">Add Time Slot</button>
                                </div>
                            </div>
                            <div class="availability-week-nav">
                                <button type="button" class="btn btn-primary btn-sm mb-0 text-white" id="prevWeekBtn">Previous Week</button>
                                <span class="availability-fallback-week-label mb-0" id="weekRangeLabel"></span>
                                <button type="button" class="btn btn-primary btn-sm mb-0 text-white" id="nextWeekBtn">Next Week</button>
                            </div>
                            <div id="availabilityCalendar"></div>
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
                    <h5 class="modal-title" id="availabilityModalTitle">Add Time Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="save_slot" value="1">
                        <input type="hidden" name="slot_id" id="slot_id" value="">
                        <input type="hidden" name="day_of_week" id="day_of_week" value="">
                        <div class="mb-3">
                            <label class="form-control-label">Date</label>
                            <input type="date" class="form-control" name="slot_date" id="slot_date" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-control-label">Start Time</label>
                                <select class="form-control form-select" name="start_time" id="start_time" required>
                                    {AVAILABILITY_TIME_OPTIONS}
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-control-label">End Time</label>
                                <select class="form-control form-select" name="end_time" id="end_time" required>
                                    {AVAILABILITY_TIME_OPTIONS}
                                </select>
                            </div>
                        </div>
                        <div class="mb-0">
                            <label class="form-control-label">Availability Status</label>
                            <select class="form-control" name="slot_type" id="slot_type" required>
                                <option value="available">Available for appointments</option>
                                <option value="unavailable">Unavailable / break</option>
                            </select>
                            <small class="text-muted d-block mt-2">By default, your full working hours are open for booking. Use <strong>Unavailable</strong> for breaks, or <strong>Available</strong> to open only part of the day.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="availabilitySaveButton">Save Slot</button>
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
    <script src="../assets/js/fullcalendar/fallback.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
        var availabilityEvents = {AVAILABILITY_EVENTS_JSON};
        var workingHours = {WORKING_HOURS_JSON};
        var fallbackWeekStartIso = null;

        function parseIsoDate(iso) {
            var parts = iso.split('-').map(Number);
            return new Date(parts[0], parts[1] - 1, parts[2]);
        }

        function getWeekStart(date) {
            var d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
            d.setDate(d.getDate() - d.getDay());
            return d;
        }

        function dayNameFromDate(date) {
            return ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][date.getDay()];
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
            var startStr = weekStart.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            var endStr = weekEnd.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
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
            renderAvailabilityCalendar();
        }

        function formatAvailabilityTimeLabel(timeValue) {
            if (!timeValue) {
                return '';
            }
            var parts = String(timeValue).split(':');
            var hours = parseInt(parts[0], 10);
            var minutes = parts[1] || '00';
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

        function getWorkingHoursForDayName(dayName) {
            if (!dayName) {
                return null;
            }
            return workingHours[dayName] || null;
        }

        function formatWorkingHoursLabel(dayName) {
            var schedule = getWorkingHoursForDayName(dayName);
            if (!schedule || !schedule.enabled) {
                return 'Day off';
            }
            var start = (schedule.start || '09:00:00').slice(0, 5);
            var end = (schedule.end || '17:00:00').slice(0, 5);
            return formatAvailabilityTimeLabel(start) + ' - ' + formatAvailabilityTimeLabel(end);
        }

        function applyWorkingHoursToSlotTimeSelects(dayName) {
            var schedule = getWorkingHoursForDayName(dayName);
            var startSelect = document.getElementById('start_time');
            var endSelect = document.getElementById('end_time');
            if (!startSelect || !endSelect) {
                return;
            }

            var whStart = '06:00';
            var whEnd = '22:00';
            var enabled = true;
            if (schedule) {
                enabled = !!schedule.enabled;
                whStart = (schedule.start || '09:00:00').slice(0, 5);
                whEnd = (schedule.end || '17:00:00').slice(0, 5);
            }

            [startSelect, endSelect].forEach(function(selectEl) {
                selectEl.querySelectorAll('option').forEach(function(option) {
                    if (!option.value) {
                        option.disabled = false;
                        return;
                    }
                    option.disabled = !enabled || option.value < whStart || option.value > whEnd;
                });
            });

            if (!enabled) {
                startSelect.value = '';
                endSelect.value = '';
                return;
            }

            refreshAvailabilityEndTimeOptions(endSelect.value || '');
        }

        function openAvailabilityModal(day, slotId, slotDate, startTime, endTime, slotType) {
            document.getElementById('availabilityModalTitle').textContent = slotId ? 'Edit Time Slot' : 'Add Time Slot';
            document.getElementById('availabilitySaveButton').textContent = slotId ? 'Update Slot' : 'Save Slot';
            document.getElementById('slot_id').value = slotId || '';
            document.getElementById('slot_date').value = slotDate || '';
            var resolvedDay = day || (slotDate ? dayNameFromDate(parseIsoDate(slotDate)) : '');
            document.getElementById('day_of_week').value = resolvedDay;
            var startSelect = document.getElementById('start_time');
            var endSelect = document.getElementById('end_time');
            applyWorkingHoursToSlotTimeSelects(resolvedDay);
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
                ? 'Remove this appointment block? The linked appointment will be marked as rejected.'
                : 'Delete this time slot?';
            if (!confirm(confirmMessage)) {
                return;
            }
            document.getElementById('delete_slot_id').value = slotId;
            document.getElementById('deleteSlotForm').submit();
        }

        function renderAvailabilityCalendar() {
            var calendarEl = document.getElementById('availabilityCalendar');
            var weekLabelEl = document.getElementById('weekRangeLabel');
            if (!calendarEl) return;

            var weekStartIso = getCurrentWeekStartIso();
            if (weekLabelEl) {
                weekLabelEl.textContent = formatWeekRangeLabel(weekStartIso);
            }

            var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var html = '<div class="availability-fallback-calendar">';
            html += '<div class="availability-fallback-header">';

            dayNames.forEach(function(dayName, dayIndex) {
                var dayDateIso = addDaysToIso(weekStartIso, dayIndex);
                var dayDate = parseIsoDate(dayDateIso);
                var dateLabel = dayDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                html += '<div><span class="availability-fallback-day-name">' + dayName + '</span>';
                html += '<span class="availability-fallback-day-date">' + dateLabel + '</span></div>';
            });

            html += '</div><div class="availability-fallback-grid">';
            dayNames.forEach(function(dayName, dayIndex) {
                var dayDateIso = addDaysToIso(weekStartIso, dayIndex);
                var dayKey = dayNameFromDate(parseIsoDate(dayDateIso));
                html += '<div class="availability-fallback-day" data-date="' + dayDateIso + '">';
                html += '<div class="working-hours-band">Hours: ' + formatWorkingHoursLabel(dayKey) + '</div>';
                var eventsForDay = availabilityEvents.filter(function(event) {
                    var props = event.extendedProps || {};
                    var eventDate = props.slotDate || (event.start ? event.start.split('T')[0] : '');
                    return eventDate === dayDateIso;
                });
                if (eventsForDay.length === 0) {
                    html += '<p class="text-sm text-muted mb-0">No hours set</p>';
                } else {
                    eventsForDay.forEach(function(event) {
                        var props = event.extendedProps || {};
                        var slotId = props.slotId || event.id || '';
                        html += '<div class="availability-fallback-event" style="background-color:' + event.backgroundColor + '"';
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
                            html += ' title="Delete slot" aria-label="Delete slot">&times;</button>';
                        }
                        html += '</div>';
                    });
                }
                html += '</div>';
            });
            html += '</div></div>';
            calendarEl.innerHTML = html;
        }

        document.addEventListener('DOMContentLoaded', function() {
            var slotDateInput = document.getElementById('slot_date');
            if (slotDateInput) {
                slotDateInput.addEventListener('change', function() {
                    if (this.value) {
                        var day = dayNameFromDate(parseIsoDate(this.value));
                        document.getElementById('day_of_week').value = day;
                        applyWorkingHoursToSlotTimeSelects(day);
                    }
                });
            }

            document.querySelectorAll('.wh-start-select, .wh-end-select').forEach(function(selectEl) {
                selectEl.addEventListener('change', function() {
                    var day = this.getAttribute('data-day');
                    var row = this.closest('tr');
                    if (!row || !day) {
                        return;
                    }
                    var startSelect = row.querySelector('.wh-start-select');
                    var endSelect = row.querySelector('.wh-end-select');
                    if (!startSelect || !endSelect) {
                        return;
                    }
                    endSelect.querySelectorAll('option').forEach(function(option) {
                        if (!option.value) {
                            option.disabled = false;
                            return;
                        }
                        option.disabled = startSelect.value !== '' && option.value <= startSelect.value;
                    });
                    if (!endSelect.value || endSelect.options[endSelect.selectedIndex].disabled) {
                        var firstValid = '';
                        endSelect.querySelectorAll('option').forEach(function(option) {
                            if (!option.disabled && option.value && firstValid === '') {
                                firstValid = option.value;
                            }
                        });
                        endSelect.value = firstValid;
                    }
                });
            });

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

            document.getElementById('availabilityCalendar').addEventListener('click', function(event) {
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

            renderAvailabilityCalendar();
        });
    </script>
</body>
</html>
HTML;

$lawyerName = isset($_SESSION['lawyer_name']) ? $_SESSION['lawyer_name'] : 'Lawyer';

$html = str_replace('{$message}', $messageHtml, $html);
$html = str_replace('{NAVIGATION}', $navHtml, $html);
$html = str_replace('{$lawyerName}', htmlspecialchars($lawyerName), $html);
$html = str_replace('{AVAILABILITY_EVENTS_JSON}', json_encode($availabilityEvents), $html);
$html = str_replace('{WORKING_HOURS_JSON}', json_encode($workingHours), $html);
$html = str_replace('{WORKING_HOURS_ROWS}', $workingHoursRowsHtml, $html);
$html = str_replace('{AVAILABILITY_TIME_OPTIONS}', $availabilityTimeOptions, $html);

echo $html;
?>
