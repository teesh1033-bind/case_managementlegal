<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: client-login.php');
    exit;
}

$clientId = $_SESSION['client_id'];
$clientName = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';

require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
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
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body.client-court-tracking-page {
            background: #f0f2f8;
            --cct-primary: var(--legalpro-theme-primary, #5e72e4);
            --cct-primary-dark: var(--legalpro-theme-primary-dark, #825ee4);
            --cct-primary-soft: var(--lp-cases-accent-soft, rgba(94, 114, 228, 0.12));
            --cct-primary-border: var(--lp-cases-accent-border, rgba(94, 114, 228, 0.35));
            --cct-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
            --cct-r: 16px;
            --cct-shadow: 0 2px 12px rgba(0,0,0,0.07);
        }

        .cct-hero-card {
            background: var(--cct-gradient);
            border-radius: 20px;
            padding: 2rem 2.5rem;
            color: #fff;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .cct-hero-card::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: rgba(255,255,255,.08);
        }
        .cct-hero-kicker {
            font-size: 11px; font-weight: 600;
            letter-spacing: .12em; text-transform: uppercase;
            opacity: .75; margin-bottom: .35rem;
        }
        .cct-hero-title { font-size: 22px; font-weight: 800; margin-bottom: .3rem; }
        .cct-hero-sub { font-size: 13px; opacity: .8; margin-bottom: 1.5rem; max-width: 36rem; }
        .cct-hero-stats { display: flex; gap: .85rem; flex-wrap: wrap; position: relative; z-index: 1; }
        .cct-stat-pill {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 12px;
            padding: .6rem 1.1rem;
            backdrop-filter: blur(10px);
            min-width: 5rem;
            text-align: center;
        }
        .cct-stat-pill .num { font-size: 20px; font-weight: 700; line-height: 1; }
        .cct-stat-pill .lbl { font-size: 11px; opacity: .75; margin-top: 2px; }

        .client-court-tracking-page .dashboard-calendar-hub {
            border-radius: var(--cct-r);
            border: 1px solid #e9ecf3;
            box-shadow: var(--cct-shadow);
            overflow: hidden;
            margin-bottom: 1.5rem;
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
            <?php if ($courtTableError !== ''): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($courtTableError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="cct-hero-card">
                <p class="cct-hero-kicker">Docket</p>
                <h4 class="cct-hero-title">Hearings &amp; appearances</h4>
                <p class="cct-hero-sub">Use the calendar for a month view, or scan the list for dates, titles, and status. Click an event or <strong>View</strong> for full information.</p>
                <div class="cct-hero-stats">
                    <div class="cct-stat-pill">
                        <div class="num"><?php echo (int) $ctTotal; ?></div>
                        <div class="lbl">Total</div>
                    </div>
                    <div class="cct-stat-pill">
                        <div class="num"><?php echo (int) $ctUpcoming; ?></div>
                        <div class="lbl">Upcoming</div>
                    </div>
                    <div class="cct-stat-pill">
                        <div class="num"><?php echo (int) $ctScheduled; ?></div>
                        <div class="lbl">Scheduled</div>
                    </div>
                    <div class="cct-stat-pill">
                        <div class="num"><?php echo (int) $ctCompleted; ?></div>
                        <div class="lbl">Completed</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-calendar-hub">
                        <div class="dashboard-calendar-hub__head">
                            <div>
                                <h6 class="text-capitalize mb-0 font-weight-bold" style="color: #344767;">Court Dates Calendar</h6>
                                <p class="text-sm mb-0 text-muted">Click an event or upcoming item for details</p>
                                <div class="dashboard-legend-pills">
                                    <span class="dashboard-legend-pill dashboard-legend-pill--scheduled"><i></i> Scheduled</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--completed"><i></i> Completed</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--postponed"><i></i> Postponed</span>
                                    <span class="dashboard-legend-pill dashboard-legend-pill--cancelled"><i></i> Cancelled</span>
                                </div>
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
                return;
            }

            var calendar = new FullCalendar.Calendar(calendarEl, {
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
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    viewCourtDate(info.event.id);
                }
            });
            calendar.render();
        });
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
