<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/appointment_availability.php';
require_once __DIR__ . '/../lib/case_lawyers.php';
require_once __DIR__ . '/../lib/appointment_list_ui.php';
require_once __DIR__ . '/../lib/portal_list_ui.php';
require_once __DIR__ . '/../lib/portal_calendar_events.php';
require_once __DIR__ . '/../inc/portal-calendar-studio.php';

$message = '';
$messageType = '';
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $message = urldecode($_GET['msg']);
    $messageType = $_GET['type'];
}

// Ensure appointments table has a status column (adds once, ignored afterwards)
try {
    $pdo->query("ALTER TABLE appointments ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER ends_at");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = isset($_POST['form_type']) ? $_POST['form_type'] : '';

    if ($formType === 'status') {
        $appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : '';
        $allowedStatuses = ['pending', 'approved', 'rejected'];

        if ($appointmentId && in_array($status, $allowedStatuses, true)) {
            try {
                // Get old status for tracking
                $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
                $stmt->execute([$appointmentId]);
                $appointmentData = $stmt->fetch();

                $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
                $stmt->execute([$status, $appointmentId]);

                if ($appointmentData) {
                    if ($status === 'rejected') {
                        removeAppointmentAvailabilitySlot($pdo, $appointmentId);
                    } else {
                        syncAppointmentAvailabilitySlot($pdo, array_merge($appointmentData, ['status' => $status]));
                    }
                }

                // Track status change
                if ($appointmentData && $appointmentData['status'] != $status) {
                    CaseEvents::trackAppointmentUpdated($appointmentData['case_id'], [
                        'status' => $appointmentData['status']
                    ], [
                        'status' => $status
                    ]);
                }

                $label = ucfirst($status);
                header('Location: appointments.php?msg=' . urlencode('Appointment marked as ' . strtolower($label) . '.') . '&type=success');
                exit;
            } catch (PDOException $e) {
                $message = 'Unable to update status: ' . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid appointment status update.';
            $messageType = 'danger';
        }
    }

    // Handle appointment deletion
    if (isset($_POST['delete_appointment'])) {
        $appointmentId = isset($_POST['appointment_id']) ? (int) $_POST['appointment_id'] : 0;

        if ($appointmentId <= 0) {
            header('Location: appointments.php?msg=' . urlencode('Invalid appointment selected.') . '&type=danger');
            exit;
        }

        try {
            // Get appointment details for tracking before deletion
            $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = ?");
            $stmt->execute([$appointmentId]);
            $appointment = $stmt->fetch();

            if ($appointment) {
                // Delete the appointment
                $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
                $stmt->execute([$appointmentId]);

                removeAppointmentAvailabilitySlot($pdo, $appointmentId);

                // Track deletion in case events
                CaseEvents::trackAppointmentDeleted($appointment['case_id'], [
                    'appointment_date' => $appointment['starts_at'],
                    'lawyer_id' => $appointment['lawyer_id']
                ]);

                header('Location: appointments.php?msg=' . urlencode('Appointment deleted successfully.') . '&type=success');
                exit;
            }

            header('Location: appointments.php?msg=' . urlencode('Appointment not found.') . '&type=danger');
            exit;
        } catch (PDOException $e) {
            header('Location: appointments.php?msg=' . urlencode('Unable to delete appointment: ' . $e->getMessage()) . '&type=danger');
            exit;
        }
    }
}

// Fetch appointment rows
try {
    $stmt = $pdo->query("
        SELECT 
            a.*,
            cs.title AS case_title,
            cs.id AS case_id,
            CONCAT('C-', LPAD(cs.id, 4, '0'), ' · ', cs.title) as case_display,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name,
            CONCAT(l.first_name, ' ', l.last_name) AS lawyer_name
        FROM appointments a
        LEFT JOIN cases cs ON cs.id = a.case_id
        LEFT JOIN clients cl ON cl.id = cs.client_id
        LEFT JOIN lawyers l ON l.id = a.lawyer_id
        ORDER BY a.starts_at DESC
    ");
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $appointments = [];
    if (!$message) {
        $message = 'Unable to load appointments: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

// Build appointment table rows
$appointmentsRows = '';
if (empty($appointments)) {
    $appointmentsRows = '<tr><td colspan="7" class="text-center py-5">
        <div class="text-center">
            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center mb-3" style="width:3rem;height:3rem;min-width:3rem;">' . legalpro_icon('calendar-clock') . '</div>
            <p class="text-muted mt-0 mb-0">No appointments booked yet.</p>
            <p class="text-xs text-muted mb-0">Click a date on the calendar or use New Appointment to schedule.</p>
        </div>
    </td></tr>';
} else {
    foreach ($appointments as $appointment) {
        $appointmentsRows .= legalpro_render_portal_appointment_table_row(
            $appointment,
            admin_appointment_list_actions_html($appointment),
            ['person_header' => 'client', 'include_calendar' => true, 'row_id_prefix' => 'appt-row-']
        );
    }
}

// Render message block
$messageHtml = '';
if ($message) {
    $messageHtml = '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">
        ' . htmlspecialchars($message) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>';
}

$apptStatTotal = count($appointments);
$apptStatPending = 0;
$apptStatAccepted = 0;
$apptStatUpcoming = 0;
$apptNow = time();
foreach ($appointments as $apptRow) {
    $apptStatus = strtolower((string) ($apptRow['status'] ?? 'pending'));
    if ($apptStatus === 'pending') {
        $apptStatPending++;
    }
    if (in_array($apptStatus, ['approved', 'accepted'], true)) {
        $apptStatAccepted++;
    }
    if (!empty($apptRow['starts_at']) && strtotime((string) $apptRow['starts_at']) >= $apptNow && $apptStatus !== 'rejected') {
        $apptStatUpcoming++;
    }
}

// Calendar events (appointments only)
$appointmentCalendarEvents = legalpro_portal_build_appointment_calendar_events($appointments, [
    'subtitle_field' => 'client',
    'subtitle_fallback' => 'Unknown',
    'title_fallback' => 'Appointment',
]);

$appointmentCalendarEventsJson = json_encode(
    $appointmentCalendarEvents,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

$iconAppointmentEmpty = legalpro_icon('calendar');
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
        . $iconAppointmentEmpty
        . '<span>No upcoming appointments</span></div>';
} else {
    usort($upcomingForCalendar, function ($a, $b) {
        return strtotime($a['starts_at']) <=> strtotime($b['starts_at']);
    });
    $upcomingAppointmentsCalendarHtml = '<div class="lp-appt-timeline">';
    foreach (array_slice($upcomingForCalendar, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'pending'));
        $caseDisplay = !empty($row['case_display']) ? $row['case_display'] : 'Appointment';
        $clientName = trim(($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : '—';
        $hourLabel = date('g:i A', strtotime($row['starts_at']));
        $dayLabel = date('M j', strtotime($row['starts_at']));

        $upcomingAppointmentsCalendarHtml .= '
        <button type="button" class="lp-appt-timeline__item lp-appt-timeline__item--' . htmlspecialchars($status) . '" data-appointment-id="' . (int) $row['id'] . '">
            <span class="lp-appt-timeline__time">' . htmlspecialchars($hourLabel) . '<small>' . htmlspecialchars($dayLabel) . '</small></span>
            <span class="flex-grow-1">
                <p class="lp-appt-timeline__title">' . htmlspecialchars($caseDisplay) . '</p>
                <p class="lp-appt-timeline__sub">' . htmlspecialchars($clientName) . '</p>
            </span>
        </button>';
    }
    $upcomingAppointmentsCalendarHtml .= '</div>';
}

$apptListSubtitle = $apptStatTotal . ' total appointment' . ($apptStatTotal === 1 ? '' : 's');

$adminAppointmentsCalendarSection = legalpro_render_portal_schedule_hub_calendar([
    'calendar_id' => 'appointmentsCalendar',
    'add_href' => 'new_appointment.php',
    'add_title' => 'Schedule appointment',
    'add_aria' => 'Schedule appointment',
], 'appointmentsCalendarHub');

$adminAppointmentsListSection = legalpro_render_portal_schedule_hub_list_section(
    'appointmentsTable',
    'Appointment List',
    $apptListSubtitle,
    'appointmentsSearchInput',
    'Search by service…',
    'Search by service',
    'appointmentsStatusFilter',
    legalpro_portal_appointment_status_options(),
    'appointmentsTableBody',
    $appointmentsRows,
    'appointmentsFilterEmpty',
    [
        'empty_message' => 'No appointments match your filters.',
        'pagination_aria' => 'Appointments pagination',
    ]
);

$adminAppointmentsListFilterScript = legalpro_portal_list_filter_script(
    'appointmentsSearchInput',
    'appointmentsTableBody',
    'appointmentsFilterEmpty',
    '.legalpro-admin-list-row',
    'appointmentsStatusFilter'
);

ob_start();
include __DIR__ . '/../inc/admin-portal-head.php';
$adminPortalHeadHtml = ob_get_clean();
$portalThemeBodyClass = legalpro_portal_theme_body_class();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
	<link rel="icon" type="image/png" href="../assets/img/favicon.png">
	<title>LegalPro Case Manager - Appointments</title>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
	<link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
	<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
	<link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
	<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
	{ADMIN_PORTAL_HEAD}
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-appointments-page lp-schedule-hub-page{PORTAL_THEME_BODY_CLASS}">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav navbar navbar-vertical navbar-expand-xs" id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg ">
		{APPT_PAGE_NAVBAR}
		<div class="container-fluid py-4">
			{MESSAGE}

			{CALENDAR_SECTION}

			{LIST_SECTION}
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
		</div>
	</main>

	<div class="modal fade" id="appointmentCalendarModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header lp-cal-view-modal-header">
					<h6 class="modal-title text-white font-weight-bold" id="appointmentModalTitle">Appointment</h6>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<div class="row g-3 mb-3">
						<div class="col-6">
							<label class="text-xs text-uppercase text-muted">Client</label>
							<p id="appointmentModalClient" class="mb-0 text-sm font-weight-bold"></p>
						</div>
						<div class="col-6">
							<label class="text-xs text-uppercase text-muted">Lawyer</label>
							<p id="appointmentModalLawyer" class="mb-0 text-sm font-weight-bold"></p>
						</div>
					</div>
					<div class="row g-3 mb-3">
						<div class="col-6">
							<label class="text-xs text-uppercase text-muted">Status</label>
							<p id="appointmentModalStatus" class="mb-0 text-sm font-weight-bold"></p>
						</div>
						<div class="col-6">
							<label class="text-xs text-uppercase text-muted">Scheduled time</label>
							<p id="appointmentModalTime" class="mb-0 text-sm font-weight-bold"></p>
						</div>
					</div>
					<div class="mb-3">
						<label class="text-xs text-uppercase text-muted">Notes</label>
						<div id="appointmentModalNotes" class="appointment-modal-notes p-3 rounded"></div>
					</div>
					<a id="appointmentModalEditLink" href="new_appointment.php" class="btn btn-sm lp-portal-accent-btn appointment-modal-edit-btn w-100 mb-0">Edit appointment</a>
				</div>
			</div>
		</div>
	</div>

	<!-- LEGALPRO_ADMIN_SCRIPTS -->
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
	<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
	<script src="../assets/js/spa-nav.js"></script>
	<script>
		function deleteAppointment(appointmentId, caseDisplay) {
			if (confirm('Are you sure you want to delete the appointment for "' + caseDisplay + '"?\n\nThis action cannot be undone.')) {
				var form = document.createElement('form');
				form.method = 'POST';
				form.innerHTML = '<input type="hidden" name="delete_appointment" value="1"><input type="hidden" name="appointment_id" value="' + appointmentId + '">';
				document.body.appendChild(form);
				form.submit();
			}
		}

		document.addEventListener('DOMContentLoaded', function () {
			var appointmentEvents = {APPOINTMENT_CALENDAR_EVENTS_JSON};

			function formatAppointmentDateTime(dateValue) {
				if (!dateValue) {
					return '—';
				}
				var date = dateValue instanceof Date ? dateValue : new Date(dateValue);
				if (isNaN(date.getTime())) {
					return '—';
				}
				var dd = String(date.getDate()).padStart(2, '0');
				var mm = String(date.getMonth() + 1).padStart(2, '0');
				var yy = date.getFullYear();
				var h = String(date.getHours()).padStart(2, '0');
				var mi = String(date.getMinutes()).padStart(2, '0');
				return dd + '/' + mm + '/' + yy + ' at ' + h + ':' + mi;
			}

			function openAppointmentModal(eventLike) {
				var props = eventLike.extendedProps || {};
				document.getElementById('appointmentModalTitle').textContent = eventLike.title || 'Appointment';
				document.getElementById('appointmentModalClient').textContent = props.client || '—';
				document.getElementById('appointmentModalLawyer').textContent = props.lawyer || '—';
				document.getElementById('appointmentModalStatus').textContent = props.statusLabel || props.status || 'Pending';
				document.getElementById('appointmentModalNotes').textContent = props.notes || 'No notes added.';

				var start = eventLike.start instanceof Date ? eventLike.start : new Date(eventLike.start);
				var timeText = formatAppointmentDateTime(start);
				if (eventLike.end) {
					var end = eventLike.end instanceof Date ? eventLike.end : new Date(eventLike.end);
					timeText += ' — ' + formatAppointmentDateTime(end);
				}
				document.getElementById('appointmentModalTime').textContent = timeText;
				document.getElementById('appointmentModalEditLink').href = 'new_appointment.php?id=' + (props.appointmentId || eventLike.id);

				if (window.bootstrap && bootstrap.Modal) {
					bootstrap.Modal.getOrCreateInstance(document.getElementById('appointmentCalendarModal')).show();
				}
			}

			function focusAppointmentRow(id) {
				var row = document.getElementById('appt-row-' + id);
				var wrap = document.querySelector('#appointmentsTable [data-lp-admin-paginate]');
				if (wrap && window.LegalproAdminTablePagination && row) {
					window.LegalproAdminTablePagination.focusRow(wrap, row);
				} else if (row) {
					row.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			}

			function scheduleAppointmentOnDate(ymd) {
				if (ymd) {
					window.location.href = 'new_appointment.php?date=' + encodeURIComponent(ymd);
				}
			}

			if (typeof LegalproCalendarStudio !== 'undefined') {
				LegalproCalendarStudio.mountScheduleHub({
					mode: 'appointment',
					calendarEl: '#appointmentsCalendar',
					events: appointmentEvents,
					agendaEmptyText: 'No appointments this month',
					agendaIdAttr: 'data-appointment-id',
					scheduleActionLabel: 'Schedule appointment',
					viewActionLabel: 'View appointment',
					onAgendaDelete: function (id) {
						var delMatch = appointmentEvents.find(function (ev) {
							return String(ev.id) === String(id);
						});
						var delTitle = delMatch ? (delMatch.title || 'Appointment') : 'this appointment';
						deleteAppointment(id, delTitle);
					},
					onAgendaItemClick: function (id, match) {
						if (match) {
							openAppointmentModal(match);
						}
						focusAppointmentRow(id);
					},
					onDateClick: function (ymd) {
						scheduleAppointmentOnDate(ymd);
					},
					onEventClick: function (event) {
						openAppointmentModal(event);
						focusAppointmentRow(event.id);
					}
				});
			}
		});
	</script>
	{LIST_FILTER_SCRIPT}
</body>
</html>
HTML;

$apptPageNavbar = legalpro_render_portal_page_navbar('Appointments', $apptStatUpcoming . ' upcoming');
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{ADMIN_PORTAL_HEAD}', $adminPortalHeadHtml, $html);
$html = str_replace('{PORTAL_THEME_BODY_CLASS}', $portalThemeBodyClass, $html);
$html = str_replace('{APPT_PAGE_NAVBAR}', $apptPageNavbar, $html);
$html = str_replace('{CALENDAR_SECTION}', $adminAppointmentsCalendarSection, $html);
$html = str_replace('{LIST_SECTION}', $adminAppointmentsListSection, $html);
$html = str_replace('{LIST_FILTER_SCRIPT}', $adminAppointmentsListFilterScript, $html);
$html = str_replace('{APPT_STAT_TOTAL}', (string) $apptStatTotal, $html);
$html = str_replace('{APPT_STAT_UPCOMING}', (string) $apptStatUpcoming, $html);
$html = str_replace('{APPT_STAT_PENDING}', (string) $apptStatPending, $html);
$html = str_replace('{APPT_STAT_ACCEPTED}', (string) $apptStatAccepted, $html);
$html = str_replace('{UPCOMING_APPOINTMENTS_CALENDAR}', $upcomingAppointmentsCalendarHtml, $html);
$html = str_replace('{APPOINTMENT_CALENDAR_EVENTS_JSON}', $appointmentCalendarEventsJson, $html);

// rewrite internal links
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);

ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo legalpro_apply_copyright_line($html);
?>

