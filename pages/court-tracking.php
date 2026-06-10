<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/court-time-picker.php';
require_once __DIR__ . '/../lib/court_time_booking.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

// Check if court_dates table exists
$tableExists = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'court_dates'");
    $tableExists = $stmt->fetch() ? true : false;
} catch (PDOException $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $_SESSION['error_message'] = "Court dates table not found. Please run the SQL script in sql/create_court_dates_table.sql or visit fix_court_dates_table.php to create it.";
}

// Table creation is now handled by the SQL script in sql/create_court_dates_table.sql

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check table exists before processing
    $stmt = $pdo->query("SHOW TABLES LIKE 'court_dates'");
    if (!$stmt->fetch()) {
        $_SESSION['error_message'] = "Court dates table not found. Please create the table first.";
        header('Location: court-tracking.php');
        exit;
    }

    if (isset($_POST['add_court_date'])) {
        $case_id = (int)$_POST['case_id'];
        $courtDatePart = trim((string) ($_POST['court_date'] ?? ''));
        $courtTimePart = legalpro_normalize_time_hm($_POST['court_time'] ?? '');
        $court_date = $courtDatePart . ' ' . $courtTimePart;
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $created_by = (int)$_SESSION['admin_id'];

        $bookingCheck = legalpro_validate_court_booking_datetime($pdo, $courtDatePart, $courtTimePart);
        if (!$bookingCheck['ok']) {
            $_SESSION['error_message'] = $bookingCheck['message'];
            header('Location: court-tracking.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO court_dates (case_id, court_date, title, description, location, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$case_id, $court_date, $title, $description, $location, $created_by]);

            // Log the event
            require_once __DIR__ . '/../lib/case_events.php';
            CaseEvents::trackCourtDateCreated($case_id, $title, $court_date);

            $_SESSION['success_message'] = "Court date added successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding court date: " . $e->getMessage();
        }
        header('Location: court-tracking.php');
        exit;
    }

    if (isset($_POST['update_court_date'])) {
        $id = (int)$_POST['id'];
        $case_id = (int)$_POST['case_id'];
        $courtDatePart = trim((string) ($_POST['court_date'] ?? ''));
        $courtTimePart = legalpro_normalize_time_hm($_POST['court_time'] ?? '');
        $court_date = $courtDatePart . ' ' . $courtTimePart;
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $status = $_POST['status'];

        $bookingCheck = legalpro_validate_court_booking_datetime($pdo, $courtDatePart, $courtTimePart, $id, $status);
        if (!$bookingCheck['ok']) {
            $_SESSION['error_message'] = $bookingCheck['message'];
            header('Location: court-tracking.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE court_dates SET
                case_id = ?, court_date = ?, title = ?, description = ?, location = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$case_id, $court_date, $title, $description, $location, $status, $id]);

            $_SESSION['success_message'] = "Court date updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating court date: " . $e->getMessage();
        }
        header('Location: court-tracking.php');
        exit;
    }

    if (isset($_POST['delete_court_date'])) {
        $id = (int)$_POST['id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM court_dates WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION['success_message'] = "Court date deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting court date: " . $e->getMessage();
        }
        header('Location: court-tracking.php');
        exit;
    }
}

// Get all court dates for calendar
try {
    $stmt = $pdo->query("
        SELECT
            cd.*,
            c.title as case_title,
            c.id as case_id,
            CONCAT(cl.first_name, ' ', cl.last_name) as client_name,
            u.username as created_by_name,
            u.role as creator_role
        FROM court_dates cd
        LEFT JOIN cases c ON cd.case_id = c.id
        LEFT JOIN clients cl ON c.client_id = cl.id
        LEFT JOIN users u ON cd.created_by = u.id
        ORDER BY cd.court_date ASC
    ");
    $court_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $court_dates = [];
}

// Get cases for dropdown
try {
    $stmt = $pdo->query("
        SELECT c.id, c.title, CONCAT(cl.first_name, ' ', cl.last_name) as client_name
        FROM cases c
        LEFT JOIN clients cl ON c.client_id = cl.id
        ORDER BY c.title ASC
    ");
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cases = [];
}

$courtBookingEntries = legalpro_court_booking_entries_from_rows($court_dates);

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
            'description' => $date['description'] ?? '',
            'location' => $date['location'] ?? '',
            'client_name' => $date['client_name'] ?? '',
            'case_title' => $date['case_title'] ?? '',
            'court_title' => $date['title'] ?? '',
            'created_by_name' => $date['created_by_name'] ?? '',
            'creator_role' => $date['creator_role'] ?? '',
            'case_id' => $caseId,
        ],
    ];
}

$iconCourtRow = legalpro_icon('landmark');
$iconCourtEmpty = legalpro_icon('calendar');

