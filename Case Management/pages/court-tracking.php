<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

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
        $court_date = $_POST['court_date'] . ' ' . $_POST['court_time'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $created_by = (int)$_SESSION['admin_id'];

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
        $court_date = $_POST['court_date'] . ' ' . $_POST['court_time'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $status = $_POST['status'];

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

// Prepare calendar events for FullCalendar
$calendar_events = [];
foreach ($court_dates as $date) {
    $status_color = '';
    switch ($date['status']) {
        case 'scheduled': $status_color = '#17a2b8'; break;
        case 'completed': $status_color = '#28a745'; break;
        case 'cancelled': $status_color = '#dc3545'; break;
        case 'postponed': $status_color = '#ffc107'; break;
        default: $status_color = '#6c757d';
    }

    $calendar_events[] = [
        'id' => $date['id'],
        'title' => $date['case_title'] . ' - ' . $date['title'],
        'start' => $date['court_date'],
        'backgroundColor' => $status_color,
        'borderColor' => $status_color,
        'textColor' => '#fff',
        'extendedProps' => [
            'description' => $date['description'],
            'location' => $date['location'],
            'status' => $date['status'],
            'client_name' => $date['client_name'],
            'created_by_name' => $date['created_by_name'],
            'creator_role' => $date['creator_role']
        ]
    ];
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
    <link rel="stylesheet" href="../assets/css/simple-calendar.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.10.1/main.min.css" />
    <style>
        .fc-event {
            cursor: pointer;
        }
        .court-date-modal .modal-dialog {
            max-width: 600px;
        }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        /* Only calendar month navigation buttons */
        .fc .fc-prev-button,
        .fc .fc-next-button {
            background: #ffffff !important;
            border-color: #ffffff !important;
            color: #344767 !important;
        }
        .fc .fc-prev-button:hover,
        .fc .fc-next-button:hover,
        .fc .fc-prev-button:focus,
        .fc .fc-next-button:focus {
            background: #f8f9fa !important;
            border-color: #f8f9fa !important;
            color: #1f2b4d !important;
            box-shadow: none !important;
        }
        .fc .fc-prev-button .fc-icon,
        .fc .fc-next-button .fc-icon {
            color: #344767 !important;
        }
        /* Fallback simple calendar prev/next controls */
        .simple-calendar .calendar-header .btn:first-of-type,
        .simple-calendar .calendar-header .btn:last-of-type {
            background: #ffffff !important;
            border-color: #ffffff !important;
            color: #344767 !important;
        }
        .status-scheduled { background-color: #17a2b8; color: white; }
        .status-completed { background-color: #28a745; color: white; }
        .status-cancelled { background-color: #dc3545; color: white; }
        .status-postponed { background-color: #ffc107; color: black; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
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
                                <span class="d-sm-inline d-none">Logout</span>
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
                    <div class="card">
                        <div class="card-header pb-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">Court Dates Calendar</h6>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCourtDateModal">
                                    <i class="fas fa-plus me-2"></i>Add Court Date
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Court Dates List -->
            <div class="row mt-4">
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
                                                <td><?php echo htmlspecialchars($date['case_title']); ?></td>
                                                <td><?php echo htmlspecialchars($date['client_name']); ?></td>
                                                <td><?php echo date('M d, Y g:i A', strtotime($date['court_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($date['title']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $date['status']; ?>">
                                                        <?php echo ucfirst($date['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-info btn-sm me-1" onclick="viewCourtDate(<?php echo $date['id']; ?>)" title="View Details">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-warning btn-sm me-1" onclick="editCourtDate(<?php echo $date['id']; ?>)" title="Edit Court Date">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteCourtDate(<?php echo $date['id']; ?>)" title="Delete Court Date">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
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
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Court Date *</label>
                                <input type="date" name="court_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Court Time *</label>
                                <input type="time" name="court_time" class="form-control" required>
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
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Court Date *</label>
                                <input type="date" name="court_date" id="edit_court_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Court Time *</label>
                                <input type="time" name="court_time" id="edit_court_time" class="form-control" required>
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
    <script src="../assets/js/fullcalendar/fallback.js"></script>
    <script>
        // Try FullCalendar first, fallback to simple calendar
        let calendarLoaded = false;

        try {
            // Load FullCalendar from CDN
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.10.1/main.min.js';
            script.onload = function() {
                calendarLoaded = true;
                console.log('FullCalendar loaded successfully');
                initFullCalendar();
            };
            script.onerror = function() {
                console.warn('FullCalendar CDN failed, using fallback');
                initSimpleCalendar();
            };
            document.head.appendChild(script);
        } catch (e) {
            console.error('Error loading FullCalendar:', e);
            initSimpleCalendar();
        }

        function initFullCalendar() {
            const calendarEl = document.getElementById('calendar');
            if (!calendarEl) return;

            try {
                const calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: <?php echo json_encode($calendar_events); ?>,
                    eventClick: function(info) {
                        console.log('Event clicked:', info.event.id);
                        viewCourtDate(info.event.id);
                    },
                    height: 'auto',
                    eventDisplay: 'block'
                });
                calendar.render();
                console.log('FullCalendar rendered successfully');
            } catch (error) {
                console.error('Error initializing FullCalendar:', error);
                initSimpleCalendar();
            }
        }

        function initSimpleCalendar() {
            const calendarEl = document.getElementById('calendar');
            if (!calendarEl) return;

            try {
                simpleCalendar = new SimpleCalendar(calendarEl, {
                    events: <?php echo json_encode($calendar_events); ?>
                });
                console.log('Simple calendar rendered successfully');
            } catch (error) {
                console.error('Error initializing simple calendar:', error);
                calendarEl.innerHTML = '<div class="alert alert-danger">Failed to load calendar. Please contact administrator.</div>';
            }
        }

        // Initialize on DOM load if FullCalendar is already loaded
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof FullCalendar !== 'undefined') {
                calendarLoaded = true;
                initFullCalendar();
            } else {
                // Wait a bit for CDN to load
                setTimeout(function() {
                    if (!calendarLoaded) {
                        initSimpleCalendar();
                    }
                }, 2000);
            }
        });
    </script>

    <script>
        // Initialize FullCalendar
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing calendar...');
            var calendarEl = document.getElementById('calendar');

            if (!calendarEl) {
                console.error('Calendar element not found!');
                return;
            }

            if (typeof FullCalendar === 'undefined') {
                console.error('FullCalendar not loaded!');
                calendarEl.innerHTML = '<div class="alert alert-warning">Calendar library failed to load. Please refresh the page.</div>';
                return;
            }

            try {
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: <?php echo json_encode($calendar_events); ?>,
                    eventClick: function(info) {
                        console.log('Event clicked:', info.event.id);
                        viewCourtDate(info.event.id);
                    },
                    height: 'auto',
                    eventDisplay: 'block'
                });
                calendar.render();
                console.log('Calendar rendered successfully');
            } catch (error) {
                console.error('Error initializing calendar:', error);
                calendarEl.innerHTML = '<div class="alert alert-danger">Error loading calendar: ' + error.message + '</div>';
            }
        });

        // View court date details
        function viewCourtDate(id) {
            // Find the event data
            var events = <?php echo json_encode($court_dates); ?>;
            var eventData = events.find(function(e) { return e.id == id; });

            if (eventData) {
                document.getElementById('view_case_title').textContent = eventData.case_title;
                document.getElementById('view_client_name').textContent = eventData.client_name;
                document.getElementById('view_datetime').textContent = new Date(eventData.court_date).toLocaleString();
                document.getElementById('view_status').textContent = eventData.status.charAt(0).toUpperCase() + eventData.status.slice(1);
                document.getElementById('view_status').className = 'status-badge status-' + eventData.status;
                document.getElementById('view_title').textContent = eventData.title;
                document.getElementById('view_description').textContent = eventData.description || 'No description';
                document.getElementById('view_location').textContent = eventData.location || 'Not specified';
                document.getElementById('view_created_by').textContent = eventData.created_by_name || 'Unknown';
                document.getElementById('view_creator_role').textContent = eventData.creator_role ? eventData.creator_role.charAt(0).toUpperCase() + eventData.creator_role.slice(1) : 'Unknown';

                var modal = new bootstrap.Modal(document.getElementById('viewCourtDateModal'));
                modal.show();
            }
        }

        // Edit court date
        function editCourtDate(id) {
            // Find the event data
            var events = <?php echo json_encode($court_dates); ?>;
            var eventData = events.find(function(e) { return e.id == id; });

            if (eventData) {
                document.getElementById('edit_id').value = eventData.id;
                document.getElementById('edit_case_id').value = eventData.case_id;
                var dateTime = new Date(eventData.court_date);
                document.getElementById('edit_court_date').value = dateTime.toISOString().split('T')[0];
                document.getElementById('edit_court_time').value = dateTime.toTimeString().split(' ')[0].substring(0, 5);
                document.getElementById('edit_title').value = eventData.title;
                document.getElementById('edit_description').value = eventData.description || '';
                document.getElementById('edit_location').value = eventData.location || '';
                document.getElementById('edit_status').value = eventData.status;

                var modal = new bootstrap.Modal(document.getElementById('editCourtDateModal'));
                modal.show();
            }
        }

        // Delete court date
        function deleteCourtDate(id) {
            if (confirm('Are you sure you want to delete this court date?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="id" value="' + id + '"><input type="hidden" name="delete_court_date" value="1">';
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
