<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/lawyer_portal_vocab.php';

ensure_lawyer_case_vocabulary($pdo);

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$priorityFilter = isset($_GET['priority']) ? strtolower(trim((string) $_GET['priority'])) : 'all';
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

if ($priorityFilter !== 'all' && in_array($priorityFilter, lawyer_task_priority_keys(), true)) {
    if ($priorityFilter === 'normal') {
        $query .= " AND LOWER(TRIM(c.priority)) IN ('normal', 'medium', 'low')";
    } elseif ($priorityFilter === 'high') {
        $query .= " AND LOWER(TRIM(c.priority)) = 'high'";
    } else {
        $query .= " AND LOWER(TRIM(c.priority)) = 'urgent'";
    }
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
        <h5 class="font-weight-bolder mt-3 mb-2">' . htmlspecialchars(lawyer_tf('cases.empty_title', 'No cases found')) . '</h5>
        <p class="text-sm text-muted mb-0">' . htmlspecialchars(lawyer_tf('cases.empty_sub', 'Try adjusting your search, status or priority filter.')) . '</p>
    </div></td></tr>';
} else {
    foreach ($cases as $case) {
        $statusBadge = lawyer_case_status_badge((string) ($case['status'] ?? ''));
        $priorityBadge = lawyer_case_priority_badge((string) ($case['priority'] ?? 'Normal'));
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
            <td class="align-middle text-center lc-col-category">
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
            <td class="align-middle text-end lp-table-actions">
                <a href="lawyer-case-view.php?id=' . (int)$case['id'] . '" class="btn btn-sm btn-primary mb-0">' . htmlspecialchars(lawyer_tf('common.view', 'View')) . '</a>
            </td>
        </tr>';
    }
}

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

