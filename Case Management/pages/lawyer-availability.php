<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$message = '';
$messageType = '';

// Handle time slot management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_slot'])) {
        $dayOfWeek = $_POST['day_of_week'];
        $startTime = $_POST['start_time'];
        $endTime = $_POST['end_time'];
        $slotType = $_POST['slot_type'];

        try {
            $stmt = $pdo->prepare("INSERT INTO lawyer_time_slots (lawyer_id, day_of_week, start_time, end_time, slot_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$lawyerId, $dayOfWeek, $startTime, $endTime, $slotType]);
            $message = 'Time slot added successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error adding time slot: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    } elseif (isset($_POST['delete_slot'])) {
        $slotId = (int)$_POST['slot_id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM lawyer_time_slots WHERE id = ? AND lawyer_id = ?");
            $stmt->execute([$slotId, $lawyerId]);
            $message = 'Time slot deleted successfully!';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error deleting time slot: ' . htmlspecialchars($e->getMessage());
            $messageType = 'danger';
        }
    }
}

// Fetch current time slots
$timeSlots = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM lawyer_time_slots WHERE lawyer_id = ? ORDER BY day_of_week, slot_order, start_time");
    $stmt->execute([$lawyerId]);
    $timeSlots = $stmt->fetchAll();
} catch (PDOException $e) {
    $timeSlots = [];
}

$messageHtml = $message ? '<div class="alert alert-' . htmlspecialchars($messageType) . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($message) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';

$daysOfWeek = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

