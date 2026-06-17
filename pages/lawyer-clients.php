<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

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

$iconClientRow = legalpro_icon('user');
$iconClientEmpty = legalpro_icon('users');
$iconCardHeader = legalpro_icon('users');

// Build clients table HTML
$clientsTable = '';
if (empty($clients)) {
    $clientsTable = '<tr><td colspan="5" class="border-0"><div class="text-center py-5 px-4">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconClientEmpty . '</div>
        <h5 class="font-weight-bolder mt-3 mb-2">No clients found</h5>
        <p class="text-sm text-muted mb-0">Try adjusting your search.</p>
    </div></td></tr>';
} else {
    foreach ($clients as $client) {
        $fullName = htmlspecialchars($client['first_name'] . ' ' . $client['last_name']);
        $caseTitles = htmlspecialchars($client['case_titles']);
        $caseTitlesShort = strlen($caseTitles) > 50 ? substr($caseTitles, 0, 50) . '...' : $caseTitles;

        $clientsTable .= '
        <tr>
            <td class="align-middle">
                <div class="d-flex align-items-center">
                    <div class="lawyer-client-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconClientRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . $fullName . '</h6>
                        <p class="text-xs text-muted mb-0">' . htmlspecialchars($client['email']) . '</p>
                    </div>
                </div>
            </td>
            <td class="align-middle">' . htmlspecialchars($client['phone'] ?: 'Not provided') . '</td>
            <td class="align-middle text-center">
                <span class="ca-status-pill ca-status-pill--scheduled d-inline-block mb-1">' . (int)$client['total_cases'] . ' total</span><br>
                <span class="ca-status-pill ca-status-pill--done d-inline-block">' . (int)$client['active_cases'] . ' active</span>
            </td>
            <td class="align-middle">
                <span class="text-sm" title="' . $caseTitles . '">' . $caseTitlesShort . '</span>
            </td>
            <td class="align-middle text-end lp-table-actions">
                <a href="lawyer-client-view.php?id=' . (int)$client['id'] . '" class="btn btn-sm btn-primary mb-0">View Details</a>
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
    <title>LegalPro - My Clients</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
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
                                <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary me-3">{ICON_CARD_HEADER}</div>
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
                            {COPYRIGHT_LINE}
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
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

$replacements = [
    '{NAVIGATION}' => $navHtml,
    '{SEARCH_VALUE}' => htmlspecialchars($search),
    '{TOTAL_CLIENTS}' => count($clients),
    '{CLIENTS_TABLE}' => $clientsTable,
    '{ICON_CARD_HEADER}' => $iconCardHeader,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
?>
