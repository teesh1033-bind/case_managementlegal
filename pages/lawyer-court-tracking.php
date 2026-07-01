<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';

// Check if lawyer is logged in
if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = $_SESSION['lawyer_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';
$allowedStatusFilters = ['scheduled', 'completed', 'cancelled', 'postponed'];

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

    if ($statusFilter !== 'all' && in_array($statusFilter, $allowedStatusFilters, true)) {
        $sql .= " AND LOWER(COALESCE(cd.status, 'scheduled')) = ?";
        $params[] = $statusFilter;
    }

    if ($search !== '') {
        $sql .= " AND (
            c.title LIKE ?
            OR cl.first_name LIKE ?
            OR cl.last_name LIKE ?
            OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE ?
            OR cd.title LIKE ?
            OR cd.location LIKE ?
        )";
        $searchParam = '%' . $search . '%';
        $params = array_merge($params, array_fill(0, 6, $searchParam));
    }

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
            'description' => $date['description'] ?? '',
            'location' => $date['location'] ?? '',
            'client_name' => $date['client_name'] ?? '',
            'case_title' => $date['case_title'] ?? '',
            'court_title' => $date['title'] ?? '',
            'hearingTitle' => $hearingTitle,
            'created_by_name' => $date['created_by_name'] ?? '',
            'creator_role' => $date['creator_role'] ?? '',
            'case_id' => $caseId,
            'statusLabel' => ucfirst($status),
            'courtDateId' => (int) $date['id'],
            'courtDateLabel' => $courtDateLabel,
            'searchHay' => strtolower(implode(' ', array_filter([
                $displayTitle,
                $date['case_title'] ?? '',
                $hearingTitle,
                $date['client_name'] ?? '',
                $date['location'] ?? '',
                $date['description'] ?? '',
                $status,
                $courtDateLabel,
                date('Y-m-d', strtotime((string) $date['court_date'])),
                date('m/d/Y', strtotime((string) $date['court_date'])),
                $caseNumber,
                (string) $date['id'],
            ]))),
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
    $upcomingCourtDatesHtml = '<div class="dashboard-upcoming-empty"><div class="dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary">' . $iconCourtEmpty . '</div>' . htmlspecialchars(lawyer_tf('court.empty_upcoming', 'No upcoming court dates')) . '</div>';
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

