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

// Get search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch clients associated with this lawyer's cases
$query = "
    SELECT DISTINCT cl.*,
           COUNT(DISTINCT c.id) as total_cases,
           COUNT(DISTINCT CASE WHEN c.status != 'closed' THEN c.id END) as active_cases,
           GROUP_CONCAT(DISTINCT c.title SEPARATOR '; ') as case_titles
    FROM clients cl
    INNER JOIN cases c ON c.client_id = cl.id
    INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
    WHERE cl2.lawyer_id = ?
";

$params = [$lawyerId];

if (!empty($search)) {
    $query .= " AND (cl.first_name LIKE ? OR cl.last_name LIKE ? OR cl.email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " GROUP BY cl.id ORDER BY cl.last_name, cl.first_name";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
} catch (PDOException $e) {
    $clients = [];
}

// Build clients table HTML
$clientsTable = '';
if (empty($clients)) {
    $clientsTable = '<tr><td colspan="5" class="text-center text-muted py-4">No clients found</td></tr>';
} else {
    foreach ($clients as $client) {
        $fullName = htmlspecialchars($client['first_name'] . ' ' . $client['last_name']);
        $caseTitles = htmlspecialchars($client['case_titles']);
        $caseTitlesShort = strlen($caseTitles) > 50 ? substr($caseTitles, 0, 50) . '...' : $caseTitles;

        $clientsTable .= '
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                        <i class="ni ni-circle-08 text-white text-xs opacity-10"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 text-sm">' . $fullName . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($client['email']) . '</p>
                    </div>
                </div>
            </td>
            <td>' . htmlspecialchars($client['phone'] ?: 'Not provided') . '</td>
            <td class="text-center">
                <span class="badge bg-primary">' . (int)$client['total_cases'] . ' total</span><br>
                <span class="badge bg-success">' . (int)$client['active_cases'] . ' active</span>
            </td>
            <td>
                <span class="text-sm" title="' . $caseTitles . '">' . $caseTitlesShort . '</span>
            </td>
            <td class="text-end">
                <a href="lawyer-client-view.php?id=' . (int)$client['id'] . '" class="btn btn-sm btn-primary">View Details</a>
            </td>
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
                <a class="nav-link active" href="lawyer-clients.php">
                    <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="ni ni-circle-08 text-info text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">My Clients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="lawyer-appointments.php">
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
    <title>LegalPro - My Clients</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-clients-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Clients</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">My Clients</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <!-- Search -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <form method="GET" class="row align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label">Search Clients</label>
                                    <input type="text" class="form-control" name="search" value="{SEARCH_VALUE}" placeholder="Search by name or email">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label d-block invisible">Search</label>
                                    <button type="submit" class="btn btn-primary w-100 mb-0">Search</button>
                                </div>
                                <div class="col-md-2 text-end">
                                    <p class="text-sm text-muted mb-0">Total: {TOTAL_CLIENTS} clients</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clients Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center">
                                <div class="icon icon-shape icon-md bg-gradient-primary shadow text-center border-radius-md me-3">
                                    <i class="ni ni-circle-08 text-white text-lg opacity-10"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0">My Clients</h6>
                                    <p class="text-xs text-muted mb-0">Clients from your assigned cases</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Phone</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Cases</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Associated Cases</th>
                                            <th class="text-secondary opacity-7"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {CLIENTS_TABLE}
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
    '{SEARCH_VALUE}' => htmlspecialchars($search),
    '{TOTAL_CLIENTS}' => count($clients),
    '{CLIENTS_TABLE}' => $clientsTable,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo $html;
?>
