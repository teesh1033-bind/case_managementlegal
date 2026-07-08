<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/appointment_availability.php';
require_once __DIR__ . '/../lib/portal_list_ui.php';
require_once __DIR__ . '/../lib/appointment_list_ui.php';
require_once __DIR__ . '/../inc/portal-calendar-studio.php';

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

    $stmt = $pdo->prepare("
        SELECT
            lts.*,
            a.starts_at AS appointment_starts_at,
            a.ends_at AS appointment_ends_at,
            a.notes AS appointment_notes,
            c.id AS case_id,
            c.title AS case_title,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name
        FROM lawyer_time_slots lts
        LEFT JOIN appointments a ON a.id = lts.appointment_id
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN clients cl ON cl.id = c.client_id
        WHERE lts.lawyer_id = ?
        ORDER BY COALESCE(lts.slot_date, '9999-12-31'), lts.day_of_week, lts.slot_order, lts.start_time
    ");
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
            'status' => $isAppointment ? 'accepted' : ($slotType === 'available' ? 'pending' : 'rejected'),
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

$availabilitySlotsRows = '';
$datedSlots = array_values(array_filter($timeSlots, static function (array $slot): bool {
    return !empty($slot['slot_date']);
}));
$editBtnClass = legalpro_portal_accent_action_btn_class();
$deleteBtnClass = legalpro_portal_danger_action_btn_class();
foreach ($datedSlots as $slot) {
    $slotId = (int) ($slot['id'] ?? 0);
    $dayOfWeek = (string) ($slot['day_of_week'] ?? '');
    $appointmentId = (int) ($slot['appointment_id'] ?? 0);
    $isAppointment = $appointmentId > 0;
    $slotType = (string) ($slot['slot_type'] ?? 'available');
    $slotDate = (string) ($slot['slot_date'] ?? '');
    $startTimeShort = substr((string) ($slot['start_time'] ?? ''), 0, 5);
    $endTimeShort = substr((string) ($slot['end_time'] ?? ''), 0, 5);
    $editBtn = $isAppointment
        ? ''
        : '<button type="button" class="' . $editBtnClass . ' mb-0" onclick="openAvailabilityModal(\''
            . htmlspecialchars($dayOfWeek, ENT_QUOTES, 'UTF-8') . '\', '
            . $slotId . ', \''
            . htmlspecialchars($slotDate, ENT_QUOTES, 'UTF-8') . '\', \''
            . htmlspecialchars($startTimeShort, ENT_QUOTES, 'UTF-8') . '\', \''
            . htmlspecialchars($endTimeShort, ENT_QUOTES, 'UTF-8') . '\', \''
            . htmlspecialchars($slotType, ENT_QUOTES, 'UTF-8') . '\')" title="'
            . htmlspecialchars(lawyer_tf('availability.edit_slot', 'Edit Time Slot'), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(lawyer_tf('common.edit', 'Edit')) . '</button>';
    $deleteBtn = '<button type="button" class="' . $deleteBtnClass . ' mb-0" onclick="deleteAvailabilitySlot('
        . $slotId . ', ' . ($isAppointment ? 'true' : 'false') . ')" title="'
        . htmlspecialchars(lawyer_tf('availability.delete_slot', 'Delete slot'), ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(lawyer_tf('common.delete', 'Delete')) . '</button>';
    $actionsHtml = $editBtn . $deleteBtn;
    $availabilitySlotsRows .= legalpro_render_portal_availability_appointment_table_row($slot, $actionsHtml);
}

$slotsListTitle = lawyer_tf('availability.slots_list_title', 'Availability Slots');
$slotsListSubtitle = count($datedSlots) . ' ' . lawyer_tf('availability.slot_label', 'slot') . (count($datedSlots) === 1 ? '' : 's');
$availabilitySlotsListSection = legalpro_render_portal_schedule_hub_list_section(
    'availabilitySlotsTable',
    $slotsListTitle,
    $slotsListSubtitle,
    'availabilitySlotsSearchInput',
    lawyer_tf('availability.search_placeholder', 'Search by day, date, time, available, unavailable…'),
    lawyer_tf('availability.search_label', 'Search availability'),
    'availabilitySlotsStatusFilter',
    legalpro_portal_appointment_status_options(),
    'availabilitySlotsTableBody',
    $availabilitySlotsRows,
    'availabilitySlotsFilterEmpty',
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
        'all_statuses_label' => lawyer_tf('common.all_statuses', 'All statuses'),
        'empty_message' => lawyer_tf('availability.no_match_search', 'No availability matches your search.'),
        'pagination_aria' => lawyer_tf('availability.slots_pagination_aria', 'Availability slots pagination'),
    ]
);

$availabilityCalendarSection = legalpro_render_portal_schedule_hub_calendar([
    'calendar_id' => 'availabilityCalendar',
    'add_onclick' => 'openAvailabilityModal()',
    'add_title' => lawyer_tf('availability.add_slot', 'Add Time Slot'),
    'add_aria' => lawyer_tf('availability.add_slot', 'Add Time Slot'),
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
], 'lawyerAvailabilityCalendarHub');

$availabilityListFilterScript = legalpro_portal_list_filter_script(
    'availabilitySlotsSearchInput',
    'availabilitySlotsTableBody',
    'availabilitySlotsFilterEmpty',
    '.legalpro-admin-list-row',
    'availabilitySlotsStatusFilter'
);

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
    'statusScheduled' => lawyer_tf('appointments.status_scheduled', 'Scheduled'),
    'statusConfirmed' => lawyer_tf('appointments.status_confirmed', 'Confirmed'),
    'statusRescheduled' => lawyer_tf('appointments.status_rescheduled', 'Rescheduled'),
    'statusPast' => lawyer_tf('appointments.status_past', 'Past'),
    'statusCompleted' => lawyer_tf('appointments.status_completed', 'Completed'),
    'statusCancelled' => lawyer_tf('appointments.status_cancelled', 'Cancelled'),
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
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-availability-page lp-schedule-hub-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}
        <div class="container-fluid py-4">
            {$message}

            {AVAILABILITY_CALENDAR_SECTION}

            {AVAILABILITY_LIST_SECTION}
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

        function parseIsoDate(iso) {
            var parts = iso.split('-').map(Number);
            return new Date(parts[0], parts[1] - 1, parts[2]);
        }

        function dayNameFromDate(date) {
            return ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][date.getDay()];
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

        function handleAvailabilityEvent(eventLike) {
            var props = eventLike.extendedProps || {};
            var slotId = props.slotId || eventLike.id;
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

            document.getElementById('start_time').addEventListener('change', function() {
                refreshAvailabilityEndTimeOptions('');
            });

            if (typeof LegalproCalendarStudio !== 'undefined') {
                LegalproCalendarStudio.mountScheduleHub({
                    mode: 'appointment',
                    calendarEl: '#availabilityCalendar',
                    events: availabilityEvents,
                    displayLabels: {
                        scheduled: availI18n.statusScheduled,
                        confirmed: availI18n.statusConfirmed,
                        rescheduled: availI18n.statusRescheduled,
                        past: availI18n.statusPast,
                        completed: availI18n.statusCompleted,
                        cancelled: availI18n.statusCancelled
                    },
                    agendaEmptyText: availI18n.noSlots,
                    agendaIdAttr: 'data-slot-id',
                    scheduleActionLabel: availI18n.addSlot,
                    viewActionLabel: availI18n.editSlot,
                    onDateClick: function(ymd) {
                        openAvailabilityModalFromDate(ymd);
                    },
                    onEventClick: function(event) {
                        handleAvailabilityEvent(event);
                    },
                    onAgendaItemClick: function(id, event) {
                        if (event) {
                            handleAvailabilityEvent(event);
                            return;
                        }
                        var match = availabilityEvents.find(function(item) {
                            return String(item.id) === String(id);
                        });
                        if (match) {
                            handleAvailabilityEvent(match);
                        }
                    }
                });
            }
        });
    </script>
    {LIST_FILTER_SCRIPT}
</body>
</html>
HTML;

$lawyerName = isset($_SESSION['lawyer_name']) ? $_SESSION['lawyer_name'] : 'Lawyer';

$html = str_replace('{$message}', $messageHtml, $html);
$html = str_replace('{AVAILABILITY_CALENDAR_SECTION}', $availabilityCalendarSection, $html);
$html = str_replace('{AVAILABILITY_LIST_SECTION}', $availabilitySlotsListSection, $html);
$html = str_replace('{LIST_FILTER_SCRIPT}', $availabilityListFilterScript, $html);
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
