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

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$query = "
    SELECT c.*, cl.first_name, cl.last_name, cl.email, cl.phone,
           GROUP_CONCAT(DISTINCT l.first_name, ' ', l.last_name SEPARATOR ', ') as assigned_lawyers
    FROM cases c
    INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
    INNER JOIN clients cl ON cl.id = c.client_id
    INNER JOIN lawyers l ON l.id = cl2.lawyer_id
    WHERE cl2.lawyer_id = ?
";

$params = [$lawyerId];

if ($statusFilter !== 'all') {
    $query .= " AND c.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $query .= " AND (c.title LIKE ? OR cl.first_name LIKE ? OR cl.last_name LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " GROUP BY c.id ORDER BY c.created_at DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $cases = $stmt->fetchAll();
} catch (PDOException $e) {
    $cases = [];
}

$iconCaseRow = legalpro_icon('briefcase');
$iconCaseEmpty = legalpro_icon('briefcase');
$iconCardHeader = legalpro_icon('briefcase');

// Build cases table HTML
$casesTable = '';
if (empty($cases)) {
    $casesTable = '<tr><td colspan="7" class="border-0"><div class="text-center py-5 px-4">
        <div class="lp-empty-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary mx-auto d-flex align-items-center justify-content-center">' . $iconCaseEmpty . '</div>
        <h5 class="font-weight-bolder mt-3 mb-2">No cases found</h5>
        <p class="text-sm text-muted mb-0">Try adjusting your search or status filter.</p>
    </div></td></tr>';
} else {
    foreach ($cases as $case) {
        $statusBadge = client_case_status_badge((string) ($case['status'] ?? ''));
        $priorityBadge = client_case_priority_badge((string) ($case['priority'] ?? 'Normal'));
        $categoryLabel = trim((string) ($case['category'] ?? ''));
        $categoryPill = $categoryLabel !== ''
            ? '<span class="lc-category-pill">' . htmlspecialchars($categoryLabel) . '</span>'
            : '<span class="ca-status-pill ca-status-pill--muted">—</span>';

        $casesTable .= '
        <tr>
            <td class="align-middle">
                <div class="d-flex align-items-center">
                    <div class="lawyer-cases-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0 me-3">' . $iconCaseRow . '</div>
                    <div>
                        <h6 class="mb-0 text-sm">' . htmlspecialchars($case['title']) . '</h6>
                        <p class="text-xs text-muted mb-0">Case #' . htmlspecialchars($case['id']) . '</p>
                    </div>
                </div>
            </td>
            <td class="align-middle">
                <div class="lc-category-cell">' . $categoryPill . '</div>
            </td>
            <td class="align-middle">
                <h6 class="mb-0 text-sm">' . htmlspecialchars($case['first_name'] . ' ' . $case['last_name']) . '</h6>
                <p class="text-xs text-muted mb-0">' . htmlspecialchars($case['email']) . '</p>
            </td>
            <td class="align-middle text-center">
                <div class="lc-table-pill-cell">' . $statusBadge . '</div>
            </td>
            <td class="align-middle text-center">
                <div class="lc-table-pill-cell">' . $priorityBadge . '</div>
            </td>
            <td class="align-middle text-center">
                <span class="text-xs text-muted">' . date('M d, Y', strtotime($case['created_at'])) . '</span>
            </td>
            <td class="align-middle text-end">
                <a href="lawyer-case-view.php?id=' . (int)$case['id'] . '" class="btn btn-sm btn-primary">View Details</a>
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
    <title>LegalPro - My Cases</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-cases-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Cases</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">My Cases</h6>
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
                                    <label class="form-label">Search Cases</label>
                                    <input type="text" class="form-control" name="search" value="{SEARCH_VALUE}" placeholder="Case title or client name">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status Filter</label>
                                    <select class="form-select" name="status">
                                        <option value="all"{STATUS_ALL}>All Cases</option>
                                        <option value="open"{STATUS_OPEN}>Open</option>
                                        <option value="in_progress"{STATUS_IN_PROGRESS}>In Progress</option>
                                        <option value="closed"{STATUS_CLOSED}>Closed</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label d-block invisible">Filter</label>
                                    <button type="submit" class="btn btn-primary w-100 mb-0">Filter</button>
                                </div>
                                <div class="col-md-3 text-end">
                                    <p class="text-sm text-muted mb-0">Total: {TOTAL_CASES} cases</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cases Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-4">
                        <div class="card-header pb-0 pt-3">
                            <div class="d-flex align-items-center">
                                <div class="lp-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary me-3">{ICON_CARD_HEADER}</div>
                                <div>
                                    <h6 class="mb-0">My Cases</h6>
                                    <p class="text-xs text-muted mb-0">Cases assigned to you</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case Details</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Category</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Priority</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Created</th>
                                            <th class="text-secondary opacity-7"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {CASES_TABLE}
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
    '{ICON_CARD_HEADER}' => $iconCardHeader,
    '{NAVIGATION}' => $navHtml,
    '{SEARCH_VALUE}' => htmlspecialchars($search),
    '{STATUS_ALL}' => $statusFilter === 'all' ? ' selected' : '',
    '{STATUS_OPEN}' => $statusFilter === 'open' ? ' selected' : '',
    '{STATUS_IN_PROGRESS}' => $statusFilter === 'in_progress' ? ' selected' : '',
    '{STATUS_CLOSED}' => $statusFilter === 'closed' ? ' selected' : '',
    '{TOTAL_CASES}' => count($cases),
    '{CASES_TABLE}' => $casesTable,
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo $html;
?>
