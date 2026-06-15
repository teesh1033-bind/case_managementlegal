<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

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
$calendarJson      = json_encode($calendarEvents);
$chartLabelsJson   = json_encode($chartLabels);
$chartInvoicedJson = json_encode($chartInvoiced);
$chartPaidJson     = json_encode($chartPaid);
$catLabels         = json_encode(array_column($caseByCategory, 'cat'));
$catData           = json_encode(array_map(fn($r) => (int)$r['cnt'], $caseByCategory));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Dashboard — LegalPro Case Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <link href="../assets/css/dashboard-enhancements.css?v=5" rel="stylesheet" />
    <link href="../assets/css/legalpro-icons.css?v=2" rel="stylesheet" />
    <!-- MODERNISED STYLESHEET — drop in legalpro-modern.css to upgrade -->
    <link href="../assets/css/legalpro-modern.css?v=1" rel="stylesheet" />

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
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal legalpro-dashboard-page">
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

            <!-- Quick actions -->
            <div class="dashboard-quick-actions ms-auto d-none d-lg-flex">
                <a href="case-new.php" class="btn btn-sm bg-gradient-primary mb-0">
                    <i class="fas fa-plus me-1"></i>New Case
                </a>
                <a href="appointments.php" class="btn btn-sm btn-outline-secondary mb-0">Appointments</a>
                <a href="clients.php"      class="btn btn-sm btn-outline-secondary mb-0">Clients</a>
            </div>

        </div>
    </nav>

    <div class="container-fluid py-4 px-4">

        <!-- ── AT-A-GLANCE STRIP ───────────────────────────────────────── -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="dashboard-glance">
                    <a href="appointments.php" class="dashboard-glance__item">
                        <div class="dashboard-glance-icon-wrap dashboard-glance-icon-wrap--primary">
                            <?= legalpro_icon('clock') ?>
                        </div>
                        <div>
                            <div class="dashboard-glance__value"><?= $appointmentsToday ?></div>
                            <div class="dashboard-glance__label">Appointments today</div>
                        </div>
                    </a>
                    <a href="appointments.php" class="dashboard-glance__item">
                        <div class="dashboard-glance-icon-wrap dashboard-glance-icon-wrap--info">
                            <?= legalpro_icon('calendar') ?>
                        </div>
                        <div>
                            <div class="dashboard-glance__value"><?= $appointmentsThisWeek ?></div>
                            <div class="dashboard-glance__label">This week</div>
                        </div>
                    </a>
                    <a href="appointments.php" class="dashboard-glance__item">
                        <div class="dashboard-glance-icon-wrap dashboard-glance-icon-wrap--warning">
                            <?= legalpro_icon('bell') ?>
                        </div>
                        <div>
                            <div class="dashboard-glance__value"><?= $dueToday ?></div>
                            <div class="dashboard-glance__label">Pending today</div>
                        </div>
                    </a>
                    <a href="invoices.php" class="dashboard-glance__item">
                        <div class="dashboard-glance-icon-wrap dashboard-glance-icon-wrap--success">
                            <?= legalpro_icon('file-text') ?>
                        </div>
                        <div>
                            <div class="dashboard-glance__value"><?= $unpaidInvoices ?></div>
                            <div class="dashboard-glance__label">Open invoices</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>

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

        <!-- ── CHARTS ROW ──────────────────────────────────────────────── -->
        <div class="row mt-2 mb-4">

            <!-- Financial overview (line) -->
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div class="card h-100">
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
                    <div class="card-body">
                        <div style="position:relative;height:220px;">
                            <canvas id="chart-financial" aria-label="Line chart: invoiced vs collected over 6 months">Financial chart unavailable.</canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Case categories (doughnut) -->
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <p class="lp-section-hd">Cases by Category</p>
                        <p class="lp-section-sub">Distribution across practice areas</p>
                    </div>
                    <div class="card-body d-flex flex-column align-items-center">
                        <div style="position:relative;width:180px;height:180px;">
                            <canvas id="chart-categories" aria-label="Doughnut chart of case categories">Category chart unavailable.</canvas>
                        </div>
                        <div id="cat-legend" style="margin-top:1rem;width:100%;font-size:.75rem;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── RECENT CASES + TOP CLIENTS ─────────────────────────────── -->
        <div class="row mb-4">
            <div class="col-lg-7 mb-4 mb-lg-0">
                <div class="card h-100">
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
            </div>

            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <p class="lp-section-hd">Top Clients</p>
                            <p class="lp-section-sub">Ranked by total cases</p>
                        </div>
                        <a href="clients.php" class="btn btn-sm btn-outline-primary mb-0" style="border-radius:99px!important;font-size:.74rem!important;">All clients</a>
                    </div>
                    <div class="card-body">
                        <?= $topClientsHtml ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── APPOINTMENTS CALENDAR ───────────────────────────────────── -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="dashboard-calendar-hub">
                    <div class="dashboard-calendar-hub__head">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <p class="lp-section-hd">Appointments Calendar</p>
                                <p class="lp-section-sub">Click an event for details · drag to reschedule</p>
                                <div class="dashboard-legend-pills">
                                    <span class="dashboard-legend-pill dashboard-legend-pill--pending"><i></i> Pending</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--accepted"><i></i> Accepted</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--rejected"><i></i> Rejected</span>
                                </div>
                            </div>
                            <a href="appointments.php" class="btn btn-sm bg-gradient-primary mb-0">Manage appointments</a>
                        </div>
                    </div>
                    <div class="dashboard-calendar-hub__body">
                        <div class="dashboard-calendar-layout">
                            <div id="dashboardCalendar"></div>
                            <aside class="dashboard-upcoming-panel">
                                <div class="dashboard-upcoming-panel__title">
                                    <span>Upcoming</span>
                                    <a href="appointments.php" class="text-xs font-weight-bold" style="color:#5e72e4;">View all</a>
                                </div>
                                <div class="dashboard-upcoming-list" id="upcomingAppointmentsList">
                                    <?= $upcomingHtml ?>
                                </div>
                            </aside>
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

