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

// ─── Case status breakdown (for bar chart) ────────────────────────────────────
$caseByStatus = [];
try {
    $stmt = $pdo->query("SELECT COALESCE(status,'open') AS st, COUNT(*) AS cnt FROM cases GROUP BY st ORDER BY cnt DESC");
    foreach ($stmt->fetchAll() as $r) $caseByStatus[$r['st']] = (int)$r['cnt'];
} catch (PDOException $e) {}

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

// ─── Calendar events ──────────────────────────────────────────────────────────
$calendarEvents = [];
try {
    $stmt = $pdo->query("
        SELECT a.id, a.case_id, a.starts_at, a.ends_at, a.notes, a.status,
               CONCAT('C-',LPAD(cs.id,4,'0'),' · ',cs.title) AS case_display,
               TRIM(CONCAT(cl.first_name,' ',cl.last_name)) AS client_name,
               TRIM(CONCAT(l.first_name,' ',l.last_name)) AS lawyer_name
        FROM appointments a
        LEFT JOIN cases cs ON cs.id=a.case_id
        LEFT JOIN clients cl ON cl.id=cs.client_id
        LEFT JOIN lawyers l ON l.id=a.lawyer_id
        WHERE a.starts_at IS NOT NULL ORDER BY a.starts_at ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $st = strtolower($row['status'] ?? 'pending');
        $calendarEvents[] = [
            'id' => (string)$row['id'],
            'title' => $row['case_display'] ?: 'General appointment',
            'start' => $row['starts_at'],
            'end'   => $row['ends_at'] ?: null,
            'backgroundColor' => 'transparent',
            'borderColor'     => 'transparent',
            'textColor'       => '#344767',
            'extendedProps' => [
                'client'        => $row['client_name'] ?: 'Unknown',
                'lawyer'        => $row['lawyer_name'] ?: 'Unassigned',
                'notes'         => $row['notes'] ?? '',
                'status'        => $st,
                'statusLabel'   => ucfirst($st === 'approved' ? 'accepted' : $st),
                'appointmentId' => (int)$row['id'],
            ],
        ];
    }
} catch (PDOException $e) {}

