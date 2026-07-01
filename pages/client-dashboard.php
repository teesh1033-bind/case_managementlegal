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
    $message     = client_t('dashboard.error_load');
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
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';

$hour = (int) date('G');
$greeting = client_greeting();
$firstName = htmlspecialchars(explode(' ', trim((string) $client_name))[0] ?: $client_name);
$dateLabel = date('l, F j');

$caseWord = client_plural('common.case', 'common.cases', $openCases);
$meetingWord = client_plural('common.meeting', 'common.meetings', $upcomingCount);
$heroGlanceHtml = '<div class="cd-hero-glance">'
    . '<span class="cd-hero-glance__item">' . htmlspecialchars(client_t('dashboard.open_cases', ['count' => (string) $openCases, 'cases' => $caseWord])) . '</span>'
    . '<span class="cd-hero-glance__item">' . htmlspecialchars(client_t('dashboard.upcoming_meetings', ['count' => (string) $upcomingCount, 'meetings' => $meetingWord])) . '</span>'
    . '</div>';

$nextApptBanner = '';
if ($nextAppt) {
    $dt = date('l, j F · g:i A', strtotime($nextAppt['starts_at']));
    $nextApptBanner = '<div class="cd-next-appt">'
        . legalpro_icon('calendar-clock')
        . '<span>' . htmlspecialchars(client_t('common.next')) . ': <strong>' . htmlspecialchars($dt) . '</strong> · ' . htmlspecialchars($nextAppt['case_title'] ?: client_t('common.general')) . '</span>'
        . '</div>';
}

$quickActionsHtml = '';
$quickActions = [
    ['url' => 'client-cases.php', 'icon' => 'briefcase', 'tone' => '', 'label' => client_t('dashboard.quick.cases'), 'desc' => client_t('dashboard.quick.cases_desc')],
    ['url' => 'client-documents.php', 'icon' => 'file-text', 'tone' => 'success', 'label' => client_t('dashboard.quick.documents'), 'desc' => client_t('dashboard.quick.documents_desc')],
    ['url' => 'client-appointments.php', 'icon' => 'calendar', 'tone' => 'info', 'label' => client_t('dashboard.quick.appointments'), 'desc' => client_t('dashboard.quick.appointments_desc')],
    ['url' => 'client-payments.php', 'icon' => 'credit-card', 'tone' => 'warning', 'label' => client_t('dashboard.quick.payments'), 'desc' => client_t('dashboard.quick.payments_desc')],
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
        . '<p class="cd-empty-title">' . htmlspecialchars(client_t('dashboard.no_cases')) . '</p>'
        . '<p class="cd-empty-sub">' . htmlspecialchars(client_t('dashboard.no_cases_sub')) . '</p>'
        . '</div>';
} else {
    foreach ($recentCases as $case) {
        $num     = 'C-' . str_pad((string) $case['id'], 4, '0', STR_PAD_LEFT);
        $title   = htmlspecialchars($case['title']);
        $lawyer  = htmlspecialchars($case['lawyer_names'] ?: client_t('common.unassigned'));
        $updated = date('M j, Y', strtotime($case['updated_at']));
        $pill    = client_case_status_badge((string) ($case['status'] ?? ''));
        $caseSearchHay = htmlspecialchars(strtolower($num . ' ' . ($case['title'] ?? '') . ' ' . ($case['lawyer_names'] ?? '') . ' ' . ($case['status'] ?? '')), ENT_QUOTES, 'UTF-8');
        $recentCasesHtml .= '<a href="client-case-view.php?id=' . (int) $case['id'] . '" class="cd-list-row" data-search="' . $caseSearchHay . '">'
            . '<div class="cd-list-row__icon">' . legalpro_icon('briefcase') . '</div>'
            . '<div class="cd-list-row__body">'
            . '<div class="cd-list-row__title">' . $num . ' · ' . $title . '</div>'
            . '<div class="cd-list-row__meta">' . $lawyer . ' · ' . htmlspecialchars(client_t('common.updated')) . ' ' . $updated . '</div>'
            . '</div>'
            . '<div class="cd-list-row__aside">' . $pill . '</div>'
            . '</a>';
    }
}

