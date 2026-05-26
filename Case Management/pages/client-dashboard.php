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
    <title>LegalPro - Client Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<<<<<<< HEAD
<link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
=======
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
>>>>>>> f827a933538474659c1629f07f5a4af06a073209

    <style>
        .client-dashboard-page { --cd-radius: 1rem; --cd-radius-lg: 1.25rem; }
        .client-dashboard-page .cd-hero {
            border-radius: var(--cd-radius-lg);
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.08);
        }
        .client-dashboard-page .cd-hero .cd-hero-kicker {
            letter-spacing: 0.12em;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #5e72e4;
            opacity: 1;
        }
        .client-dashboard-page .cd-hero .cd-hero-title {
            color: #344767;
        }
        .client-dashboard-page .cd-hero .cd-hero-text {
            color: #67748e;
        }
        .client-dashboard-page .cd-stat-card {
            border-radius: var(--cd-radius-lg);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .client-dashboard-page .cd-stat-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: 4px 0 0 4px;
        }
        .client-dashboard-page .cd-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.75rem 1.75rem rgba(52, 71, 103, 0.12) !important;
        }
        .client-dashboard-page .cd-stat-card--primary::before { background: linear-gradient(180deg, #5e72e4, #324cdd); }
        .client-dashboard-page .cd-stat-card--success::before { background: linear-gradient(180deg, #2dce89, #24a46d); }
        .client-dashboard-page .cd-stat-card--warning::before { background: linear-gradient(180deg, #fb6340, #f56036); }
        .client-dashboard-page .cd-stat-card--dark::before { background: linear-gradient(180deg, #8898aa, #525f7f); }
        .client-dashboard-page .cd-stat-card .cd-stat-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .client-dashboard-page .cd-stat-card .cd-stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }
        .client-dashboard-page .cd-stat-card .cd-stat-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--bs-secondary-color);
        }
        .client-dashboard-page .cd-panel {
            border-radius: var(--cd-radius-lg);
            border: 1px solid rgba(0, 0, 0, 0.04);
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.06);
        }
        .client-dashboard-page .cd-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.1rem 1.25rem 0.85rem;
        }
        .client-dashboard-page .cd-panel .card-header h6 {
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .client-dashboard-page .cd-panel .card-header .cd-panel-sub {
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
            margin: 0.15rem 0 0;
        }
        .client-dashboard-page .cd-list-item {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 0.75rem;
            transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
        }
        .client-dashboard-page .cd-list-item:hover {
            border-color: rgba(94, 114, 228, 0.35);
            background: rgba(94, 114, 228, 0.04);
            box-shadow: 0 0.35rem 1rem rgba(94, 114, 228, 0.08);
        }
        .client-dashboard-page .cd-list-item .flex-grow-1 { min-width: 0; }
        .client-dashboard-page .cd-list-item:last-child { margin-bottom: 0 !important; }
<<<<<<< HEAD
        .client-dashboard-page .navbar-main {
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.86) !important;
            border: 1px solid rgba(255, 255, 255, 0.6) !important;
            box-shadow: 0 0.35rem 1.25rem rgba(52, 71, 103, 0.08) !important;
            margin-top: 20px;
        }
        .client-dashboard-page .breadcrumb .text-dark { color: #344767 !important; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 client-dashboard-page">
    <div class="min-height-300 bg-primary position-absolute w-100"></div>
=======
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal client-dashboard-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="#">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
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
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
       
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
<<<<<<< HEAD
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-dark" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Dashboard</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-dark">Dashboard</h5>
=======
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-white" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Dashboard</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-white">Dashboard</h5>
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search cases or appointments…" value="" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
<<<<<<< HEAD
                            <a href="javascript:;" class="nav-link text-body font-weight-bold px-0">
=======
                            <a href="javascript:;" class="nav-link text-white font-weight-bold px-0">
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
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

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card cd-hero border-0">
                        <div class="card-body p-4 p-lg-5 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-4">
                            <div class="flex-grow-1" style="max-width: 36rem;">
                                <p class="cd-hero-kicker mb-2">Your legal workspace</p>
                                <h4 class="cd-hero-title font-weight-bolder mb-2">Welcome back, {CLIENT_NAME}</h4>
                                <p class="cd-hero-text text-sm mb-0" style="line-height: 1.55;">Review active matters, prepare for upcoming meetings, and stay on top of court dates—all from one place.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                                <a href="client-cases.php" class="btn btn-sm bg-gradient-primary text-white font-weight-bold mb-0 px-3">My cases</a>
                                <a href="client-appointments.php" class="btn btn-sm btn-outline-primary font-weight-bold mb-0 px-3">Appointments</a>
                                <a href="client-court-tracking.php" class="btn btn-sm btn-outline-primary font-weight-bold mb-0 px-3">Court tracking</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row">
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card cd-stat-card cd-stat-card--primary border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <p class="cd-stat-label mb-1">Total cases</p>
                                    <p class="cd-stat-value text-dark mb-0">{TOTAL_CASES}</p>
                                </div>
                                <div class="cd-stat-icon bg-gradient-primary text-white shadow">
                                    <i class="ni ni-folder-17 opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card cd-stat-card cd-stat-card--success border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <p class="cd-stat-label mb-1">Open</p>
                                    <p class="cd-stat-value text-dark mb-0">{OPEN_CASES}</p>
                                </div>
                                <div class="cd-stat-icon bg-gradient-success text-white shadow">
                                    <i class="ni ni-check-bold opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <div class="card cd-stat-card cd-stat-card--warning border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <p class="cd-stat-label mb-1">Pending</p>
                                    <p class="cd-stat-value text-dark mb-0">{PENDING_CASES}</p>
                                </div>
                                <div class="cd-stat-icon bg-gradient-warning text-white shadow">
                                    <i class="ni ni-time-alarm opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="card cd-stat-card cd-stat-card--dark border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <p class="cd-stat-label mb-1">Closed</p>
                                    <p class="cd-stat-value text-dark mb-0">{CLOSED_CASES}</p>
                                </div>
                                <div class="cd-stat-icon bg-gradient-dark text-white shadow">
                                    <i class="ni ni-archive-2 opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-2">
                <!-- Recent Cases -->
                <div class="col-lg-6 mb-4">
                    <div class="card cd-panel h-100">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6>Recent cases</h6>
                                <p class="cd-panel-sub">Latest updates on your matters</p>
                            </div>
                            <a href="client-cases.php" class="btn btn-sm btn-outline-primary mb-0">View all</a>
                        </div>
                        <div class="card-body p-3 pt-2">
                            {RECENT_CASES}
                        </div>
                    </div>
                </div>

                <!-- Upcoming Appointments -->
                <div class="col-lg-6 mb-4">
                    <div class="card cd-panel h-100">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6>Upcoming appointments</h6>
                                <p class="cd-panel-sub">Accepted meetings on your calendar</p>
                            </div>
                            <a href="client-appointments.php" class="btn btn-sm btn-outline-primary mb-0">Schedule</a>
                        </div>
                        <div class="card-body p-3 pt-2">
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
    $recentCasesHtml = '<div class="text-center text-muted py-5 px-3"><p class="text-sm mb-1 font-weight-bold">No cases yet</p><p class="text-xs mb-3">When your firm opens a matter for you, it will show up here.</p><a href="client-cases.php" class="btn btn-sm btn-primary mb-0">Go to My cases</a></div>';
} else {
    foreach ($recentCases as $case) {
        $lawyerNames = $case['lawyer_names'] ?: 'Unassigned';
        $caseId = (int) $case['id'];
        switch ($case['status']) {
            case 'open':
                $statusBadge = '<span class="badge badge-sm bg-gradient-success">Open</span>';
                break;
            case 'closed':
                $statusBadge = '<span class="badge badge-sm bg-gradient-secondary">Closed</span>';
                break;
            case 'pending':
                $statusBadge = '<span class="badge badge-sm bg-gradient-warning">Pending</span>';
                break;
            default:
                $statusBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars($case['status']) . '</span>';
                break;
        }

        $recentCasesHtml .= '
            <a href="client-case-view.php?id=' . $caseId . '" class="cd-list-item d-block text-decoration-none text-reset mb-2 p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1">
                        <h6 class="mb-1 text-sm font-weight-bold text-truncate">' . htmlspecialchars($case['title']) . '</h6>
                        <p class="text-xs text-secondary mb-0"><strong class="font-weight-bold">Lawyer:</strong> ' . htmlspecialchars($lawyerNames) . '</p>
                        <p class="text-xs text-secondary mb-0"><strong class="font-weight-bold">Updated:</strong> ' . date('M j, Y', strtotime($case['updated_at'])) . '</p>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-2 flex-shrink-0">
                        ' . $statusBadge . '
                        <span class="text-xs text-primary font-weight-bold">View <i class="ni ni-bold-right ms-1" aria-hidden="true"></i></span>
                    </div>
                </div>
            </a>';
    }
}
$html = str_replace('{RECENT_CASES}', $recentCasesHtml, $html);

// Upcoming Appointments
$appointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $appointmentsHtml = '<div class="text-center text-muted py-5 px-3"><p class="text-sm mb-1 font-weight-bold">No upcoming meetings</p><p class="text-xs mb-3">Accepted appointments will appear here with date and counsel.</p><a href="client-appointments.php" class="btn btn-sm btn-primary mb-0">Book an appointment</a></div>';
} else {
    foreach ($upcomingAppointments as $apt) {
        $appointmentDate = date('M j, Y g:i A', strtotime($apt['starts_at']));
        $notesRaw = $apt['notes'] ?: '';
        $notesPreview = $notesRaw !== '' ? htmlspecialchars(substr($notesRaw, 0, 72)) . (strlen($notesRaw) > 72 ? '…' : '') : 'No notes';
        $appointmentsHtml .= '
            <a href="client-appointments.php" class="cd-list-item d-block text-decoration-none text-reset mb-2 p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1">
                        <h6 class="mb-1 text-sm font-weight-bold text-truncate">' . htmlspecialchars($apt['case_title'] ?: 'General appointment') . '</h6>
                        <p class="text-xs text-secondary mb-0"><strong class="font-weight-bold">When:</strong> ' . $appointmentDate . '</p>
                        <p class="text-xs text-secondary mb-0"><strong class="font-weight-bold">Lawyer:</strong> ' . htmlspecialchars($apt['lawyer_name'] ?: 'TBD') . '</p>
                        <p class="text-xs text-secondary mb-0 text-truncate" title="' . htmlspecialchars($apt['notes'] ?: '') . '">' . $notesPreview . '</p>
                    </div>
                    <span class="text-xs text-primary font-weight-bold flex-shrink-0 pt-1">Calendar <i class="ni ni-bold-right ms-1" aria-hidden="true"></i></span>
                </div>
            </a>';
    }
}
$html = str_replace('{UPCOMING_APPOINTMENTS}', $appointmentsHtml, $html);


echo $html;
?>
