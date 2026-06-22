<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = $_SESSION['client_id'];
$clientName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';

require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
$iconCourtRow = legalpro_icon('landmark');
$iconCourtEmpty = legalpro_icon('calendar');

// Check if court_dates table exists
$tableExists = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'court_dates'");
    $tableExists = $stmt->fetch() ? true : false;
} catch (PDOException $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $_SESSION['error_message'] = "Court dates table not found. Please contact administrator.";
}

// Table creation is now handled by the SQL script in sql/create_court_dates_table.sql

// Get court dates for this client's cases
try {
    $stmt = $pdo->prepare("
        SELECT
            cd.*,
            c.title as case_title,
            c.id as case_id,
            u.username as created_by_name,
            u.role as creator_role
        FROM court_dates cd
        INNER JOIN cases c ON cd.case_id = c.id
        LEFT JOIN users u ON cd.created_by = u.id
        WHERE c.client_id = ?
        ORDER BY cd.court_date ASC
    ");
    $stmt->execute([$clientId]);
    $court_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $court_dates = [];
}

// Prepare calendar events for FullCalendar (dashboard-style dots)
$calendar_events = [];
foreach ($court_dates as $date) {
    $caseId = (int) ($date['case_id'] ?? 0);
    $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
    $status = strtolower((string) ($date['status'] ?? 'scheduled'));
    $displayTitle = $caseNumber . ' · ' . ($date['title'] ?? 'Court date');
    if (!empty($date['case_title'])) {
        $displayTitle = $caseNumber . ' · ' . $date['case_title'];
    }
    $hearingTitle = (string) ($date['title'] ?? 'Court date');
    $courtDateLabel = date('M j, Y g:i A', strtotime((string) $date['court_date']));

    $calendar_events[] = [
        'id' => (string) $date['id'],
        'title' => $displayTitle,
        'start' => $date['court_date'],
        'backgroundColor' => 'transparent',
        'borderColor' => 'transparent',
        'textColor' => '#344767',
        'extendedProps' => [
            'status' => $status,
            'description' => $date['description'],
            'location' => $date['location'],
            'created_by_name' => $date['created_by_name'],
            'creator_role' => $date['creator_role'],
            'case_title' => $date['case_title'] ?? '',
            'case_id' => $caseId,
            'hearingTitle' => $hearingTitle,
            'statusLabel' => ucfirst($status),
            'courtDateId' => (int) $date['id'],
            'courtDateLabel' => $courtDateLabel,
            'searchHay' => strtolower(implode(' ', array_filter([
                $displayTitle,
                $date['case_title'] ?? '',
                $hearingTitle,
                $date['location'] ?? '',
                $date['description'] ?? '',
                $status,
                $courtDateLabel,
                date('Y-m-d', strtotime((string) $date['court_date'])),
                date('m/d/Y', strtotime((string) $date['court_date'])),
                $caseNumber,
                (string) $date['id'],
            ]))),
        ]
    ];
}

$upcomingCourtDatesHtml = '';
$upcomingCourtDates = array_values(array_filter($court_dates, function ($row) {
    $status = strtolower((string) ($row['status'] ?? ''));
    return strtotime((string) ($row['court_date'] ?? '')) >= time()
        && ($status === 'scheduled' || $status === 'postponed');
}));
if (empty($upcomingCourtDates)) {
    $upcomingCourtDatesHtml = '<div class="dashboard-upcoming-empty"><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">' . $iconCourtEmpty . '</div>No upcoming court dates</div>';
} else {
    usort($upcomingCourtDates, function ($a, $b) {
        return strtotime((string) ($a['court_date'] ?? '')) <=> strtotime((string) ($b['court_date'] ?? ''));
    });
    foreach (array_slice($upcomingCourtDates, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'scheduled'));
        if (!in_array($status, ['scheduled', 'completed', 'postponed', 'cancelled'], true)) {
            $status = 'scheduled';
        }
        $caseId = (int) ($row['case_id'] ?? 0);
        $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
        $title = htmlspecialchars($caseNumber . ' · ' . ($row['title'] ?? 'Court date'));
        $hourLabel = date('g:i A', strtotime((string) $row['court_date']));
        $dayLabel = date('M j', strtotime((string) $row['court_date']));
        $upcomingCourtDatesHtml .= '
        <button type="button" class="dashboard-upcoming-item dashboard-upcoming-item--' . htmlspecialchars($status) . ' cct-search-row" data-court-date-id="' . (int) $row['id'] . '"' . legalpro_client_search_data_attr([
            $caseNumber,
            $row['case_title'] ?? '',
            $row['title'] ?? '',
            $row['location'] ?? '',
            $row['description'] ?? '',
            $status,
            $hourLabel,
            $dayLabel,
        ]) . '>
            <span class="dashboard-upcoming-item__time">' . htmlspecialchars($hourLabel) . '<br><small style="font-weight:500;opacity:.8">' . htmlspecialchars($dayLabel) . '</small></span>
            <span class="flex-grow-1">
                <p class="dashboard-upcoming-item__title">' . $title . '</p>
                <p class="dashboard-upcoming-item__sub">' . htmlspecialchars((string) ($row['case_title'] ?? '')) . '</p>
            </span>
        </button>';
    }
}