// Group time slots by day
$slotsByDay = [];
foreach ($daysOfWeek as $day) {
    $slotsByDay[$day] = array_filter($timeSlots, function($slot) use ($day) {
        return $slot['day_of_week'] === $day;
    });
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - My Availability</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <style>
        .day-card {
            transition: all 0.3s ease;
        }
        .day-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .time-slot {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 4px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .time-slot-time {
            flex: 1;
        }
        .time-slot-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .time-slot-meta .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            line-height: 1;
        }
        .time-slot-delete-form {
            display: inline-flex;
            align-items: center;
            margin: 0;
        }
        .time-slot-delete-btn {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1 !important;
            padding: 0;
            margin: 0;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 700;
        }
        .time-slot.available {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .time-slot.unavailable {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-availability-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
        <div class="sidenav-header">
            <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
            <a class="navbar-brand m-0" href="#">
                <img src="../assets/img/logo-ct-dark.png" width="26px" height="26px" class="navbar-brand-img h-100" alt="LegalPro logo">
                <span class="ms-1 font-weight-bold">LegalPro</span>
            </a>
        </div>
        <hr class="horizontal dark mt-0">
        <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="lawyer-dashboard.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-tv-2 text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="tasks.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-check-bold text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Tasks</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="lawyer-cases.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-folder-17 text-warning text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Cases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="lawyer-clients.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-circle-08 text-info text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Clients</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="lawyer-appointments.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-calendar-grid-58 text-success text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Appointments</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="lawyer-court-tracking.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-collection text-primary text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">Court Tracking</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="lawyer-availability.php">
                        <div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                            <i class="ni ni-time-alarm text-danger text-sm opacity-10"></i>
                        </div>
                        <span class="nav-link-text ms-1">My Availability</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="sidenav-footer position-absolute bottom-0 w-100">
            <div class="text-center">
                <p class="text-xs text-muted mb-1">Logged in as</p>
                <p class="text-sm font-weight-bold mb-2">{$lawyerName}</p>
                <a href="lawyer-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </aside>
    <main class="main-content position-relative border-radius-lg">
        <!-- Navbar -->
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">My Availability</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white">Manage My Availability</h6>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
                    <ul class="navbar-nav justify-content-end">
                        <!-- <li class="nav-item d-flex align-items-center">
                            <span class="text-sm text-white">
                                <i class="fa fa-user me-sm-1"></i>
                                Lawyer Portal
                            </span> 
                        </li> -->
                    </ul>
                </div>
            </div>
        </nav>
        <!-- End Navbar -->
        <div class="container-fluid py-4">
            {$message}

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Set Your Weekly Availability</h6>
                            <p class="text-sm text-muted mb-0">Configure your available time slots for each day of the week</p>
                        </div>
                        <div class="card-body">
                            <!-- Add Time Slot Form -->
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="card bg-light">
                                        <div class="card-header">
                                            <h6 class="mb-0">Add Time Slot</h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="POST" action="">
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label class="form-control-label">Day of Week</label>
                                                            <select class="form-control" name="day_of_week" required>
                                                                <option value="">Select day</option>
                                                                <option value="monday">Monday</option>
                                                                <option value="tuesday">Tuesday</option>
                                                                <option value="wednesday">Wednesday</option>
                                                                <option value="thursday">Thursday</option>
                                                                <option value="friday">Friday</option>
                                                                <option value="saturday">Saturday</option>
                                                                <option value="sunday">Sunday</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <div class="form-group">
                                                            <label class="form-control-label">Start Time</label>
                                                            <input type="time" class="form-control" name="start_time" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <div class="form-group">
                                                            <label class="form-control-label">End Time</label>
                                                            <input type="time" class="form-control" name="end_time" required>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label class="form-control-label">Type</label>
                                                            <select class="form-control" name="slot_type" required>
                                                                <option value="available">Available</option>
                                                                <option value="unavailable">Unavailable (Break)</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <div class="form-group">
                                                            <label class="form-control-label">&nbsp;</label>
                                                            <button type="submit" name="add_slot" class="btn btn-primary w-100">Add Slot</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Weekly Schedule -->
                            <div class="row">
                                {$weeklySchedule}
                            </div>
                        </div>
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

// Generate weekly schedule HTML
$weeklySchedule = '';
foreach ($daysOfWeek as $day) {
    $dayName = ucfirst($day);
    $slots = $slotsByDay[$day];

    $scheduleHtml = '';
    if (empty($slots)) {
        $scheduleHtml = '<p class="text-muted mb-0">No time slots set</p>';
    } else {
        foreach ($slots as $slot) {
            $slotClass = $slot['slot_type'] === 'available' ? 'available' : 'unavailable';
            $deleteBtn = '<form method="POST" class="time-slot-delete-form">
                            <input type="hidden" name="slot_id" value="' . $slot['id'] . '">
                            <button type="submit" name="delete_slot" class="btn btn-sm btn-danger btn-color-danger time-slot-delete-btn" onclick="return confirm(\'Delete this time slot?\')" aria-label="Delete time slot">&times;</button>
                          </form>';

            $scheduleHtml .= '<div class="time-slot ' . $slotClass . '">
                                <span class="time-slot-time"><strong>' . date('g:i A', strtotime($slot['start_time'])) . ' - ' . date('g:i A', strtotime($slot['end_time'])) . '</strong></span>
                                <span class="time-slot-meta">
                                    <span class="badge ' . ($slot['slot_type'] === 'available' ? 'bg-success' : 'bg-danger') . '">' . ucfirst($slot['slot_type']) . '</span>
                                    ' . $deleteBtn . '
                                </span>
                              </div>';
        }
    }

    $weeklySchedule .= '<div class="col-md-6 col-lg-4 mb-4">
                            <div class="card day-card h-100">
                                <div class="card-header">
                                    <h6 class="mb-0">' . $dayName . '</h6>
                                </div>
                                <div class="card-body">
                                    ' . $scheduleHtml . '
                                </div>
                            </div>
                        </div>';
}

$html = str_replace('{$message}', $messageHtml, $html);
$html = str_replace('{$lawyerName}', htmlspecialchars($_SESSION['lawyer_name']), $html);
$html = str_replace('{$weeklySchedule}', $weeklySchedule, $html);

echo $html;
?>
