<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../lib/case_events.php';
require_once __DIR__ . '/../lib/appointment_availability.php';
require_once __DIR__ . '/../lib/case_lawyers.php';

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
        $appointmentId = (int)$_POST['appointment_id'];

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

                $message = 'Appointment deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Appointment not found.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Unable to delete appointment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
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
$iconApptRow = legalpro_icon('calendar-clock');
$appointmentsRows = '';
if (empty($appointments)) {
    $appointmentsRows = '<tr><td colspan="5" class="text-center py-5">
        <div class="text-center">
            <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary d-inline-flex align-items-center justify-content-center mb-3" style="width:3rem;height:3rem;min-width:3rem;">' . legalpro_icon('calendar-clock') . '</div>
            <p class="text-muted mt-0 mb-0">No appointments booked yet.</p>
            <p class="text-xs text-muted mb-0">Use the New Appointment page to book your first appointment.</p>
        </div>
    </td></tr>';
} else {
    foreach ($appointments as $appointment) {
        // Determine display based on available data
        $caseDisplay = isset($appointment['case_display']) && !empty($appointment['case_display'])
            ? $appointment['case_display']
            : 'No Case Assigned';

        $clientFirstName = isset($appointment['client_first_name']) ? $appointment['client_first_name'] : '';
        $clientLastName = isset($appointment['client_last_name']) ? $appointment['client_last_name'] : '';
        $clientName = trim($clientFirstName . ' ' . $clientLastName);
        $clientName = $clientName ? $clientName : 'Unknown Client';
        $lawyerName = $appointment['lawyer_name'] ? $appointment['lawyer_name'] : 'Unassigned';

        $startsAt = $appointment['starts_at'] ? date('m/d/y · H:i', strtotime($appointment['starts_at'])) : 'TBD';
        $status = isset($appointment['status']) ? strtolower($appointment['status']) : 'pending';
        if ($status === 'accepted') {
            $editActionHtml = '<button type="button" class="btn btn-sm btn-secondary mb-0" disabled title="Accepted appointments cannot be edited">Edit</button>';
        } elseif ($status === 'rejected') {
            $editActionHtml = '<a href="new_appointment.php?id=' . (int) $appointment['id'] . '" class="btn btn-sm bg-gradient-primary text-white mb-0" title="Choose another lawyer or time; the assigned lawyer must accept or reject">Reassign</a>';
        } else {
            $editActionHtml = '<a href="new_appointment.php?id=' . (int) $appointment['id'] . '" class="btn btn-sm btn-dark mb-0">Edit</a>';
        }
        $statusBadge = lawyer_appointment_status_badge($appointment);
        $searchBlob = strtolower($caseDisplay . ' ' . $clientName . ' ' . $lawyerName . ' ' . $startsAt . ' ' . $status);

        $appointmentsRows .= '
        <tr class="legalpro-admin-list-row" data-search="' . htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') . '">
            <td class="align-middle ps-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="appt-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0">' . $iconApptRow . '</div>
                    <div>
                        <h6 class="text-sm mb-0">' . htmlspecialchars($caseDisplay) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($clientName) . '</p>
                    </div>
                </div>
            </td>
            <td class="align-middle">
                <p class="text-sm font-weight-bold mb-0">' . htmlspecialchars($lawyerName) . '</p>
                <p class="text-xs text-muted mb-0">Lawyer</p>
            </td>
            <td class="align-middle text-center">
                <p class="text-sm font-weight-bold mb-0">' . htmlspecialchars($startsAt) . '</p>
            </td>
            <td class="align-middle text-center">' . $statusBadge . '</td>
            <td class="align-middle text-end pe-3">
                <div class="legalpro-admin-list-row__actions">
                    ' . $editActionHtml . '
                    <a href="javascript:void(0)" class="btn btn-sm btn-danger mb-0" onclick="deleteAppointment(' . (int)$appointment['id'] . ', \'' . addslashes($caseDisplay) . '\'); return false;">Delete</a>
                </div>
            </td>
        </tr>';
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

$upcomingCount = 0;
foreach ($appointments as $apt) {
    if (!empty($apt['starts_at']) && strtotime($apt['starts_at']) >= time()) {
        $upcomingCount++;
    }
}
$appointmentsSubtitle = $upcomingCount === 1
    ? '1 upcoming'
    : $upcomingCount . ' upcoming';

// Calendar events (appointments only)
$appointmentCalendarEvents = [];
foreach ($appointments as $row) {
    if (empty($row['starts_at'])) {
        continue;
    }

    $status = strtolower((string) ($row['status'] ?? 'pending'));
    $caseDisplay = !empty($row['case_display'])
        ? $row['case_display']
        : (!empty($row['case_title']) ? $row['case_title'] : 'General appointment');
    $clientName = trim(($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? ''));
    $lawyerName = trim((string) ($row['lawyer_name'] ?? ''));

    $startsLabel = !empty($row['starts_at'])
        ? date('M j, Y · g:i A', strtotime($row['starts_at']))
        : 'Date TBD';
    $searchHay = strtolower(
        $caseDisplay . ' ' . $clientName . ' ' . $lawyerName . ' ' . $startsLabel . ' ' . $status
    );

    $appointmentCalendarEvents[] = [
        'id' => (string) $row['id'],
        'title' => $caseDisplay,
        'start' => $row['starts_at'],
        'end' => !empty($row['ends_at']) ? $row['ends_at'] : null,
        'backgroundColor' => 'transparent',
        'borderColor' => 'transparent',
        'textColor' => '#344767',
        'extendedProps' => [
            'client' => $clientName !== '' ? $clientName : 'Unknown',
            'lawyer' => $lawyerName !== '' ? $lawyerName : 'Unassigned',
            'notes' => $row['notes'] ?? '',
            'status' => $status,
            'statusLabel' => ucfirst($status === 'approved' ? 'accepted' : $status),
            'appointmentId' => (int) $row['id'],
            'caseDisplay' => $caseDisplay,
            'startsLabel' => $startsLabel,
            'searchHay' => $searchHay,
        ],
    ];
}

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
    foreach (array_slice($upcomingForCalendar, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'pending'));
        $caseDisplay = !empty($row['case_display']) ? $row['case_display'] : 'Appointment';
        $clientName = trim(($row['client_first_name'] ?? '') . ' ' . ($row['client_last_name'] ?? ''));
        $clientName = $clientName !== '' ? $clientName : '—';
        $hourLabel = date('g:i A', strtotime($row['starts_at']));
        $dayLabel = date('M j', strtotime($row['starts_at']));

        $upcomingAppointmentsCalendarHtml .= '
        <button type="button" class="dashboard-upcoming-item dashboard-upcoming-item--' . htmlspecialchars($status) . '" data-appointment-id="' . (int) $row['id'] . '">
            <span class="dashboard-upcoming-item__time">' . htmlspecialchars($hourLabel) . '<br><small style="font-weight:500;opacity:.8">' . htmlspecialchars($dayLabel) . '</small></span>
            <span class="flex-grow-1">
                <p class="dashboard-upcoming-item__title">' . htmlspecialchars($caseDisplay) . '</p>
                <p class="dashboard-upcoming-item__sub">' . htmlspecialchars($clientName) . '</p>
            </span>
        </button>';
    }
}