// ─── Upcoming sidebar ─────────────────────────────────────────────────────────
$upcomingAppointments = [];
try {
    $stmt = $pdo->query("
        SELECT a.id, a.starts_at, a.status,
               CONCAT('C-',LPAD(cs.id,4,'0'),' · ',cs.title) AS case_display,
               TRIM(CONCAT(cl.first_name,' ',cl.last_name)) AS client_name
        FROM appointments a
        LEFT JOIN cases cs ON cs.id=a.case_id
        LEFT JOIN clients cl ON cl.id=cs.client_id
        WHERE a.starts_at >= NOW() ORDER BY a.starts_at ASC LIMIT 8
    ");
    $upcomingAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// ─── Top clients by case count ────────────────────────────────────────────────
$topClients = [];
try {
    $stmt = $pdo->query("
        SELECT TRIM(CONCAT(cl.first_name,' ',cl.last_name)) AS name,
               COUNT(c.id) AS case_count,
               SUM(CASE WHEN c.status='closed' THEN 1 ELSE 0 END) AS closed_count
        FROM clients cl LEFT JOIN cases c ON c.client_id=cl.id
        GROUP BY cl.id ORDER BY case_count DESC LIMIT 5
    ");
    $topClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                    <h6 class=\"mb-1 text-dark text-sm font-weight-bold\">{$num} · {$title}</h6>
                    <span class=\"text-xs\">" . htmlspecialchars($client) . " · {$date}</span>
                </div>
                <span class=\"badge {$badge}\">{$slabel}</span>
            </div></a>";
    }
}

// Upcoming appointments
$upcomingHtml = '';
if (empty($upcomingAppointments)) {
    $upcomingHtml = '<div class="dashboard-upcoming-empty">' . legalpro_icon('calendar') . '<span>No upcoming appointments</span></div>';
} else {
    foreach ($upcomingAppointments as $up) {
        $st    = htmlspecialchars(strtolower($up['status'] ?? 'pending'));
        $time  = date('g:i A', strtotime($up['starts_at']));
        $date2 = date('M j', strtotime($up['starts_at']));
        $title = htmlspecialchars($up['case_display'] ?: 'Appointment');
        $cli   = htmlspecialchars($up['client_name'] ?: '—');
        $upcomingHtml .= "<button type=\"button\" class=\"dashboard-upcoming-item dashboard-upcoming-item--{$st}\" data-appointment-id=\"{$up['id']}\">
            <span class=\"dashboard-upcoming-item__time\">{$time}<br><small>{$date2}</small></span>
            <span class=\"flex-grow-1\">
                <p class=\"dashboard-upcoming-item__title\">{$title}</p>
                <p class=\"dashboard-upcoming-item__sub\">{$cli}</p>
            </span></button>";
    }
}

// Top clients
$topClientsHtml = '';
if (empty($topClients)) {
    $topClientsHtml = '<p class="text-sm text-muted text-center py-2">No client data yet.</p>';
} else {
    $maxCount = max(array_column($topClients, 'case_count')) ?: 1;
    foreach ($topClients as $tc) {
        $n   = htmlspecialchars($tc['name']);
        $cnt = (int)$tc['case_count'];
        $cls = (int)$tc['closed_count'];
        $pct = round(($cnt / $maxCount) * 100);
        $initials = implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', $tc['name'])));
        $initials = substr($initials, 0, 2);
        $topClientsHtml .= "<div class=\"lp-top-client d-flex align-items-center gap-2 mb-3\">
            <div class=\"lp-avatar\">{$initials}</div>
            <div class=\"flex-grow-1\">
                <div class=\"d-flex justify-content-between align-items-baseline mb-1\">
                    <span class=\"text-sm font-weight-bold text-dark\">{$n}</span>
                    <span class=\"text-xs text-muted\">{$cnt} case" . ($cnt !== 1 ? 's' : '') . "</span>
                </div>
                <div class=\"lp-progress-bar\">
                    <div class=\"lp-progress-fill\" style=\"width:{$pct}%\"></div>
                </div>
            </div></div>";
    }
}

// ─── JSON for JS ──────────────────────────────────────────────────────────────
$calendarJson      = json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$chartLabelsJson   = json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$chartInvoicedJson = json_encode($chartInvoiced, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$chartPaidJson     = json_encode($chartPaid, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$catLabels         = json_encode(array_column($caseByCategory, 'cat'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$catData           = json_encode(array_map(fn($r) => (int)$r['cnt'], $caseByCategory), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$statusLabelsJson  = json_encode(array_map(static fn($s) => ucwords(str_replace('_', ' ', (string)$s)), array_keys($caseByStatus)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$statusDataJson    = json_encode(array_values($caseByStatus), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
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
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/admin-portal-head.php'; ?>

    <style>
        /* Inline extras not yet in the drop-in CSS */
        .lp-avatar {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgba(94,114,228,0.12);
            color: #5e72e4; font-size: 0.72rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; letter-spacing: 0.03em;
        }
        .lp-progress-bar {
            height: 5px; background: #f1f5f9;
            border-radius: 99px; overflow: hidden;
        }
        .lp-progress-fill {
            height: 100%; background: linear-gradient(90deg,#5e72e4,#825ee4);
            border-radius: 99px; transition: width 0.6s cubic-bezier(.4,0,.2,1);
        }
        .lp-kpi-delta {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 0.72rem; font-weight: 700;
        }
        .lp-kpi-delta--up   { color: #2dce89; }
        .lp-kpi-delta--down { color: #f5365c; }
        /* Collection rate badge */
        .lp-collection-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(45,206,137,0.1); color: #1e9e6a;
            font-size: 0.72rem; font-weight: 700;
            padding: 3px 10px; border-radius: 99px;
        }
        /* Section header */
        .lp-section-hd {
            font-size: 0.9rem; font-weight: 700; color: #1e293b; margin-bottom: 0;
        }
        .lp-section-sub {
            font-size: 0.75rem; color: #94a3b8; margin-top: 2px;
        }
        .cat-legend-label { color: #64748b; }
        .cat-legend-pct { color: #1e293b; }
        /* Command palette hint */
        .lp-cmd-hint {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.71rem; color: #94a3b8;
        }
        .lp-cmd-hint kbd {
            padding: 1px 5px; border: 1px solid #e2e8f0;
            border-radius: 4px; background: #f8fafc;
            font-family: inherit; font-size: 0.68rem; font-weight: 600;
            color: #64748b; box-shadow: 0 1px 0 #e2e8f0;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-dashboard-page<?php echo legalpro_portal_theme_body_class(); ?>">
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
            <div>
                <h6 class="font-weight-bolder mb-0">Dashboard</h6>
                <p class="dashboard-welcome-sub mb-0 mt-1">
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

        <!-- ── MAIN GRAPH (curvy line chart) ────────────────────────────── -->
        <div class="row mt-2 mb-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <p class="lp-section-hd">Financial Trend</p>
                            <p class="lp-section-sub">Curved monthly trend for invoiced vs collected</p>
                        </div>
                        <span class="lp-collection-badge">
                            <?= $collectionRate ?>% collected
                        </span>
                    </div>
                    <div class="card-body">
                        <div style="position:relative;height:340px;">
                            <canvas id="chart-status" aria-label="Bar chart of case status distribution">Status chart unavailable.</canvas>
                        </div>
                    </div>
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
/* ── Curvy financial line chart ─────────────────────────────────────────── */
(function() {
    var ctxEl = document.getElementById('chart-status');
    if (!ctxEl) {
        return;
    }
    var ctx = ctxEl.getContext('2d');
    var g1 = ctx.createLinearGradient(0, 340, 0, 30);
    g1.addColorStop(1, 'rgba(123, 97, 255, 0.00)');
    g1.addColorStop(0, 'rgba(123, 97, 255, 0.28)');
    var g2 = ctx.createLinearGradient(0, 340, 0, 30);
    g2.addColorStop(1, 'rgba(45, 206, 137, 0.00)');
    g2.addColorStop(0, 'rgba(45, 206, 137, 0.22)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= $chartLabelsJson ?>,
            datasets: [{
                label: 'Invoiced',
                data: <?= $chartInvoicedJson ?>,
                borderColor: '#7B61FF',
                backgroundColor: g1,
                fill: true,
                tension: 0.45,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#7B61FF',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                borderWidth: 3
            }, {
                label: 'Collected',
                data: <?= $chartPaidJson ?>,
                borderColor: '#2DCE89',
                backgroundColor: g2,
                fill: true,
                tension: 0.45,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: '#2DCE89',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                borderWidth: 3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#6b7a9b',
                        usePointStyle: true,
                        boxWidth: 9,
                        font: { family: 'Inter', size: 11, weight: '600' }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.dataset.label + ': ' + (window.LegalProFormatCurrency ? window.LegalProFormatCurrency(ctx.parsed.y) : ctx.parsed.y);
                        }
                    }
                }
            },
            interaction: { intersect: false, mode: 'index' },
            scales: {
                y: {
                    beginAtZero: true,
                    grace: '12%',
                    ticks: {
                        color: '#8392ab',
                        font: { size: 11, family: 'Inter' },
                        callback: function(v) { return window.LegalProFormatCurrency ? window.LegalProFormatCurrency(v, 0) : v; }
                    },
                    grid: { color: 'rgba(120, 136, 167, 0.20)', drawBorder: false }
                },
                x: {
                    ticks: { color: '#6b7a9b', font: { size: 11, family: 'Inter', weight: '600' } },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>

<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
<script src="../assets/js/spa-nav.js"></script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
<?php
echo legalpro_apply_copyright_line(ob_get_clean());