<!-- ── APPOINTMENT DETAIL MODAL ──────────────────────────────────────────── -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true" style="z-index:99999;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#5e72e4 0%,#825ee4 100%);">
                <h6 class="modal-title text-white font-weight-bold" id="modalTitle">Appointment</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label>Client</label>
                        <p id="modalClient" class="mb-0"></p>
                    </div>
                    <div class="col-6">
                        <label>Lawyer</label>
                        <p id="modalLawyer" class="mb-0"></p>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label>Status</label>
                        <p id="modalStatus" class="mb-0"></p>
                    </div>
                    <div class="col-6">
                        <label>Scheduled time</label>
                        <p id="modalTime" class="mb-0"></p>
                    </div>
                </div>
                <div class="mb-3">
                    <label>Notes</label>
                    <div id="modalNotes" class="p-3 rounded" style="background:#f8fafc;font-size:.83rem;color:#64748b;min-height:52px;white-space:pre-wrap;"></div>
                </div>
                <a id="modalEditLink" href="appointments.php" class="btn btn-sm bg-gradient-dark w-100 mb-0">Edit appointment</a>
            </div>
        </div>
    </div>
</div>

<!-- ── SCRIPTS ────────────────────────────────────────────────────────────── -->
<script src="../assets/js/core/popper.min.js"></script>
<script src="../assets/js/core/bootstrap.min.js"></script>
<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
<script src="../assets/js/plugins/chartjs.min.js"></script>

<script>
/* ── Financial line chart ────────────────────────────────────────────────── */
(function() {
    var ctx = document.getElementById('chart-financial').getContext('2d');
    var g1 = ctx.createLinearGradient(0,230,0,50);
    g1.addColorStop(1,'rgba(94,114,228,0.18)'); g1.addColorStop(0,'rgba(94,114,228,0)');
    var g2 = ctx.createLinearGradient(0,230,0,50);
    g2.addColorStop(1,'rgba(45,206,137,0.18)'); g2.addColorStop(0,'rgba(45,206,137,0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= $chartLabelsJson ?>,
            datasets: [{
                label: 'Invoiced', tension: 0.4, pointRadius: 4,
                pointBackgroundColor: '#5e72e4', borderColor: '#5e72e4',
                backgroundColor: g1, borderWidth: 2.5, fill: true,
                data: <?= $chartInvoicedJson ?>
            },{
                label: 'Collected', tension: 0.4, pointRadius: 4,
                pointBackgroundColor: '#2dce89', borderColor: '#2dce89',
                backgroundColor: g2, borderWidth: 2.5, fill: true,
                data: <?= $chartPaidJson ?>
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top',
                    labels: { color: '#67748e', font: { family: 'Montserrat', size: 11 }, usePointStyle: true, pointStyle: 'circle', boxWidth: 8 }
                },
                tooltip: { mode: 'index', intersect: false,
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits:2,maximumFractionDigits:2});
                        }
                    }
                }
            },
            interaction: { intersect: false, mode: 'index' },
            scales: {
                y: { grid: { borderDash: [5,5], color: 'rgba(0,0,0,0.05)' },
                     ticks: { color: '#8392ab', font: { size:11,family:'Montserrat' },
                              callback: function(v){ return '$'+v.toLocaleString(); } } },
                x: { grid: { display: false },
                     ticks: { color: '#8392ab', font: { size:11,family:'Montserrat' } } }
            }
        }
    });
})();

