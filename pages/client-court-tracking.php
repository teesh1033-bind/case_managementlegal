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
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';
require_once __DIR__ . '/../lib/portal_list_ui.php';
require_once __DIR__ . '/../lib/appointment_list_ui.php';
require_once __DIR__ . '/../lib/portal_calendar_events.php';
require_once __DIR__ . '/../inc/portal-calendar-studio.php';
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
    $_SESSION['error_message'] = client_t('court.table_missing');
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

$calendar_events = legalpro_portal_build_court_calendar_events($court_dates);

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
        $title = htmlspecialchars($caseNumber . ' Â· ' . ($row['title'] ?? 'Court date'));
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
    'kicker' => client_t('court.kicker'),
    'title' => client_t('court.hero_title'),
    'subtitle' => client_t('court.subtitle'),
    'show_date' => true,
    'aria_label' => client_t('court.aria'),
    'stats' => [
        ['num' => (string) $ctTotal, 'lbl' => client_t('common.total')],
        ['num' => (string) $ctUpcoming, 'lbl' => client_t('court.stat_upcoming')],
        ['num' => (string) $ctScheduled, 'lbl' => client_t('court.stat_scheduled')],
        ['num' => (string) $ctCompleted, 'lbl' => client_t('court.stat_completed')],
    ],
    'actions' => [
        ['url' => 'client-dashboard.php', 'label' => client_t('nav.dashboard'), 'primary' => true, 'icon' => 'layout-dashboard'],
        ['url' => 'client-cases.php', 'label' => client_t('nav.my_cases'), 'icon' => 'briefcase'],
    ],
]);

