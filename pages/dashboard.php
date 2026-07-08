<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/admin-locale.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

// ─── Schema migrations (safe idempotent) ─────────────────────────────────────
foreach ([
    "ALTER TABLE cases ADD COLUMN user_id INT NULL AFTER client_id",
    "ALTER TABLE cases ADD COLUMN priority VARCHAR(50) DEFAULT 'Normal' AFTER status",
    "ALTER TABLE cases ADD COLUMN category VARCHAR(50) DEFAULT 'Civil' AFTER priority",
    "ALTER TABLE cases ADD COLUMN estimated_fees DECIMAL(10,2) DEFAULT 0.00 AFTER category",
    "ALTER TABLE cases ADD COLUMN start_date DATE NULL AFTER estimated_fees",
    "ALTER TABLE cases ADD COLUMN expected_completion DATE NULL AFTER start_date",
] as $sql) {
    try { $pdo->query($sql); }
    catch (PDOException $e) { if (stripos($e->getMessage(), 'duplicate column name') === false) throw $e; }
}

// ─── Core KPIs ────────────────────────────────────────────────────────────────
$totalCases = $activeCases = $completedCases = $pendingTasks = $newCasesThisWeek = 0;
$dueToday = $appointmentsToday = $appointmentsThisWeek = $unpaidInvoices = 0;

try {
    $totalCases      = (int)$pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn();
    $activeCases     = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status != 'closed'")->fetchColumn();
    $completedCases  = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'closed'")->fetchColumn();
    $newCasesThisWeek= (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $pendingTasks    = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();
    $dueToday        = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(starts_at) = CURDATE() AND status = 'pending'")->fetchColumn();
    $appointmentsToday = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(starts_at) = CURDATE()")->fetchColumn();
    $appointmentsThisWeek = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE starts_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND starts_at < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)")->fetchColumn();
    $unpaidInvoices  = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status IS NULL OR LOWER(status) NOT IN ('paid','cancelled')")->fetchColumn();
} catch (PDOException $e) {}

// ─── Case category breakdown (for donut chart) ────────────────────────────────
$caseByCategory = [];
try {
    $stmt = $pdo->query("SELECT COALESCE(category,'Uncategorised') AS cat, COUNT(*) AS cnt FROM cases GROUP BY cat ORDER BY cnt DESC LIMIT 6");
    foreach ($stmt->fetchAll() as $r) $caseByCategory[] = $r;
} catch (PDOException $e) {}

require_once __DIR__ . '/../lib/admin-dashboard-activity.php';
$dashboardActivityItems = legalpro_admin_get_activity_feed($pdo, 60);
$dashboardActivityHtml = legalpro_admin_render_activity_feed_html($dashboardActivityItems);

// ─── Financial overview — last 6 months ───────────────────────────────────────
$chartLabels = $chartInvoiced = $chartPaid = [];
for ($i = 5; $i >= 0; $i--) {
    $mk = date('Y-m', strtotime("-$i months"));
    $chartLabels[] = date('M', strtotime($mk . '-01'));
    $inv = $paid = 0;
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE DATE_FORMAT(COALESCE(issue_date,created_at),'%Y-%m')=?");
        $s->execute([$mk]); $inv = (float)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE_FORMAT(COALESCE(payment_date,created_at),'%Y-%m')=?");
        $s->execute([$mk]); $paid = (float)$s->fetchColumn();
    } catch (PDOException $e) {}
    $chartInvoiced[] = round($inv, 2);
    $chartPaid[]     = round($paid, 2);
}
$chartPaidTotal    = array_sum($chartPaid);
$chartInvoicedTotal = array_sum($chartInvoiced);
$collectionRate    = $chartInvoicedTotal > 0 ? round(($chartPaidTotal / $chartInvoicedTotal) * 100) : 0;

