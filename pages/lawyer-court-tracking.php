<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/portal_list_ui.php';
require_once __DIR__ . '/../lib/appointment_list_ui.php';
require_once __DIR__ . '/../lib/portal_calendar_events.php';
require_once __DIR__ . '/../inc/portal-calendar-studio.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];

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
        header('Location: lawyer-court-tracking.php');
        exit;
    }

    if (isset($_POST['add_court_date'])) {
        $case_id = (int)$_POST['case_id'];
        $court_date = $_POST['court_date'] . ' ' . $_POST['court_time'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $created_by = (int)$_SESSION['lawyer_id'];

        // Verify the case is assigned to this lawyer
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM case_lawyers WHERE case_id = ? AND lawyer_id = ?");
        $stmt->execute([$case_id, $lawyerId]);
        if ($stmt->fetchColumn() == 0) {
            $_SESSION['error_message'] = "You can only add court dates for cases assigned to you.";
            header('Location: lawyer-court-tracking.php');
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
        header('Location: lawyer-court-tracking.php');
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

        // Verify the case is assigned to this lawyer
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM case_lawyers WHERE case_id = ? AND lawyer_id = ?");
        $stmt->execute([$case_id, $lawyerId]);
        if ($stmt->fetchColumn() == 0) {
            $_SESSION['error_message'] = "You can only edit court dates for cases assigned to you.";
            header('Location: lawyer-court-tracking.php');
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
        header('Location: lawyer-court-tracking.php');
        exit;
    }

    if (isset($_POST['delete_court_date'])) {
        $id = (int)$_POST['id'];

        // Verify the court date belongs to a case assigned to this lawyer
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM court_dates cd
            INNER JOIN case_lawyers cl ON cl.case_id = cd.case_id
            WHERE cd.id = ? AND cl.lawyer_id = ?
        ");
        $stmt->execute([$id, $lawyerId]);
        if ($stmt->fetchColumn() == 0) {
            $_SESSION['error_message'] = "You can only delete court dates for cases assigned to you.";
            header('Location: lawyer-court-tracking.php');
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM court_dates WHERE id = ?");
            $stmt->execute([$id]);

            $_SESSION['success_message'] = "Court date deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting court date: " . $e->getMessage();
        }
        header('Location: lawyer-court-tracking.php');
        exit;
    }
}

// Get court dates for cases assigned to this lawyer
try {
    $sql = "
        SELECT
            cd.*,
            c.title as case_title,
            c.id as case_id,
            CONCAT(cl.first_name, ' ', cl.last_name) as client_name,
            u.username as created_by_name,
            u.role as creator_role
        FROM court_dates cd
        INNER JOIN case_lawyers clw ON clw.case_id = cd.case_id
        LEFT JOIN cases c ON cd.case_id = c.id
        LEFT JOIN clients cl ON c.client_id = cl.id
        LEFT JOIN users u ON cd.created_by = u.id
        WHERE clw.lawyer_id = ?
    ";
    $params = [$lawyerId];

    $sql .= " ORDER BY cd.court_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $court_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $court_dates = [];
}

// Get cases assigned to this lawyer for dropdown
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, CONCAT(cl.first_name, ' ', cl.last_name) as client_name
        FROM cases c
        INNER JOIN case_lawyers clw ON clw.case_id = c.id
        LEFT JOIN clients cl ON c.client_id = cl.id
        WHERE clw.lawyer_id = ?
        ORDER BY c.title ASC
    ");
    $stmt->execute([$lawyerId]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cases = [];
}

$courtDatesCount = count($court_dates);
$courtDatesCountLabel = $courtDatesCount === 1 ? '1 court date' : $courtDatesCount . ' court dates';

$calendar_events = legalpro_portal_build_court_calendar_events($court_dates);

$iconCourtRow = legalpro_icon('landmark');
$iconCourtEmpty = legalpro_icon('calendar');

$upcomingCourtDatesHtml = '';
$upcomingCourtDates = array_values(array_filter($court_dates, function ($row) {
    return strtotime($row['court_date']) >= time()
        && strtolower((string) ($row['status'] ?? '')) === 'scheduled';
}));
if (empty($upcomingCourtDates)) {
    $upcomingCourtDatesHtml = '<div class="dashboard-upcoming-empty"><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">' . $iconCourtEmpty . '</div>' . htmlspecialchars(lawyer_tf('court.empty_upcoming', 'No upcoming court dates')) . '</div>';
} else {
    usort($upcomingCourtDates, function ($a, $b) {
        return strtotime($a['court_date']) <=> strtotime($b['court_date']);
    });
    foreach (array_slice($upcomingCourtDates, 0, 8) as $row) {
        $status = strtolower((string) ($row['status'] ?? 'scheduled'));
        $caseId = (int) ($row['case_id'] ?? 0);
        $caseNumber = $caseId > 0 ? 'C-' . str_pad((string) $caseId, 4, '0', STR_PAD_LEFT) : 'Case';
        $title = htmlspecialchars($caseNumber . ' Â· ' . ($row['title'] ?? 'Court date'));
        $client = !empty($row['client_name']) ? htmlspecialchars($row['client_name']) : 'â€”';
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

$pageTitle = lawyer_tf('court.page_title', 'Court Tracking');
$upcomingCourtCount = count($upcomingCourtDates);
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle, [], [
    'subtitle' => $upcomingCourtCount . ' ' . strtolower(lawyer_tf('court.upcoming', 'upcoming')),
]);
$htmlLang = lawyer_portal_html_lang();
$courtSearchLabel = lawyer_tf('court.search_label', 'Search Court Dates');
$courtSearchPlaceholder = lawyer_tf('court.search_placeholder', 'Case, client, title or location');
$courtCalSearchLabel = lawyer_tf('court.cal_search_label', 'Search court dates');
$courtCalSearchPlaceholder = lawyer_tf('court.cal_search_placeholder', 'Search by case, client, hearing, location, statusâ€¦');
$courtAllStatus = lawyer_tf('court.all_status', 'All Status');
$courtEmptyTable = lawyer_tf('court.empty_table', 'No court dates found. Try adjusting your search or status filter.');
$courtEmptySearch = lawyer_tf('court.empty_search', 'No court dates match your search.');
$courtLblStatus = lawyer_tf('common.status', 'Status');
$courtLblFilter = lawyer_tf('common.filter', 'Filter');
$courtLblReset = lawyer_tf('common.reset', 'Reset');
$courtLblActions = lawyer_tf('common.actions', 'Actions');
$courtSearchResetAria = lawyer_tf('header.search_reset', 'Reset');

$lawyerCourtDatesTableRows = '';
foreach ($court_dates as $date) {
    $courtActionsHtml = '<button type="button" class="' . legalpro_portal_accent_action_btn_class() . ' mb-0" onclick="viewCourtDate(' . (int) $date['id'] . ')" title="View">View</button>'
        . '<button type="button" class="' . legalpro_portal_accent_action_btn_class() . ' mb-0" onclick="editCourtDate(' . (int) $date['id'] . ')" title="Edit">Edit</button>'
        . '<button type="button" class="' . legalpro_portal_danger_action_btn_class() . ' mb-0" onclick="deleteCourtDate(' . (int) $date['id'] . ')" title="Delete">Delete</button>';
    $lawyerCourtDatesTableRows .= legalpro_render_portal_court_date_table_row($date, $courtActionsHtml);
}

$lawyerCourtCalendarSection = legalpro_render_portal_schedule_hub_calendar([
    'calendar_id' => 'courtTrackingCalendar',
    'add_modal' => '#addCourtDateModal',
    'add_title' => 'Add court date',
    'add_aria' => 'Add court date',
], 'lawyerCourtCalendarHub');

$lawyerCourtListSection = legalpro_render_portal_schedule_hub_list_section(
    'courtDatesTable',
    'Court Date List',
    $courtDatesCountLabel,
    'lawyerCourtDatesSearchInput',
    $courtSearchPlaceholder,
    $courtSearchLabel,
    'lawyerCourtDatesStatusFilter',
    legalpro_portal_appointment_status_options(),
    'lawyerCourtDatesTableBody',
    $lawyerCourtDatesTableRows,
    'lawyerCourtDatesFilterEmpty',
    [
        'all_statuses_label' => $courtAllStatus,
        'empty_message' => $courtEmptySearch,
        'pagination_aria' => 'Court dates pagination',
    ]
);
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - LegalPro</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
<link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <style>
        .court-date-modal .modal-dialog {
            max-width: 600px;
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
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-court-tracking-page lp-schedule-hub-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/lawyer-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?php echo $breadcrumbNavbar; ?>

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

            <?php
            echo $lawyerCourtCalendarSection;
            echo $lawyerCourtListSection;
            ?>
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
    <?php
    $courtDateViewShowClient = true;
    include __DIR__ . '/../inc/court-date-view-modal.php';
    ?>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="../assets/js/court-date-view-modal.js?v=2"></script>
    <script>window.LCT_I18N=<?php echo json_encode(['emptySearch' => $courtEmptySearch], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;</script>
    <script>
        var lawyerCourtTrackingCalendar = null;

        function escapeHtmlLct(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function focusLawyerCourtDateRow(id) {
            var row = document.getElementById('court-' + id);
            var wrap = document.querySelector('#courtDatesTable [data-lp-admin-paginate]');
            if (wrap && window.LegalproAdminTablePagination && row) {
                window.LegalproAdminTablePagination.focusRow(wrap, row);
            } else if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var courtEvents = <?php echo json_encode($calendar_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

            if (typeof LegalproCalendarStudio !== 'undefined') {
                lawyerCourtTrackingCalendar = LegalproCalendarStudio.mountScheduleHub({
                    mode: 'court',
                    calendarEl: '#courtTrackingCalendar',
                    events: courtEvents,
                    agendaEmptyText: 'No court dates this month',
                    agendaIdAttr: 'data-court-date-id',
                    scheduleActionLabel: 'Add court date',
                    viewActionLabel: 'View court date',
                    onAgendaItemClick: function(id) {
                        viewCourtDate(id);
                        focusLawyerCourtDateRow(id);
                    },
                    onDateClick: function(ymd) {
                        var modalEl = document.getElementById('addCourtDateModal');
                        if (modalEl && window.bootstrap && bootstrap.Modal) {
                            var dateInput = modalEl.querySelector('input[name="court_date"]');
                            if (dateInput && ymd) {
                                dateInput.value = ymd;
                            }
                            bootstrap.Modal.getOrCreateInstance(modalEl).show();
                        }
                    },
                    onEventClick: function(event) {
                        viewCourtDate(event.id);
                        focusLawyerCourtDateRow(event.id);
                    }
                });
            }
        });

        // View court date details
        function viewCourtDate(id) {
            var events = <?php echo json_encode($court_dates); ?>;
            var eventData = events.find(function(e) { return e.id == id; });
            if (eventData && typeof legalproOpenCourtDateViewModal === 'function') {
                legalproOpenCourtDateViewModal(eventData);
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

                bootstrap.Modal.getOrCreateInstance(document.getElementById('editCourtDateModal')).show();
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
    <?php echo legalpro_portal_list_filter_script(
        'lawyerCourtDatesSearchInput',
        'lawyerCourtDatesTableBody',
        'lawyerCourtDatesFilterEmpty',
        '.legalpro-admin-list-row',
        'lawyerCourtDatesStatusFilter'
    ); ?>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
