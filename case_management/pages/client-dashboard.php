<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$client_id   = $_SESSION['client_id'];
$client_name = $_SESSION['client_name'];

$message     = '';
$messageType = '';

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_cases,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_cases,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_cases,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cases
        FROM cases
        WHERE client_id = ?
    ");
    $stmt->execute([$client_id]);
    $caseStats = $stmt->fetch();

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
        LIMIT 8
    ");
    $stmt->execute([$client_id]);
    $recentCases = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            a.*,
            c.title as case_title,
            GROUP_CONCAT(DISTINCT CONCAT(l.first_name, ' ', l.last_name) SEPARATOR ', ') as lawyer_name
        FROM appointments a
        LEFT JOIN cases c ON c.id = a.case_id
        LEFT JOIN case_lawyers cl ON cl.case_id = c.id
        LEFT JOIN lawyers l ON l.id = cl.lawyer_id
        WHERE a.client_id = ? AND a.starts_at > NOW() AND a.status = 'accepted'
        GROUP BY a.id
        ORDER BY a.starts_at ASC
        LIMIT 6
    ");
    $stmt->execute([$client_id]);
    $upcomingAppointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $message     = 'Error loading dashboard data.';
    $messageType = 'danger';
    $caseStats   = ['total_cases' => 0, 'open_cases' => 0, 'closed_cases' => 0, 'pending_cases' => 0];
    $recentCases = [];
    $upcomingAppointments = [];
}

$nextAppt = !empty($upcomingAppointments) ? $upcomingAppointments[0] : null;

require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';

$clientPageNavbar = legalpro_render_client_page_navbar('Dashboard', 'Dashboard', 'Search cases…');

$nextApptBanner = '';
if ($nextAppt) {
    $dt = date('l, j F · g:i A', strtotime($nextAppt['starts_at']));
    $nextApptBanner = '<div class="cd-next-appt">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Next appointment: <strong>' . htmlspecialchars($dt) . '</strong> · ' . htmlspecialchars($nextAppt['case_title'] ?: 'General') . '</span>
    </div>';
}

$recentCasesHtml = '';
if (empty($recentCases)) {
    $recentCasesHtml = '<div class="cd-empty-state">
        <div class="cd-empty-icon">📁</div>
        <p class="cd-empty-title">No cases yet</p>
        <p class="cd-empty-sub">When your firm opens a matter for you it will appear here.</p>
    </div>';
} else {
    foreach ($recentCases as $case) {
        $num     = 'C-' . str_pad((string) $case['id'], 4, '0', STR_PAD_LEFT);
        $title   = htmlspecialchars($case['title']);
        $lawyer  = htmlspecialchars($case['lawyer_names'] ?: 'Unassigned');
        $updated = date('M j, Y', strtotime($case['updated_at']));
        $pill    = client_case_status_badge((string) ($case['status'] ?? ''));
        $recentCasesHtml .= '<a href="client-case-view.php?id=' . (int) $case['id'] . '" class="cd-list-row">
            <div class="cd-list-row__icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            </div>
            <div class="cd-list-row__body">
                <div class="cd-list-row__title">' . $num . ' · ' . $title . '</div>
                <div class="cd-list-row__meta">' . $lawyer . ' · Updated ' . $updated . '</div>
            </div>
            <div class="cd-list-row__aside">' . $pill . '</div>
        </a>';
    }
}

$appointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $appointmentsHtml = '<div class="cd-empty-state">
        <div class="cd-empty-icon">📅</div>
        <p class="cd-empty-title">No upcoming meetings</p>
        <p class="cd-empty-sub">Accepted appointments will appear here once scheduled.</p>
    </div>';
} else {
    foreach ($upcomingAppointments as $apt) {
        $dayLabel  = date('M j', strtotime($apt['starts_at']));
        $timeLabel = date('g:i A', strtotime($apt['starts_at']));
        $caseTitle = htmlspecialchars($apt['case_title'] ?: 'General appointment');
        $lawyerTxt = htmlspecialchars($apt['lawyer_name'] ?: 'TBD');
        $notesRaw  = $apt['notes'] ? (string) $apt['notes'] : '';
        $notes     = $notesRaw !== '' ? htmlspecialchars(mb_substr($notesRaw, 0, 68)) . (strlen($notesRaw) > 68 ? '…' : '') : '';
        $appointmentsHtml .= '<a href="client-appointments.php" class="cd-appt-row text-decoration-none text-reset">
            <div class="cd-appt-row__date">
                <span class="cd-appt-row__day">' . $dayLabel . '</span>
                <span class="cd-appt-row__time">' . $timeLabel . '</span>
            </div>
            <div class="cd-appt-row__body">
                <div class="cd-appt-row__title">' . $caseTitle . '</div>
                <div class="cd-appt-row__meta">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    ' . $lawyerTxt . '
                </div>
                ' . ($notes !== '' ? '<div class="cd-appt-row__notes">' . $notes . '</div>' : '') . '
            </div>
            <div class="cd-appt-row__badge"><span class="cd-appt-accepted">Confirmed</span></div>
        </a>';
    }
}