/* ── Case category doughnut ──────────────────────────────────────────────── */
(function() {
    var ctx = document.getElementById('chart-categories');
    if (!ctx) return;
    var labels = <?= $catLabels ?>;
    var data   = <?= $catData ?>;
    var colors = ['#5e72e4','#2dce89','#11cdef','#fb6340','#825ee4','#f5365c'];

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: data, backgroundColor: colors,
                borderWidth: 2, borderColor: '#fff',
                hoverOffset: 6 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c){ return ' '+c.label+': '+c.parsed; } } }
            }
        }
    });

    // Custom legend
    var leg = document.getElementById('cat-legend');
    var total = data.reduce(function(a,b){return a+b;},0);
    labels.forEach(function(l,i){
        var pct = total > 0 ? Math.round(data[i]/total*100) : 0;
        leg.innerHTML += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:5px;">'+
            '<span style="width:10px;height:10px;border-radius:3px;background:'+colors[i]+';flex-shrink:0;"></span>'+
            '<span class="cat-legend-label" style="flex:1;">'+l+'</span>'+
            '<span class="cat-legend-pct" style="font-weight:700;">'+pct+'%</span></div>';
    });
})();
</script>

<!-- FullCalendar -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl   = document.getElementById('dashboardCalendar');
    var events       = <?= $calendarJson ?>;

    function fmtDT(d) {
        if (!d) return '—';
        var dd = String(d.getDate()).padStart(2,'0'),
            mm = String(d.getMonth()+1).padStart(2,'0'),
            yy = d.getFullYear(),
            h  = String(d.getHours()).padStart(2,'0'),
            mi = String(d.getMinutes()).padStart(2,'0');
        return dd+'/'+mm+'/'+yy+' at '+h+':'+mi;
    }

    function openModal(ev) {
        var p = ev.extendedProps || {};
        document.getElementById('modalTitle').textContent  = ev.title || 'Appointment';
        document.getElementById('modalClient').textContent = p.client || '—';
        document.getElementById('modalLawyer').textContent = p.lawyer || '—';
        document.getElementById('modalStatus').textContent = p.statusLabel || p.status || 'Pending';
        document.getElementById('modalNotes').textContent  = p.notes || 'No notes added.';
        var start = ev.start instanceof Date ? ev.start : new Date(ev.start);
        var t = fmtDT(start);
        if (ev.end) { var e = ev.end instanceof Date ? ev.end : new Date(ev.end); t += ' — '+fmtDT(e); }
        document.getElementById('modalTime').textContent = t;
        document.getElementById('modalEditLink').href = 'new_appointment.php?id='+(p.appointmentId||ev.id);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('appointmentModal')).show();
    }

    function statusKey(s) {
        var v = String(s||'pending').toLowerCase();
        return v==='approved'?'accepted':v;
    }

    function renderEvent(arg) {
        var p  = arg.event.extendedProps||{};
        var sk = statusKey(p.status);
        var label = arg.event.title;
        if (label.length>22) label = label.slice(0,19)+'…';
        var el = document.createElement('div');
        el.className = 'dashboard-cal-event';
        el.innerHTML = '<span class="dashboard-cal-event__dot dashboard-cal-event__dot--'+sk+'"></span>'+
                       '<span class="dashboard-cal-event__text">'+(arg.timeText?arg.timeText+' ':'')+label+'</span>';
        return { domNodes: [el] };
    }

    document.getElementById('upcomingAppointmentsList').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-appointment-id]');
        if (!btn) return;
        var id  = btn.getAttribute('data-appointment-id');
        var ev  = events.find(function(x){ return String(x.id)===String(id); });
        if (ev) openModal({ title: ev.title, start: ev.start, end: ev.end, id: ev.id, extendedProps: ev.extendedProps });
    });

    var cal = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
        height: 'auto', firstDay: 1, navLinks: true, nowIndicator: true,
        fixedWeekCount: false, dayMaxEvents: 3, moreLinkClick: 'popover',
        buttonText: { today:'Today', month:'Month', week:'Week', list:'List' },
        eventTimeFormat: { hour:'2-digit', minute:'2-digit', hour12: false },
        dayHeaderFormat: { weekday: 'short' },
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
        events: events,
        eventContent: renderEvent,
        eventClick: function(info) { info.jsEvent.preventDefault(); openModal(info.event); },
        eventDidMount: function(info) {
            var p = info.event.extendedProps;
            var tip = info.event.title;
            if (p.client) tip += '\nClient: '+p.client;
            if (p.lawyer) tip += '\nLawyer: '+p.lawyer;
            info.el.title = tip;
        }
    });
    cal.render();
});
</script>

<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
<script src="../assets/js/spa-nav.js"></script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>