$pageToolbar = legalpro_render_page_toolbar(
    'Appointment list',
    'View appointments on the calendar or in the list below.'
);

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
	<link href="../assets/css/legalpro-admin-portal.css?v=33" rel="stylesheet" />
	<link href="../assets/css/dashboard-enhancements.css?v=16" rel="stylesheet" />
	<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
	<?php include __DIR__ . '/../inc/portal-theme-head.php'; ?>
	<?php legalpro_icons_asset_links(); ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-appointments-page<?php echo legalpro_portal_theme_body_class(); ?>">
	<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
	<aside class="sidenav navbar navbar-vertical navbar-expand-xs" id="sidenav-main"></aside>
	<main class="main-content position-relative border-radius-lg ">
		<nav class="navbar navbar-main navbar-expand-lg px-0 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
			<div class="container-fluid py-1 px-3">
				<div>
					<h6 class="font-weight-bolder mb-0"><i class="ni ni-calendar-grid-58 me-2 text-primary"></i>Appointments</h6>
					<p class="dashboard-welcome-sub mb-0 mt-1">{APPOINTMENTS_SUBTITLE}</p>
				</div>
			</div>
		</nav>
		<div class="container-fluid py-4">
			{MESSAGE}
			{PAGE_TOOLBAR}

			<div class="row mb-4">
				<div class="col-12">
					<div class="dashboard-calendar-hub">
						<div class="dashboard-calendar-hub__head">
							<div class="admin-calendar-hub__intro">
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
									<a href="new_appointment.php" class="btn btn-sm bg-gradient-primary mb-0 appointments-schedule-btn">
										<i class="ni ni-fat-add appointments-schedule-btn__icon me-1"></i> Schedule Appointment
									</a>
								</div>
							</div>
							{APPOINTMENTS_CAL_SEARCH}
						</div>
						<div class="dashboard-calendar-hub__body">
							<div class="dashboard-calendar-layout">
								<div id="appointmentsCalendar"></div>
								<aside class="dashboard-upcoming-panel">
									<div class="dashboard-upcoming-panel__title">
										<span>Upcoming</span>
										<a href="#appointmentsTable" class="text-xs text-primary font-weight-bold">View list</a>
									</div>
									<div class="dashboard-upcoming-list" id="upcomingAppointmentsList">
										{UPCOMING_APPOINTMENTS_CALENDAR}
									</div>
								</aside>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="row" id="appointmentsTable">
				<div class="col-12">
					<div class="card">
						<div class="card-header pb-3 pt-3 lp-card-header-primary">
							<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
								<div>
									<h6 class="mb-0 text-white">All Appointments</h6>
									<p class="text-xs mb-0 opacity-8">View and manage scheduled appointments</p>
								</div>
							</div>
						</div>
						<div class="card-body px-0 pt-0 pb-2">
							{APPOINTMENTS_SEARCH}
							<div class="table-responsive">
								<table class="table align-items-center mb-0">
									<thead>
										<tr>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Case & Client</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Lawyer / Staff</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Date & Time</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Status</th>
											<th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end pe-3">Actions</th>
										</tr>
									</thead>
									<tbody id="appointmentsTableBody">
										{APPOINTMENT_ROWS}
										<tr id="appointmentsFilterEmpty" class="d-none">
											<td colspan="5" class="text-center text-muted text-sm py-4">No appointments match your search.</td>
										</tr>
									</tbody>
								</table>
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
		</div>
	</main>

	<div class="modal fade" id="appointmentCalendarModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header" style="background:linear-gradient(135deg,#5e72e4 0%,#825ee4 100%);">
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
					<a id="appointmentModalEditLink" href="new_appointment.php" class="btn btn-sm bg-gradient-dark appointment-modal-edit-btn w-100 mb-0">Edit appointment</a>
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
			var calendarEl = document.getElementById('appointmentsCalendar');
			var appointmentEvents = {APPOINTMENT_CALENDAR_EVENTS_JSON};
			var upcomingList = document.getElementById('upcomingAppointmentsList');

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

			function appointmentStatusKey(status) {
				var value = String(status || 'pending').toLowerCase();
				return value === 'approved' ? 'accepted' : value;
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
				upcomingList.addEventListener('click', function (e) {
					var btn = e.target.closest('[data-appointment-id]');
					if (!btn) {
						return;
					}
					var id = btn.getAttribute('data-appointment-id');
					var match = appointmentEvents.find(function (item) {
						return String(item.id) === String(id);
					});
					if (match) {
						openAppointmentModal({
							id: match.id,
							title: match.title,
							start: match.start,
							end: match.end,
							extendedProps: match.extendedProps
						});
					}
				});
			}

			if (!calendarEl || typeof FullCalendar === 'undefined') {
				return;
			}

			var calendar = new FullCalendar.Calendar(calendarEl, {
				initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
				height: 'auto',
				firstDay: 1,
				navLinks: true,
				nowIndicator: true,
				fixedWeekCount: false,
				dayMaxEvents: 3,
				moreLinkClick: 'popover',
				buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
				eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
				dayHeaderFormat: { weekday: 'short' },
				headerToolbar: {
					left: 'prev,next today',
					center: 'title',
					right: 'dayGridMonth,timeGridWeek,listWeek'
				},
				events: appointmentEvents,
				eventContent: renderAppointmentEvent,
				dateClick: function(info) {
					if (window.legalproHandleCalendarDateClick) {
						window.legalproHandleCalendarDateClick(info, function(event) {
							openAppointmentModal(event);
						});
					}
				},
				dayCellDidMount: function(info) {
					if (window.legalproMountCalendarDayCell) {
						window.legalproMountCalendarDayCell(info);
					}
				},
				eventClick: function (info) {
					info.jsEvent.preventDefault();
					openAppointmentModal(info.event);
				},
				eventDidMount: function (info) {
					if (window.legalproMountCalendarEventClickable) {
						window.legalproMountCalendarEventClickable(info);
					}
					var props = info.event.extendedProps || {};
					var tip = info.event.title;
					if (props.client) {
						tip += '\nClient: ' + props.client;
					}
					if (props.lawyer) {
						tip += '\nLawyer: ' + props.lawyer;
					}
					info.el.setAttribute('title', tip);
				}
			});
			calendar.render();

			function escapeHtmlAdmin(str) {
				return String(str)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;');
			}

			(function initAdminAppointmentsCalendarSearch(cal) {
				var input = document.getElementById('aaCalSearchInput');
				var resultsEl = document.getElementById('aaCalSearchResults');
				if (!input || !resultsEl) {
					return;
				}

				function hideResults() {
					resultsEl.hidden = true;
					resultsEl.innerHTML = '';
				}

				input.addEventListener('input', function () {
					var q = input.value.trim().toLowerCase();
					if (!q) {
						hideResults();
						return;
					}

					var matches = appointmentEvents.filter(function (ev) {
						var props = ev.extendedProps || {};
						var hay = props.searchHay || ((ev.title || '') + ' ' + (props.client || '')).toLowerCase();
						return hay.indexOf(q) !== -1;
					}).sort(function (a, b) {
						return new Date(b.start).getTime() - new Date(a.start).getTime();
					});

					if (!matches.length) {
						resultsEl.innerHTML = '<div class="admin-cal-search-empty">No appointments match your search.</div>';
						resultsEl.hidden = false;
						return;
					}

					var html = '';
					matches.slice(0, 12).forEach(function (ev) {
						var props = ev.extendedProps || {};
						var statusKey = appointmentStatusKey(props.status);
						html += '<button type="button" class="admin-cal-search-item" data-appointment-id="' + escapeHtmlAdmin(props.appointmentId || ev.id) + '" data-start="' + escapeHtmlAdmin(ev.start || '') + '">' +
							'<span class="admin-cal-search-item__dot admin-cal-search-item__dot--' + escapeHtmlAdmin(statusKey) + '" aria-hidden="true"></span>' +
							'<span class="admin-cal-search-item__body">' +
								'<p class="admin-cal-search-item__title">' + escapeHtmlAdmin(ev.title || 'Appointment') + '</p>' +
								'<p class="admin-cal-search-item__sub">' + escapeHtmlAdmin(props.startsLabel || '') + ' · ' + escapeHtmlAdmin(props.client || 'Client') + ' · ' + escapeHtmlAdmin(props.statusLabel || props.status || 'Pending') + '</p>' +
							'</span>' +
						'</button>';
					});
					resultsEl.innerHTML = html;
					resultsEl.hidden = false;
				});

				input.addEventListener('keydown', function (e) {
					if (e.key === 'Escape') {
						hideResults();
						input.blur();
					}
				});

				resultsEl.addEventListener('click', function (e) {
					var btn = e.target.closest('[data-appointment-id]');
					if (!btn) {
						return;
					}
					var id = btn.getAttribute('data-appointment-id');
					var start = btn.getAttribute('data-start');
					if (cal && start) {
						cal.gotoDate(start);
					}
					var match = appointmentEvents.find(function (item) {
						return String(item.id) === String(id);
					});
					if (match) {
						openAppointmentModal({
							id: match.id,
							title: match.title,
							start: match.start,
							end: match.end,
							extendedProps: match.extendedProps
						});
					}
					document.getElementById('appointmentsTable').scrollIntoView({ behavior: 'smooth', block: 'start' });
					hideResults();
				});

				document.addEventListener('click', function (e) {
					if (!e.target.closest('.admin-cal-search-wrap')) {
						hideResults();
					}
				});
			})(calendar);
		});
	</script>
	{APPOINTMENTS_SEARCH_SCRIPT}
