<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$lawyerName = $_SESSION['lawyer_name'];

$stats = [];
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM case_lawyers WHERE lawyer_id = ?');
    $stmt->execute([$lawyerId]);
    $stats['total_cases'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM cases c
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE cl.lawyer_id = ? AND c.status != 'closed'
    ");
    $stmt->execute([$lawyerId]);
    $stats['active_cases'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.client_id) FROM cases c
        INNER JOIN case_lawyers cl ON cl.case_id = c.id
        WHERE cl.lawyer_id = ?
    ");
    $stmt->execute([$lawyerId]);
    $stats['total_clients'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments a
        WHERE a.lawyer_id = ? AND DATE(a.starts_at) >= CURDATE()
        AND DATE(a.starts_at) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND a.status = 'accepted'
    ");
    $stmt->execute([$lawyerId]);
    $stats['upcoming_appointments'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.status, c.priority, c.created_at, cl.first_name, cl.last_name
        FROM cases c
        INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id
        INNER JOIN clients cl ON cl.id = c.client_id
        WHERE cl2.lawyer_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$lawyerId]);
    $recentCases = $stmt->fetchAll();

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

$hour = (int) date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$firstName = htmlspecialchars(explode(' ', trim((string) $lawyerName))[0] ?: $lawyerName);
$dateLabel = date('l, F j');
$activeCases = (int) ($stats['active_cases'] ?? 0);
$upcomingCount = (int) ($stats['upcoming_appointments'] ?? 0);
$nextAppt = !empty($upcomingAppointments) ? $upcomingAppointments[0] : null;

$iconStatCases = legalpro_icon('briefcase');
$iconStatActive = legalpro_icon('layers');
$iconStatClients = legalpro_icon('users');
$iconStatAppts = legalpro_icon('calendar');
$iconPanelCases = legalpro_icon('briefcase');
$iconPanelAppts = legalpro_icon('calendar');
$iconRowCase = legalpro_icon('briefcase');
$iconRowAppt = legalpro_icon('calendar');
$iconArrow = legalpro_icon('arrow-right');
$iconCalendarClock = legalpro_icon('calendar-clock');
$iconUser = legalpro_icon('user');

$heroGlanceHtml = '<div class="ld-hero-glance">'
    . '<span class="ld-hero-glance__item"><strong>' . $activeCases . '</strong> active ' . ($activeCases === 1 ? 'case' : 'cases') . '</span>'
    . '<span class="ld-hero-glance__item"><strong>' . $upcomingCount . '</strong> upcoming ' . ($upcomingCount === 1 ? 'meeting' : 'meetings') . '</span>'
    . '</div>';

$nextApptBanner = '';
if ($nextAppt) {
    $dt = date('l, j F · g:i A', strtotime($nextAppt['starts_at']));
    $nextApptBanner = '<div class="ld-next-appt">'
        . $iconCalendarClock
        . '<span>Next: <strong>' . htmlspecialchars($dt) . '</strong> · ' . htmlspecialchars($nextAppt['case_title'] ?: 'Appointment') . '</span>'
        . '</div>';
}

$quickActionsHtml = '';
$quickActions = [
    ['url' => 'lawyer-cases.php', 'icon' => 'briefcase', 'tone' => '', 'label' => 'My cases', 'desc' => 'All assigned matters'],
    ['url' => 'lawyer-clients.php', 'icon' => 'users', 'tone' => 'info', 'label' => 'My clients', 'desc' => 'Client directory'],
    ['url' => 'lawyer-appointments.php', 'icon' => 'calendar', 'tone' => 'success', 'label' => 'Appointments', 'desc' => 'Schedule & requests'],
    ['url' => 'lawyer-court-tracking.php', 'icon' => 'landmark', 'tone' => 'warning', 'label' => 'Court tracking', 'desc' => 'Hearings & dates'],
];
foreach ($quickActions as $action) {
    $iconClass = $action['tone'] !== '' ? ' ld-quick-card__icon--' . $action['tone'] : '';
    $quickActionsHtml .= '<a href="' . htmlspecialchars($action['url']) . '" class="ld-quick-card">'
        . '<span class="ld-quick-card__icon' . $iconClass . '">' . legalpro_icon($action['icon']) . '</span>'
        . '<span><span class="ld-quick-card__label">' . htmlspecialchars($action['label']) . '</span>'
        . '<span class="ld-quick-card__desc">' . htmlspecialchars($action['desc']) . '</span></span>'
        . '</a>';
}

$recentCasesHtml = '';
if (empty($recentCases)) {
    $recentCasesHtml = '<div class="ld-empty-state">'
        . '<div class="ld-empty-icon">' . legalpro_icon('folder-open') . '</div>'
        . '<p class="ld-empty-title">No cases assigned yet</p>'
        . '<p class="ld-empty-sub">Cases assigned to you will appear here.</p>'
        . '</div>';
} else {
    foreach ($recentCases as $case) {
        $num = 'C-' . str_pad((string) $case['id'], 4, '0', STR_PAD_LEFT);
        $title = htmlspecialchars($case['title']);
        $clientName = htmlspecialchars(trim($case['first_name'] . ' ' . $case['last_name']));
        $created = date('M j, Y', strtotime($case['created_at']));
        $statusBadge = client_case_status_badge((string) ($case['status'] ?? ''));
        $priorityBadge = client_case_priority_badge((string) ($case['priority'] ?? 'Normal'));

        $recentCasesHtml .= '<a href="lawyer-case-view.php?id=' . (int) $case['id'] . '" class="ld-list-row">'
            . '<div class="ld-list-row__icon">' . $iconRowCase . '</div>'
            . '<div class="ld-list-row__body">'
            . '<div class="ld-list-row__title">' . $num . ' · ' . $title . '</div>'
            . '<div class="ld-list-row__meta">' . $clientName . ' · Assigned ' . $created . '</div>'
            . '</div>'
            . '<div class="ld-list-row__aside">' . $statusBadge . $priorityBadge . '</div>'
            . '</a>';
    }
}

$upcomingAppointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $upcomingAppointmentsHtml = '<div class="ld-empty-state">'
        . '<div class="ld-empty-icon">' . $iconRowAppt . '</div>'
        . '<p class="ld-empty-title">No upcoming appointments</p>'
        . '<p class="ld-empty-sub">Accepted appointments will appear here once scheduled.</p>'
        . '</div>';
} else {
    foreach ($upcomingAppointments as $appointment) {
        $dayLabel = date('M j', strtotime($appointment['starts_at']));
        $timeLabel = date('g:i A', strtotime($appointment['starts_at']));
        $caseTitle = htmlspecialchars($appointment['case_title'] ?: 'Appointment');
        $clientName = htmlspecialchars(trim($appointment['first_name'] . ' ' . $appointment['last_name']));
        $notesRaw = trim((string) ($appointment['description'] ?? ''));
        $notes = $notesRaw !== '' ? htmlspecialchars(mb_substr($notesRaw, 0, 68)) . (strlen($notesRaw) > 68 ? '…' : '') : '';

        $upcomingAppointmentsHtml .= '<a href="lawyer-appointments.php" class="ld-appt-row">'
            . '<div class="ld-appt-row__date">'
            . '<span class="ld-appt-row__day">' . $dayLabel . '</span>'
            . '<span class="ld-appt-row__time">' . $timeLabel . '</span>'
            . '</div>'
            . '<div class="ld-appt-row__body">'
            . '<div class="ld-appt-row__title">' . $caseTitle . '</div>'
            . '<div class="ld-appt-row__meta">' . $iconUser . $clientName . '</div>'
            . ($notes !== '' ? '<div class="ld-appt-row__notes">' . $notes . '</div>' : '')
            . '</div>'
            . '<div class="ld-appt-row__badge"><span class="ld-appt-badge">Confirmed</span></div>'
            . '</a>';
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
    <link href="../assets/css/lawyer-dashboard.css?v=2" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-dashboard-page legalpro-dashboard-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Dashboard</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Dashboard</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4 px-4">
            <div class="ld-page">
                <section class="ld-hero-card" aria-label="Dashboard overview">
                    <div class="ld-hero-main">
                        <div class="ld-hero-top">
                            <p class="ld-hero-kicker">Lawyer workspace</p>
                            <span class="ld-hero-date">{HERO_DATE}</span>
                        </div>
                        <h1 class="ld-hero-title">{GREETING}, {LAWYER_FIRST_NAME}</h1>
                        <p class="ld-hero-sub">Manage your cases, prepare for client meetings, and stay on top of court dates — all in one place.</p>
                        {HERO_GLANCE}
                        {NEXT_APPT_BANNER}
                    </div>
                    <div class="ld-hero-actions">
                        <a href="lawyer-cases.php" class="btn btn-primary-solid">View cases</a>
                        <a href="lawyer-appointments.php" class="btn btn-ghost">Appointments</a>
                    </div>
                </section>

                <section class="ld-quick-grid" aria-label="Quick actions">
                    {QUICK_ACTIONS}
                </section>

                <section class="ld-kpi-grid" aria-label="Practice statistics">
                    <a href="lawyer-cases.php" class="ld-kpi" style="--kpi-accent: var(--ld-primary);">
                        <div>
                            <div class="ld-kpi__val">{TOTAL_CASES}</div>
                            <div class="ld-kpi__lbl">Total cases</div>
                        </div>
                        <div class="ld-kpi__icon">{ICON_STAT_CASES}</div>
                    </a>
                    <a href="lawyer-cases.php" class="ld-kpi" style="--kpi-accent: #2dce89;">
                        <div>
                            <div class="ld-kpi__val">{ACTIVE_CASES}</div>
                            <div class="ld-kpi__lbl">Active cases</div>
                        </div>
                        <div class="ld-kpi__icon" style="background:rgba(45,206,137,.1);color:#2dce89;">{ICON_STAT_ACTIVE}</div>
                    </a>
                    <a href="lawyer-clients.php" class="ld-kpi" style="--kpi-accent: #11cdef;">
                        <div>
                            <div class="ld-kpi__val">{TOTAL_CLIENTS}</div>
                            <div class="ld-kpi__lbl">My clients</div>
                        </div>
                        <div class="ld-kpi__icon" style="background:rgba(17,205,239,.12);color:#11cdef;">{ICON_STAT_CLIENTS}</div>
                    </a>
                    <a href="lawyer-appointments.php" class="ld-kpi" style="--kpi-accent: #fb6340;">
                        <div>
                            <div class="ld-kpi__val">{UPCOMING_APPOINTMENTS_COUNT}</div>
                            <div class="ld-kpi__lbl">This week</div>
                        </div>
                        <div class="ld-kpi__icon" style="background:rgba(251,99,64,.12);color:#fb6340;">{ICON_STAT_APPTS}</div>
                    </a>
                </section>

                <section class="ld-layout">
                    <div class="ld-panel">
                        <div class="ld-panel-hdr">
                            <div class="ld-panel-hdr__left">
                                <span class="ld-panel-hdr__icon">{ICON_PANEL_CASES}</span>
                                <div>
                                    <p class="ld-panel-title">Recent cases</p>
                                    <p class="ld-panel-sub">Your most recently assigned matters</p>
                                </div>
                            </div>
                            <a href="lawyer-cases.php" class="btn-ld-link">{ICON_ARROW} View all</a>
                        </div>
                        <div class="ld-panel-body">
                            {RECENT_CASES}
                        </div>
                    </div>
                    <div class="ld-panel">
                        <div class="ld-panel-hdr">
                            <div class="ld-panel-hdr__left">
                                <span class="ld-panel-hdr__icon" style="background:rgba(45,206,137,.1);color:#2dce89;">{ICON_PANEL_APPTS}</span>
                                <div>
                                    <p class="ld-panel-title">Upcoming appointments</p>
                                    <p class="ld-panel-sub">Your next scheduled meetings</p>
                                </div>
                            </div>
                            <a href="lawyer-appointments.php" class="btn-ld-link">{ICON_ARROW} View all</a>
                        </div>
                        <div class="ld-panel-body">
                            {UPCOMING_APPOINTMENTS}
                        </div>
                    </div>
                </section>
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
    '{GREETING}' => $greeting,
    '{LAWYER_FIRST_NAME}' => $firstName,
    '{HERO_DATE}' => htmlspecialchars($dateLabel),
    '{HERO_GLANCE}' => $heroGlanceHtml,
    '{NEXT_APPT_BANNER}' => $nextApptBanner,
    '{QUICK_ACTIONS}' => $quickActionsHtml,
    '{TOTAL_CASES}' => (int) ($stats['total_cases'] ?? 0),
    '{ACTIVE_CASES}' => $activeCases,
    '{TOTAL_CLIENTS}' => (int) ($stats['total_clients'] ?? 0),
    '{UPCOMING_APPOINTMENTS_COUNT}' => $upcomingCount,
    '{RECENT_CASES}' => $recentCasesHtml,
    '{UPCOMING_APPOINTMENTS}' => $upcomingAppointmentsHtml,
    '{ICON_STAT_CASES}' => $iconStatCases,
    '{ICON_STAT_ACTIVE}' => $iconStatActive,
    '{ICON_STAT_CLIENTS}' => $iconStatClients,
    '{ICON_STAT_APPTS}' => $iconStatAppts,
    '{ICON_PANEL_CASES}' => $iconPanelCases,
    '{ICON_PANEL_APPTS}' => $iconPanelAppts,
    '{ICON_ARROW}' => $iconArrow,
    '{PORTAL_THEME_BODY_CLASS}' => legalpro_portal_theme_body_class(),
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);
echo legalpro_apply_copyright_line($html);
