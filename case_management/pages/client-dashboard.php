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
$upcomingCount = count($upcomingAppointments);
$openCases = (int) ($caseStats['open_cases'] ?? 0);
$totalCases = (int) ($caseStats['total_cases'] ?? 0);

require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../inc/admin-layout.php';

$hour = (int) date('G');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}
$firstName = htmlspecialchars(explode(' ', trim((string) $client_name))[0] ?: $client_name);
$dateLabel = date('l, F j');

$heroGlanceHtml = '<div class="cd-hero-glance">'
    . '<span class="cd-hero-glance__item"><strong>' . $openCases . '</strong> open ' . ($openCases === 1 ? 'case' : 'cases') . '</span>'
    . '<span class="cd-hero-glance__item"><strong>' . $upcomingCount . '</strong> upcoming ' . ($upcomingCount === 1 ? 'meeting' : 'meetings') . '</span>'
    . '</div>';

$nextApptBanner = '';
if ($nextAppt) {
    $dt = date('l, j F · g:i A', strtotime($nextAppt['starts_at']));
    $nextApptBanner = '<div class="cd-next-appt">'
        . legalpro_icon('calendar-clock')
        . '<span>Next: <strong>' . htmlspecialchars($dt) . '</strong> · ' . htmlspecialchars($nextAppt['case_title'] ?: 'General') . '</span>'
        . '</div>';
}

$quickActionsHtml = '';
$quickActions = [
    ['url' => 'client-cases.php', 'icon' => 'briefcase', 'tone' => '', 'label' => 'My Cases', 'desc' => 'All your matters'],
    ['url' => 'client-documents.php', 'icon' => 'file-text', 'tone' => 'success', 'label' => 'Documents', 'desc' => 'View & download'],
    ['url' => 'client-appointments.php', 'icon' => 'calendar', 'tone' => 'info', 'label' => 'Appointments', 'desc' => 'Book or review'],
    ['url' => 'client-payments.php', 'icon' => 'credit-card', 'tone' => 'warning', 'label' => 'Payments', 'desc' => 'Invoices & receipts'],
];
foreach ($quickActions as $action) {
    $iconClass = $action['tone'] !== '' ? ' cd-quick-card__icon--' . $action['tone'] : '';
    $quickActionsHtml .= '<a href="' . htmlspecialchars($action['url']) . '" class="cd-quick-card">'
        . '<span class="cd-quick-card__icon' . $iconClass . '">' . legalpro_icon($action['icon']) . '</span>'
        . '<span><span class="cd-quick-card__label">' . htmlspecialchars($action['label']) . '</span>'
        . '<span class="cd-quick-card__desc">' . htmlspecialchars($action['desc']) . '</span></span>'
        . '</a>';
}

$recentCasesHtml = '';
if (empty($recentCases)) {
    $recentCasesHtml = '<div class="cd-empty-state">'
        . '<div class="cd-empty-icon">' . legalpro_icon('folder-open') . '</div>'
        . '<p class="cd-empty-title">No cases yet</p>'
        . '<p class="cd-empty-sub">When your firm opens a matter for you it will appear here.</p>'
        . '</div>';
} else {
    foreach ($recentCases as $case) {
        $num     = 'C-' . str_pad((string) $case['id'], 4, '0', STR_PAD_LEFT);
        $title   = htmlspecialchars($case['title']);
        $lawyer  = htmlspecialchars($case['lawyer_names'] ?: 'Unassigned');
        $updated = date('M j, Y', strtotime($case['updated_at']));
        $pill    = client_case_status_badge((string) ($case['status'] ?? ''));
        $caseSearchHay = htmlspecialchars(strtolower($num . ' ' . ($case['title'] ?? '') . ' ' . ($case['lawyer_names'] ?? '') . ' ' . ($case['status'] ?? '')), ENT_QUOTES, 'UTF-8');
        $recentCasesHtml .= '<a href="client-case-view.php?id=' . (int) $case['id'] . '" class="cd-list-row" data-search="' . $caseSearchHay . '">'
            . '<div class="cd-list-row__icon">' . legalpro_icon('briefcase') . '</div>'
            . '<div class="cd-list-row__body">'
            . '<div class="cd-list-row__title">' . $num . ' · ' . $title . '</div>'
            . '<div class="cd-list-row__meta">' . $lawyer . ' · Updated ' . $updated . '</div>'
            . '</div>'
            . '<div class="cd-list-row__aside">' . $pill . '</div>'
            . '</a>';
    }
}

$appointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $appointmentsHtml = '<div class="cd-empty-state">'
        . '<div class="cd-empty-icon">' . legalpro_icon('calendar') . '</div>'
        . '<p class="cd-empty-title">No upcoming meetings</p>'
        . '<p class="cd-empty-sub">Accepted appointments will appear here once scheduled.</p>'
        . '</div>';
} else {
    foreach ($upcomingAppointments as $apt) {
        $dayLabel  = date('M j', strtotime($apt['starts_at']));
        $timeLabel = date('g:i A', strtotime($apt['starts_at']));
        $caseTitle = htmlspecialchars($apt['case_title'] ?: 'General appointment');
        $lawyerTxt = htmlspecialchars($apt['lawyer_name'] ?: 'TBD');
        $notesRaw  = $apt['notes'] ? (string) $apt['notes'] : '';
        $notes     = $notesRaw !== '' ? htmlspecialchars(mb_substr($notesRaw, 0, 68)) . (strlen($notesRaw) > 68 ? '…' : '') : '';
        $apptSearchHay = htmlspecialchars(strtolower($caseTitle . ' ' . $lawyerTxt . ' ' . $dayLabel . ' ' . $timeLabel . ' ' . $notesRaw), ENT_QUOTES, 'UTF-8');
        $appointmentsHtml .= '<a href="client-appointments.php" class="cd-appt-row" data-search="' . $apptSearchHay . '">'
            . '<div class="cd-appt-row__date">'
            . '<span class="cd-appt-row__day">' . $dayLabel . '</span>'
            . '<span class="cd-appt-row__time">' . $timeLabel . '</span>'
            . '</div>'
            . '<div class="cd-appt-row__body">'
            . '<div class="cd-appt-row__title">' . $caseTitle . '</div>'
            . '<div class="cd-appt-row__meta">' . legalpro_icon('user') . $lawyerTxt . '</div>'
            . ($notes !== '' ? '<div class="cd-appt-row__notes">' . $notes . '</div>' : '')
            . '</div>'
            . '<div class="cd-appt-row__badge"><span class="cd-appt-accepted">Confirmed</span></div>'
            . '</a>';
    }
}

require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../lib/client-portal-features.php';

$activityItems = legalpro_client_get_activity_feed($pdo, $client_id, 25);
$activityFeedHtml = legalpro_client_render_activity_feed_html($activityItems);

$clientPageNavbar = legalpro_render_client_page_navbar(
    'Dashboard',
    'Dashboard',
    '',
    ['include_search' => false]
);

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
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=6" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <link href="../assets/css/client-dashboard.css?v=2" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-dashboard-page{PORTAL_THEME_BODY_CLASS}">
<div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>

<?php include __DIR__ . '/../inc/client-menunav.php'; ?>

