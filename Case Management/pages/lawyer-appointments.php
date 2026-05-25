<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];

// Handle appointment status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_action'])) {
    $appointmentId = (int)$_POST['appointment_id'];
    $action = $_POST['appointment_action'];

    if (in_array($action, ['accept', 'reject'])) {
        $status = ($action === 'accept') ? 'accepted' : 'rejected';

        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ? AND lawyer_id = ?");
            $stmt->execute([$status, $appointmentId, $lawyerId]);

            $message = "Appointment " . ($action === 'accept' ? 'accepted' : 'rejected') . ' successfully.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error updating appointment: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

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

if ($statusFilter !== 'all') {
    if ($statusFilter === 'pending') {
        $query .= " AND a.status = 'pending'";
    } elseif ($statusFilter === 'accepted') {
        $query .= " AND a.status = 'accepted'";
    } elseif ($statusFilter === 'rejected') {
        $query .= " AND a.status = 'rejected'";
    } elseif ($statusFilter === 'upcoming') {
        $query .= " AND a.starts_at >= NOW() AND a.status = 'accepted'";
    }
}

$query .= " ORDER BY a.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $appointments = [];
}

// Build appointments table HTML
$appointmentsTable = '';
if (empty($appointments)) {
    $appointmentsTable = '<tr><td colspan="6" class="text-center text-muted py-4">No appointments found matching your criteria</td></tr>';
} else {
    foreach ($appointments as $appointment) {
        $appointmentDate = date('M d, Y', strtotime($appointment['starts_at']));
        $appointmentTime = date('g:i A', strtotime($appointment['starts_at']));
        $isPast = strtotime($appointment['starts_at']) < time();
        $isToday = date('Y-m-d', strtotime($appointment['starts_at'])) === date('Y-m-d');

        $statusBadge = '';
        switch ($appointment['status']) {
            case 'pending':
                $statusBadge = '<span class="badge bg-warning">Pending Approval</span>';
                break;
            case 'accepted':
                if ($isPast) {
                    $statusBadge = '<span class="badge bg-secondary">Completed</span>';
                } elseif ($isToday) {
                    $statusBadge = '<span class="badge bg-info">Today</span>';
                } else {
                    $statusBadge = '<span class="badge bg-success">Scheduled</span>';
                }
                break;
            case 'rejected':
                $statusBadge = '<span class="badge bg-danger">Rejected</span>';
                break;
            default:
                $statusBadge = '<span class="badge bg-light">' . htmlspecialchars($appointment['status']) . '</span>';
        }

        $rowClass = $appointment['status'] === 'rejected' ? 'table-danger' : ($isToday && $appointment['status'] === 'accepted' ? 'table-info' : '');

        $appointmentsTable .= '
        <tr class="' . $rowClass . '">
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-time-alarm text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($appointment['case_title']) . '</h6>
                        <p class="text-xs text-muted mb-0">Case #' . htmlspecialchars($appointment['case_id']) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <h6 class="mb-0 text-sm">' . htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) . '</h6>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($appointment['email']) . '</p>
            </td>
            <td class="text-center">
                <span class="text-sm font-weight-bold">' . htmlspecialchars($appointmentDate) . '</span>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($appointmentTime) . '</p>
            </td>
            <td>' . htmlspecialchars($appointment['notes'] ?: 'No notes') . '</td>
            <td class="text-center">' . $statusBadge . '</td>
            <td class="text-end">
                <a href="lawyer-case-view.php?id=' . (int)$appointment['case_id'] . '" class="btn btn-sm btn-primary me-1">View Case</a>';
                if ($appointment['status'] === 'pending') {
                    $appointmentsTable .= '
                <form method="post" class="d-inline">
                    <input type="hidden" name="appointment_id" value="' . (int)$appointment['id'] . '">
                    <input type="hidden" name="appointment_action" value="accept">
                    <button type="submit" class="btn btn-sm btn-success me-1" onclick="return confirm(\'Accept this appointment?\')">Accept</button>
                </form>
                <form method="post" class="d-inline">
                    <input type="hidden" name="appointment_id" value="' . (int)$appointment['id'] . '">
                    <input type="hidden" name="appointment_action" value="reject">
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Reject this appointment?\')">Reject</button>
                </form>';
                }
            $appointmentsTable .= '</td>
        </tr>';
    }
}

// Create navigation HTML
$navHtml = <<<'NAV'
<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0" href="lawyer-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0">
    <div class="collapse navbar-collapse w-auto">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="lawyer-dashboard.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="tasks.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-check-bold text-success text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Tasks</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-cases.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Cases</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-clients.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-circle-08 text-info text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Clients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="lawyer-appointments.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-calendar-grid-58 text-success text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Appointments</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-court-tracking.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-collection text-primary text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Court Tracking</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-availability.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-time-alarm text-danger text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Availability</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="sidenav-footer position-absolute bottom-0 w-100">
        <div class="text-center">
            <p class="text-xs text-muted mb-1">Logged in as</p>
            <p class="text-sm font-weight-bold mb-2">{LAWYER_NAME}</p>
            <a href="lawyer-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
        </div>
    </div>
</aside>
NAV;

$navHtml = str_replace('{LAWYER_NAME}', htmlspecialchars($lawyerName), $navHtml);

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
            <!-- Filters -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <form method="GET" class="row align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Status Filter</label>
                                    <select class="form-select" name="status">
                                        <option value="all"{STATUS_ALL}>All Appointments</option>
                                        <option value="pending"{STATUS_PENDING}>Pending</option>
                                        <option value="accepted"{STATUS_ACCEPTED}>Accepted</option>
                                        <option value="rejected"{STATUS_REJECTED}>Rejected</option>
                                        <option value="upcoming"{STATUS_UPCOMING}>Upcoming</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label d-block invisible">Filter</label>
                                    <button type="submit" class="btn btn-primary w-100 mb-0">Filter</button>
                                </div>
                                <div class="col-md-5 text-end">
                                    <p class="text-sm text-muted mb-0">Total: {TOTAL_APPOINTMENTS} appointments</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Appointments Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center">
                                <div class="icon icon-shape icon-md bg-gradient-primary shadow text-center border-radius-md me-3">
                                    <i class="ni ni-time-alarm text-white text-lg opacity-10"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">My Appointments</h6>
                                    <p class="text-xs text-muted mb-0">Appointments from your assigned cases</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date & Time</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Description</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Location</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-secondary opacity-7"></th>
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
                            © <script>document.write(new Date().getFullYear())</script>, LegalPro Lawyer Portal.
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

$replacements = [
    '{NAVIGATION}' => $navHtml,
    '{STATUS_ALL}' => $statusFilter === 'all' ? ' selected' : '',
    '{STATUS_PENDING}' => $statusFilter === 'pending' ? ' selected' : '',
    '{STATUS_ACCEPTED}' => $statusFilter === 'accepted' ? ' selected' : '',
    '{STATUS_REJECTED}' => $statusFilter === 'rejected' ? ' selected' : '',
    '{STATUS_UPCOMING}' => $statusFilter === 'upcoming' ? ' selected' : '',
    '{STATUS_TODAY}' => '',
    '{STATUS_PAST}' => '',
    '{TOTAL_APPOINTMENTS}' => count($appointments),
    '{APPOINTMENTS_TABLE}' => $appointmentsTable,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo $html;
?>