$upcomingCourtDatesHtml = '';
$upcomingCourtDates = array_values(array_filter($court_dates, function ($row) {
    return strtotime($row['court_date']) >= time()
        && strtolower((string) ($row['status'] ?? '')) === 'scheduled';
}));
if (empty($upcomingCourtDates)) {
    $upcomingCourtDatesHtml = '<div class="dashboard-upcoming-empty"><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">' . $iconCourtEmpty . '</div>No upcoming court dates</div>';
} else {
    usort($upcomingCourtDates, function ($a, $b) {
        return strtotime($a['court_date']) <=> strtotime($b['court_date']);
    });
    foreach (array_slice($upcomingCourtDates, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'scheduled'));
        $caseId = (int) ($row['case_id'] ?? 0);
        $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
        $title = htmlspecialchars($caseNumber . ' · ' . ($row['title'] ?? 'Court date'));
        $client = !empty($row['client_name']) ? htmlspecialchars($row['client_name']) : '—';
        $hourLabel = date('g:i A', strtotime($row['court_date']));
        $dayLabel = date('M j', strtotime($row['court_date']));
        $upcomingCourtDatesHtml .= '
        <button type="button" class="dashboard-upcoming-item dashboard-upcoming-item--' . htmlspecialchars($status) . '" data-court-date-id="' . (int) $row['id'] . '">
            <span class="dashboard-upcoming-item__time">' . htmlspecialchars($hourLabel) . '<br><small style="font-weight:500;opacity:.8">' . htmlspecialchars($dayLabel) . '</small></span>
            <span class="flex-grow-1">
                <p class="dashboard-upcoming-item__title">' . $title . '</p>
                <p class="dashboard-upcoming-item__sub">' . $client . '</p>
            </span>
        </button>';
    }
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
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="../assets/css/dashboard-enhancements.css?v=9" rel="stylesheet" />
    <link href="../assets/css/legalpro-admin-portal.css?v=20" rel="stylesheet" />
    <?php legalpro_icons_asset_links(); ?>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <style>
        .court-date-modal .modal-dialog {
            max-width: 640px;
        }
        .court-actions {
            display: inline-flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 0.35rem;
        }
        .court-actions .btn {
            min-width: 4.25rem;
            padding-left: 0.25rem;
            padding-right: 0.25rem;
            text-align: center;
        }
    </style>
    <?php legalpro_render_time_slot_picker_styles(); ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-court-tracking-page">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Admin</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Court Tracking</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Court Tracking</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <form class="ms-md-auto pe-md-3 d-flex align-items-center legalpro-navbar-search" method="get" action="search.php" role="search">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="search" name="q" class="form-control" placeholder="Search…" value="" autocomplete="off" maxlength="200" aria-label="Search">
                        </div>
                    </form>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="admin-logout.php" class="nav-link text-white font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <!-- <span class="d-sm-inline d-none">Logout</span> -->
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="row">
                <div class="col-12">
                    <div class="dashboard-calendar-hub">
                        <div class="dashboard-calendar-hub__head">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <h6 class="text-capitalize mb-0 font-weight-bold dashboard-calendar-hub__title">Court Dates Calendar</h6>
                                    <p class="text-sm mb-0 text-muted">Click an event or upcoming item for details</p>
                                    <div class="dashboard-legend-pills">
                                        <span class="dashboard-legend-pill dashboard-legend-pill--scheduled"><i></i> Scheduled</span>
                                        <span class="dashboard-legend-pill dashboard-legend-pill--completed"><i></i> Completed</span>
                                        <span class="dashboard-legend-pill dashboard-legend-pill--postponed"><i></i> Postponed</span>
                                        <span class="dashboard-legend-pill dashboard-legend-pill--cancelled"><i></i> Cancelled</span>
                                    </div>
                                </div>
                                <button class="btn btn-sm bg-gradient-primary mb-0" data-bs-toggle="modal" data-bs-target="#addCourtDateModal">
                                    <i class="fas fa-plus me-1"></i>Add Court Date
                                </button>
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

            <!-- Court Dates List -->
            <div class="row mt-4" id="courtDatesTable">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Upcoming Court Dates</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive p-0">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date & Time</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Title</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($court_dates as $date): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center gap-3 py-1">
                                                        <div class="ct-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0"><?php echo $iconCourtRow; ?></div>
                                                        <span class="text-sm font-weight-bold"><?php echo htmlspecialchars($date['case_title']); ?></span>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($date['client_name']); ?></td>
                                                <td><?php echo date('M d, Y g:i A', strtotime($date['court_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($date['title']); ?></td>
                                                <td class="align-middle text-center">
                                                    <?php echo legalpro_court_date_status_badge((string) ($date['status'] ?? '')); ?>
                                                </td>
                                                <td class="align-middle">
                                                    <div class="court-actions">
                                                        <button type="button" class="btn btn-sm btn-primary mb-0" onclick="viewCourtDate(<?php echo (int) $date['id']; ?>)" title="View">View</button>
                                                        <button type="button" class="btn btn-sm btn-dark mb-0" onclick="editCourtDate(<?php echo (int) $date['id']; ?>)" title="Edit">Edit</button>
                                                        <button type="button" class="btn btn-sm btn-danger mb-0" onclick="deleteCourtDate(<?php echo (int) $date['id']; ?>)" title="Delete">Delete</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Court Date Modal -->
    <div class="modal fade" id="addCourtDateModal" tabindex="-1">
        <div class="modal-dialog court-date-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Court Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Case *</label>
                                <select name="case_id" class="form-select" required>
                                    <option value="">Select a case...</option>
                                    <?php foreach ($cases as $case): ?>
                                        <option value="<?php echo $case['id']; ?>">
                                            <?php echo htmlspecialchars($case['title'] . ' - ' . $case['client_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Court Date *</label>
                                <input type="date" name="court_date" id="add_court_date" class="form-control" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Court Time *</label>
                                <?php echo legalpro_render_time_slot_picker('court_time', 'add_court_time_grid', 'add_court_time'); ?>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g., Hearing, Trial, etc." required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Additional details about the court date"></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control" placeholder="Court location/address">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_court_date" class="btn btn-primary">Add Court Date</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Court Date Modal -->
    <div class="modal fade" id="editCourtDateModal" tabindex="-1">
        <div class="modal-dialog court-date-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Court Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Case *</label>
                                <select name="case_id" id="edit_case_id" class="form-select" required>
                                    <option value="">Select a case...</option>
                                    <?php foreach ($cases as $case): ?>
                                        <option value="<?php echo $case['id']; ?>">
                                            <?php echo htmlspecialchars($case['title'] . ' - ' . $case['client_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Court Date *</label>
                                <input type="date" name="court_date" id="edit_court_date" class="form-control" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Court Time *</label>
                                <?php echo legalpro_render_time_slot_picker('court_time', 'edit_court_time_grid', 'edit_court_time'); ?>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Title *</label>
                                <input type="text" name="title" id="edit_title" class="form-control" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" id="edit_location" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_status" class="form-select">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <option value="postponed">Postponed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_court_date" class="btn btn-primary">Update Court Date</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Court Date Modal -->
    <div class="modal fade" id="viewCourtDateModal" tabindex="-1">
        <div class="modal-dialog court-date-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Court Date Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <strong>Case:</strong> <span id="view_case_title"></span>
                        </div>
                        <div class="col-md-12 mb-3">
                            <strong>Client:</strong> <span id="view_client_name"></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Date & Time:</strong> <span id="view_datetime"></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Status:</strong> <span id="view_status" class="lp-pill lp-pill--status-default"></span>
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

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('courtTrackingCalendar');
            var courtEvents = <?php echo json_encode($calendar_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
            var courtRows = <?php echo json_encode($court_dates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

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

            document.getElementById('upcomingCourtDatesList').addEventListener('click', function(e) {
                var btn = e.target.closest('[data-court-date-id]');
                if (!btn) return;
                viewCourtDate(btn.getAttribute('data-court-date-id'));
            });

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
                },
                eventDidMount: function(info) {
                    var tip = info.event.title;
                    var p = info.event.extendedProps || {};
                    if (p.client_name) tip += '\nClient: ' + p.client_name;
                    if (p.location) tip += '\nLocation: ' + p.location;
                    info.el.setAttribute('title', tip);
                }
            });
            calendar.render();
        });

        function viewCourtDate(id) {
            var events = <?php echo json_encode($court_dates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
            var eventData = events.find(function(e) { return String(e.id) === String(id); });

            if (eventData) {
                document.getElementById('view_case_title').textContent = eventData.case_title;
                document.getElementById('view_client_name').textContent = eventData.client_name;
                document.getElementById('view_datetime').textContent = new Date(eventData.court_date).toLocaleString();
                var statusLabels = {
                    scheduled: 'Scheduled',
                    completed: 'Completed',
                    cancelled: 'Cancelled',
                    postponed: 'Postponed'
                };
                var statusPills = {
                    scheduled: 'lp-pill lp-pill--status-progress',
                    completed: 'lp-pill lp-pill--status-closed',
                    cancelled: 'lp-pill lp-pill--status-declined',
                    postponed: 'lp-pill lp-pill--status-pending'
                };
                var statusKey = (eventData.status || '').toLowerCase();
                document.getElementById('view_status').textContent = statusLabels[statusKey] || (statusKey.charAt(0).toUpperCase() + statusKey.slice(1));
                document.getElementById('view_status').className = statusPills[statusKey] || 'lp-pill lp-pill--status-default';
                document.getElementById('view_title').textContent = eventData.title;
                document.getElementById('view_description').textContent = eventData.description || 'No description';
                document.getElementById('view_location').textContent = eventData.location || 'Not specified';
                document.getElementById('view_created_by').textContent = eventData.created_by_name || 'Unknown';
                document.getElementById('view_creator_role').textContent = eventData.creator_role ? eventData.creator_role.charAt(0).toUpperCase() + eventData.creator_role.slice(1) : 'Unknown';

                bootstrap.Modal.getOrCreateInstance(document.getElementById('viewCourtDateModal')).show();
            }
        }

        var courtBookingEntries = <?php echo json_encode($courtBookingEntries, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

        function getCourtBookedTimesForDate(dateValue, excludeId) {
            return courtBookingEntries
                .filter(function(entry) {
                    if (!dateValue || entry.date !== dateValue) {
                        return false;
                    }
                    if (excludeId && String(entry.id) === String(excludeId)) {
                        return false;
                    }
                    return entry.status === 'scheduled';
                })
                .map(function(entry) { return entry.time; });
        }

        function refreshAddCourtTimeAvailability() {
            var dateValue = document.getElementById('add_court_date') ? document.getElementById('add_court_date').value : '';
            if (typeof updateLegalproTimeSlotAvailability === 'function') {
                updateLegalproTimeSlotAvailability('add_court_time_grid', 'add_court_time', getCourtBookedTimesForDate(dateValue, null));
            }
        }

        function refreshEditCourtTimeAvailability() {
            var dateValue = document.getElementById('edit_court_date') ? document.getElementById('edit_court_date').value : '';
            var excludeId = document.getElementById('edit_id') ? document.getElementById('edit_id').value : '';
            if (typeof updateLegalproTimeSlotAvailability === 'function') {
                updateLegalproTimeSlotAvailability('edit_court_time_grid', 'edit_court_time', getCourtBookedTimesForDate(dateValue, excludeId), { preserveSelection: true });
            }
        }

        function editCourtDate(id) {
            var events = <?php echo json_encode($court_dates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
            var eventData = events.find(function(e) { return String(e.id) === String(id); });

            if (eventData) {
                document.getElementById('edit_id').value = eventData.id;
                document.getElementById('edit_case_id').value = eventData.case_id;
                var dateTime = new Date(eventData.court_date);
                var dateValue = dateTime.toISOString().split('T')[0];
                document.getElementById('edit_court_date').value = dateValue;
                var editTime = dateTime.toTimeString().split(' ')[0].substring(0, 5);
                var bookedTimes = getCourtBookedTimesForDate(dateValue, eventData.id);
                if (typeof setLegalproTimeSlotSelection === 'function') {
                    setLegalproTimeSlotSelection('edit_court_time_grid', 'edit_court_time', editTime, bookedTimes);
                } else {
                    document.getElementById('edit_court_time').value = editTime;
                }
                document.getElementById('edit_title').value = eventData.title;
                document.getElementById('edit_description').value = eventData.description || '';
                document.getElementById('edit_location').value = eventData.location || '';
                document.getElementById('edit_status').value = eventData.status;

                bootstrap.Modal.getOrCreateInstance(document.getElementById('editCourtDateModal')).show();
            }
        }

        function deleteCourtDate(id) {
            if (confirm('Are you sure you want to delete this court date?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="id" value="' + id + '"><input type="hidden" name="delete_court_date" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        var addCourtDateModal = document.getElementById('addCourtDateModal');
        if (addCourtDateModal) {
            addCourtDateModal.addEventListener('shown.bs.modal', function () {
                if (typeof setLegalproTimeSlotSelection === 'function') {
                    setLegalproTimeSlotSelection('add_court_time_grid', 'add_court_time', '', []);
                }
                refreshAddCourtTimeAvailability();
            });
        }

        var addCourtDateInput = document.getElementById('add_court_date');
        if (addCourtDateInput) {
            addCourtDateInput.addEventListener('change', refreshAddCourtTimeAvailability);
        }

        var editCourtDateInput = document.getElementById('edit_court_date');
        if (editCourtDateInput) {
            editCourtDateInput.addEventListener('change', refreshEditCourtTimeAvailability);
        }

        var editCourtStatus = document.getElementById('edit_status');
        if (editCourtStatus) {
            editCourtStatus.addEventListener('change', refreshEditCourtTimeAvailability);
        }
    </script>
    <?php legalpro_render_time_slot_picker_script(); ?>
<?php include __DIR__ . '/../inc/footer.php'; ?>
