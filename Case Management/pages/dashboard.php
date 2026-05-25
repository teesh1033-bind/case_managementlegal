<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

// Ensure cases table has all required columns
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN user_id INT NULL AFTER client_id");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN priority VARCHAR(50) DEFAULT 'Normal' AFTER status");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN category VARCHAR(50) DEFAULT 'Civil' AFTER priority");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN estimated_fees DECIMAL(10,2) DEFAULT 0.00 AFTER category");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN start_date DATE NULL AFTER estimated_fees");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}
try {
    $pdo->query("ALTER TABLE cases ADD COLUMN expected_completion DATE NULL AFTER start_date");
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column name') === false) {
        throw $e;
    }
}

// Fetch dashboard statistics
$totalCases = 0;
$activeCases = 0;
$completedCases = 0;
$pendingTasks = 0;
$newCasesThisWeek = 0;

try {
    // Total cases
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cases");
    $result = $stmt->fetch();
    $totalCases = (int)$result['total'];
    
    // Active cases (not closed)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE status != 'closed'");
    $result = $stmt->fetch();
    $activeCases = (int)$result['total'];
    
    // Completed cases (closed)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE status = 'closed'");
    $result = $stmt->fetch();
    $completedCases = (int)$result['total'];
    
    // New cases this week
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cases WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $result = $stmt->fetch();
    $newCasesThisWeek = (int)$result['total'];
    
    // Pending tasks (using pending appointments as tasks)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM appointments WHERE status = 'pending'");
    $result = $stmt->fetch();
    $pendingTasks = (int)$result['total'];
    
    // Due today (appointments today)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM appointments WHERE DATE(starts_at) = CURDATE() AND status = 'pending'");
    $result = $stmt->fetch();
    $dueToday = (int)$result['total'];
    
} catch (PDOException $e) {
    // Use defaults if error
    $dueToday = 0;
}