$appointmentsHtml = '';
if (empty($upcomingAppointments)) {
    $appointmentsHtml = '<div class="cd-empty-state">'
        . '<div class="cd-empty-icon">' . legalpro_icon('calendar') . '</div>'
        . '<p class="cd-empty-title">' . htmlspecialchars(client_t('dashboard.no_meetings')) . '</p>'
        . '<p class="cd-empty-sub">' . htmlspecialchars(client_t('dashboard.no_meetings_sub')) . '</p>'
        . '</div>';
} else {
    foreach ($upcomingAppointments as $apt) {
        $dayLabel  = date('M j', strtotime($apt['starts_at']));
        $timeLabel = date('g:i A', strtotime($apt['starts_at']));
        $caseTitle = htmlspecialchars($apt['case_title'] ?: client_t('common.general_appointment'));
        $lawyerTxt = htmlspecialchars($apt['lawyer_name'] ?: client_t('common.tbd'));
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
            . '<div class="cd-appt-row__badge"><span class="cd-appt-accepted">' . htmlspecialchars(client_t('common.confirmed')) . '</span></div>'
            . '</a>';
    }
}

require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../lib/client-portal-features.php';

$activityItems = legalpro_client_get_activity_feed($pdo, $client_id, 25);
$activityFeedHtml = legalpro_client_render_activity_feed_html($activityItems);

$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('dashboard.title'),
    client_t('dashboard.title'),
    '',
    ['include_search' => false]
);

