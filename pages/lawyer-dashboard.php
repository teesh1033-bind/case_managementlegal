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

// Get dashboard statistics
$stats = [];
try {
    // Total cases assigned to this lawyer
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM case_lawyers WHERE lawyer_id = ?");
    $stmt->execute([$lawyerId]);
    $stats['total_cases'] = $stmt->fetchColumn();

    // Active cases (not closed)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM cases c
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE cl.lawyer_id = ? AND c.status != 'closed'
    ");
    $stmt->execute([$lawyerId]);
    $stats['active_cases'] = $stmt->fetchColumn();

    // Total clients (unique clients from their cases)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) FROM cases c
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE cl.lawyer_id = ?
    ");
    $stmt->execute([$lawyerId]);
    $stats['total_clients'] = $stmt->fetchColumn();

    // Upcoming appointments (next 7 days)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments a
        WHERE a.lawyer_id = ? AND DATE(a.starts_at) >= CURDATE()
        AND DATE(a.starts_at) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND a.status = 'accepted'
    ");
    $stmt->execute([$lawyerId]);
    $stats['upcoming_appointments'] = $stmt->fetchColumn();

    // Recent cases
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.status, c.created_at, cl.first_name, cl.last_name
        FROM cases c
        INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
        INNER JOIN clients cl ON cl.id = c.client_id
        WHERE cl2.lawyer_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$lawyerId]);
    $recentCases = $stmt->fetchAll();

    // Upcoming appointments details
    $stmt = $pdo->prepare("
        SELECT a.starts_at, a.notes as description, c.title as case_title, cl.first_name, cl.last_name
        FROM appointments a
        INNER JOIN cases c ON c.id = a.case_id
        INNER JOIN clients cl ON cl.id = c.client_id
        WHERE a.lawyer_id = ? AND DATE(a.starts_at) >= CURDATE()
        AND a.status = 'accepted'
        ORDER BY a.starts_at
        LIMIT 5
    ");
    $stmt->execute([$lawyerId]);
    $upcomingAppointments = $stmt->fetchAll();

} catch (PDOException $e) {
    $stats = ['total_cases' => 0, 'active_cases' => 0, 'total_clients' => 0, 'upcoming_appointments' => 0];
    $recentCases = [];
    $upcomingAppointments = [];
}

// Build recent cases HTML
$recentCasesHtml = '';
if (empty($recentCases)) {
    $recentCasesHtml = '<tr><td colspan="4" class="text-center text-muted py-4">No cases assigned yet</td></tr>';
} else {
    foreach ($recentCases as $case) {
        $statusBadge = '';
        switch ($case['status']) {
            case 'open': $statusBadge = '<span class="badge bg-success">Open</span>'; break;
            case 'in_progress': $statusBadge = '<span class="badge bg-primary">In Progress</span>'; break;
            case 'closed': $statusBadge = '<span class="badge bg-secondary">Closed</span>'; break;
            default: $statusBadge = '<span class="badge bg-light">' . htmlspecialchars($case['status']) . '</span>';
        }

        $recentCasesHtml .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-folder-17 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($case['title']) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($case['first_name'] . ' ' . $case['last_name']) . '</p>
                    </div>
                </div>
            </td>
            <td class="text-center align-middle">' . $statusBadge . '</td>
            <td class="text-center">
                <span class="text-xs text-muted">' . date('M d, Y', strtotime($case['created_at'])) . '</span>
            </td>
            <td class="text-center align-middle">
                <a href="lawyer-case-view.php?id=' . (int)$case['id'] . '" class="btn btn-sm btn-primary mb-0">View</a>
            </td>
        </tr>';
    }
}

// Build upcoming appointments HTML
$upcomingAppointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $upcomingAppointmentsHtml = '<tr><td colspan="4" class="text-center text-muted py-4">No upcoming appointments</td></tr>';
} else {
    foreach ($upcomingAppointments as $appointment) {
        $upcomingAppointmentsHtml .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md me-3">
                        <i class="ni ni-time-alarm text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($appointment['case_title']) . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']) . '</p>
                    </div>
                </div>
            </td>
            <td class="text-center">
                <span class="text-sm font-weight-bold">' . date('M d, Y', strtotime($appointment['starts_at'])) . '</span>
                <p class="text-xs text-muted mb-0">' . date('g:i A', strtotime($appointment['starts_at'])) . '</p>
            </td>
            <td>
                <span class="text-sm">' . htmlspecialchars($appointment['description'] ?: 'No description') . '</span>
            </td>
        </tr>';
    }
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
    <title>LegalPro - Lawyer Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=3" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-dashboard-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <div>
                    <h6 class="font-weight-bolder mb-0">Dashboard</h6>
                    <p class="dashboard-welcome-sub mb-0 mt-1">Welcome back, {LAWYER_NAME}</p>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <!-- Statistics Cards - First Row -->
            <div class="row mb-4">
                <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Cases</p>
                                        <h5 class="font-weight-bolder mb-0">{TOTAL_CASES}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                        <i class="ni ni-collection text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Cases</p>
                                        <h5 class="font-weight-bolder mb-0">{ACTIVE_CASES}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                        <i class="ni ni-active-40 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-sm-6">
                    <div class="card">
                        <div class="card-body p-3">
                            <div class="row">
                                <div class="col-8">
                                    <div class="numbers">
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">My Clients</p>
                                        <h5 class="font-weight-bolder mb-0">{TOTAL_CLIENTS}</h5>
                                    </div>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                        <i class="ni ni-circle-08 text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Recent Cases and Upcoming Appointments -->
            <div class="row">
                <!-- Recent Cases -->
                <div class="col-lg-7 mb-4">
                    <div class="card">
                        <div class="card-header pb-0 p-3">
                            <div class="d-flex align-items-center">
                                <div class="icon icon-shape icon-md bg-gradient-primary shadow text-center border-radius-md me-3">
                                    <i class="ni ni-collection text-white text-lg opacity-10"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">Recent Cases</h6>
                                    <p class="text-sm text-muted mb-0">Your most recently assigned cases</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date</th>
                                            <th class="text-secondary opacity-7"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {RECENT_CASES}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Appointments -->
                <div class="col-lg-5 mb-4">
                    <div class="card">
                        <div class="card-header pb-0 p-3">
                            <div class="d-flex align-items-center">
                                <div class="icon icon-shape icon-md bg-gradient-success shadow text-center border-radius-md me-3">
                                    <i class="ni ni-time-alarm text-white text-lg opacity-10"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">Upcoming Appointments</h6>
                                    <p class="text-sm text-muted mb-0">Your next scheduled appointments</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Appointment</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date & Time</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {UPCOMING_APPOINTMENTS}
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
                        <div class="copyright text-center text-sm text-white text-lg-start">
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
    '{LAWYER_NAME}' => htmlspecialchars($lawyerName),
    '{TOTAL_CASES}' => $stats['total_cases'],
    '{ACTIVE_CASES}' => $stats['active_cases'],
    '{TOTAL_CLIENTS}' => $stats['total_clients'],
    '{RECENT_CASES}' => $recentCasesHtml,
    '{UPCOMING_APPOINTMENTS}' => $upcomingAppointmentsHtml,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo $html;
?>