$ctTotal = count($court_dates);
$ctScheduled = 0;
$ctCompleted = 0;
$ctUpcoming = 0;
$todayStart = strtotime('today');
foreach ($court_dates as $_cd) {
    $st = $_cd['status'] ?? '';
    if ($st === 'scheduled') {
        $ctScheduled++;
    }
    if ($st === 'completed') {
        $ctCompleted++;
    }
    $cdTs = !empty($_cd['court_date']) ? strtotime($_cd['court_date']) : 0;
    if ($cdTs >= $todayStart && ($st === 'scheduled' || $st === 'postponed')) {
        $ctUpcoming++;
    }
}

$heroHtml = client_portal_render_hero([
    'kicker' => 'Docket',
    'title' => 'Hearings & appearances',
    'subtitle' => 'Use the calendar for a month view, search for hearings, or scan the list below. Click an event or search result for full information.',
    'show_date' => true,
    'aria_label' => 'Court tracking overview',
    'stats' => [
        ['num' => (string) $ctTotal, 'lbl' => 'Total'],
        ['num' => (string) $ctUpcoming, 'lbl' => 'Upcoming'],
        ['num' => (string) $ctScheduled, 'lbl' => 'Scheduled'],
        ['num' => (string) $ctCompleted, 'lbl' => 'Completed'],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => 'Dashboard', 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-cases.php', 'label' => 'My cases', 'icon' => 'briefcase'],
    ],
]);