$pageTitle = lawyer_tf('cases.page_title', 'My Cases');
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-cases-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {BREADCRUMB_NAVBAR}

        <div class="container-fluid py-4">
            <!-- Filters -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <form method="GET" class="row align-items-end g-3">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label">{LBL_SEARCH}</label>
                                    <input type="text" class="form-control" name="search" value="{SEARCH_VALUE}" placeholder="{PH_SEARCH}">
                                </div>
                                <div class="col-lg-2 col-md-3">
                                    <label class="form-label">{LBL_STATUS}</label>
                                    <select class="form-select" name="status">
                                        <option value="all"{STATUS_ALL}>{OPT_ALL_CASES}</option>
                                        <option value="active"{STATUS_ACTIVE}>{OPT_ACTIVE}</option>
                                        <option value="pending"{STATUS_PENDING}>{OPT_PENDING}</option>
                                        <option value="under_review"{STATUS_UNDER_REVIEW}>{OPT_UNDER_REVIEW}</option>
                                        <option value="closed"{STATUS_CLOSED}>{OPT_CLOSED}</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3">
                                    <label class="form-label">{LBL_PRIORITY}</label>
                                    <select class="form-select" name="priority">
                                        <option value="all"{PRIORITY_ALL}>{OPT_ALL_PRIORITIES}</option>
                                        <option value="normal"{PRIORITY_NORMAL}>{OPT_NORMAL}</option>
                                        <option value="high"{PRIORITY_HIGH}>{OPT_HIGH}</option>
                                        <option value="urgent"{PRIORITY_URGENT}>{OPT_URGENT}</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label d-block invisible">{LBL_ACTIONS}</label>
                                    <div class="lp-lawyer-filter-actions">
                                        <button type="submit" class="btn btn-primary mb-0">{BTN_FILTER}</button>
                                        <a href="lawyer-cases.php" class="btn btn-outline-secondary mb-0">{BTN_RESET}</a>
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-12 text-lg-end">
                                    <p class="text-sm text-muted mb-0">{TOTAL_COUNT}</p>
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
                                    <h6 class="mb-0">{PAGE_TITLE}</h6>
                                    <p class="text-xs text-muted mb-0">{CARD_SUBTITLE}</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{COL_DETAILS}</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 lc-col-category">{COL_CATEGORY}</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{LBL_CLIENT}</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{LBL_STATUS}</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{LBL_PRIORITY}</th>
                                            <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">{LBL_CREATED}</th>
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
    '{HTML_LANG}' => lawyer_portal_html_lang(),
    '{PAGE_TITLE}' => htmlspecialchars($pageTitle),
    '{BREADCRUMB_NAVBAR}' => $breadcrumbNavbar,
    '{LBL_SEARCH}' => htmlspecialchars(lawyer_tf('cases.search_label', 'Search Cases')),
    '{PH_SEARCH}' => htmlspecialchars(lawyer_tf('cases.search_placeholder', 'Case title or client name')),
    '{LBL_STATUS}' => htmlspecialchars(lawyer_tf('common.status', 'Status')),
    '{LBL_PRIORITY}' => htmlspecialchars(lawyer_tf('common.priority', 'Priority')),
    '{LBL_CLIENT}' => htmlspecialchars(lawyer_tf('common.client', 'Client')),
    '{LBL_CREATED}' => htmlspecialchars(lawyer_tf('common.created', 'Created')),
    '{LBL_ACTIONS}' => htmlspecialchars(lawyer_tf('common.actions', 'Actions')),
    '{OPT_ALL_CASES}' => htmlspecialchars(lawyer_tf('cases.all_cases', 'All Cases')),
    '{OPT_ACTIVE}' => htmlspecialchars(lawyer_status_label('active')),
    '{OPT_PENDING}' => htmlspecialchars(lawyer_status_label('pending')),
    '{OPT_UNDER_REVIEW}' => htmlspecialchars(lawyer_status_label('under_review')),
    '{OPT_CLOSED}' => htmlspecialchars(lawyer_status_label('closed')),
    '{OPT_ALL_PRIORITIES}' => htmlspecialchars(lawyer_tf('cases.all_priorities', 'All Priorities')),
    '{OPT_NORMAL}' => htmlspecialchars(lawyer_priority_label('normal')),
    '{OPT_HIGH}' => htmlspecialchars(lawyer_priority_label('high')),
    '{OPT_URGENT}' => htmlspecialchars(lawyer_priority_label('urgent')),
    '{BTN_FILTER}' => htmlspecialchars(lawyer_tf('common.filter', 'Filter')),
    '{BTN_RESET}' => htmlspecialchars(lawyer_tf('common.reset', 'Reset')),
    '{TOTAL_COUNT}' => htmlspecialchars(lawyer_tf('cases.total_count', 'Total: :count cases', ['count' => count($cases)])),
    '{CARD_SUBTITLE}' => htmlspecialchars(lawyer_tf('cases.card_subtitle', 'Cases assigned to you')),
    '{COL_DETAILS}' => htmlspecialchars(lawyer_tf('cases.col_details', 'Case Details')),
    '{COL_CATEGORY}' => htmlspecialchars(lawyer_tf('cases.col_category', 'Category')),
    '{ICON_CARD_HEADER}' => $iconCardHeader,
    '{NAVIGATION}' => $navHtml,
    '{SEARCH_VALUE}' => htmlspecialchars($search),
    '{STATUS_ALL}' => $statusFilter === 'all' ? ' selected' : '',
    '{STATUS_ACTIVE}' => $statusFilter === 'active' ? ' selected' : '',
    '{STATUS_PENDING}' => $statusFilter === 'pending' ? ' selected' : '',
    '{STATUS_UNDER_REVIEW}' => $statusFilter === 'under_review' ? ' selected' : '',
    '{STATUS_CLOSED}' => $statusFilter === 'closed' ? ' selected' : '',
    '{PRIORITY_ALL}' => $priorityFilter === 'all' ? ' selected' : '',
    '{PRIORITY_NORMAL}' => $priorityFilter === 'normal' ? ' selected' : '',
    '{PRIORITY_HIGH}' => $priorityFilter === 'high' ? ' selected' : '',
    '{PRIORITY_URGENT}' => $priorityFilter === 'urgent' ? ' selected' : '',
    '{TOTAL_CASES}' => count($cases),
    '{CASES_TABLE}' => $casesTable,
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
?>
