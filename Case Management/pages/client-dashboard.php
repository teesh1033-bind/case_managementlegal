<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

$message = '';
$messageType = '';

try {
    // Get client cases summary
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_cases,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_cases,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_cases,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cases
        FROM cases
        WHERE client_id = ?
    ");
    $stmt->execute([$client_id]);
    $caseStats = $stmt->fetch();

    // Get recent cases (last 5)
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') as lawyer_names
        FROM cases c
        LEFT JOIN case_lawyers cl ON cl.case_id = c.id
        LEFT JOIN lawyers l ON l.id = cl.lawyer_id
        WHERE c.client_id = ?
        GROUP BY c.id
        ORDER BY c.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$client_id]);
    $recentCases = $stmt->fetchAll();

    // Get upcoming appointments (next 5 - only accepted ones)
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            a.*,
            c.title as case_title,
            GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') as lawyer_name
        FROM appointments a
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN case_lawyers cl ON cl.case_id = c.id
        LEFT JOIN lawyers l ON l.id = cl.lawyer_id
        WHERE a.client_id = ? AND a.starts_at > NOW() AND a.status = 'accepted'
        GROUP BY a.id
        ORDER BY a.starts_at ASC
        LIMIT 5
    ");
    $stmt->execute([$client_id]);
    $upcomingAppointments = $stmt->fetchAll();


} catch (PDOException $e) {
    $message = 'Error loading dashboard data: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $caseStats = ['total_cases' => 0, 'open_cases' => 0, 'closed_cases' => 0, 'pending_cases' => 0];
    $recentCases = [];
    $upcomingAppointments = [];
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LexMate - Client Dashboard</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100">
    <div class="min-height-300 bg-primary position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="#">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LexMate logo">
            <span class="ms-1 font-weight-bold">LexMate</span>
            </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link active" href="client-dashboard.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-cases.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Cases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-appointments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-calendar-grid-58 text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Appointments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-court-tracking.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-collection text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Court Tracking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-payments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-credit-card text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Payments</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidenav-footer position-absolute bottom-0 w-100">
            <div class="text-center">
                <p class="text-xs text-muted mb-1">Logged in as</p>
                <p class="text-sm font-weight-bold mb-2">{CLIENT_NAME}</p>
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger w-100">Logout</a>
            </div>
        </div>
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="javascript:;">Pages</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Dashboard</li>
                    </ol>
                    <h6 class="font-weight-bolder mb-0">Client Dashboard</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <div class="ms-md-auto pe-md-3 d-flex align-items-center">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="text" class="form-control" placeholder="Type here...">
                        </div>
                    </div>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, {CLIENT_NAME}</span>
                            </a>
                        </li>
                        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
                                <div class="sidenav-toggler-inner">
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                </div>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        <!-- End Navbar -->
        <div class="container-fluid py-4">
            {MESSAGE}

            <!-- Stats Cards -->
            <div class="row">
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Cases</p>
                                        <h5 class="font-weight-bolder mb-0">{TOTAL_CASES}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                        <i class="ni ni-folder-17 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Open Cases</p>
                                        <h5 class="font-weight-bolder mb-0">{OPEN_CASES}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                        <i class="ni ni-check-bold text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Pending Cases</p>
                                        <h5 class="font-weight-bolder mb-0">{PENDING_CASES}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                                        <i class="ni ni-time-alarm text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Closed Cases</p>
                                        <h5 class="font-weight-bolder mb-0">{CLOSED_CASES}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                                        <i class="ni ni-archive-2 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <!-- Recent Cases -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6>Recent Cases</h6>
                        </div>
                        <div class="card-body p-3">
                            {RECENT_CASES}
                        </div>
                    </div>
                </div>

                <!-- Upcoming Appointments -->
                <div class="col-lg-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6>Upcoming Appointments</h6>
                        </div>
                        <div class="card-body p-3">
                            {UPCOMING_APPOINTMENTS}
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

// Replace placeholders
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{TOTAL_CASES}', isset($caseStats['total_cases']) ? $caseStats['total_cases'] : 0, $html);
$html = str_replace('{OPEN_CASES}', isset($caseStats['open_cases']) ? $caseStats['open_cases'] : 0, $html);
$html = str_replace('{PENDING_CASES}', isset($caseStats['pending_cases']) ? $caseStats['pending_cases'] : 0, $html);
$html = str_replace('{CLOSED_CASES}', isset($caseStats['closed_cases']) ? $caseStats['closed_cases'] : 0, $html);

// Recent Cases
$recentCasesHtml = '';
if (empty($recentCases)) {
    $recentCasesHtml = '<p class="text-sm text-muted">No cases found.</p>';
} else {
    foreach ($recentCases as $case) {
        $lawyerNames = $case['lawyer_names'] ?: 'Unassigned';
        switch ($case['status']) {
            case 'open':
                $statusBadge = '<span class="badge badge-sm bg-gradient-success">Open</span>';
                break;
            case 'closed':
                $statusBadge = '<span class="badge badge-sm bg-gradient-danger">Closed</span>';
                break;
            case 'pending':
                $statusBadge = '<span class="badge badge-sm bg-gradient-warning">Pending</span>';
                break;
            default:
                $statusBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars($case['status']) . '</span>';
                break;
        }

        $recentCasesHtml .= '
            <div class="d-flex align-items-center mb-3">
                <div class="w-100">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-1 text-sm">' . htmlspecialchars($case['title']) . '</h6>
                        ' . $statusBadge . '
                    </div>
                    <p class="text-xs text-secondary mb-0">Lawyer: ' . htmlspecialchars($lawyerNames) . '</p>
                    <p class="text-xs text-secondary mb-0">Updated: ' . date('M d, Y', strtotime($case['updated_at'])) . '</p>
                </div>
            </div>
            <hr class="horizontal dark">';
    }
}
$html = str_replace('{RECENT_CASES}', $recentCasesHtml, $html);

// Upcoming Appointments
$appointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $appointmentsHtml = '<p class="text-sm text-muted">No upcoming appointments.</p>';
} else {
    foreach ($upcomingAppointments as $apt) {
        $appointmentDate = date('M d, Y g:i A', strtotime($apt['starts_at']));
        $appointmentsHtml .= '
            <div class="d-flex align-items-center mb-3">
                <div class="w-100">
                    <h6 class="mb-1 text-sm">' . htmlspecialchars($apt['case_title'] ?: 'General Appointment') . '</h6>
                    <p class="text-xs text-secondary mb-0">Date: ' . $appointmentDate . '</p>
                    <p class="text-xs text-secondary mb-0">Lawyer: ' . htmlspecialchars($apt['lawyer_name'] ?: 'TBD') . '</p>
                    <p class="text-xs text-secondary mb-0">Notes: ' . htmlspecialchars(substr($apt['notes'] ?: 'No notes', 0, 50)) . '...</p>
                </div>
            </div>
            <hr class="horizontal dark">';
    }
}
$html = str_replace('{UPCOMING_APPOINTMENTS}', $appointmentsHtml, $html);


echo $html;
?>