$courtTableError = '';
if (!empty($_SESSION['error_message'])) {
    $courtTableError = (string) $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>Court Tracking - LegalPro</title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php
    define('LEGALPRO_SKIP_DASHBOARD_ENHANCEMENTS', true);
    include __DIR__ . '/../inc/client-portal-head.php';
    include __DIR__ . '/../inc/client-court-tracking-calendar-css.php';
    ?>
    <link href="../assets/css/client-portal-pages.css?v=1" rel="stylesheet" />
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body.client-court-tracking-page {
            --cct-primary: var(--legalpro-theme-primary, #5e72e4);
            --cct-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
            --cct-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
            --cct-primary-border: var(--lp-cases-accent-border, rgba(94, 114, 228, 0.35));
            --cct-r: 16px;
            --cct-shadow: 0 2px 12px rgba(0,0,0,0.07);
        }

        .client-court-tracking-page .cct-panel {
            background: #fff;
            border-radius: var(--cct-r);
            border: 1px solid #e9ecf3;
            box-shadow: var(--cct-shadow);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .cct-panel-hdr {
            padding: 1.1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
        }
        .cct-panel-hdr h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
        .cct-panel-hdr p { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }
        .cct-count {
            background: var(--cct-primary-soft);
            color: var(--cct-primary);
            font-size: 11px; font-weight: 700;
            padding: .2rem .65rem; border-radius: 99px;
        }
        .cct-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cct-table thead th {
            background: #f8fafc; color: #94a3b8;
            font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
            padding: .7rem 1rem; border-bottom: 1px solid #f1f5f9; white-space: nowrap;
        }
        .cct-table thead th:first-child { padding-left: 1.5rem; }
        .cct-table thead th:last-child { padding-right: 1.5rem; text-align: right; }
        .cct-table tbody tr { border-bottom: 1px solid #f8fafc; transition: background .1s; }
        .cct-table tbody tr:hover { background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04); }
        .cct-table tbody td { padding: .85rem 1rem; vertical-align: middle; }
        .cct-table tbody td:first-child { padding-left: 1.5rem; }
        .cct-table tbody td:last-child { padding-right: 1.5rem; text-align: right; }

        .cct-row-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--cct-primary-soft); color: var(--cct-primary);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .cct-row-icon .lp-icon svg { stroke: currentColor; }
        .btn-cct-view {
            padding: .35rem .9rem; border-radius: 8px;
            border: 1.5px solid var(--cct-primary); color: var(--cct-primary);
            font-size: 12px; font-weight: 600; background: none; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .btn-cct-view:hover { background: var(--cct-primary); color: #fff; }

        .cct-empty {
            padding: 3.5rem 1.5rem; text-align: center;
        }
        .cct-empty-icon {
            width: 52px; height: 52px; border-radius: 14px;
            background: var(--cct-primary-soft); color: var(--cct-primary);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
        }
        .cct-empty h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: .35rem; }
        .cct-empty p { font-size: 13px; color: #94a3b8; max-width: 24rem; margin: 0 auto; }

        .client-court-tracking-page .fc-event { cursor: pointer; }
        .client-court-tracking-page #courtTrackingCalendar { min-height: 28rem; }
        .client-court-tracking-page .min-width-0 { min-width: 0; }

        .client-court-tracking-page .cct-cal-search-wrap {
            position: relative;
            width: 100%;
            flex-shrink: 0;
        }
        .client-court-tracking-page .dashboard-calendar-hub__head {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .client-court-tracking-page .cct-cal-search-wrap--featured {
            padding: .9rem 1rem 1rem;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12) 0%, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04) 100%);
            border: 1px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            box-shadow: 0 6px 22px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .client-court-tracking-page .cct-cal-search-label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--cct-primary);
            margin-bottom: .55rem;
        }
        .client-court-tracking-page .cct-cal-search-field {
            display: flex;
            align-items: center;
            gap: .7rem;
            background: #fff;
            border: 2px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.32);
            border-radius: 12px;
            padding: .7rem 1rem;
            transition: border-color .15s, box-shadow .15s, transform .15s;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.07);
        }
        .client-court-tracking-page .cct-cal-search-field:focus-within {
            border-color: var(--cct-primary);
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.18), 0 4px 16px rgba(15, 23, 42, 0.1);
            transform: translateY(-1px);
        }
        .client-court-tracking-page .cct-cal-search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
            color: var(--cct-primary);
            flex-shrink: 0;
        }
        .client-court-tracking-page .cct-cal-search-field svg {
            width: 18px;
            height: 18px;
            color: currentColor;
            flex-shrink: 0;
        }
        .client-court-tracking-page .cct-cal-search-input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            font-family: inherit;
        }
        .client-court-tracking-page .cct-cal-search-input::placeholder {
            color: #64748b;
            font-weight: 500;
        }
        .client-court-tracking-page .cct-cal-search-results {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 6px);
            z-index: 30;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            max-height: 320px;
            overflow-y: auto;
            padding: .35rem;
        }
        .client-court-tracking-page .cct-cal-search-item {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            width: 100%;
            text-align: left;
            border: none;
            background: transparent;
            border-radius: 10px;
            padding: .65rem .75rem;
            cursor: pointer;
            font-family: inherit;
            transition: background .12s;
        }
        .client-court-tracking-page .cct-cal-search-item:hover,
        .client-court-tracking-page .cct-cal-search-item:focus-visible {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
            outline: none;
        }
        .client-court-tracking-page .cct-cal-search-item__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: .45rem;
            flex-shrink: 0;
        }
        .client-court-tracking-page .cct-cal-search-item__dot--scheduled { background: #5e72e4; }
        .client-court-tracking-page .cct-cal-search-item__dot--completed { background: #2dce89; }
        .client-court-tracking-page .cct-cal-search-item__dot--postponed { background: #fb6340; }
        .client-court-tracking-page .cct-cal-search-item__dot--cancelled { background: #f5365c; }
        .client-court-tracking-page .cct-cal-search-item__body { min-width: 0; flex: 1; }
        .client-court-tracking-page .cct-cal-search-item__title {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .client-court-tracking-page .cct-cal-search-item__sub {
            font-size: 11.5px;
            color: #64748b;
            margin: 0;
        }
        .client-court-tracking-page .cct-cal-search-empty {
            padding: 1rem .75rem;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-wrap--featured {
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.2) 0%, rgba(61, 69, 92, 0.55) 100%);
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-label {
            color: #b8c4ff;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-icon {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            color: #d4dcff;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-field,
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-results {
            background: var(--lp-dark-surface-raised, #3d455c);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35);
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-field:focus-within {
            border-color: #9aaeff;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.22);
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-input {
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-input::placeholder {
            color: #94a3b8;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-item__title {
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search {
            min-width: min(100%, 340px);
        }
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search .input-group {
            border: 2px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.3) !important;
            background: rgba(255, 255, 255, 0.96) !important;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.1);
            border-radius: 12px !important;
        }
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search .input-group:focus-within {
            border-color: var(--cct-primary) !important;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.16), 0 4px 16px rgba(15, 23, 42, 0.1) !important;
        }
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search .form-control,
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search input[type="search"].form-control {
            font-size: 14px !important;
            font-weight: 600 !important;
        }
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search .input-group-text {
            color: var(--cct-primary) !important;
        }
        body.legalpro-dark-mode.client-court-tracking-page .navbar-main .legalpro-navbar-search .input-group {
            background: var(--lp-dark-surface-raised, #3d455c) !important;
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35) !important;
        }
        .court-date-modal .modal-dialog { max-width: 600px; }

        @media (max-width: 640px) {
            .cct-hero-card { padding: 1.5rem; }
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-court-tracking-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?php
        echo legalpro_render_client_page_navbar('Court tracking', 'Court tracking', 'Search hearings & cases…', array_merge(
            legalpro_client_page_search_options('client-court-tracking.php'),
            ['client_name' => $clientName]
        ));
        ?>

        <div class="container-fluid py-4">
            <div class="cp-page">
            <?php if ($courtTableError !== ''): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($courtTableError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php echo $heroHtml; ?>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-calendar-hub">
                        <div class="dashboard-calendar-hub__head">
                            <div class="cct-calendar-hub__intro">
                                <h6 class="text-capitalize mb-0 font-weight-bold" style="color: var(--cct-primary);">Court Dates Calendar</h6>
                                <p class="text-sm mb-0 text-muted">Use the search bar below to find hearings quickly, or click a calendar event</p>
                                <div class="dashboard-legend-pills">
                                    <span class="dashboard-legend-pill dashboard-legend-pill--scheduled"><i></i> Scheduled</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--completed"><i></i> Completed</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--postponed"><i></i> Postponed</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--cancelled"><i></i> Cancelled</span>
                                </div>
                            </div>
                            <div class="cct-cal-search-wrap cct-cal-search-wrap--featured">
                                <label class="cct-cal-search-label" for="cctCalSearchInput">Search court dates</label>
                                <div class="cct-cal-search-field">
                                    <span class="cct-cal-search-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
                                            <circle cx="11" cy="11" r="7"></circle>
                                            <path d="M20 20l-3-3"></path>
                                        </svg>
                                    </span>
                                    <input type="search" id="cctCalSearchInput" class="cct-cal-search-input"
                                           placeholder="Search by case, hearing, location, status…" autocomplete="off">
                                </div>
                                <div class="cct-cal-search-results" id="cctCalSearchResults" hidden></div>
                            </div>
                        </div>
                        <div class="dashboard-calendar-hub__body">
                            <div class="dashboard-calendar-layout">
                                <div id="courtTrackingCalendar"></div>
                                <aside class="dashboard-upcoming-panel">
                                    <div class="dashboard-upcoming-panel__title">
                                        <span>Upcoming</span>
                                        <a href="#courtDatesTable" class="text-xs text-primary font-weight-bold">View all</a>
                                    </div>
                                    <div class="dashboard-upcoming-list" id="upcomingCourtDatesList">
                                        <?php echo $upcomingCourtDatesHtml; ?>
                                    </div>
                                </aside>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cct-panel" id="courtDatesTable">
                <div class="cct-panel-hdr">
                    <div>
                        <h5>All court dates</h5>
                        <p>Sorted by date, earliest first.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <span class="cct-count" id="cctRowCount"><?php echo (int) $ctTotal; ?> total</span>
                        <a href="client-cases.php" class="btn-cct-view text-decoration-none">My cases</a>
                    </div>
                </div>
                <?php if (empty($court_dates)): ?>
                    <div class="cct-empty">
                        <div class="cct-empty-icon"><?php echo $iconCourtEmpty; ?></div>
                        <h5>No court dates yet</h5>
                        <p>When your legal team adds hearings or appearances for your matters, they will appear here and on the calendar above.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="cct-table">
                            <thead>
                                <tr>
                                    <th>Case</th>
                                    <th>Date &amp; time</th>
                                    <th>Title</th>
                                    <th style="text-align:center">Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($court_dates as $date):
                                    $cid = (int) ($date['case_id'] ?? 0);
                                    $rowStatusBadge = client_court_date_status_badge((string) ($date['status'] ?? ''));
                                    ?>
                                    <?php
                                    $rowCaseNumber = $cid > 0 ? 'C-' . str_pad((string) $cid, 4, '0', STR_PAD_LEFT) : '';
                                    ?>
                                    <tr class="cct-search-row"<?php echo legalpro_client_search_data_attr([
                                        $rowCaseNumber,
                                        $date['case_title'] ?? '',
                                        $date['title'] ?? '',
                                        $date['location'] ?? '',
                                        $date['description'] ?? '',
                                        $date['status'] ?? '',
                                        $date['court_date'] ?? '',
                                    ]); ?>>
                                        <td>
                                            <div class="d-flex align-items-center gap-3 py-1">
                                                <div class="cct-row-icon"><?php echo $iconCourtRow; ?></div>
                                                <div class="min-width-0">
                                                    <?php if ($cid > 0): ?>
                                                    <a href="client-case-view.php?id=<?php echo $cid; ?>" class="text-sm font-weight-bold mb-0 d-inline-block text-truncate text-reset" style="max-width: 14rem;"><?php echo htmlspecialchars($date['case_title']); ?></a>
                                                    <?php else: ?>
                                                    <span class="text-sm font-weight-bold d-inline-block text-truncate" style="max-width: 14rem;"><?php echo htmlspecialchars($date['case_title']); ?></span>
                                                    <?php endif; ?>
                                                    <p class="text-xs text-muted mb-0">Matter</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0"><?php echo date('M j, Y', strtotime($date['court_date'])); ?></p>
                                            <p class="text-xs text-muted mb-0"><?php echo date('g:i A', strtotime($date['court_date'])); ?></p>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0 text-truncate" style="max-width: 12rem;" title="<?php echo htmlspecialchars($date['title']); ?>"><?php echo htmlspecialchars($date['title']); ?></p>
                                        </td>
                                        <td class="text-center"><?php echo $rowStatusBadge; ?></td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                                <button type="button" class="btn-cct-view cdoc-touch-btn" onclick="viewCourtDate(<?php echo (int) $date['id']; ?>)" title="View">View</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </main>

    <!-- View Court Date Modal -->
    <div class="modal fade" id="viewCourtDateModal" tabindex="-1" aria-labelledby="viewCourtDateModalLabel" aria-hidden="true">
        <div class="modal-dialog court-date-modal modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-radius-xl shadow-lg overflow-hidden">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-weight-bolder mb-0" id="viewCourtDateModalLabel">Court date details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <strong>Case:</strong> <span id="view_case_title"></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Date & Time:</strong> <span id="view_datetime"></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Status:</strong> <span id="view_status" class="ca-status-pill ca-status-pill--muted"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Title:</strong> <span id="view_title"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Description:</strong> <span id="view_description"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Location:</strong> <span id="view_location"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Created by:</strong> <span id="view_created_by"></span> (<span id="view_creator_role"></span>)
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/jquery.min.js"></script>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script>
        var clientCourtTrackingCalendar = null;

        function escapeHtmlCct(text) {
            var d = document.createElement('div');
            d.textContent = text == null ? '' : String(text);
            return d.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('courtTrackingCalendar');
            var courtEvents = <?php echo json_encode($calendar_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

            function courtStatusKey(status) {
                var value = String(status || 'scheduled').toLowerCase();
                if (['scheduled', 'completed', 'cancelled', 'postponed'].indexOf(value) === -1) {
                    return 'scheduled';
                }
                return value;
            }

            function renderCourtEvent(arg) {
                var props = arg.event.extendedProps || {};
                var statusKey = courtStatusKey(props.status);
                var timeText = arg.timeText || '';
                var title = arg.event.title || 'Court date';
                if (title.length > 22) {
                    title = title.slice(0, 19) + '...';
                }
                var wrap = document.createElement('div');
                wrap.className = 'dashboard-cal-event';
                wrap.innerHTML =
                    '<span class="dashboard-cal-event__dot dashboard-cal-event__dot--' + statusKey + '"></span>' +
                    '<span class="dashboard-cal-event__text">' + timeText + (timeText ? ' ' : '') + title + '</span>';
                return { domNodes: [wrap] };
            }

            var upcomingList = document.getElementById('upcomingCourtDatesList');
            if (upcomingList) {
                upcomingList.addEventListener('click', function(e) {
                    var btn = e.target.closest('[data-court-date-id]');
                    if (!btn) return;
                    viewCourtDate(btn.getAttribute('data-court-date-id'));
                });
            }

            if (!calendarEl || typeof FullCalendar === 'undefined') {
                initCctCalendarSearch(courtEvents);
                return;
            }

            clientCourtTrackingCalendar = new FullCalendar.Calendar(calendarEl, {
                initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
                height: 'auto',
                firstDay: 1,
                navLinks: true,
                nowIndicator: true,
                fixedWeekCount: false,
                dayMaxEvents: 3,
                moreLinkClick: 'day',
                buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
                eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                dayHeaderFormat: { weekday: 'short' },
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listWeek'
                },
                events: courtEvents,
                eventContent: renderCourtEvent,
                dateClick: function(info) {
                    if (window.legalproHandleCalendarDateClick) {
                        window.legalproHandleCalendarDateClick(info, function(event) {
                            viewCourtDate(event.id);
                        });
                    }
                },
                dayCellDidMount: function(info) {
                    if (window.legalproMountCalendarDayCell) {
                        window.legalproMountCalendarDayCell(info);
                    }
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    viewCourtDate(info.event.id);
                },
                eventDidMount: function(info) {
                    if (window.legalproMountCalendarEventClickable) {
                        window.legalproMountCalendarEventClickable(info);
                    }
                    info.el.setAttribute('title', info.event.title || 'Court date');
                }
            });
            clientCourtTrackingCalendar.render();
            initCctCalendarSearch(courtEvents);
        });

        function initCctCalendarSearch(courtEvents) {
            var input = document.getElementById('cctCalSearchInput');
            var resultsEl = document.getElementById('cctCalSearchResults');
            if (!input || !resultsEl) {
                return;
            }

            function courtStatusKey(status) {
                var value = String(status || 'scheduled').toLowerCase();
                if (['scheduled', 'completed', 'cancelled', 'postponed'].indexOf(value) === -1) {
                    return 'scheduled';
                }
                return value;
            }

            function hideResults() {
                resultsEl.hidden = true;
                resultsEl.innerHTML = '';
            }

            function renderSearchResults(matches) {
                var query = input.value.trim();
                if (!query) {
                    hideResults();
                    return;
                }
                if (!matches.length) {
                    resultsEl.innerHTML = '<div class="cct-cal-search-empty">No court dates match your search.</div>';
                    resultsEl.hidden = false;
                    return;
                }

                var html = '';
                matches.slice(0, 12).forEach(function(ev) {
                    var props = ev.extendedProps || {};
                    var statusKey = courtStatusKey(props.status);
                    var title = ev.title || 'Court date';
                    var when = props.courtDateLabel || '';
                    var hearing = props.hearingTitle || '';
                    var location = props.location ? ' · ' + props.location : '';
                    html += '<button type="button" class="cct-cal-search-item" data-court-date-id="' + escapeHtmlCct(props.courtDateId || ev.id) + '" data-start="' + escapeHtmlCct(ev.start || '') + '">' +
                        '<span class="cct-cal-search-item__dot cct-cal-search-item__dot--' + escapeHtmlCct(statusKey) + '" aria-hidden="true"></span>' +
                        '<span class="cct-cal-search-item__body">' +
                            '<p class="cct-cal-search-item__title">' + escapeHtmlCct(title) + '</p>' +
                            '<p class="cct-cal-search-item__sub">' + escapeHtmlCct(when) + (hearing ? ' · ' + escapeHtmlCct(hearing) : '') + escapeHtmlCct(location) + ' · ' + escapeHtmlCct(props.statusLabel || props.status || 'Scheduled') + '</p>' +
                        '</span>' +
                    '</button>';
                });
                resultsEl.innerHTML = html;
                resultsEl.hidden = false;
            }

            input.addEventListener('input', function() {
                var q = input.value.trim().toLowerCase();
                if (!q) {
                    hideResults();
                    return;
                }

                var matches = courtEvents.filter(function(ev) {
                    var props = ev.extendedProps || {};
                    var hay = props.searchHay || ((ev.title || '') + ' ' + (props.case_title || '')).toLowerCase();
                    return hay.indexOf(q) !== -1;
                });

                matches.sort(function(a, b) {
                    return new Date(b.start).getTime() - new Date(a.start).getTime();
                });

                renderSearchResults(matches);
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideResults();
                    input.blur();
                }
            });

            resultsEl.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-court-date-id]');
                if (!btn) {
                    return;
                }
                var id = btn.getAttribute('data-court-date-id');
                var start = btn.getAttribute('data-start');
                if (clientCourtTrackingCalendar && start) {
                    clientCourtTrackingCalendar.gotoDate(start);
                }
                viewCourtDate(id);
                hideResults();
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.cct-cal-search-wrap')) {
                    hideResults();
                }
            });
        }
    </script>

    <script>
        // View court date details
        function viewCourtDate(id) {
            // Find the event data
            var events = <?php echo json_encode($court_dates); ?>;
            var eventData = events.find(function(e) { return e.id == id; });

            if (eventData) {
                document.getElementById('view_case_title').textContent = eventData.case_title;
                document.getElementById('view_datetime').textContent = new Date(eventData.court_date).toLocaleString();
                var statusLabels = {
                    scheduled: 'Scheduled',
                    completed: 'Completed',
                    cancelled: 'Cancelled',
                    postponed: 'Postponed'
                };
                var statusPills = {
                    scheduled: 'ca-status-pill ca-status-pill--scheduled',
                    completed: 'ca-status-pill ca-status-pill--done',
                    cancelled: 'ca-status-pill ca-status-pill--declined',
                    postponed: 'ca-status-pill ca-status-pill--pending'
                };
                var statusKey = (eventData.status || '').toLowerCase();
                document.getElementById('view_status').textContent = statusLabels[statusKey] || (statusKey.charAt(0).toUpperCase() + statusKey.slice(1));
                document.getElementById('view_status').className = statusPills[statusKey] || 'ca-status-pill ca-status-pill--muted';
                document.getElementById('view_title').textContent = eventData.title;
                document.getElementById('view_description').textContent = eventData.description || 'No description';
                document.getElementById('view_location').textContent = eventData.location || 'Not specified';
                document.getElementById('view_created_by').textContent = eventData.created_by_name || 'Unknown';
                document.getElementById('view_creator_role').textContent = eventData.creator_role ? eventData.creator_role.charAt(0).toUpperCase() + eventData.creator_role.slice(1) : 'Unknown';

                bootstrap.Modal.getOrCreateInstance(document.getElementById('viewCourtDateModal')).show();
            }
        }
    </script>
    <?php echo legalpro_render_client_page_search_script('.cct-search-row', '#cctRowCount', 'court date', 'court dates', ' total'); ?>
    <script>
    (function () {
        document.addEventListener('DOMContentLoaded', function () {
            var params = new URLSearchParams(window.location.search);
            var q = (params.get('q') || '').trim().toLowerCase();
            if (!q) return;
            document.querySelectorAll('#upcomingCourtDatesList .cct-search-row').forEach(function (row) {
                var hay = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                row.style.display = hay.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    })();
    </script>
</body>
</html>