</body>
</html>
HTML;

$appointmentsCalSearchHtml = legalpro_render_admin_featured_cal_search(
    'aaCalSearchInput',
    'aaCalSearchResults',
    'Search appointments',
    'Search by case, client, lawyer, date, or status…'
);
$appointmentsSearchHtml = legalpro_render_admin_featured_list_search(
    'appointmentsSearchInput',
    'Search appointment list',
    'Filter the table below by case, client, lawyer, or status…'
);
$appointmentsSearchScript = legalpro_admin_list_search_script('appointmentsSearchInput', 'appointmentsTableBody', 'appointmentsFilterEmpty');

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{PAGE_TOOLBAR}', $pageToolbar, $html);
$html = str_replace('{APPOINTMENTS_SUBTITLE}', htmlspecialchars($appointmentsSubtitle), $html);
$html = str_replace('{APPOINTMENT_ROWS}', $appointmentsRows, $html);
$html = str_replace('{UPCOMING_APPOINTMENTS_CALENDAR}', $upcomingAppointmentsCalendarHtml, $html);
$html = str_replace('{APPOINTMENT_CALENDAR_EVENTS_JSON}', $appointmentCalendarEventsJson, $html);
$html = str_replace('{APPOINTMENTS_CAL_SEARCH}', $appointmentsCalSearchHtml, $html);
$html = str_replace('{APPOINTMENTS_SEARCH}', $appointmentsSearchHtml, $html);
$html = str_replace('{APPOINTMENTS_SEARCH_SCRIPT}', $appointmentsSearchScript, $html);

// rewrite internal links from .html to .php (fallback if any remain)
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

