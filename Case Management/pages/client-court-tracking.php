<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if client is logged in
if (!isset($_SESSION['client_id'])) {
    header('Location: client-login.php');
    exit;
}

$clientId = $_SESSION['client_id'];

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
    <title>Court Tracking - LexMate</title>
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
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
        .status-scheduled { background-color: #17a2b8; color: white; }
        .status-completed { background-color: #28a745; color: white; }
        .status-cancelled { background-color: #dc3545; color: white; }
        .status-postponed { background-color: #ffc107; color: black; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100">
    <div class="min-height-300 bg-dark position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="#">
            <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LexMate logo">
            <span class="ms-1 font-weight-bold">LexMate</span>
        </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="client-dashboard.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-cases.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Cases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-appointments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-calendar-grid-58 text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Appointments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="client-court-tracking.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-collection text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Court Tracking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="client-payments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-credit-card text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Payments</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidenav-footer position-absolute bottom-0 w-100">
            <div class="text-center">
                <p class="text-xs text-muted mb-1">Logged in as</p>
                <p class="text-sm font-weight-bold mb-2"><?php echo htmlspecialchars($_SESSION['client_name']); ?></p>
                <a href="client-logout.php" class="btn btn-sm btn-outline-danger w-100">Logout</a>
            </div>
        </div>
    </aside>

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="javascript:;">Client</a></li>
                        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Court Tracking</li>
                    </ol>
                    <h6 class="font-weight-bolder mb-0">Court Tracking</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <div class="ms-md-auto pe-md-3 d-flex align-items-center">
                        <div class="input-group">
                            <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
                            <input type="text" class="form-control" placeholder="Search...">
                        </div>
                    </div>
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <a href="client-logout.php" class="nav-link text-dark font-weight-bold px-0">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Logout</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">My Court Dates Calendar</h6>
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
                            <h6 class="mb-0">My Court Dates</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <?php if (empty($court_dates)): ?>
                                <div class="text-center py-5">
                                    <i class="ni ni-calendar-grid-58 text-muted" style="font-size: 3rem;"></i>
                                    <h4 class="text-muted mt-3">No Court Dates</h4>
                                    <p class="text-muted">You don't have any court dates scheduled yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive p-0">
                                    <table class="table align-items-center mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Case</th>
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
                                                    <td><?php echo date('M d, Y g:i A', strtotime($date['court_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($date['title']); ?></td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $date['status']; ?>">
                                                            <?php echo ucfirst($date['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-info btn-sm" onclick="viewCourtDate(<?php echo $date['id']; ?>)" title="View Court Date Details">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>

    <script>
        // Initialize FullCalendar
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?php echo json_encode($calendar_events); ?>,
                eventClick: function(info) {
                    viewCourtDate(info.event.id);
                },
                height: 'auto'
            });
            calendar.render();
        });

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

                var modal = new bootstrap.Modal(document.getElementById('viewCourtDateModal'));
                modal.show();
            }
        }
    </script>
</body>
</html>