// ─── Recent cases ─────────────────────────────────────────────────────────────
$recentCases = [];
try {
    $stmt = $pdo->query("
        SELECT c.*, cl.first_name AS cfn, cl.last_name AS cln
        FROM cases c LEFT JOIN clients cl ON cl.id=c.client_id
        ORDER BY c.created_at DESC LIMIT 8
    ");
    $recentCases = $stmt->fetchAll();
} catch (PDOException $e) {}

// ─── Derived ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../inc/legalpro-icons.php';
$completionRate     = $totalCases > 0 ? round(($completedCases / $totalCases) * 100) : 0;
$adminDisplayName   = $_SESSION['admin_username'] ?? 'Admin';
$welcomeDate        = date('l, j F Y');

// ─── Build HTML fragments ────────────────────────────────────────────────────
// Recent cases
$recentCasesHtml = '';
if (empty($recentCases)) {
    $recentCasesHtml = '<p class="text-sm text-muted text-center py-3">No cases yet. <a href="case-new.php">Create your first case</a></p>';
} else {
    foreach ($recentCases as $c) {
        $num      = 'C-'.str_pad($c['id'],4,'0',STR_PAD_LEFT);
        $title    = htmlspecialchars($c['title']);
        $client   = trim(($c['cfn'] ?? '').' '.($c['cln'] ?? '')) ?: 'Unassigned';
        $status   = strtolower($c['status'] ?? 'open');
        $slabel   = ucfirst(str_replace('_',' ',$status));
        $badge    = match(true) {
            $status === 'closed'      => 'bg-gradient-success',
            $status === 'in_progress' => 'bg-gradient-warning',
            default                   => 'bg-gradient-primary',
        };
        $date = isset($c['created_at']) ? date('M d, Y', strtotime($c['created_at'])) : 'N/A';
        $recentCasesHtml .= "<a href=\"case-view.php?id={$c['id']}\" style=\"text-decoration:none;color:inherit;\">
            <div class=\"dashboard-recent-item d-flex justify-content-between align-items-center mb-1\">
                <div class=\"d-flex flex-column\">
                    <h6 class=\"mb-1 text-sm font-weight-bold vu-panel-title\">{$num} · {$title}</h6>
                    <span class=\"text-xs\">" . htmlspecialchars($client) . " · {$date}</span>
                </div>
                <span class=\"badge {$badge}\">{$slabel}</span>
            </div></a>";
    }
}

// ─── JSON for JS ──────────────────────────────────────────────────────────────
$chartLabelsJson   = json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$chartInvoicedJson = json_encode($chartInvoiced, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$chartPaidJson     = json_encode($chartPaid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$catLabels         = json_encode(array_column($caseByCategory, 'cat'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$catData           = json_encode(array_map(fn($r) => (int)$r['cnt'], $caseByCategory), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
ob_start();
?>
<!DOCTYPE html>
<html lang="<?= admin_portal_html_lang() ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Dashboard — LegalPro Case Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/admin-portal-head.php'; ?>
    <link href="../assets/css/vision-ui-dashboard.css?v=11" rel="stylesheet" />

    <style>
        /* Inline extras not yet in the drop-in CSS */
        .lp-kpi-delta {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 0.72rem; font-weight: 700;
        }
        .lp-kpi-delta--up   { color: var(--vu-success, #2dce89); }
        .lp-kpi-delta--down { color: var(--vu-danger, #f5365c); }
        .lp-collection-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: var(--vu-collection-bg, rgba(45,206,137,0.1));
            color: var(--vu-collection-text, #1e9e6a);
            font-size: 0.72rem; font-weight: 700;
            padding: 3px 10px; border-radius: 99px;
        }
        .lp-section-hd {
            font-size: 0.9rem; font-weight: 700;
            color: var(--vu-text, #1e293b); margin-bottom: 0;
        }
        .lp-section-sub {
            font-size: 0.75rem;
            color: var(--vu-muted, #94a3b8); margin-top: 2px;
        }
        .cat-legend-label { color: var(--vu-muted, #64748b); }
        .cat-legend-pct { color: var(--vu-text, #1e293b); }
        .lp-cmd-hint {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.71rem; color: var(--vu-muted, #94a3b8);
        }
        .lp-cmd-hint kbd {
            padding: 1px 5px; border: 1px solid var(--vu-border, #e2e8f0);
            border-radius: 4px; background: var(--vu-bg-soft, #f8fafc);
            font-family: inherit; font-size: 0.68rem; font-weight: 600;
            color: var(--vu-muted, #64748b); box-shadow: 0 1px 0 var(--vu-border, #e2e8f0);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-dashboard-page vision-ui-theme<?php echo legalpro_portal_theme_body_class(); ?>">
<div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>

<?php
// Sidebar
ob_start();
include __DIR__ . '/../inc/menunav.php';
echo ob_get_clean();
?>

<main class="main-content position-relative border-radius-lg">

    <!-- ── Sticky top navbar ──────────────────────────────────────────── -->
    <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="true">
        <div class="container-fluid py-1 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="legalpro-navbar-heading">
                <p class="vu-breadcrumb mb-0">Pages / <strong>Dashboard</strong></p>
                <h6 class="vu-page-title font-weight-bolder mb-0">Dashboard</h6>
                <p class="dashboard-welcome-sub mb-0">
                    Welcome back, <?= htmlspecialchars($adminDisplayName) ?> &nbsp;·&nbsp; <?= htmlspecialchars($welcomeDate) ?>
                </p>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4 px-4">

        <!-- ── FOUR KPI STAT CARDS ─────────────────────────────────────── -->
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-4 mb-xl-0">
                <a href="tables.php" style="text-decoration:none;color:inherit;">
                    <div class="card dashboard-stat-card">
                        <div class="card-body p-3">
                            <div class="row align-items-center">
                                <div class="col-8">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Cases</p>
                                    <h5 class="font-weight-bolder"><?= $totalCases ?></h5>
                                    <p class="mb-0">
                                        <span class="lp-kpi-delta lp-kpi-delta--up">↑ <?= $newCasesThisWeek ?></span>
                                        <span class="text-muted ms-1" style="font-size:.75rem;">this week</span>
                                    </p>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">
                                        <?= legalpro_icon('briefcase') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-sm-6 mb-4 mb-xl-0">
                <a href="tables.php" style="text-decoration:none;color:inherit;">
                    <div class="card dashboard-stat-card">
                        <div class="card-body p-3">
                            <div class="row align-items-center">
                                <div class="col-8">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Cases</p>
                                    <h5 class="font-weight-bolder"><?= $activeCases ?></h5>
                                    <p class="mb-0"><span class="text-info text-sm font-weight-bold">In Progress</span></p>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--danger">
                                        <?= legalpro_icon('message-circle') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-sm-6 mb-4 mb-xl-0">
                <a href="tables.php" style="text-decoration:none;color:inherit;">
                    <div class="card dashboard-stat-card">
                        <div class="card-body p-3">
                            <div class="row align-items-center">
                                <div class="col-8">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Completed</p>
                                    <h5 class="font-weight-bolder"><?= $completedCases ?></h5>
                                    <p class="mb-0">
                                        <span class="lp-kpi-delta lp-kpi-delta--up"><?= $completionRate ?>%</span>
                                        <span class="text-muted ms-1" style="font-size:.75rem;">completion rate</span>
                                    </p>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--success">
                                        <?= legalpro_icon('file-text') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-xl-3 col-sm-6">
                <a href="appointments.php" style="text-decoration:none;color:inherit;">
                    <div class="card dashboard-stat-card">
                        <div class="card-body p-3">
                            <div class="row align-items-center">
                                <div class="col-8">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Pending Tasks</p>
                                    <h5 class="font-weight-bolder"><?= $pendingTasks ?></h5>
                                    <p class="mb-0">
                                        <span class="lp-kpi-delta lp-kpi-delta--down"><?= $dueToday ?></span>
                                        <span class="text-muted ms-1" style="font-size:.75rem;">due today</span>
                                    </p>
                                </div>
                                <div class="col-4 text-end">
                                    <div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--warning">
                                        <?= legalpro_icon('list-checks') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- ── WELCOME + INSIGHTS (Vision UI row) ─────────────────────── -->
        <div class="row mb-4">
            <div class="col-lg-7 mb-4 mb-lg-0">
                <div class="card vu-welcome-card h-100 mb-0">
                    <h4>Welcome back, <?= htmlspecialchars($adminDisplayName) ?></h4>
                    <p>Monitor your legal operations, track cases, appointments, and financial performance from one place.</p>
                    <a href="case-new.php" class="vu-welcome-btn">Create new case →</a>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="card vu-mini-card h-100 mb-0 text-center">
                            <p class="lp-section-hd mb-1">Completion Rate</p>
                            <p class="lp-section-sub">Closed vs total cases</p>
                            <div class="vu-gauge" style="--pct: <?= (int) $completionRate ?>;">
                                <span><?= $completionRate ?>%</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card vu-mini-card h-100 mb-0">
                            <p class="lp-section-hd mb-1">Operations</p>
                            <p class="lp-section-sub">Live activity snapshot</p>
                            <div class="vu-track-stats">
                                <div class="vu-track-stat">
                                    <strong><?= $appointmentsThisWeek ?></strong>
                                    <small>Appointments this week</small>
                                </div>
                                <div class="vu-track-stat">
                                    <strong><?= $collectionRate ?>%</strong>
                                    <small>Collection rate</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CHARTS ROW ──────────────────────────────────────────────── -->
        <div class="vu-dashboard-grid mt-2 mb-4">
            <div class="card vu-financial-chart-card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <p class="lp-section-hd">Financial Overview</p>
                        <p class="lp-section-sub">Invoiced vs collected · last 6 months</p>
                    </div>
                    <span class="lp-collection-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                        <?= $collectionRate ?>% collected
                    </span>
                </div>
                <div class="card-body pt-2 pb-3">
                    <div class="vu-financial-chart-wrap">
                        <canvas id="chart-financial" aria-label="Line chart: invoiced vs collected over 6 months">Financial chart unavailable.</canvas>
                    </div>
                </div>
            </div>

            <div class="card vu-side-panel vu-activity-panel">
                <div class="card-header pb-2">
                    <p class="lp-section-hd"><?= htmlspecialchars(admin_t('activity.title')) ?></p>
                    <p class="lp-section-sub"><?= htmlspecialchars(admin_t('activity.subtitle')) ?></p>
                </div>
                <div class="card-body pt-0">
                    <?= $dashboardActivityHtml ?>
                </div>
            </div>

            <div class="card vu-recent-cases-card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <p class="lp-section-hd">Recent Cases</p>
                        <p class="lp-section-sub">Latest activity across all cases</p>
                    </div>
                    <a href="tables.php" class="btn btn-sm btn-outline-primary mb-0" style="border-radius:99px!important;font-size:.74rem!important;">View all</a>
                </div>
                <div class="card-body py-2">
                    <?= $recentCasesHtml ?>
                </div>
            </div>

            <div class="card vu-side-panel vu-category-panel">
                <div class="card-header pb-2">
                    <p class="lp-section-hd">Cases by Category</p>
                    <p class="lp-section-sub">Practice area split</p>
                </div>
                <div class="card-body d-flex flex-column align-items-center justify-content-center pt-0">
                    <div class="vu-category-chart-wrap">
                        <canvas id="chart-categories" aria-label="Doughnut chart of case categories">Category chart unavailable.</canvas>
                    </div>
                    <div id="cat-legend" class="vu-category-legend"></div>
                </div>
            </div>
        </div>

        <footer class="footer pt-3 pb-4">
            <div class="container-fluid">
                <div class="copyright text-sm text-muted">
                    <?php echo legalpro_copyright_line(); ?>
                </div>
            </div>
        </footer>
    </div><!-- /container-fluid -->
</main>

<!-- ── SCRIPTS ────────────────────────────────────────────────────────────── -->
<script src="../assets/js/core/popper.min.js"></script>
<script src="../assets/js/core/bootstrap.min.js"></script>
<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
<script src="../assets/js/plugins/chartjs.min.js"></script>

<script>
function vuIsDarkTheme() {
    return document.body.classList.contains('legalpro-dark-mode');
}

function vuChartTheme() {
    var dark = vuIsDarkTheme();
    return {
        tick: dark ? '#A0AEC0' : '#707eae',
        grid: dark ? 'rgba(160, 174, 192, 0.15)' : 'rgba(112, 126, 174, 0.18)',
        doughnutBorder: dark ? '#1a1f37' : '#ffffff',
        invoicedFill: dark ? 'rgba(2, 62, 138, 0.35)' : 'rgba(2, 62, 138, 0.22)',
        collectedFill: dark ? 'rgba(1, 181, 116, 0.28)' : 'rgba(1, 181, 116, 0.2)'
    };
}

/* ── Financial line chart ────────────────────────────────────────────────── */
(function() {
    var ctxEl = document.getElementById('chart-financial');
    if (!ctxEl) return;
    var theme = vuChartTheme();
    var ctx = ctxEl.getContext('2d');
    var g1 = ctx.createLinearGradient(0, 200, 0, 20);
    g1.addColorStop(0, theme.invoicedFill);
    g1.addColorStop(1, 'rgba(2, 62, 138, 0)');
    var g2 = ctx.createLinearGradient(0, 200, 0, 20);
    g2.addColorStop(0, theme.collectedFill);
    g2.addColorStop(1, 'rgba(1, 181, 116, 0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= $chartLabelsJson ?>,
            datasets: [{
                label: 'Invoiced', tension: 0.45, pointRadius: 4,
                pointBackgroundColor: '#023e8a', borderColor: '#023e8a',
                backgroundColor: g1, borderWidth: 3, fill: true,
                data: <?= $chartInvoicedJson ?>
            }, {
                label: 'Collected', tension: 0.45, pointRadius: 4,
                pointBackgroundColor: '#01B574', borderColor: '#01B574',
                backgroundColor: g2, borderWidth: 3, fill: true,
                data: <?= $chartPaidJson ?>
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top',
                    labels: { color: theme.tick, font: { family: 'Inter', size: 11 }, usePointStyle: true, boxWidth: 8 }
                },
                tooltip: { mode: 'index', intersect: false,
                    callbacks: {
                        label: function(c) {
                            return ' ' + c.dataset.label + ': ' + (window.LegalProFormatCurrency ? window.LegalProFormatCurrency(c.parsed.y) : c.parsed.y);
                        }
                    }
                }
            },
            interaction: { intersect: false, mode: 'index' },
            scales: {
                y: { grid: { color: theme.grid, drawBorder: false },
                     ticks: { color: theme.tick, font: { size: 11 },
                              callback: function(v) { return window.LegalProFormatCurrency ? window.LegalProFormatCurrency(v, 0) : v; } } },
                x: { grid: { display: false }, ticks: { color: theme.tick, font: { size: 11 } } }
            }
        }
    });
})();

/* ── Case category doughnut ──────────────────────────────────────────────── */
(function() {
    var ctx = document.getElementById('chart-categories');
    if (!ctx) return;
    var theme = vuChartTheme();
    var labels = <?= $catLabels ?>;
    var data   = <?= $catData ?>;
    var colors = ['#023e8a', '#01B574', '#001845', '#FFB547', '#7551FF', '#E31A1A'];

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: data, backgroundColor: colors, borderWidth: 2, borderColor: theme.doughnutBorder, hoverOffset: 6 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { return ' ' + c.label + ': ' + c.parsed; } } }
            }
        }
    });

    var leg = document.getElementById('cat-legend');
    if (!leg) return;
    var total = data.reduce(function(a, b) { return a + b; }, 0);
    labels.forEach(function(l, i) {
        var pct = total > 0 ? Math.round(data[i] / total * 100) : 0;
        leg.innerHTML += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;">' +
            '<span style="width:10px;height:10px;border-radius:3px;background:' + colors[i] + ';flex-shrink:0;"></span>' +
            '<span class="cat-legend-label" style="flex:1;">' + l + '</span>' +
            '<span class="cat-legend-pct" style="font-weight:700;">' + pct + '%</span></div>';
    });
})();
</script>

<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
<script src="../assets/js/spa-nav.js"></script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
<?php
echo legalpro_apply_copyright_line(ob_get_clean());