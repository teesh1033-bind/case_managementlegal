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
    $upcomingCourtDatesHtml = '<div class="dashboard-upcoming-empty"><i class="ni ni-calendar-grid-58"></i>No upcoming court dates</div>';
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
        <button type="button" class="dashboard-upcoming-item dashboard-upcoming-item--' . htmlspecialchars($status) . '" data-court-date-id="' . (int) $row['id'] . '">
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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=4" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
<link href="../assets/css/dashboard-enhancements.css?v=4" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" />
    <style>
        .client-court-tracking-page { --cct-radius: 1.15rem; }
        .client-court-tracking-page .cct-hero {
            border-radius: var(--cct-radius);
            background: #fff;
            box-shadow: 0 0.25rem 1rem rgba(52, 71, 103, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-court-tracking-page .cct-hero .cct-hero-kicker {
            letter-spacing: 0.12em;
            color: #5e72e4;
            opacity: 1;
        }
        .client-court-tracking-page .cct-hero .cct-hero-title {
            color: #344767;
        }
        .client-court-tracking-page .cct-hero .cct-hero-text {
            color: #67748e;
        }
        .client-court-tracking-page .cct-hero-pill {
            background: #f8f9fe;
            border-radius: 0.75rem;
            padding: 0.55rem 0.9rem;
            border: 1px solid rgba(94, 114, 228, 0.15);
            min-width: 5rem;
            text-align: center;
        }
        .client-court-tracking-page .cct-hero-pill .cct-hero-pill-label {
            color: #67748e;
        }
        .client-court-tracking-page .cct-hero-pill .cct-hero-pill-value {
            color: #344767;
        }
        .client-court-tracking-page .cct-panel {
            border-radius: var(--cct-radius);
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 0.25rem 1.1rem rgba(52, 71, 103, 0.07);
            overflow: hidden;
        }
        .client-court-tracking-page .cct-panel.cct-panel-calendar {
            overflow: visible;
        }
        .client-court-tracking-page .cct-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.1rem 1.25rem 0.9rem;
        }
        .client-court-tracking-page .cct-panel .card-header h5 {
            font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0;
        }
        .client-court-tracking-page .cct-cal-wrap {
            padding: 0 1rem 1.25rem;
        }
        .client-court-tracking-page .cct-cal-wrap #courtTrackingCalendar {
            min-height: 28rem;
        }
        .client-court-tracking-page .fc-event { cursor: pointer; }
        .client-court-tracking-page .cct-panel .table thead th {
            font-size: 0.65rem;
            letter-spacing: 0.06em;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            background: rgba(248, 249, 250, 0.95);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .client-court-tracking-page .cct-row td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            vertical-align: middle;
        }
        .client-court-tracking-page .cct-row:hover td { background: rgba(94, 114, 228, 0.04); }
        .client-court-tracking-page .cct-row-icon {
            width: 2.35rem;
            height: 2.35rem;
        }
        .client-court-tracking-page .min-width-0 { min-width: 0; }
        .client-court-tracking-page .cct-empty-icon {
            width: 4rem;
            height: 4rem;
        }
        .court-date-modal .modal-dialog { max-width: 600px; }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.35rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .status-scheduled { background-color: #11cdef; color: white; }
        .status-completed { background-color: #2dce89; color: white; }
        .status-cancelled { background-color: #f5365c; color: white; }
        .status-postponed { background-color: #fb6340; color: #fff; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-court-tracking-page">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?php
        require_once __DIR__ . '/../inc/client-portal-navbar.php';
        echo legalpro_render_client_page_navbar('Court tracking', 'Court tracking', 'Search hearings & cases…', ['client_name' => $clientName]);
        ?>

        <div class="container-fluid py-4">
            <?php if ($courtTableError !== ''): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($courtTableError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card cct-hero mb-0">
                        <div class="card-body p-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-4">
                            <div>
                                <p class="cct-hero-kicker text-xs text-uppercase font-weight-bold mb-1">Docket</p>
                                <h4 class="cct-hero-title font-weight-bolder mb-1">Hearings & appearances</h4>
                                <p class="cct-hero-text text-sm mb-0" style="max-width: 36rem;">Use the calendar for a month view, or scan the list for dates, titles, and status. Click an event or <strong>Details</strong> for full information.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-3 justify-content-lg-end">
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Total</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctTotal; ?></p>
                                </div>
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Upcoming</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctUpcoming; ?></p>
                                </div>
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Scheduled</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctScheduled; ?></p>
                                </div>
                                <div class="cct-hero-pill">
                                    <p class="cct-hero-pill-label text-xs mb-0">Completed</p>
                                    <p class="cct-hero-pill-value font-weight-bolder mb-0" style="font-size: 1.35rem;"><?php echo (int) $ctCompleted; ?></p>
                                </div>
                            </div>
                        </div>
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

            <div class="row">
                <div class="col-12">
                    <div class="card cct-panel">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h5 class="text-dark">All court dates</h5>
                                <p class="text-sm text-muted mb-0">Sorted by date, earliest first.</p>
                            </div>
                            <a href="client-cases.php" class="btn btn-sm btn-outline-primary mb-0">My cases</a>
                        </div>
                        <div class="card-body px-0 pt-0 pb-0">
                            <?php if (empty($court_dates)): ?>
                                <div class="text-center py-5 px-4">
                                    <div class="cct-empty-icon icon icon-shape icon-lg bg-gradient-light shadow-sm mx-auto border-radius-lg d-flex align-items-center justify-content-center">
                                        <i class="ni ni-calendar-grid-58 text-primary text-lg opacity-10" aria-hidden="true"></i>
                                    </div>
                                    <h5 class="font-weight-bolder mt-4 mb-2">No court dates yet</h5>
                                    <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 24rem;">When your legal team adds hearings or appearances for your matters, they will appear here and on the calendar above.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-items-center mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Case</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Date &amp; time</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Title</th>
                                                <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 pe-4 text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($court_dates as $date):
                                                $cid = (int) ($date['case_id'] ?? 0);
                                                switch ($date['status'] ?? '') {
                                                    case 'scheduled':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-info">Scheduled</span>';
                                                        break;
                                                    case 'completed':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-success">Completed</span>';
                                                        break;
                                                    case 'cancelled':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-danger">Cancelled</span>';
                                                        break;
                                                    case 'postponed':
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-warning">Postponed</span>';
                                                        break;
                                                    default:
                                                        $rowStatusBadge = '<span class="badge badge-sm bg-gradient-secondary">' . htmlspecialchars((string) ($date['status'] ?? '')) . '</span>';
                                                }
                                                ?>
                                                <tr class="cct-row">
                                                    <td class="ps-4">
                                                        <div class="d-flex align-items-center gap-3 py-1">
                                                            <div class="cct-row-icon icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md flex-shrink-0">
                                                                <i class="ni ni-briefcase-24 text-white text-xs opacity-10" aria-hidden="true"></i>
                                                            </div>
                                                            <div class="min-width-0">
                                                                <?php if ($cid > 0): ?>
                                                                <a href="client-case-view.php?id=<?php echo $cid; ?>" class="text-sm font-weight-bold mb-0 d-inline-block text-truncate" style="max-width: 14rem;"><?php echo htmlspecialchars($date['case_title']); ?></a>
                                                                <?php else: ?>
                                                                <h6 class="mb-0 text-sm font-weight-bold text-truncate" style="max-width: 14rem;"><?php echo htmlspecialchars($date['case_title']); ?></h6>
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
                                                    <td class="align-middle text-center">
                                                        <?php echo $rowStatusBadge; ?>
                                                    </td>
                                                    <td class="align-middle text-end pe-4">
                                                        <button type="button" class="btn btn-sm btn-primary mb-0" onclick="viewCourtDate(<?php echo (int) $date['id']; ?>)" title="View">View</button>
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
                            <strong>Status:</strong> <span id="view_status" class="status-badge"></span>
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
                document.getElementById('view_status').textContent = eventData.status.charAt(0).toUpperCase() + eventData.status.slice(1);
                document.getElementById('view_status').className = 'status-badge status-' + eventData.status;
                document.getElementById('view_title').textContent = eventData.title;
                document.getElementById('view_description').textContent = eventData.description || 'No description';
                document.getElementById('view_location').textContent = eventData.location || 'Not specified';
                document.getElementById('view_created_by').textContent = eventData.created_by_name || 'Unknown';
                document.getElementById('view_creator_role').textContent = eventData.creator_role ? eventData.creator_role.charAt(0).toUpperCase() + eventData.creator_role.slice(1) : 'Unknown';

                bootstrap.Modal.getOrCreateInstance(document.getElementById('viewCourtDateModal')).show();
            }
        }
    </script>
</body>
</html>