$messageHtml = $message
    ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show mb-3" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>'
    : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>My Dashboard — LegalPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=6" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>

    <style>
    *, *::before, *::after { box-sizing: border-box; }
    .client-dashboard-page {
        font-family: 'Inter', system-ui, sans-serif;
        background: #f0f2f8;
        --cp-primary: var(--legalpro-theme-primary, #5e72e4);
        --cp-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
        --cp-primary-light: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
        --cp-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
        --cp-primary-border: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.2);
        --cp-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
        --cp-success: #2dce89;
        --cp-warning: #fb6340;
        --cp-text: #1e293b;
        --cp-muted: #94a3b8;
        --cp-surface: #ffffff;
        --cp-border: rgba(0,0,0,0.07);
        --cp-r: 16px;
        --cp-ease: 0.18s cubic-bezier(.4,0,.2,1);
        --cp-shadow: 0 2px 12px rgba(0,0,0,0.07);
        --cp-shadow-lg: 0 8px 32px rgba(0,0,0,0.1);
    }

    .cd-hero-card {
        background: var(--cp-gradient);
        border-radius: 20px;
        padding: 2rem 2.5rem;
        color: #fff;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 1.5rem;
    }
    .cd-hero-card::before {
        content: '';
        position: absolute;
        top: -60px; right: -60px;
        width: 200px; height: 200px;
        border-radius: 50%;
        background: rgba(255,255,255,.08);
    }
    .cd-hero-main { flex: 1; min-width: 0; position: relative; z-index: 1; }
    .cd-hero-kicker {
        font-size: 11px; font-weight: 600;
        letter-spacing: .12em; text-transform: uppercase;
        opacity: .75; margin-bottom: .35rem;
    }
    .cd-hero-title {
        font-size: 1.45rem; font-weight: 800;
        letter-spacing: -.02em; margin-bottom: .45rem; line-height: 1.2;
    }
    .cd-hero-sub {
        font-size: .82rem; opacity: .85; line-height: 1.55;
        max-width: 34rem; margin-bottom: .9rem;
    }
    .cd-next-appt {
        display: inline-flex; align-items: center; gap: .5rem;
        font-size: .76rem; font-weight: 600;
        background: rgba(255,255,255,.15);
        border: 1px solid rgba(255,255,255,.25);
        padding: .45rem 1rem; border-radius: 99px;
        width: fit-content; color: #fff;
    }
    .cd-next-appt svg { flex-shrink: 0; opacity: .9; }
    .cd-hero-actions {
        display: flex; flex-wrap: wrap; gap: .5rem;
        position: relative; z-index: 1; flex-shrink: 0;
    }
    .cd-hero-actions .btn {
        border-radius: 99px !important;
        font-size: .78rem !important; font-weight: 700 !important;
        padding: .45rem 1.15rem !important;
        text-decoration: none;
    }
    .cd-hero-actions .btn-primary-solid {
        background: #fff; color: var(--cp-primary); border: none;
        box-shadow: 0 4px 14px rgba(0,0,0,.12);
    }
    .cd-hero-actions .btn-primary-solid:hover { opacity: .92; color: var(--cp-primary); }
    .cd-hero-actions .btn-ghost {
        background: transparent; color: #fff;
        border: 1.5px solid rgba(255,255,255,.45);
    }
    .cd-hero-actions .btn-ghost:hover {
        background: rgba(255,255,255,.12); color: #fff;
    }

    .cd-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .cd-kpi {
        background: var(--cp-surface);
        border: 1px solid var(--cp-border);
        border-radius: var(--cp-r);
        box-shadow: var(--cp-shadow);
        padding: 1.1rem 1.25rem;
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: .75rem;
        transition: transform var(--cp-ease), box-shadow var(--cp-ease);
        position: relative; overflow: hidden;
    }
    .cd-kpi::after {
        content: ''; position: absolute;
        bottom: 0; left: 0; right: 0; height: 3px;
        background: var(--kpi-color, var(--cp-primary));
        border-radius: 0 0 var(--cp-r) var(--cp-r);
        opacity: 0; transition: opacity var(--cp-ease);
    }
    .cd-kpi:hover { transform: translateY(-3px); box-shadow: var(--cp-shadow-lg); }
    .cd-kpi:hover::after { opacity: 1; }
    .cd-kpi__val {
        font-size: 2rem; font-weight: 800; line-height: 1;
        letter-spacing: -.04em; color: var(--cp-text);
    }
    .cd-kpi__lbl {
        font-size: .66rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .08em;
        color: var(--cp-muted); margin-top: .3rem;
    }
    .cd-kpi__icon {
        width: 2.4rem; height: 2.4rem; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .cd-kpi--primary { --kpi-color: var(--cp-primary); }
    .cd-kpi__icon--primary {
        background: var(--cp-primary-soft);
        color: var(--cp-primary);
    }

    .cd-panel {
        background: var(--cp-surface);
        border: 1px solid #e9ecf3;
        border-radius: var(--cp-r);
        box-shadow: var(--cp-shadow);
        overflow: hidden;
        height: 100%;
    }
    .cd-panel-hdr {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: .5rem;
    }
    .cd-panel-title { font-size: 15px; font-weight: 700; color: var(--cp-text); margin: 0; }
    .cd-panel-sub { font-size: 12px; color: var(--cp-muted); margin: 2px 0 0; }
    .cd-panel-body { padding: .75rem 1rem; }
    .btn-cd-link {
        padding: .35rem .9rem; border-radius: 8px;
        border: 1.5px solid var(--cp-primary); color: var(--cp-primary);
        font-size: 12px; font-weight: 600; background: none;
        text-decoration: none; display: inline-block;
        transition: background .15s, color .15s;
    }
    .btn-cd-link:hover { background: var(--cp-primary); color: #fff; }
    .cd-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 2rem; }
    @media (max-width: 991px) { .cd-layout { grid-template-columns: 1fr; } }

    .cd-list-row {
        display: flex; align-items: center; gap: .85rem;
        padding: .8rem .85rem; border-radius: 10px;
        text-decoration: none; color: var(--cp-text);
        transition: background var(--cp-ease), transform var(--cp-ease);
        border: 1px solid transparent;
        margin-bottom: .25rem;
    }
    .cd-list-row:hover {
        background: var(--cp-primary-light);
        border-color: var(--cp-primary-border);
        color: var(--cp-text);
        text-decoration: none;
    }
    .cd-list-row__icon {
        width: 2rem; height: 2rem; border-radius: 8px;
        background: var(--cp-primary-soft);
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; color: var(--cp-primary);
    }
    .cd-list-row__body { flex: 1; min-width: 0; }
    .cd-list-row__title { font-size: .83rem; font-weight: 700; color: var(--cp-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cd-list-row__meta { font-size: .72rem; color: var(--cp-muted); margin-top: 2px; }
    .cd-list-row__aside { flex-shrink: 0; }

    .cd-appt-row {
        display: flex; align-items: flex-start; gap: .9rem;
        padding: .8rem .85rem; border-radius: 10px;
        border: 1px solid var(--cp-border);
        margin-bottom: .5rem;
        transition: border-color var(--cp-ease), box-shadow var(--cp-ease);
    }
    .cd-appt-row:last-child { margin-bottom: 0; }
    .cd-appt-row:hover {
        border-color: var(--lp-cases-accent-border, rgba(94, 114, 228, 0.3));
        box-shadow: 0 4px 14px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
        color: var(--cp-text);
    }
    .cd-appt-row__date {
        min-width: 52px; text-align: center;
        background: var(--cp-primary-light);
        border-radius: 10px; padding: .55rem .4rem;
        flex-shrink: 0;
    }
    .cd-appt-row__day { display: block; font-size: .78rem; font-weight: 800; color: var(--cp-primary); }
    .cd-appt-row__time { display: block; font-size: .65rem; font-weight: 600; color: var(--cp-muted); margin-top: 2px; }
    .cd-appt-row__body { flex: 1; min-width: 0; }
    .cd-appt-row__title { font-size: .83rem; font-weight: 700; color: var(--cp-text); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cd-appt-row__meta { font-size: .71rem; color: var(--cp-muted); display: flex; align-items: center; gap: 4px; }
    .cd-appt-row__notes { font-size: .71rem; color: var(--cp-muted); margin-top: 4px; }
    .cd-appt-accepted {
        display: inline-block; padding: 3px 9px; border-radius: 99px;
        font-size: .65rem; font-weight: 800;
        background: rgba(45,206,137,.1); color: #1e9e6a;
    }
    .cd-appt-row__badge { flex-shrink: 0; padding-top: 2px; }

    .cd-empty-state { padding: 2rem 1rem; text-align: center; }
    .cd-empty-icon { font-size: 2rem; margin-bottom: .5rem; }
    .cd-empty-title { font-size: .85rem; font-weight: 700; color: #64748b; margin: 0 0 .25rem; }
    .cd-empty-sub { font-size: .76rem; color: var(--cp-muted); margin: 0; }

    .client-dashboard-page .ca-status-pill {
        font-size: .68rem;
        padding: 3px 10px;
    }

    @media (max-width: 1199px) {
        .cd-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 767px) {
        .cd-hero-card { padding: 1.4rem 1.25rem; }
        .cd-hero-title { font-size: 1.2rem; }
        .cd-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-dashboard-page">
<div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>

<?php include __DIR__ . '/../inc/client-menunav.php'; ?>

<main class="main-content position-relative border-radius-lg">
    {CLIENT_NAVBAR}

    <div class="container-fluid py-4 px-4">
        {MESSAGE}

        <div class="cd-hero-card">
            <div class="cd-hero-main">
                <p class="cd-hero-kicker">Your legal workspace</p>
                <h4 class="cd-hero-title">Welcome back, {CLIENT_NAME}</h4>
                <p class="cd-hero-sub">Track your cases, prepare for upcoming meetings, and stay on top of court dates — all from one place.</p>
                {NEXT_APPT_BANNER}
            </div>
            <div class="cd-hero-actions">
                <a href="client-cases.php" class="btn btn-primary-solid">My Cases</a>
                <a href="client-appointments.php" class="btn btn-ghost">Appointments</a>
                <a href="client-court-tracking.php" class="btn btn-ghost">Court Dates</a>
            </div>
        </div>

        <div class="cd-kpi-grid">
            <div class="cd-kpi cd-kpi--primary">
                <div>
                    <div class="cd-kpi__val">{TOTAL_CASES}</div>
                    <div class="cd-kpi__lbl">Total Cases</div>
                </div>
                <div class="cd-kpi__icon cd-kpi__icon--primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                </div>
            </div>
            <div class="cd-kpi" style="--kpi-color:#2dce89;">
                <div>
                    <div class="cd-kpi__val">{OPEN_CASES}</div>
                    <div class="cd-kpi__lbl">Open</div>
                </div>
                <div class="cd-kpi__icon" style="background:rgba(45,206,137,.1);color:#2dce89;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
            </div>
            <div class="cd-kpi" style="--kpi-color:#fb6340;">
                <div>
                    <div class="cd-kpi__val">{PENDING_CASES}</div>
                    <div class="cd-kpi__lbl">Pending</div>
                </div>
                <div class="cd-kpi__icon" style="background:rgba(251,99,64,.1);color:#fb6340;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
            <div class="cd-kpi" style="--kpi-color:#8898aa;">
                <div>
                    <div class="cd-kpi__val">{CLOSED_CASES}</div>
                    <div class="cd-kpi__lbl">Closed</div>
                </div>
                <div class="cd-kpi__icon" style="background:rgba(136,152,170,.12);color:#525f7f;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
            </div>
        </div>

        <div class="cd-layout">
            <div class="cd-panel">
                <div class="cd-panel-hdr">
                    <div>
                        <p class="cd-panel-title">Recent Cases</p>
                        <p class="cd-panel-sub">Latest updates on your matters</p>
                    </div>
                    <a href="client-cases.php" class="btn-cd-link">View all</a>
                </div>
                <div class="cd-panel-body">
                    {RECENT_CASES}
                </div>
            </div>
            <div class="cd-panel">
                <div class="cd-panel-hdr">
                    <div>
                        <p class="cd-panel-title">Upcoming Appointments</p>
                        <p class="cd-panel-sub">Confirmed meetings on your calendar</p>
                    </div>
                    <a href="client-appointments.php" class="btn-cd-link">Book one</a>
                </div>
                <div class="cd-panel-body">
                    {UPCOMING_APPOINTMENTS}
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

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{CLIENT_NAME}', htmlspecialchars($client_name), $html);
$html = str_replace('{NEXT_APPT_BANNER}', $nextApptBanner, $html);
$html = str_replace('{TOTAL_CASES}', (int) ($caseStats['total_cases'] ?? 0), $html);
$html = str_replace('{OPEN_CASES}', (int) ($caseStats['open_cases'] ?? 0), $html);
$html = str_replace('{PENDING_CASES}', (int) ($caseStats['pending_cases'] ?? 0), $html);
$html = str_replace('{CLOSED_CASES}', (int) ($caseStats['closed_cases'] ?? 0), $html);
$html = str_replace('{RECENT_CASES}', $recentCasesHtml, $html);
$html = str_replace('{UPCOMING_APPOINTMENTS}', $appointmentsHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
