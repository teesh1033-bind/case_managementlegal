<?php
require_once __DIR__ . '/../inc/db.php';
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
$appointmentsRows = '';
if (empty($appointments)) {
    $appointmentsRows = '<tr><td colspan="5" class="text-center py-5">
        <div class="text-center">
            <i class="ni ni-calendar-grid-58 text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-0">No appointments booked yet.</p>
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
        switch ($status) {
            case 'accepted':
                $badgeClass = 'bg-gradient-success';
                $statusText = 'Accepted';
                break;
            case 'rejected':
                $badgeClass = 'bg-gradient-danger';
                $statusText = 'Rejected';
                break;
            default:
                $badgeClass = 'bg-gradient-warning';
                $statusText = 'Pending';
                break;
        }

        $appointmentsRows .= '
        <tr>
            <td class="ps-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md me-2">
                        <i class="ni ni-folder-17 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="text-sm mb-0">' . htmlspecialchars($caseDisplay) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($clientName) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <p class="text-sm font-weight-bold mb-0">' . htmlspecialchars($lawyerName) . '</p>
                <p class="text-xs text-muted mb-0">Lawyer</p>
            </td>
            <td class="text-center">
                <p class="text-sm font-weight-bold mb-0">' . htmlspecialchars($startsAt) . '</p>
            </td>
            <td class="text-center">
                <span class="badge ' . $badgeClass . ' badge-sm">' . $statusText . '</span>
            </td>
            <td class="text-end pe-3">
                <div class="d-flex gap-1 justify-content-end">
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

require_once __DIR__ . '/../inc/admin-layout.php';
$pageToolbar = legalpro_render_page_toolbar(
    'Appointment list',
    'View appointments on the calendar or in the list below.',
    '<a href="new_appointment.php" class="btn btn-sm btn-primary mb-0"><i class="ni ni-fat-add me-1"></i> Schedule Appointment</a>'
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
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
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

			<div class="row">
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
									<tbody>
										{APPOINTMENT_ROWS}
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
								© <script>document.write(new Date().getFullYear())</script>, LegalPro Case Manager.
							</div>
						</div>
					</div>
				</div>
			</footer>
		</div>
	</main>
	<script src="../assets/js/core/popper.min.js"></script>
	<script src="../assets/js/core/bootstrap.min.js"></script>
	<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
	<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
	<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
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
	</script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{PAGE_TOOLBAR}', $pageToolbar, $html);
$html = str_replace('{APPOINTMENTS_SUBTITLE}', htmlspecialchars($appointmentsSubtitle), $html);
$html = str_replace('{APPOINTMENT_ROWS}', $appointmentsRows, $html);

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

echo $html;
?>