// Fetch recent cases for the replacement widget
$recentCases = [];
try {
    $stmt = $pdo->query("
        SELECT 
            c.*,
            cl.first_name AS client_first_name,
            cl.last_name AS client_last_name
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $recentCases = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentCases = [];
}

// ==========================================
// NEW CALENDAR DATA QUERY PIPELINE
// ==========================================
$calendarEvents = [];
try {
    // Queries data out from your existing active appointments list schema fields
    $calStmt = $pdo->query("SELECT id, title, client_name, starts_at, notes FROM appointments");
    $appointmentsList = $calStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($appointmentsList as $row) {
        $calendarEvents[] = [
            'id'    => $row['id'],
            'title' => $row['title'],
            'start' => $row['starts_at'], // Links map data structure straight to key layout grids
            'extendedProps' => [
                'client' => $row['client_name'] ?? 'Unassigned Client',
                'notes'  => $row['notes'] ?? ''
            ]
        ];
    }
} catch (PDOException $e) {
    $calendarEvents = [];
}

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro Case Manager - Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <style>
        #dashboardCalendar {
            font-family: 'Montserrat', sans-serif;
            color: #2c3e50;
        }
        .fc .fc-toolbar-title {
            font-size: 1.2rem !important;
            font-weight: 700;
            color: #344767;
        }
        .fc .fc-button-primary {
            background-image: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%) !important;
            border: none !important;
            text-transform: capitalize;
            font-size: 0.85rem;
            border-radius: 0.5rem !important;
            padding: 0.5rem 1rem;
        }
        .fc .fc-button-primary:hover {
            box-shadow: 0 4px 7px -1px rgba(0, 0, 0, 0.11), 0 2px 4px -1px rgba(0, 0, 0, 0.07);
            opacity: 0.85;
        }
        .fc .fc-button-active {
            background-image: linear-gradient(310deg, #344767 0%, #5e72e4 100%) !important;
        }
        .fc-event {
            cursor: pointer;
            padding: 3px 6px;
            background-color: #e8eaf6 !important;
            color: #5e72e4 !important;
            border-left: 4px solid #5e72e4 !important;
            border-top: none !important;
            border-right: none !important;
            border-bottom: none !important;
            font-weight: 600;
            font-size: 0.8rem;
            border-radius: 4px;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4 " id="sidenav-main">
    </aside>
    <main class="main-content position-relative border-radius-lg ">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="javascript:;">Pages</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Dashboard</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Dashboard</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <a href="tables.php" style="text-decoration: none; color: inherit;">
                        <div class="card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-8">
                                        <div class="numbers">
                                            <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Cases</p>
                                            <h5 class="font-weight-bolder">{TOTAL_CASES}</h5>
                                            <p class="mb-0">
                                                <span class="text-success text-sm font-weight-bolder">+{NEW_CASES_WEEK}</span>
                                                new this week
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                            <i class="ni ni-collection text-lg opacity-10" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <a href="tables.php" style="text-decoration: none; color: inherit;">
                        <div class="card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-8">
                                        <div class="numbers">
                                            <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Cases</p>
                                            <h5 class="font-weight-bolder">{ACTIVE_CASES}</h5>
                                            <p class="mb-0">
                                                <span class="text-info text-sm font-weight-bolder">In Progress</span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                                            <i class="ni ni-world text-lg opacity-10" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                    <a href="tables.php" style="text-decoration: none; color: inherit;">
                        <div class="card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-8">
                                        <div class="numbers">
                                            <p class="text-sm mb-0 text-uppercase font-weight-bold">Completed Cases</p>
                                            <h5 class="font-weight-bolder">{COMPLETED_CASES}</h5>
                                            <p class="mb-0">
                                                <span class="text-success text-sm font-weight-bolder">{COMPLETION_RATE}%</span>
                                                completion rate
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                            <i class="ni ni-paper-diploma text-lg opacity-10" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a href="appointments.php" style="text-decoration: none; color: inherit;">
                        <div class="card" style="cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                            <div class="card-body p-3">
                                <div class="row">
                                    <div class="col-8">
                                        <div class="numbers">
                                            <p class="text-sm mb-0 text-uppercase font-weight-bold">Pending Tasks</p>
                                            <h5 class="font-weight-bolder">{PENDING_TASKS}</h5>
                                            <p class="mb-0">
                                                <span class="text-danger text-sm font-weight-bolder">{DUE_TODAY}</span>
                                                due today
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
                                            <i class="ni ni-time-alarm text-lg opacity-10" aria-hidden="true"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            <div class="row mt-4">
                <div class="col-lg-7 mb-lg-0 mb-4">
                    <div class="card z-index-2 h-100">
                        <div class="card-header pb-0 pt-3 bg-transparent">
                            <h6 class="text-capitalize">Financial Overview</h6>
                            <p class="text-sm mb-0">
                                <i class="fa fa-arrow-up text-success"></i>
                                <span class="font-weight-bold">Net positive</span> trend this quarter
                            </p>
                        </div>
                        <div class="card-body p-3">
                            <div class="chart">
                                <canvas id="chart-line" class="chart-canvas" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header pb-0 pt-3 bg-transparent">
                            <h6 class="text-capitalize">Recent Cases</h6>
                            <p class="text-sm mb-0">Latest case activity and updates</p>
                        </div>
                        <div class="card-body p-3">
                            {RECENT_CASES_LIST}
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="card p-4 border-0 shadow-sm" style="border-radius: 1rem; background: #ffffff;">
                        <div class="mb-3">
                            <h6 class="text-capitalize mb-0 font-weight-bold" style="color: #344767;">Appointments Schedule</h6>
                            <p class="text-sm mb-0 text-muted">Review localized monthly calendars, custom court assignments, and direct target metrics</p>
                        </div>
                        <div id="dashboardCalendar"></div>
                    </div>
                </div>
            </div>

            <footer class="footer pt-3">
                <div class="container-fluid">
                    <div class="row align-items-center justify-content-lg-between">
                        <div class="col-lg-6 mb-lg-0 mb-4">
                            <div class="copyright text-center text-sm text-muted text-lg-start">
                                © <script>document.write(new Date().getFullYear())</script>, LegalPro Case Manager.
                            </div>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </main>

    <div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true" style="z-index: 99999;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 1rem; border: none; box-shadow: 0 20px 27px 0 rgba(0, 0, 0, 0.05);">
                <div class="modal-header" style="background-image: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%); color: white; border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                    <h6 class="modal-title text-white font-weight-bold" id="modalTitle">Appointment View</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="text-xs font-weight-bold text-uppercase opacity-7">Client / Case Reference</label>
                        <p id="modalClient" class="text-sm font-weight-bold text-dark mb-0"></p>
                    </div>
                    <div class="mb-3">
                        <label class="text-xs font-weight-bold text-uppercase opacity-7">Scheduled Time (DD/MM/YYYY)</label>
                        <p id="modalTime" class="text-sm font-weight-bold text-dark mb-0"></p>
                    </div>
                    <div>
                        <label class="text-xs font-weight-bold text-uppercase opacity-7">Details & Notes</label>
                        <div id="modalNotes" class="p-3 bg-gray-100 border-radius-lg text-sm text-secondary" style="white-space: pre-wrap; min-height: 60px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/chartjs.min.js"></script>
    <script>
        var ctx1 = document.getElementById("chart-line").getContext("2d");
        var gradientStroke1 = ctx1.createLinearGradient(0, 230, 0, 50);
        gradientStroke1.addColorStop(1, 'rgba(94, 114, 228, 0.2)');
        gradientStroke1.addColorStop(0.2, 'rgba(94, 114, 228, 0.0)');
        gradientStroke1.addColorStop(0, 'rgba(94, 114, 228, 0)');
        new Chart(ctx1, {
            type: "line",
            data: {
                labels: ["Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
                datasets: [{
                    label: "Mobile apps",
                    tension: 0.4,
                    borderWidth: 0,
                    pointRadius: 0,
                    borderColor: "#5e72e4",
                    backgroundColor: gradientStroke1,
                    borderWidth: 3,
                    fill: true,
                    data: [50, 40, 300, 220, 500, 250, 400, 230, 500],
                    maxBarThickness: 6
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false,
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                scales: {
                    y: {
                        grid: {
                            drawBorder: false,
                            display: true,
                            drawOnChartArea: true,
                            drawTicks: false,
                            borderDash: [5, 5]
                        },
                        ticks: {
                            display: true,
                            padding: 10,
                            color: '#fbfbfb',
                            font: {
                                size: 11,
                                family: "Montserrat",
                                style: 'normal',
                                lineHeight: 2
                            },
                        }
                    },
                    x: {
                        grid: {
                            drawBorder: false,
                            display: false,
                            drawOnChartArea: false,
                            drawTicks: false,
                            borderDash: [5, 5]
                        },
                        ticks: {
                            display: true,
                            color: '#ccc',
                            padding: 20,
                            font: {
                                size: 11,
                                family: "Montserrat",
                                style: 'normal',
                                lineHeight: 2
                            },
                        }
                    },
                },
            },
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('dashboardCalendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek'
                },
                // Direct interpolation of the pre-fetched events array
                events: {CALENDAR_EVENTS_JSON},
                eventClick: function(info) {
                    document.getElementById('modalTitle').innerText = info.event.title;
                    document.getElementById('modalClient').innerText = info.event.extendedProps.client;
                    
                    // Format timestamp properties directly to target DD/MM/YYYY layouts
                    const date = info.event.start;
                    const day = String(date.getDate()).padStart(2, '0');
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const year = date.getFullYear();
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');

                    document.getElementById('modalTime').innerText = `${day}/${month}/${year} at ${hours}:${minutes}`;
                    document.getElementById('modalNotes').innerText = info.event.extendedProps.notes || 'No extra descriptive details added.';
                    
                    var appointmentModal = new bootstrap.Modal(document.getElementById('appointmentModal'));
                    appointmentModal.show();
                }
            });
            calendar.render();
        });
    </script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script src="../assets/js/spa-nav.js"></script>
</body>
</html>
HTML;

// Calculate completion rate
$completionRate = $totalCases > 0 ? round(($completedCases / $totalCases) * 100) : 0;

// Build recent cases list
$recentCasesList = '';
if (empty($recentCases)) {
    $recentCasesList = '<p class="text-sm text-muted text-center py-3">No cases found. <a href="case-new.php">Create your first case</a></p>';
} else {
    foreach ($recentCases as $case) {
        $caseId = (int)$case['id'];
        $caseNumber = 'C-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
        $title = htmlspecialchars($case['title']);
        $clientFirstName = isset($case['client_first_name']) ? $case['client_first_name'] : '';
        $clientLastName = isset($case['client_last_name']) ? $case['client_last_name'] : '';
        $clientName = trim($clientFirstName . ' ' . $clientLastName) ?: 'Unassigned';
        
        $status = isset($case['status']) ? strtolower($case['status']) : 'open';
        $statusLabel = ucfirst(str_replace('_', ' ', $status));
        $badgeClass = 'bg-gradient-info';
        if ($status === 'in_progress') {
            $badgeClass = 'bg-gradient-warning';
        } elseif ($status === 'closed') {
            $badgeClass = 'bg-gradient-success';
        }
        
        $createdDate = isset($case['created_at']) && $case['created_at'] ? date('M d, Y', strtotime($case['created_at'])) : 'N/A';
        
        $recentCasesList .= '
        <a href="tables.php?case_id=' . $caseId . '" style="text-decoration: none; color: inherit;">
            <div class="list-group-item border-0 d-flex justify-content-between align-items-center ps-0 mb-2 border-radius-lg" style="cursor: pointer;">
                <div class="d-flex flex-column">
                    <h6 class="mb-1 text-dark text-sm">' . $caseNumber . ' · ' . $title . '</h6>
                    <span class="text-xs">' . htmlspecialchars($clientName) . ' · ' . $createdDate . '</span>
                </div>
                <span class="badge ' . $badgeClass . '">' . $statusLabel . '</span>
            </div>
        </a>';
    }
}

// Replace placeholders
$html = str_replace('{TOTAL_CASES}', $totalCases, $html);
$html = str_replace('{ACTIVE_CASES}', $activeCases, $html);
$html = str_replace('{COMPLETED_CASES}', $completedCases, $html);
$html = str_replace('{PENDING_TASKS}', $pendingTasks, $html);
$html = str_replace('{NEW_CASES_WEEK}', $newCasesThisWeek, $html);
$html = str_replace('{COMPLETION_RATE}', $completionRate, $html);
$html = str_replace('{DUE_TODAY}', $dueToday, $html);
$html = str_replace('{RECENT_CASES_LIST}', $recentCasesList, $html);

// Inject dynamic calendar data directly into the template
$html = str_replace('{CALENDAR_EVENTS_JSON}', json_encode($calendarEvents), $html);

// rewrite internal links from .html to .php
$html = preg_replace('/href="([^"\']+)\.html"/i', 'href="$1.php"', $html);

// capture shared sidebar HTML
ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();

// replace the first <aside>...</aside> with the sidebar include output
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

// capture footer/scripts
ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();

// insert footer before closing </body>
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo $html;
?>