$messageHtml = $message
    ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show mb-3" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . htmlspecialchars(client_t('common.close')) . '"></button></div>'
    : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>{PAGE_TITLE}</title>
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

        <section class="cd-hero-card" aria-label="{DASH_ARIA}">
            <div class="cd-hero-main">
                <div class="cd-hero-top">
                    <p class="cd-hero-kicker">{DASH_KICKER}</p>
                    <span class="cd-hero-date">{HERO_DATE}</span>
                </div>
                <h1 class="cd-hero-title">{GREETING}, {CLIENT_NAME}</h1>
                <p class="cd-hero-sub">{DASH_SUB}</p>
                {HERO_GLANCE}
                {NEXT_APPT_BANNER}
            </div>
            <div class="cd-hero-actions">
                <a href="client-cases.php" class="btn btn-primary-solid">{DASH_VIEW_CASES}</a>
                <a href="chatbot.php" class="btn btn-ghost">{DASH_ASK_ASSISTANT}</a>
            </div>
        </section>

        <section class="cd-quick-grid" aria-label="Quick actions">
            {QUICK_ACTIONS}
        </section>

        <section class="cd-kpi-grid" aria-label="Case statistics">
            <div class="cd-kpi" style="--kpi-accent: var(--cd-primary);">
                <div>
                    <div class="cd-kpi__val">{TOTAL_CASES}</div>
                    <div class="cd-kpi__lbl">{LBL_TOTAL_CASES}</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_BRIEFCASE}</div>
            </div>
            <div class="cd-kpi" style="--kpi-accent: #2dce89;">
                <div>
                    <div class="cd-kpi__val">{OPEN_CASES}</div>
                    <div class="cd-kpi__lbl">{LBL_ACTIVE}</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_ACTIVITY}</div>
            </div>
            <div class="cd-kpi" style="--kpi-accent: #fb6340;">
                <div>
                    <div class="cd-kpi__val">{PENDING_CASES}</div>
                    <div class="cd-kpi__lbl">{LBL_PENDING}</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_CLOCK}</div>
            </div>
            <div class="cd-kpi" style="--kpi-accent: #8898aa;">
                <div>
                    <div class="cd-kpi__val">{CLOSED_CASES}</div>
                    <div class="cd-kpi__lbl">{LBL_CLOSED}</div>
                </div>
                <div class="cd-kpi__icon">{KPI_ICON_CHECK}</div>
            </div>
        </section>

        <section class="cd-panel cd-activity-panel">
            <div class="cd-panel-hdr">
                <div class="cd-panel-hdr__left">
                    <span class="cd-panel-hdr__icon">{PANEL_ICON_ACTIVITY}</span>
                    <div>
                        <p class="cd-panel-title">{LBL_RECENT_ACTIVITY}</p>
                        <p class="cd-panel-sub">{LBL_RECENT_ACTIVITY_SUB}</p>
                    </div>
                </div>
                <a href="client-documents.php" class="btn-cd-link">{LINK_ICON_DOCS} {LBL_DOCUMENTS}</a>
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
                            <p class="cd-panel-title">{LBL_RECENT_CASES}</p>
                            <p class="cd-panel-sub">{LBL_RECENT_CASES_SUB}</p>
                        </div>
                    </div>
                    <a href="client-cases.php" class="btn-cd-link">{LINK_ICON_ARROW} {LBL_VIEW_ALL}</a>
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
                            <p class="cd-panel-title">{LBL_UPCOMING_APPOINTMENTS}</p>
                            <p class="cd-panel-sub">{LBL_UPCOMING_APPOINTMENTS_SUB}</p>
                        </div>
                    </div>
                    <a href="client-appointments.php" class="btn-cd-link">{LINK_ICON_PLUS} {LBL_BOOK_ONE}</a>
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
$html = str_replace('{HTML_LANG}', client_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars(client_t('dashboard.page_title') . ' — LegalPro'), $html);
$html = str_replace('{DASH_ARIA}', htmlspecialchars(client_t('dashboard.aria_overview')), $html);
$html = str_replace('{DASH_KICKER}', htmlspecialchars(client_t('dashboard.kicker')), $html);
$html = str_replace('{DASH_SUB}', htmlspecialchars(client_t('dashboard.subtitle')), $html);
$html = str_replace('{DASH_VIEW_CASES}', htmlspecialchars(client_t('dashboard.view_cases_btn')), $html);
$html = str_replace('{DASH_ASK_ASSISTANT}', htmlspecialchars(client_t('dashboard.ask_assistant_btn')), $html);
$html = str_replace('{LBL_TOTAL_CASES}', htmlspecialchars(client_t('dashboard.total_cases')), $html);
$html = str_replace('{LBL_ACTIVE}', htmlspecialchars(client_t('dashboard.active')), $html);
$html = str_replace('{LBL_PENDING}', htmlspecialchars(client_t('dashboard.pending')), $html);
$html = str_replace('{LBL_CLOSED}', htmlspecialchars(client_t('dashboard.closed')), $html);
$html = str_replace('{LBL_RECENT_ACTIVITY}', htmlspecialchars(client_t('dashboard.recent_activity')), $html);
$html = str_replace('{LBL_RECENT_ACTIVITY_SUB}', htmlspecialchars(client_t('dashboard.recent_activity_sub')), $html);
$html = str_replace('{LBL_DOCUMENTS}', htmlspecialchars(client_t('dashboard.documents_link')), $html);
$html = str_replace('{LBL_RECENT_CASES}', htmlspecialchars(client_t('dashboard.recent_cases')), $html);
$html = str_replace('{LBL_RECENT_CASES_SUB}', htmlspecialchars(client_t('dashboard.recent_cases_sub')), $html);
$html = str_replace('{LBL_VIEW_ALL}', htmlspecialchars(client_t('dashboard.view_all')), $html);
$html = str_replace('{LBL_UPCOMING_APPOINTMENTS}', htmlspecialchars(client_t('dashboard.upcoming_appointments')), $html);
$html = str_replace('{LBL_UPCOMING_APPOINTMENTS_SUB}', htmlspecialchars(client_t('dashboard.upcoming_appointments_sub')), $html);
$html = str_replace('{LBL_BOOK_ONE}', htmlspecialchars(client_t('dashboard.book_one')), $html);
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
