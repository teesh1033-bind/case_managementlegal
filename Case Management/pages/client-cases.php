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
    // Get all client cases with lawyer information
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
    ");
    $stmt->execute([$client_id]);
    $cases = $stmt->fetchAll();

} catch (PDOException $e) {
    $message = 'Error loading cases: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
    $cases = [];
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$caseCount = count($cases);

// Build cases table rows
$casesRows = '';
if (empty($cases)) {
    $casesRows = '<tr><td colspan="6" class="border-0">
        <div class="text-center py-5 px-4">
            <div class="cc-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                <i class="ni ni-folder-17 text-primary text-lg opacity-10" aria-hidden="true"></i>
            </div>
            <h5 class="font-weight-bolder mt-4 mb-2">No cases yet</h5>
            <p class="text-sm text-muted mb-4 mx-auto" style="max-width: 22rem;">When your legal team opens a matter for you, it will appear in this list with status, priority, and assigned counsel.</p>
            <a href="client-dashboard.php" class="btn btn-sm btn-primary mb-0">Go to dashboard</a>
        </div>
    </td></tr>';
} else {
    foreach ($cases as $case) {
        $caseId = (int) $case['id'];
        $caseNumber = 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT);
        $lawyerNames = $case['lawyer_names'] ?: 'Unassigned';

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

        switch ($case['priority']) {
            case 'High':
                $priorityBadge = '<span class="badge badge-sm bg-gradient-danger">High</span>';
                break;
            case 'Normal':
                $priorityBadge = '<span class="badge badge-sm bg-gradient-warning">Normal</span>';
                break;
            case 'Low':
                $priorityBadge = '<span class="badge badge-sm bg-gradient-info">Low</span>';
                break;
            default:
                $priorityBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars($case['priority']) . '</span>';
                break;
        }

        $updated = isset($case['updated_at']) ? date('M j, Y', strtotime($case['updated_at'])) : '';

        $casesRows .= '<tr class="cc-case-row">
            <td class="ps-4">
                <div class="d-flex align-items-center gap-3 py-1">
                    <div class="cc-case-icon icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md flex-shrink-0">
                        <i class="ni ni-folder-17 text-white text-xs opacity-10" aria-hidden="true"></i>
                    </div>
                    <div class="min-width-0">
                        <p class="text-xs text-primary font-weight-bold mb-0">' . htmlspecialchars($caseNumber) . '</p>
                        <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 14rem;">' . htmlspecialchars($case['title']) . '</h6>
                        <p class="text-xs text-muted mb-0">Updated ' . htmlspecialchars($updated) . '</p>
                    </div>
                </div>
            </td>
            <td>
                <span class="cc-pill text-xs font-weight-bold">' . htmlspecialchars($case['category']) . '</span>
            </td>
            <td class="align-middle text-center">
                ' . $statusBadge . '
            </td>
            <td class="align-middle text-center">
                ' . $priorityBadge . '
            </td>
            <td>
                <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 10rem;" title="' . htmlspecialchars($lawyerNames) . '">' . htmlspecialchars($lawyerNames) . '</p>
            </td>
            <td class="align-middle text-end pe-4">
                <a href="client-case-view.php?id=' . $caseId . '" class="btn btn-sm btn-outline-primary mb-0">View</a>
            </td>
        </tr>';
    }
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - My Cases</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <style>
        .client-cases-page { --cc-radius: 1.15rem; }
        .client-cases-page .cc-hero {
            border-radius: var(--cc-radius);
            background: #fff;
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-cases-page .cc-hero .cc-hero-kicker {
            letter-spacing: 0.12em;
            color: #5e72e4;
            opacity: 1;
        }
        .client-cases-page .cc-hero .cc-hero-title {
            color: #344767;
        }
        .client-cases-page .cc-hero .cc-hero-text {
            color: #67748e;
        }
        .client-cases-page .cc-hero-stat {
            background: #f8f9fe;
            border-radius: 0.75rem;
            padding: 0.65rem 1rem;
            border: 1px solid rgba(94, 114, 228, 0.15);
        }
        .client-cases-page .cc-hero-stat .cc-hero-stat-label {
            color: #67748e;
        }
        .client-cases-page .cc-hero-stat .cc-hero-stat-value {
            color: #344767;
        }
        .client-cases-page .cc-panel {
            border-radius: var(--cc-radius);
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 0.25rem 1.1rem rgba(52, 71, 103, 0.07);
            overflow: hidden;
        }
        .client-cases-page .cc-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.15rem 1.35rem 1rem;
        }
        .client-cases-page .cc-panel .card-header h5 {
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .client-cases-page .cc-panel .table thead th {
            font-size: 0.65rem;
            letter-spacing: 0.06em;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            background: rgba(248, 249, 250, 0.95);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-cases-page .cc-case-row td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: middle;
        }
        .client-cases-page .cc-case-row:hover td {
            background: rgba(94, 114, 228, 0.04);
        }
        .client-cases-page .cc-case-icon {
            width: 2.35rem;
            height: 2.35rem;
        }
        .client-cases-page .min-width-0 { min-width: 0; }
        .client-cases-page .cc-pill {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 2rem;
            background: rgba(94, 114, 228, 0.08);
            color: #324cdd;
        }
        .client-cases-page .cc-empty-icon {
            width: 4rem;
            height: 4rem;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal client-cases-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
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
                    <a class="nav-link active" href="client-cases.php">
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
                        <li class="breadcrumb-item text-sm"><a class="opacity-6 text-white" href="client-dashboard.php">Client</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Cases</li>
                    </ol>
                    <h5 class="font-weight-bolder mb-0 text-white">My Cases</h5>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search cases…" value="" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="javascript:;" class="nav-link text-white font-weight-bold px-0">
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
                    <div class="card cc-hero mb-0">
                        <div class="card-body p-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <div>
                                <p class="cc-hero-kicker text-xs text-uppercase font-weight-bold mb-1">Your matters</p>
                                <h4 class="cc-hero-title font-weight-bolder mb-1">All cases in one place</h4>
                                <p class="cc-hero-text text-sm mb-0">Review status, priority, and who is representing you on each file.</p>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <div class="cc-hero-stat text-center text-md-start">
                                    <p class="cc-hero-stat-label text-xs mb-0">Total cases</p>
                                    <p class="cc-hero-stat-value font-weight-bolder mb-0" style="font-size: 1.75rem; line-height: 1.2;">{CASE_COUNT}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card cc-panel mb-4">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">Case list</h5>
                                <p class="text-sm text-muted mb-0">Sorted by most recently updated.</p>
                            </div>
                            <a href="client-dashboard.php" class="btn btn-sm btn-outline-primary mb-0">Dashboard</a>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Category</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Priority</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Lawyer(s)</th>
                                            <th class="text-secondary opacity-7"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {CASES_ROWS}
                                    </tbody>
                                </table>
                            </div>
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
$html = str_replace('{CASE_COUNT}', (string) $caseCount, $html);
$html = str_replace('{CASES_ROWS}', $casesRows, $html);

echo $html;