<main class="main-content position-relative border-radius-lg">
    {CLIENT_NAVBAR}

    <div class="container-fluid py-4 px-4">
        <div class="cd-page">
        {MESSAGE}

        <section class="cd-hero-card" aria-label="Dashboard overview">
            <div class="cd-hero-main">
                <div class="cd-hero-top">
                    <p class="cd-hero-kicker">Your legal workspace</p>
                    <span class="cd-hero-date">{HERO_DATE}</span>
                </div>
                <h1 class="cd-hero-title">{GREETING}, {CLIENT_NAME}</h1>
                <p class="cd-hero-sub">Track your cases, prepare for meetings, and stay on top of court dates — all in one place.</p>
                {HERO_GLANCE}
                {NEXT_APPT_BANNER}
            </div>
            <div class="cd-hero-actions">
                <a href="client-cases.php" class="btn btn-primary-solid">View cases</a>
                <a href="chatbot.php" class="btn btn-ghost">Ask assistant</a>
            </div>
        </section>

        <section class="cd-quick-grid" aria-label="Quick actions">
            {QUICK_ACTIONS}
        </section>

        <section class="cd-kpi-grid" aria-label="Case statistics">
            <div class="cd-kpi" style="--kpi-accent: var(--cd-primary);">
                <div>
                    <div class="cd-kpi__val">{TOTAL_CASES}</div>
                    <div class="cd-kpi__lbl">Total cases</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_BRIEFCASE}</div>
            </div>
            <div class="cd-kpi" style="--kpi-accent: #2dce89;">
                <div>
                    <div class="cd-kpi__val">{OPEN_CASES}</div>
                    <div class="cd-kpi__lbl">Open</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_ACTIVITY}</div>
            </div>
            <div class="cd-kpi" style="--kpi-accent: #fb6340;">
                <div>
                    <div class="cd-kpi__val">{PENDING_CASES}</div>
                    <div class="cd-kpi__lbl">Pending</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_CLOCK}</div>
            </div>
            <div class="cd-kpi" style="--kpi-accent: #8898aa;">
                <div>
                    <div class="cd-kpi__val">{CLOSED_CASES}</div>
                    <div class="cd-kpi__lbl">Closed</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_CHECK}</div>
            </div>
        </section>

        <section class="cd-panel cd-activity-panel">
            <div class="cd-panel-hdr">
                <div class="cd-panel-hdr__left">
                    <span class="cd-panel-hdr__icon">{PANEL_ICON_ACTIVITY}</span>
                    <div>
                        <p class="cd-panel-title">Recent activity</p>
                        <p class="cd-panel-sub">Invoices, hearings, documents, and appointments</p>
                    </div>
                </div>
                <a href="client-documents.php" class="btn-cd-link">{LINK_ICON_DOCS} Documents</a>
            </div>
            <div class="cd-panel-body">
                {ACTIVITY_FEED}
            </div>
        </section>

        <section class="cd-layout">
            <div class="cd-panel">
                <div class="cd-panel-hdr">
                    <div class="cd-panel-hdr__left">
                        <span class="cd-panel-hdr__icon">{PANEL_ICON_CASES}</span>
                        <div>
                            <p class="cd-panel-title">Recent cases</p>
                            <p class="cd-panel-sub">Latest updates on your matters</p>
                        </div>
                    </div>
                    <a href="client-cases.php" class="btn-cd-link">{LINK_ICON_ARROW} View all</a>
                </div>
                <div class="cd-panel-body">
                    {RECENT_CASES}
                </div>
            </div>
            <div class="cd-panel">
                <div class="cd-panel-hdr">
                    <div class="cd-panel-hdr__left">
                        <span class="cd-panel-hdr__icon">{PANEL_ICON_CALENDAR}</span>
                        <div>
                            <p class="cd-panel-title">Upcoming appointments</p>
                            <p class="cd-panel-sub">Confirmed meetings on your calendar</p>
                        </div>
                    </div>
                    <a href="client-appointments.php" class="btn-cd-link">{LINK_ICON_PLUS} Book one</a>
                </div>
                <div class="cd-panel-body">
                    {UPCOMING_APPOINTMENTS}
                </div>
            </div>
        </section>

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
$html = str_replace('{GREETING}', $greeting, $html);
$html = str_replace('{CLIENT_NAME}', $firstName, $html);
$html = str_replace('{HERO_DATE}', legalpro_icon('calendar') . htmlspecialchars($dateLabel), $html);
$html = str_replace('{HERO_GLANCE}', $heroGlanceHtml, $html);
$html = str_replace('{NEXT_APPT_BANNER}', $nextApptBanner, $html);
$html = str_replace('{QUICK_ACTIONS}', $quickActionsHtml, $html);
$html = str_replace('{TOTAL_CASES}', $totalCases, $html);
$html = str_replace('{OPEN_CASES}', $openCases, $html);
$html = str_replace('{PENDING_CASES}', (int) ($caseStats['pending_cases'] ?? 0), $html);
$html = str_replace('{CLOSED_CASES}', (int) ($caseStats['closed_cases'] ?? 0), $html);
$html = str_replace('{KPI_ICON_BRIEFCASE}', legalpro_icon('briefcase'), $html);
$html = str_replace('{KPI_ICON_ACTIVITY}', legalpro_icon('activity'), $html);
$html = str_replace('{KPI_ICON_CLOCK}', legalpro_icon('clock'), $html);
$html = str_replace('{KPI_ICON_CHECK}', legalpro_icon('check-circle'), $html);
$html = str_replace('{PANEL_ICON_ACTIVITY}', legalpro_icon('bell'), $html);
$html = str_replace('{PANEL_ICON_CASES}', legalpro_icon('briefcase'), $html);
$html = str_replace('{PANEL_ICON_CALENDAR}', legalpro_icon('calendar'), $html);
$html = str_replace('{LINK_ICON_DOCS}', legalpro_icon('file-text'), $html);
$html = str_replace('{LINK_ICON_ARROW}', legalpro_icon('arrow-right'), $html);
$html = str_replace('{LINK_ICON_PLUS}', legalpro_icon('plus'), $html);
$html = str_replace('{RECENT_CASES}', $recentCasesHtml, $html);
$html = str_replace('{UPCOMING_APPOINTMENTS}', $appointmentsHtml, $html);
$html = str_replace('{ACTIVITY_FEED}', $activityFeedHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