$pageTitle = lawyer_tf('court.page_title', 'Court Tracking');
$breadcrumbNavbar = legalpro_render_lawyer_breadcrumb_navbar($pageTitle);
$htmlLang = lawyer_portal_html_lang();
$courtSearchLabel = lawyer_tf('court.search_label', 'Search Court Dates');
$courtSearchPlaceholder = lawyer_tf('court.search_placeholder', 'Case, client, title or location');
$courtCalSearchLabel = lawyer_tf('court.cal_search_label', 'Search court dates');
$courtCalSearchPlaceholder = lawyer_tf('court.cal_search_placeholder', 'Search by case, client, hearing, location, status…');
$courtAllStatus = lawyer_tf('court.all_status', 'All Status');
$courtEmptyTable = lawyer_tf('court.empty_table', 'No court dates found. Try adjusting your search or status filter.');
$courtEmptySearch = lawyer_tf('court.empty_search', 'No court dates match your search.');
$courtLblStatus = lawyer_tf('common.status', 'Status');
$courtLblFilter = lawyer_tf('common.filter', 'Filter');
$courtLblReset = lawyer_tf('common.reset', 'Reset');
$courtLblActions = lawyer_tf('common.actions', 'Actions');
$courtSearchResetAria = lawyer_tf('header.search_reset', 'Reset');
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
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet" />
    <style>
        .lawyer-court-tracking-page {
            --lct-primary: var(--legalpro-theme-primary, #5e72e4);
        }
        .fc .fc-toolbar.fc-header-toolbar {
            background-image: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
            border-radius: 0.5rem;
            padding: 0.65rem 1rem;
            margin-bottom: 1rem;
        }
        .fc .fc-toolbar-title {
            color: #fff !important;
            font-weight: 700;
        }
        .fc-event {
            cursor: pointer;
        }
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
        .lawyer-court-tracking-page .dashboard-calendar-hub__head {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .lawyer-court-tracking-page .lct-cal-search-wrap {
            position: relative;
            width: 100%;
        }
        .lawyer-court-tracking-page .lct-cal-search-wrap--featured {
            padding: .9rem 1rem 1rem;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12) 0%, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04) 100%);
            border: 1px solid rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.24);
            box-shadow: 0 6px 22px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
        }
        .lawyer-court-tracking-page .lct-cal-search-label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--lct-primary);
            margin-bottom: .55rem;
        }
        .lawyer-court-tracking-page .lct-cal-search-field {
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
        .lawyer-court-tracking-page .lct-cal-search-field:focus-within {
            border-color: var(--lct-primary);
            box-shadow: 0 0 0 4px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.18), 0 4px 16px rgba(15, 23, 42, 0.1);
            transform: translateY(-1px);
        }
        .lawyer-court-tracking-page .lct-cal-search-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.12);
            color: var(--lct-primary);
            flex-shrink: 0;
        }
        .lawyer-court-tracking-page .lct-cal-search-field svg {
            width: 18px;
            height: 18px;
            color: currentColor;
        }
        .lawyer-court-tracking-page .lct-cal-search-input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            font-family: inherit;
        }
        .lawyer-court-tracking-page .lct-cal-search-input::placeholder {
            color: #64748b;
            font-weight: 500;
        }
        .lawyer-court-tracking-page .lct-cal-search-results {
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
        .lawyer-court-tracking-page .lct-cal-search-item {
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
        }
        .lawyer-court-tracking-page .lct-cal-search-item:hover,
        .lawyer-court-tracking-page .lct-cal-search-item:focus-visible {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
            outline: none;
        }
        .lawyer-court-tracking-page .lct-cal-search-item__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: .45rem;
            flex-shrink: 0;
        }
        .lawyer-court-tracking-page .lct-cal-search-item__dot--scheduled { background: #5e72e4; }
        .lawyer-court-tracking-page .lct-cal-search-item__dot--completed { background: #2dce89; }
        .lawyer-court-tracking-page .lct-cal-search-item__dot--postponed { background: #fb6340; }
        .lawyer-court-tracking-page .lct-cal-search-item__dot--cancelled { background: #f5365c; }
        .lawyer-court-tracking-page .lct-cal-search-item__body { min-width: 0; flex: 1; }
        .lawyer-court-tracking-page .lct-cal-search-item__title {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lawyer-court-tracking-page .lct-cal-search-item__sub {
            font-size: 11.5px;
            color: #64748b;
            margin: 0;
        }
        .lawyer-court-tracking-page .lct-cal-search-empty {
            padding: 1rem .75rem;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-cal-search-wrap--featured {
            background: linear-gradient(135deg, rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.2) 0%, rgba(61, 69, 92, 0.55) 100%);
            border-color: rgba(255, 255, 255, 0.12);
        }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-cal-search-label { color: #b8c4ff; }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-cal-search-field,
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-cal-search-results {
            background: var(--lp-dark-surface-raised, #3d455c);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35);
        }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-cal-search-input { color: var(--lp-dark-text, #f8f9fc); }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-cal-search-item__title { color: var(--lp-dark-text, #f8f9fc); }
        .lct-court-table-wrap { padding: 0 1rem 1rem; }
        .lct-court-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding: 0.9rem 0.15rem 0.25rem;
            margin-top: 0.35rem;
            border-top: 1px solid #e9ecef;
        }
        .lct-court-pagination__info {
            margin: 0;
            font-size: 0.72rem;
            font-weight: 600;
            color: #8392ab;
        }
        .lct-court-pagination__controls {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        .lct-court-pagination__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            height: 2rem;
            padding: 0 0.55rem;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.04);
            color: #8392ab;
            font-size: 0.76rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        }
        .lct-court-pagination__btn:hover:not(:disabled) {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.1);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.35);
            color: var(--lct-primary);
            transform: translateY(-1px);
        }
        .lct-court-pagination__btn:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.22);
        }
        .lct-court-pagination__btn--active {
            background: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
            border-color: transparent;
            color: #fff;
            box-shadow: 0 4px 14px rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.32);
        }
        .lct-court-pagination__btn--active:hover:not(:disabled) {
            color: #fff;
            transform: translateY(-1px);
        }
        .lct-court-pagination__btn--nav { min-width: auto; padding: 0 0.75rem; }
        .lct-court-pagination__btn:disabled { opacity: 0.42; cursor: not-allowed; transform: none; box-shadow: none; }
        .lct-court-pagination__ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.5rem;
            height: 2rem;
            color: #8392ab;
            font-size: 0.85rem;
            font-weight: 700;
        }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-court-pagination {
            border-top-color: rgba(255, 255, 255, 0.1);
        }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-court-pagination__btn {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
        }
        body.legalpro-dark-mode.lawyer-court-tracking-page .lct-court-pagination__btn:hover:not(:disabled) {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.2);
            color: #f8f9fc;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-court-tracking-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/lawyer-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <?php echo $breadcrumbNavbar; ?>

        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body p-3">
                            <form method="GET" class="row align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label"><?php echo htmlspecialchars($courtSearchLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo htmlspecialchars($courtSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><?php echo htmlspecialchars($courtLblStatus, ENT_QUOTES, 'UTF-8'); ?></label>
                                    <select class="form-select" name="status">
                                        <option value="all"<?php echo $statusFilter === 'all' ? ' selected' : ''; ?>><?php echo htmlspecialchars($courtAllStatus, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <option value="scheduled"<?php echo $statusFilter === 'scheduled' ? ' selected' : ''; ?>>Scheduled</option>
                                        <option value="completed"<?php echo $statusFilter === 'completed' ? ' selected' : ''; ?>>Completed</option>
                                        <option value="postponed"<?php echo $statusFilter === 'postponed' ? ' selected' : ''; ?>>Postponed</option>
                                        <option value="cancelled"<?php echo $statusFilter === 'cancelled' ? ' selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label d-block invisible"><?php echo htmlspecialchars($courtLblActions, ENT_QUOTES, 'UTF-8'); ?></label>
                                    <div class="lp-lawyer-filter-actions">
                                        <button type="submit" class="btn btn-primary mb-0"><?php echo htmlspecialchars($courtLblFilter, ENT_QUOTES, 'UTF-8'); ?></button>
                                        <a href="lawyer-court-tracking.php" class="btn btn-outline-secondary mb-0"><?php echo htmlspecialchars($courtLblReset, ENT_QUOTES, 'UTF-8'); ?></a>
                                    </div>
                                </div>
                                <div class="col-md-2 text-end">
                                    <p class="text-sm text-muted mb-0">Total: <?php echo count($court_dates); ?> court dates</p>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

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
                            <div class="lct-calendar-hub__intro">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 w-100">
                                    <div>
                                        <h6 class="text-capitalize mb-0 font-weight-bold dashboard-calendar-hub__title">Court Dates Calendar</h6>
                                        <p class="text-sm mb-0 text-muted">Use the search bar below to find hearings quickly, or click a calendar event</p>
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
                            <div class="lct-cal-search-wrap lct-cal-search-wrap--featured">
                                <label class="lct-cal-search-label" for="lctCalSearchInput"><?php echo htmlspecialchars($courtCalSearchLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                                <div class="lct-cal-search-field">
                                    <span class="lct-cal-search-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25">
                                            <circle cx="11" cy="11" r="7"></circle>
                                            <path d="M20 20l-3-3"></path>
                                        </svg>
                                    </span>
                                    <input type="search" id="lctCalSearchInput" class="lct-cal-search-input"
                                           placeholder="<?php echo htmlspecialchars($courtCalSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                                    <button type="button" class="lp-lawyer-search-reset-btn" data-lawyer-search-reset="lctCalSearchInput" aria-label="<?php echo htmlspecialchars($courtSearchResetAria, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($courtLblReset, ENT_QUOTES, 'UTF-8'); ?></button>
                                </div>
                                <div class="lct-cal-search-results" id="lctCalSearchResults" hidden></div>
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
                            <h6 class="mb-0">My Court Dates</h6>
                            <p class="text-xs text-muted mb-0"><span id="lawyerCourtTableCount"><?php echo htmlspecialchars($courtDatesCountLabel); ?></span></p>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="lct-court-table-wrap" id="lawyerCourtDatesTableWrap" data-court-per-page="10">
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
                                        <?php if (empty($court_dates)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4"><?php echo htmlspecialchars($courtEmptyTable, ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php else: ?>
                                        <?php foreach ($court_dates as $date): ?>
                                            <tr id="court-<?php echo (int) $date['id']; ?>" class="lct-court-row">
                                                <td class="align-middle">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <div class="lawyer-ct-row-icon dashboard-stat-icon-wrap dashboard-stat-icon-wrap--primary flex-shrink-0"><?php echo $iconCourtRow; ?></div>
                                                        <span class="text-sm font-weight-bold"><?php echo htmlspecialchars($date['case_title']); ?></span>
                                                    </div>
                                                </td>
                                                <td class="align-middle"><?php echo htmlspecialchars($date['client_name']); ?></td>
                                                <td class="align-middle"><?php echo date('M d, Y g:i A', strtotime($date['court_date'])); ?></td>
                                                <td class="align-middle"><?php echo htmlspecialchars($date['title']); ?></td>
                                                <td class="align-middle text-center">
                                                    <?php echo client_court_date_status_badge((string) ($date['status'] ?? '')); ?>
                                                </td>
                                                <td class="align-middle text-end lp-table-actions">
                                                    <div class="court-actions">
                                                        <button type="button" class="btn btn-sm btn-primary mb-0" onclick="viewCourtDate(<?php echo (int) $date['id']; ?>)" title="View">View</button>
                                                        <button type="button" class="btn btn-sm btn-dark mb-0" onclick="editCourtDate(<?php echo (int) $date['id']; ?>)" title="Edit">Edit</button>
                                                        <button type="button" class="btn btn-sm btn-danger mb-0" onclick="deleteCourtDate(<?php echo (int) $date['id']; ?>)" title="Delete">Delete</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <nav class="lct-court-pagination" id="lawyerCourtDatesPagination" aria-label="Court dates pagination" hidden>
                                <p class="lct-court-pagination__info" data-court-range></p>
                                <div class="lct-court-pagination__controls" data-court-pages></div>
                            </nav>
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
            if (!row) {
                return;
            }
            if (typeof window.lawyerCourtShowPage === 'function') {
                var rows = Array.prototype.slice.call(document.querySelectorAll('.lct-court-row'));
                var index = rows.indexOf(row);
                if (index >= 0) {
                    var wrap = document.getElementById('lawyerCourtDatesTableWrap');
                    var perPage = wrap ? parseInt(wrap.getAttribute('data-court-per-page') || '10', 10) : 10;
                    window.lawyerCourtShowPage(Math.floor(index / perPage) + 1);
                }
            }
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.add('table-warning');
            setTimeout(function () {
                row.classList.remove('table-warning');
            }, 2200);
        }

        function initLawyerCourtDatesTablePagination() {
            var wrap = document.getElementById('lawyerCourtDatesTableWrap');
            var nav = document.getElementById('lawyerCourtDatesPagination');
            if (!wrap || !nav) {
                return;
            }

            var perPage = parseInt(wrap.getAttribute('data-court-per-page') || '10', 10);
            var rows = Array.prototype.slice.call(document.querySelectorAll('.lct-court-row'));
            var rangeEl = nav.querySelector('[data-court-range]');
            var pagesEl = nav.querySelector('[data-court-pages]');
            var countEl = document.getElementById('lawyerCourtTableCount');

            if (!rows.length || rows.length <= perPage) {
                nav.hidden = true;
                return;
            }

            nav.hidden = false;
            var currentPage = 1;
            var totalPages = Math.ceil(rows.length / perPage);

            function pageButton(label, page, options) {
                options = options || {};
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lct-court-pagination__btn';
                if (options.nav) {
                    btn.className += ' lct-court-pagination__btn--nav';
                }
                if (options.active) {
                    btn.className += ' lct-court-pagination__btn--active';
                }
                btn.textContent = label;
                btn.setAttribute('aria-label', options.ariaLabel || ('Page ' + label));
                if (options.disabled) {
                    btn.disabled = true;
                } else if (page) {
                    btn.addEventListener('click', function () {
                        showPage(page);
                    });
                }
                return btn;
            }

            function ellipsis() {
                var span = document.createElement('span');
                span.className = 'lct-court-pagination__ellipsis';
                span.textContent = '…';
                span.setAttribute('aria-hidden', 'true');
                return span;
            }

            function visiblePages() {
                if (totalPages <= 7) {
                    var all = [];
                    for (var p = 1; p <= totalPages; p++) {
                        all.push(p);
                    }
                    return all;
                }
                var pages = [1];
                var start = Math.max(2, currentPage - 1);
                var end = Math.min(totalPages - 1, currentPage + 1);
                if (start > 2) {
                    pages.push('gap');
                }
                for (var i = start; i <= end; i++) {
                    pages.push(i);
                }
                if (end < totalPages - 1) {
                    pages.push('gap');
                }
                pages.push(totalPages);
                return pages;
            }

            function renderControls() {
                if (!pagesEl) {
                    return;
                }
                pagesEl.innerHTML = '';
                pagesEl.appendChild(pageButton('‹ Prev', currentPage - 1, {
                    nav: true,
                    disabled: currentPage === 1,
                    ariaLabel: 'Previous page'
                }));
                visiblePages().forEach(function (page) {
                    if (page === 'gap') {
                        pagesEl.appendChild(ellipsis());
                        return;
                    }
                    pagesEl.appendChild(pageButton(String(page), page, {
                        active: page === currentPage,
                        ariaLabel: 'Page ' + page + (page === currentPage ? ', current' : '')
                    }));
                });
                pagesEl.appendChild(pageButton('Next ›', currentPage + 1, {
                    nav: true,
                    disabled: currentPage === totalPages,
                    ariaLabel: 'Next page'
                }));
            }

            function showPage(page) {
                currentPage = Math.max(1, Math.min(totalPages, page));
                rows.forEach(function (row, index) {
                    var rowPage = Math.floor(index / perPage) + 1;
                    row.style.display = rowPage === currentPage ? '' : 'none';
                });

                var start = (currentPage - 1) * perPage + 1;
                var end = Math.min(currentPage * perPage, rows.length);
                if (rangeEl) {
                    rangeEl.textContent = 'Showing ' + start + '–' + end + ' of ' + rows.length;
                }
                if (countEl) {
                    countEl.textContent = rows.length + (rows.length === 1 ? ' court date' : ' court dates');
                }
                renderControls();
            }

            window.lawyerCourtShowPage = showPage;
            showPage(1);
        }

        document.addEventListener('DOMContentLoaded', function() {
            initLawyerCourtDatesTablePagination();
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
                initLctCalendarSearch(courtEvents);
                return;
            }

            lawyerCourtTrackingCalendar = new FullCalendar.Calendar(calendarEl, {
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
                    var tip = info.event.title;
                    var p = info.event.extendedProps || {};
                    if (p.client_name) tip += '\nClient: ' + p.client_name;
                    if (p.location) tip += '\nLocation: ' + p.location;
                    info.el.setAttribute('title', tip);
                }
            });
            lawyerCourtTrackingCalendar.render();
            initLctCalendarSearch(courtEvents);
        });

        function initLctCalendarSearch(courtEvents) {
            var input = document.getElementById('lctCalSearchInput');
            var resultsEl = document.getElementById('lctCalSearchResults');
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
                }).sort(function(a, b) {
                    return new Date(b.start).getTime() - new Date(a.start).getTime();
                });

                if (!matches.length) {
                    resultsEl.innerHTML = '<div class="lct-cal-search-empty">' + (window.LCT_I18N ? window.LCT_I18N.emptySearch : '') + '</div>';
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
                    html += '<button type="button" class="lct-cal-search-item" data-court-date-id="' + escapeHtmlLct(props.courtDateId || ev.id) + '" data-start="' + escapeHtmlLct(ev.start || '') + '">' +
                        '<span class="lct-cal-search-item__dot lct-cal-search-item__dot--' + escapeHtmlLct(statusKey) + '" aria-hidden="true"></span>' +
                        '<span class="lct-cal-search-item__body">' +
                            '<p class="lct-cal-search-item__title">' + escapeHtmlLct(title) + '</p>' +
                            '<p class="lct-cal-search-item__sub">' + escapeHtmlLct(when) + (hearing ? ' · ' + escapeHtmlLct(hearing) : '') + escapeHtmlLct(location) + ' · ' + escapeHtmlLct(props.statusLabel || props.status || 'Scheduled') + '</p>' +
                        '</span>' +
                    '</button>';
                });
                resultsEl.innerHTML = html;
                resultsEl.hidden = false;
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
                if (lawyerCourtTrackingCalendar && start) {
                    lawyerCourtTrackingCalendar.gotoDate(start);
                }
                viewCourtDate(id);
                hideResults();
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.lct-cal-search-wrap')) {
                    hideResults();
                }
            });
        }

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
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