$courtTableError = '';
if (!empty($_SESSION['error_message'])) {
    $courtTableError = (string) $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

$clientCourtStatusResolver = static function (string $displayKey): string {
    $map = [
        'scheduled' => 'appointments.filter_scheduled',
        'confirmed' => 'appointments.filter_confirmed',
        'rescheduled' => 'appointments.filter_rescheduled',
        'past' => 'appointments.filter_past',
        'completed' => 'appointments.filter_completed',
        'cancelled' => 'appointments.filter_cancelled',
    ];

    return client_t($map[$displayKey] ?? 'appointments.filter_scheduled');
};

$clientCourtDatesTableRows = '';
foreach ($court_dates as $date) {
    $viewBtn = '<button type="button" class="' . legalpro_portal_accent_action_btn_class() . ' mb-0" onclick="viewCourtDate(' . (int) $date['id'] . ')" title="' . htmlspecialchars(client_t('common.view'), ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(client_t('common.view')) . '</button>';
    $clientCourtDatesTableRows .= legalpro_render_portal_court_date_table_row($date, $viewBtn, [
        'label_resolver' => $clientCourtStatusResolver,
    ]);
}

$clientCourtCalendarSection = legalpro_render_portal_schedule_hub_calendar([
    'calendar_id' => 'courtTrackingCalendar',
    'legend' => legalpro_portal_appointment_calendar_legend(static function (string $key, string $label): string {
        $map = [
            'scheduled' => client_t('appointments.filter_scheduled'),
            'confirmed' => client_t('appointments.filter_confirmed'),
            'rescheduled' => client_t('appointments.filter_rescheduled'),
            'past' => client_t('appointments.filter_past'),
            'completed' => client_t('appointments.filter_completed'),
            'cancelled' => client_t('appointments.filter_cancelled'),
        ];

        return $map[$key] ?? $label;
    }),
], 'clientCourtCalendarHub');

$clientCourtListSection = legalpro_render_portal_schedule_hub_list_section(
    'courtDatesTable',
    client_t('court.all_dates_title'),
    client_t('court.all_dates_sub'),
    'clientCourtDatesSearchInput',
    client_t('court.search_placeholder_long'),
    client_t('court.search_label'),
    'clientCourtDatesStatusFilter',
    legalpro_portal_appointment_status_options(),
    'clientCourtDatesTableBody',
    $clientCourtDatesTableRows,
    'clientCourtDatesFilterEmpty',
    [
        'header_labels' => [
            'datetime' => client_t('appointments.col_datetime'),
            'title' => client_t('appointments.col_title'),
            'person' => client_t('appointments.col_client'),
            'case' => client_t('appointments.col_case'),
            'status' => client_t('appointments.col_status'),
            'calendar' => client_t('appointments.col_calendar'),
            'actions' => client_t('common.actions'),
        ],
        'empty_message' => client_t('court.no_match_filters'),
        'pagination_aria' => client_t('court.pagination_aria'),
    ]
);
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(client_portal_html_lang()) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title><?= htmlspecialchars(client_t('court.page_title')) ?> - LegalPro</title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php
    define('LEGALPRO_SKIP_DASHBOARD_ENHANCEMENTS', true);
    include __DIR__ . '/../inc/client-portal-head.php';
    ?>
    <link href="../assets/css/client-portal-pages.css?v=5" rel="stylesheet" />
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body.client-court-tracking-page {
            --cct-primary: var(--legalpro-theme-primary, #023e8a);
            --cct-primary-dark: var(--legalpro-theme-primary-dark, #001845);
            --cct-primary-soft: var(--lp-cases-accent-soft, rgba(2, 62, 138, 0.12));
            --cct-primary-border: var(--lp-cases-accent-border, rgba(2, 62, 138, 0.35));
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
        .cct-table tbody tr:hover { background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.04); }
        .cct-table tbody .cct-court-row.cct-court-row--off-page { display: none; }
        .cct-court-table-wrap { padding: 0 0 0.25rem; }
        .cct-court-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding: 0.9rem 1.5rem 1.1rem;
            border-top: 1px solid #f1f5f9;
        }
        .cct-court-pagination__info {
            margin: 0;
            font-size: 0.72rem;
            font-weight: 600;
            color: #94a3b8;
        }
        .cct-court-pagination__controls {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .cct-court-pagination__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            height: 2rem;
            padding: 0 0.55rem;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.04);
            color: #8392ab;
            font-size: 0.76rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        }
        .cct-court-pagination__btn:hover:not(:disabled) {
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.1);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.35);
            color: var(--cct-primary);
            transform: translateY(-1px);
        }
        .cct-court-pagination__btn:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.22);
        }
        .cct-court-pagination__btn--active {
            background: var(--cct-gradient);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 4px 14px rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.32);
        }
        .cct-court-pagination__btn--active:hover:not(:disabled) {
            color: #fff;
            transform: translateY(-1px);
        }
        .cct-court-pagination__btn--nav { min-width: auto; padding: 0 0.75rem; }
        .cct-court-pagination__btn:disabled { opacity: 0.42; cursor: not-allowed; transform: none; box-shadow: none; }
        .cct-court-pagination__ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.5rem;
            height: 2rem;
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 700;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-court-pagination {
            border-top-color: var(--lp-dark-border, rgba(255, 255, 255, 0.1));
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-court-pagination__btn {
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.12);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.28);
            color: #c5cede;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-court-pagination__btn:hover:not(:disabled) {
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.2);
            color: #f8f9fc;
        }
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
            border: 1px solid var(--cct-primary); color: #fff;
            font-size: 12px; font-weight: 700; background: var(--cct-primary); cursor: pointer;
            transition: background .15s, border-color .15s, transform .15s;
        }
        .btn-cct-view:hover { background: var(--cct-primary-dark, #001845); border-color: var(--cct-primary-dark, #001845); color: #fff; }

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
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.12) 0%, rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.04) 100%);
            border: 1px solid rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.24);
            box-shadow: 0 6px 22px rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.12);
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
            border: 2px solid rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.32);
            border-radius: 12px;
            padding: .7rem 1rem;
            transition: border-color .15s, box-shadow .15s, transform .15s;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.07);
        }
        .client-court-tracking-page .cct-cal-search-field:focus-within {
            border-color: var(--cct-primary);
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.18), 0 4px 16px rgba(15, 23, 42, 0.1);
            transform: translateY(-1px);
        }
        .client-court-tracking-page .cct-cal-search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.12);
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
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.08);
            outline: none;
        }
        .client-court-tracking-page .cct-cal-search-item__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: .45rem;
            flex-shrink: 0;
        }
        .client-court-tracking-page .cct-cal-search-item__dot--scheduled { background: #023e8a; }
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
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.2) 0%, rgba(61, 69, 92, 0.55) 100%);
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-label {
            color: #b8c4ff;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-icon {
            background: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.24);
            color: #d4dcff;
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-field,
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-results {
            background: var(--lp-dark-surface-raised, #3d455c);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.35);
        }
        body.legalpro-dark-mode.client-court-tracking-page .cct-cal-search-field:focus-within {
            border-color: #4a90d9;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.22);
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
            border: 2px solid rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.3) !important;
            background: rgba(255, 255, 255, 0.96) !important;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.1);
            border-radius: 12px !important;
        }
        body.client-court-tracking-page .navbar-main .legalpro-navbar-search .input-group:focus-within {
            border-color: var(--cct-primary) !important;
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.16), 0 4px 16px rgba(15, 23, 42, 0.1) !important;
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
            border-color: rgba(var(--legalpro-theme-primary-rgb, 2, 62, 138), 0.35) !important;
        }
        .court-date-modal .modal-dialog { max-width: 600px; }

        @media (max-width: 640px) {
            .cct-hero-card { padding: 1.5rem; }
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-court-tracking-page lp-schedule-hub-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?php
        echo legalpro_render_client_page_navbar(client_t('court.navbar'), client_t('court.navbar'), '', array_merge(
            legalpro_client_page_search_options('client-court-tracking.php'),
            [
                'client_name' => $clientName,
                'subtitle' => $ctUpcoming . ' ' . strtolower(client_t('court.stat_upcoming')),
            ]
        ));
        ?>

        <div class="container-fluid py-4">
            <div class="cp-page">
            <?php if ($courtTableError !== ''): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($courtTableError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= htmlspecialchars(client_t('common.close')) ?>"></button>
            </div>
            <?php endif; ?>

            <?php echo $heroHtml; ?>

            <?php
            echo $clientCourtCalendarSection;
            echo $clientCourtListSection;
            ?>
            </div>
        </div>
    </main>

    <?php
    $courtDateViewShowClient = false;
    include __DIR__ . '/../inc/court-date-view-modal.php';
    ?>

    <script src="../assets/js/core/jquery.min.js"></script>
    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="../assets/js/court-date-view-modal.js?v=3"></script>
    <script>
        var clientCourtTrackingCalendar = null;
        var courtEvents = <?php echo json_encode($calendar_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

        function focusClientCourtDateRow(id) {
            var row = document.getElementById('court-' + id);
            var wrap = document.querySelector('#courtDatesTable [data-lp-admin-paginate]');
            if (wrap && window.LegalproAdminTablePagination && row) {
                window.LegalproAdminTablePagination.focusRow(wrap, row);
            } else if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (typeof LegalproCalendarStudio !== 'undefined') {
                clientCourtTrackingCalendar = LegalproCalendarStudio.mountScheduleHub({
                    mode: 'court',
                    calendarEl: '#courtTrackingCalendar',
                    events: courtEvents,
                    schedulable: false,
                    displayLabels: {
                        scheduled: <?= json_encode(client_t('appointments.filter_scheduled'), JSON_UNESCAPED_UNICODE) ?>,
                        confirmed: <?= json_encode(client_t('appointments.filter_confirmed'), JSON_UNESCAPED_UNICODE) ?>,
                        rescheduled: <?= json_encode(client_t('appointments.filter_rescheduled'), JSON_UNESCAPED_UNICODE) ?>,
                        past: <?= json_encode(client_t('appointments.filter_past'), JSON_UNESCAPED_UNICODE) ?>,
                        completed: <?= json_encode(client_t('appointments.filter_completed'), JSON_UNESCAPED_UNICODE) ?>,
                        cancelled: <?= json_encode(client_t('appointments.filter_cancelled'), JSON_UNESCAPED_UNICODE) ?>
                    },
                    agendaEmptyText: <?= json_encode(client_t('court.agenda_empty'), JSON_UNESCAPED_UNICODE) ?>,
                    agendaIdAttr: 'data-court-date-id',
                    viewActionLabel: <?= json_encode(client_t('common.view'), JSON_UNESCAPED_UNICODE) ?>,
                    onAgendaItemClick: function(id) {
                        viewCourtDate(id);
                        focusClientCourtDateRow(id);
                    },
                    onEventClick: function(event) {
                        viewCourtDate(event.id);
                        focusClientCourtDateRow(event.id);
                    }
                });
            }
        });

        function viewCourtDate(id) {
            var events = <?php echo json_encode($court_dates); ?>;
            var eventData = events.find(function(e) { return e.id == id; });
            if (eventData && typeof legalproOpenCourtDateViewModal === 'function') {
                legalproOpenCourtDateViewModal(eventData);
            }
        }
    </script>
    <?php echo legalpro_portal_list_filter_script(
        'clientCourtDatesSearchInput',
        'clientCourtDatesTableBody',
        'clientCourtDatesFilterEmpty',
        '.legalpro-admin-list-row',
        'clientCourtDatesStatusFilter'
    ); ?>
</body>
</html>
