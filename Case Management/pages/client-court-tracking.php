<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: client-login.php');
    exit;
}

$clientId = $_SESSION['client_id'];
$clientName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';

// Check if court_dates table exists
$tableExists = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'court_dates'");
    $tableExists = $stmt->fetch() ? true : false;
} catch (PDOException $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $_SESSION['error_message'] = "Court dates table not found. Please contact administrator.";
}

// Table creation is now handled by the SQL script in sql/create_court_dates_table.sql

// Get court dates for this client's cases
try {
    $stmt = $pdo->prepare("
        SELECT
            cd.*,
            c.title as case_title,
            c.id as case_id,
            u.username as created_by_name,
            u.role as creator_role
        FROM court_dates cd
        INNER JOIN cases c ON cd.case_id = c.id
        LEFT JOIN users u ON cd.created_by = u.id
        WHERE c.client_id = ?
        ORDER BY cd.court_date ASC
    ");
    $stmt->execute([$clientId]);
    $court_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $court_dates = [];
}

// Prepare calendar events for FullCalendar
$calendar_events = [];
foreach ($court_dates as $date) {
    $status_color = '';
    switch ($date['status']) {
        case 'scheduled': $status_color = '#17a2b8'; break;
        case 'completed': $status_color = '#28a745'; break;
        case 'cancelled': $status_color = '#dc3545'; break;
        case 'postponed': $status_color = '#ffc107'; break;
        default: $status_color = '#6c757d';
    }

    $calendar_events[] = [
        'id' => $date['id'],
        'title' => $date['case_title'] . ' - ' . $date['title'],
        'start' => $date['court_date'],
        'backgroundColor' => $status_color,
        'borderColor' => $status_color,
        'textColor' => '#fff',
        'extendedProps' => [
            'description' => $date['description'],
            'location' => $date['location'],
            'status' => $date['status'],
            'created_by_name' => $date['created_by_name'],
            'creator_role' => $date['creator_role']
        ]
    ];
}

$ctTotal = count($court_dates);
$ctScheduled = 0;
$ctCompleted = 0;
$ctUpcoming = 0;
$todayStart = strtotime('today');
foreach ($court_dates as $_cd) {
    $st = $_cd['status'] ?? '';
    if ($st === 'scheduled') {
        $ctScheduled++;
    }
    if ($st === 'completed') {
        $ctCompleted++;
    }
    $cdTs = !empty($_cd['court_date']) ? strtotime($_cd['court_date']) : 0;
    if ($cdTs >= $todayStart && ($st === 'scheduled' || $st === 'postponed')) {
        $ctUpcoming++;
    }
}

$courtTableError = '';
if (!empty($_SESSION['error_message'])) {
    $courtTableError = (string) $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Court Tracking - LegalPro</title>
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
    <link rel="stylesheet" href="../assets/css/simple-calendar.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.10.1/main.min.css" />
    <style>
        .client-court-tracking-page { --cct-radius: 1.15rem; }
<<<<<<< HEAD
        .client-court-tracking-page .navbar-main {
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.9) !important;
            border: 1px solid rgba(255, 255, 255, 0.6) !important;
            box-shadow: 0 0.35rem 1.25rem rgba(52, 71, 103, 0.08) !important;
            margin-top: 20px;
        }
        .client-court-tracking-page .breadcrumb .text-dark { color: #344767 !important; }
=======
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
        .client-court-tracking-page .cct-hero {
            border-radius: var(--cct-radius);
            background: #fff;
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-court-tracking-page .cct-hero .cct-hero-kicker {
            letter-spacing: 0.12em;
            color: #5e72e4;
            opacity: 1;
        }
        .client-court-tracking-page .cct-hero .cct-hero-title {
            color: #344767;
        }
        .client-court-tracking-page .cct-hero .cct-hero-text {
            color: #67748e;
        }
        .client-court-tracking-page .cct-hero-pill {
            background: #f8f9fe;
            border-radius: 0.75rem;
            padding: 0.55rem 0.9rem;
            border: 1px solid rgba(94, 114, 228, 0.15);
            min-width: 5rem;
            text-align: center;
        }
        .client-court-tracking-page .cct-hero-pill .cct-hero-pill-label {
            color: #67748e;
        }
        .client-court-tracking-page .cct-hero-pill .cct-hero-pill-value {
            color: #344767;
        }
        .client-court-tracking-page .cct-panel {
            border-radius: var(--cct-radius);
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 0.25rem 1.1rem rgba(52, 71, 103, 0.07);
            overflow: hidden;
        }
        .client-court-tracking-page .cct-panel.cct-panel-calendar {
            overflow: visible;
        }
        .client-court-tracking-page .cct-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.1rem 1.25rem 0.9rem;
        }
        .client-court-tracking-page .cct-panel .card-header h5 {
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .client-court-tracking-page .cct-cal-wrap {
            padding: 0 1rem 1.25rem;
        }
        .client-court-tracking-page .cct-cal-wrap #calendar {
            min-height: 28rem;
        }
        .client-court-tracking-page .fc .fc-toolbar.fc-header-toolbar {
            margin-bottom: 1rem;
            gap: 0.5rem;
        }
        .client-court-tracking-page .fc .fc-toolbar-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #344767;
        }
        .client-court-tracking-page .fc .fc-button {
            background-color: #5e72e4 !important;
            border-color: #5e72e4 !important;
            color: #fff !important;
            opacity: 1 !important;
            text-transform: capitalize;
            font-weight: 600;
            padding: 0.45rem 0.85rem;
            box-shadow: none;
        }
        .client-court-tracking-page .fc .fc-button:hover,
        .client-court-tracking-page .fc .fc-button:focus,
        .client-court-tracking-page .fc .fc-button:active {
            background-color: #324cdd !important;
            border-color: #324cdd !important;
            color: #fff !important;
            box-shadow: none;
        }
        .client-court-tracking-page .fc .fc-button:disabled {
            opacity: 0.5 !important;
        }
        .client-court-tracking-page .fc .fc-button-primary:not(:disabled).fc-button-active,
        .client-court-tracking-page .fc .fc-button-primary:not(:disabled):active {
            background-color: #172b4d !important;
            border-color: #172b4d !important;
        }
        .client-court-tracking-page .fc .fc-icon {
            color: #fff !important;
        }
        .client-court-tracking-page .fc .fc-icon-chevron-left,
        .client-court-tracking-page .fc .fc-icon-chevron-right {
            color: #fff !important;
        }
        .client-court-tracking-page .simple-calendar .calendar-header .btn {
            color: #5e72e4 !important;
            background-color: #fff !important;
            border-color: #fff !important;
            font-weight: 700;
            min-width: 2.25rem;
        }
        .client-court-tracking-page .simple-calendar .calendar-header .btn:hover {
            background-color: #f8f9fa !important;
            color: #324cdd !important;
        }
        .client-court-tracking-page .fc-event { cursor: pointer; border-radius: 0.35rem; }
        .client-court-tracking-page .cct-panel .table thead th {
            font-size: 0.65rem;
            letter-spacing: 0.06em;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            background: rgba(248, 249, 250, 0.95);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-court-tracking-page .cct-row td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: middle;
        }
        .client-court-tracking-page .cct-row:hover td { background: rgba(94, 114, 228, 0.04); }
        .client-court-tracking-page .cct-row-icon {
            width: 2.35rem;
            height: 2.35rem;
        }
        .client-court-tracking-page .min-width-0 { min-width: 0; }
        .client-court-tracking-page .cct-empty-icon {
            width: 4rem;
            height: 4rem;
        }
        .court-date-modal .modal-dialog { max-width: 600px; }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .status-scheduled { background-color: #11cdef; color: white; }
        .status-completed { background-color: #2dce89; color: white; }
        .status-cancelled { background-color: #f5365c; color: white; }
        .status-postponed { background-color: #fb6340; color: #fff; }
    </style>
</head>
<<<<<<< HEAD
<body class="g-sidenav-show bg-gray-100 client-court-tracking-page">
    <div class="min-height-300 bg-primary position-absolute w-100"></div>
=======
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal client-court-tracking-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="client-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
        </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="client-dashboard.php">
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
                    <a class="nav-link active" href="client-court-tracking.php">
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
                <p class="text-sm font-weight-bold mb-2"><?php echo htmlspecialchars($clientName); ?></p>
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </aside>

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
<<<<<<< HEAD
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-dark" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Court tracking</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-dark">Court tracking</h5>
=======
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-white" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Court tracking</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-white">Court tracking</h5>
>>>>>>> f827a933538474659c1629f07f5a4af06a073209
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search hearings & cases…" value="" autocomplete="off" maxlength="200" aria-label="Search">
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
                                <span class="d-sm-inline d-none">Welcome, <?php echo htmlspecialchars($clientName); ?></span>
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

        <div class="container-fluid py-4">
            <?php if ($courtTableError !== ''): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($courtTableError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card cct-hero mb-0">
                        <div class="card-body p-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-4">
                            <div>
                                <p class="cct-hero-kicker text-xs text-uppercase font-weight-bold mb-1">Docket</p>
                                <h4 class="cct-hero-title font-weight-bolder mb-1">Hearings & appearances</h4>
                                <p class="cct-hero-text text-sm mb-0" style="max-width: 36rem;">Use the calendar for a month view, or scan the list for dates, titles, and status. Click an event or <strong>Details</strong> for full information.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-3 justify-content-lg-end">
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Total</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctTotal; ?></p>
                                </div>
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Upcoming</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctUpcoming; ?></p>
                                </div>
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Scheduled</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctScheduled; ?></p>
                                </div>
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Completed</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctCompleted; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card cct-panel cct-panel-calendar mb-0">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">Calendar</h5>
                                <p class="text-sm text-muted mb-0">Month, week, or day — click an entry to open details.</p>
                            </div>
                            <a href="client-dashboard.php" class="btn btn-sm btn-outline-primary mb-0">Dashboard</a>
                        </div>
                        <div class="cct-cal-wrap">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card cct-panel">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">All court dates</h5>
                                <p class="text-sm text-muted mb-0">Sorted by date, earliest first.</p>
                            </div>
                            <a href="client-cases.php" class="btn btn-sm btn-outline-primary mb-0">My cases</a>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <?php if (empty($court_dates)): ?>
                                <div class="text-center py-5 px-4">
                                    <div class="cct-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                                        <i class="ni ni-calendar-grid-58 text-primary text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                    <h5 class="font-weight-bolder mt-4 mb-2">No court dates yet</h5>
                                    <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 24rem;">When your legal team adds hearings or appearances for your matters, they will appear here and on the calendar above.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-items-center mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Case</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Date &amp; time</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Title</th>
                                                <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 pe-4 text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($court_dates as $date):
                                                $cid = (int) ($date['case_id'] ?? 0);
                                                switch ($date['status'] ?? '') {
                                                    case 'scheduled':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-info">Scheduled</span>';
                                                        break;
                                                    case 'completed':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-success">Completed</span>';
                                                        break;
                                                    case 'cancelled':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-danger">Cancelled</span>';
                                                        break;
                                                    case 'postponed':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-warning">Postponed</span>';
                                                        break;
                                                    default:
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars((string) ($date['status'] ?? '')) . '</span>';
                                                }
                                                ?>
                                                <tr class="cct-row">
                                                    <td class="ps-4">
                                                        <div class="d-flex align-items-center gap-3 py-1">
                                                            <div class="cct-row-icon icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md flex-shrink-0">
                                                                <i class="ni ni-briefcase-24 text-white text-xs opacity-10" aria-hidden="true"></i>
                                                            </div>
                                                            <div class="min-width-0">
                                                                <?php if ($cid > 0): ?>
                                                                <a href="client-case-view.php?id=<?php echo $cid; ?>" class="text-sm font-weight-bold mb-0 d-inline-block text-truncate" style="max-width: 14rem;"><?php echo htmlspecialchars($date['case_title']); ?></a>
                                                                <?php else: ?>
                                                                <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 14rem;"><?php echo htmlspecialchars($date['case_title']); ?></h6>
                                                                <?php endif; ?>
                                                                <p class="text-xs text-muted mb-0">Matter</p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <p class="text-xs font-weight-bold mb-0"><?php echo date('M j, Y', strtotime($date['court_date'])); ?></p>
                                                        <p class="text-xs text-muted mb-0"><?php echo date('g:i A', strtotime($date['court_date'])); ?></p>
                                                    </td>
                                                    <td>
                                                        <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 12rem;" title="<?php echo htmlspecialchars($date['title']); ?>"><?php echo htmlspecialchars($date['title']); ?></p>
                                                    </td>
                                                    <td class="align-middle text-center">
                                                        <?php echo $rowStatusBadge; ?>
                                                    </td>
                                                    <td class="align-middle text-end pe-4">
                                                        <button type="button" class="btn btn-sm btn-outline-primary mb-0" onclick="viewCourtDate(<?php echo (int) $date['id']; ?>)" title="View details">
                                                            Details
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- View Court Date Modal -->
    <div class="modal fade" id="viewCourtDateModal" tabindex="-1" aria-labelledby="viewCourtDateModalLabel" aria-hidden="true">
        <div class="modal-dialog court-date-modal modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-radius-xl shadow-lg overflow-hidden">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-weight-bolder mb-0" id="viewCourtDateModalLabel">Court date details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <strong>Case:</strong> <span id="view_case_title"></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Date & Time:</strong> <span id="view_datetime"></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Status:</strong> <span id="view_status" class="status-badge"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Title:</strong> <span id="view_title"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Description:</strong> <span id="view_description"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Location:</strong> <span id="view_location"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Created by:</strong> <span id="view_created_by"></span> (<span id="view_creator_role"></span>)
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/jquery.min.js"></script>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="../assets/js/fullcalendar/fallback.js"></script>
    <script>
        // Try FullCalendar first, fallback to simple calendar
        let calendarLoaded = false;

        try {
            // Load FullCalendar from CDN
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.10.1/main.min.js';
            script.onload = function() {
                calendarLoaded = true;
                console.log('FullCalendar loaded successfully');
                initFullCalendar();
            };
            script.onerror = function() {
                console.warn('FullCalendar CDN failed, using fallback');
                initSimpleCalendar();
            };
            document.head.appendChild(script);
        } catch (e) {
            console.error('Error loading FullCalendar:', e);
            initSimpleCalendar();
        }

        function initFullCalendar() {
            const calendarEl = document.getElementById('calendar');
            if (!calendarEl) return;

            try {
                const calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: <?php echo json_encode($calendar_events); ?>,
                    eventClick: function(info) {
                        console.log('Event clicked:', info.event.id);
                        viewCourtDate(info.event.id);
                    },
                    height: 'auto',
                    eventDisplay: 'block'
                });
                calendar.render();
                console.log('FullCalendar rendered successfully');
            } catch (error) {
                console.error('Error initializing FullCalendar:', error);
                initSimpleCalendar();
            }
        }

        function initSimpleCalendar() {
            const calendarEl = document.getElementById('calendar');
            if (!calendarEl) return;

            try {
                simpleCalendar = new SimpleCalendar(calendarEl, {
                    events: <?php echo json_encode($calendar_events); ?>
                });
                console.log('Simple calendar rendered successfully');
            } catch (error) {
                console.error('Error initializing simple calendar:', error);
                calendarEl.innerHTML = '<div class="alert alert-danger">Failed to load calendar. Please contact administrator.</div>';
            }
        }

        // Initialize on DOM load if FullCalendar is already loaded
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof FullCalendar !== 'undefined') {
                calendarLoaded = true;
                initFullCalendar();
            } else {
                // Wait a bit for CDN to load
                setTimeout(function() {
                    if (!calendarLoaded) {
                        initSimpleCalendar();
                    }
                }, 2000);
            }
        });
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>

    <script>
        // View court date details
        function viewCourtDate(id) {
            // Find the event data
            var events = <?php echo json_encode($court_dates); ?>;
            var eventData = events.find(function(e) { return e.id == id; });

            if (eventData) {
                document.getElementById('view_case_title').textContent = eventData.case_title;
                document.getElementById('view_datetime').textContent = new Date(eventData.court_date).toLocaleString();
                document.getElementById('view_status').textContent = eventData.status.charAt(0).toUpperCase() + eventData.status.slice(1);
                document.getElementById('view_status').className = 'status-badge status-' + eventData.status;
                document.getElementById('view_title').textContent = eventData.title;
                document.getElementById('view_description').textContent = eventData.description || 'No description';
                document.getElementById('view_location').textContent = eventData.location || 'Not specified';
                document.getElementById('view_created_by').textContent = eventData.created_by_name || 'Unknown';
                document.getElementById('view_creator_role').textContent = eventData.creator_role ? eventData.creator_role.charAt(0).toUpperCase() + eventData.creator_role.slice(1) : 'Unknown';

                var modal = new bootstrap.Modal(document.getElementById('viewCourtDateModal'));
                modal.show();
            }
        }
    </script>
</body>
</html>
