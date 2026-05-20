<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$qDisp = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');

$portal = null;
$dashboardHref = 'login.php';
$logoutHref = 'login.php';
$userLabel = '';

if (isset($_SESSION['client_id'])) {
    $portal = 'client';
    $dashboardHref = 'client-dashboard.php';
    $logoutHref = 'client-logout.php';
    $userLabel = isset($_SESSION['client_name']) ? (string) $_SESSION['client_name'] : 'Client';
} elseif (isset($_SESSION['lawyer_id'])) {
    $portal = 'lawyer';
    $dashboardHref = 'lawyer-dashboard.php';
    $logoutHref = 'lawyer-logout.php';
    $userLabel = isset($_SESSION['lawyer_name']) ? (string) $_SESSION['lawyer_name'] : 'Lawyer';
} elseif (isset($_SESSION['admin_id'])) {
    $portal = 'admin';
    $dashboardHref = 'dashboard.php';
    $logoutHref = 'admin-logout.php';
    $userLabel = isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : 'Admin';
} else {
    header('Location: login.php');
    exit;
}

/** Escape for SQL LIKE pattern (MySQL) */
function legalpro_like_pattern(string $s): string
{
    $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);

    return '%' . $s . '%';
}

/** Optional exact case id from "123" or "C-00123" */
function legalpro_parse_case_id(string $raw): ?int
{
    $t = trim($raw);
    if ($t === '') {
        return null;
    }
    if (preg_match('/^C-?\s*0*(\d+)\s*$/i', $t, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^\d{1,9}$/', $t)) {
        return (int) $t;
    }

    return null;
}

$cases = [];
$appointments = [];
$error = '';
$exactCaseId = legalpro_parse_case_id($q);

if ($q !== '') {
    $like = legalpro_like_pattern($q);
    try {
        if ($portal === 'client') {
            $clientId = (int) $_SESSION['client_id'];
            if ($exactCaseId !== null) {
                $stmt = $pdo->prepare('
                    SELECT c.id, c.title, c.status, c.category, c.updated_at
                    FROM cases c
                    WHERE c.client_id = ?
                      AND (c.id = ? OR c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ? OR c.status LIKE ?)
                    ORDER BY c.updated_at DESC
                    LIMIT 50
                ');
                $stmt->execute([$clientId, $exactCaseId, $like, $like, $like, $like]);
            } else {
                $stmt = $pdo->prepare('
                    SELECT c.id, c.title, c.status, c.category, c.updated_at
                    FROM cases c
                    WHERE c.client_id = ?
                      AND (c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ? OR c.status LIKE ?)
                    ORDER BY c.updated_at DESC
                    LIMIT 50
                ');
                $stmt->execute([$clientId, $like, $like, $like, $like]);
            }
            $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('
                SELECT a.id, a.starts_at, a.status, a.notes, c.title AS case_title
                FROM appointments a
                LEFT JOIN cases c ON c.id = a.case_id
                WHERE a.client_id = ?
                  AND (a.notes LIKE ? OR c.title LIKE ? OR CAST(a.id AS CHAR) LIKE ?)
                ORDER BY a.starts_at DESC
                LIMIT 30
            ');
            $stmt->execute([$clientId, $like, $like, $like]);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($portal === 'lawyer') {
            $lawyerId = (int) $_SESSION['lawyer_id'];
            if ($exactCaseId !== null) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, c.title, c.status, c.category, cl.first_name, cl.last_name, c.updated_at
                    FROM cases c
                    INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id AND cl2.lawyer_id = ?
                    INNER JOIN clients cl ON cl.id = c.client_id
                    WHERE c.id = ?
                       OR (c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ? OR c.status LIKE ?
                           OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE ?)
                    ORDER BY c.updated_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$lawyerId, $exactCaseId, $like, $like, $like, $like, $like]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, c.title, c.status, c.category, cl.first_name, cl.last_name, c.updated_at
                    FROM cases c
                    INNER JOIN case_lawyers cl2 ON cl2.case_id = c.id AND cl2.lawyer_id = ?
                    INNER JOIN clients cl ON cl.id = c.client_id
                    WHERE c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ? OR c.status LIKE ?
                       OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE ?
                    ORDER BY c.updated_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$lawyerId, $like, $like, $like, $like, $like]);
            }
            $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            if ($exactCaseId !== null) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.title, c.status, c.category, cl.first_name, cl.last_name, c.updated_at
                    FROM cases c
                    INNER JOIN clients cl ON cl.id = c.client_id
                    WHERE c.id = ?
                       OR (c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ? OR c.status LIKE ?
                           OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE ?
                           OR cl.email LIKE ?)
                    ORDER BY c.updated_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$exactCaseId, $like, $like, $like, $like, $like, $like]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.title, c.status, c.category, cl.first_name, cl.last_name, c.updated_at
                    FROM cases c
                    INNER JOIN clients cl ON cl.id = c.client_id
                    WHERE c.title LIKE ? OR c.description LIKE ? OR c.category LIKE ? OR c.status LIKE ?
                       OR CONCAT(cl.first_name, ' ', cl.last_name) LIKE ?
                       OR cl.email LIKE ?
                    ORDER BY c.updated_at DESC
                    LIMIT 50
                ");
                $stmt->execute([$like, $like, $like, $like, $like, $like]);
            }
            $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $error = 'Search could not be completed. Please try again.';
    }
}

$caseViewHref = $portal === 'client' ? 'client-case-view.php' : ($portal === 'lawyer' ? 'lawyer-case-view.php' : 'case-view.php');

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function legalpro_search_status_badge(string $status): string
{
    switch (strtolower($status)) {
        case 'in_progress':
            return '<span class="badge badge-sm bg-gradient-primary">In progress</span>';
        case 'open':
            return '<span class="badge badge-sm bg-gradient-success">Open</span>';
        case 'closed':
            return '<span class="badge badge-sm bg-gradient-secondary">Closed</span>';
        case 'pending':
            return '<span class="badge badge-sm bg-gradient-warning">Pending</span>';
        case 'accepted':
            return '<span class="badge badge-sm bg-gradient-success">Accepted</span>';
        case 'rejected':
            return '<span class="badge badge-sm bg-gradient-danger">Rejected</span>';
        default:
            return '<span class="badge badge-sm bg-gradient-info">' . h($status) . '</span>';
    }
}

$un = h($userLabel);

if ($portal === 'admin') {
    ob_start();
    include __DIR__ . '/../inc/menunav.php';
    $sidebarHtml = ob_get_clean();
} elseif ($portal === 'lawyer') {
    $sidebarHtml = <<<SIDEBAR
<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0" href="lawyer-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26" height="26" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0">
    <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" href="lawyer-dashboard.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-tv-2 text-primary text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="tasks.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-check-bold text-success text-sm opacity-10"></i></div><span class="nav-link-text ms-1">My Tasks</span></a></li>
            <li class="nav-item"><a class="nav-link" href="lawyer-cases.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-folder-17 text-warning text-sm opacity-10"></i></div><span class="nav-link-text ms-1">My Cases</span></a></li>
            <li class="nav-item"><a class="nav-link" href="lawyer-clients.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-circle-08 text-info text-sm opacity-10"></i></div><span class="nav-link-text ms-1">My Clients</span></a></li>
            <li class="nav-item"><a class="nav-link" href="lawyer-appointments.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-calendar-grid-58 text-success text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Appointments</span></a></li>
            <li class="nav-item"><a class="nav-link" href="lawyer-court-tracking.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-collection text-primary text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Court Tracking</span></a></li>
            <li class="nav-item"><a class="nav-link" href="lawyer-availability.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-time-alarm text-danger text-sm opacity-10"></i></div><span class="nav-link-text ms-1">My Availability</span></a></li>
        </ul>
    </div>
    <div class="sidenav-footer position-absolute bottom-0 w-100">
        <div class="text-center">
            <p class="text-xs text-muted mb-1">Logged in as</p>
            <p class="text-sm font-weight-bold mb-2">{$un}</p>
            <a href="lawyer-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
        </div>
    </div>
</aside>
SIDEBAR;
} else {
    $sidebarHtml = <<<SIDEBAR
<aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0" href="client-dashboard.php">
            <img src="../assets/img/logo-ct-dark.png" width="26" height="26" class="navbar-brand-img h-100" alt="LegalPro logo">
            <span class="ms-1 font-weight-bold">LegalPro</span>
        </a>
    </div>
    <hr class="horizontal dark mt-0">
    <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" href="client-dashboard.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-tv-2 text-primary text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="client-cases.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-folder-17 text-warning text-sm opacity-10"></i></div><span class="nav-link-text ms-1">My Cases</span></a></li>
            <li class="nav-item"><a class="nav-link" href="client-appointments.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-calendar-grid-58 text-info text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Appointments</span></a></li>
            <li class="nav-item"><a class="nav-link" href="client-court-tracking.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-collection text-success text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Court Tracking</span></a></li>
            <li class="nav-item"><a class="nav-link" href="client-payments.php"><div class="icon icon-shape icon-sm border-radius-md text-center me-2 d-flex align-items-center justify-content-center"><i class="ni ni-credit-card text-info text-sm opacity-10"></i></div><span class="nav-link-text ms-1">Payments</span></a></li>
        </ul>
    </div>
    <div class="sidenav-footer position-absolute bottom-0 w-100">
        <div class="text-center">
            <p class="text-xs text-muted mb-1">Logged in as</p>
            <p class="text-sm font-weight-bold mb-2">{$un}</p>
            <a href="client-logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
        </div>
    </div>
</aside>
SIDEBAR;
}

$portalTitle = $portal === 'client' ? 'Client' : ($portal === 'lawyer' ? 'Lawyer' : 'Admin');
$stripClass = $portal === 'admin' ? 'bg-legalpro-admin' : ($portal === 'lawyer' ? 'bg-legalpro-lawyer' : 'bg-primary');
$bodyExtra = 'search-portal-page search-portal-page--' . $portal;
if ($portal === 'lawyer') {
    $bodyExtra .= ' legalpro-lawyer-portal';
}
$navBreadcrumbMuted = 'opacity-6 text-white';
$navHeadingClass = 'font-weight-bolder text-white mb-0';
$navUserClass = 'text-white';
$navbarBlurAttr = $portal === 'client' ? 'navbar-scroll="true"' : 'data-scroll="false"';
$caseCount = count($cases);
$aptCount = count($appointments);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro — Search</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=2" rel="stylesheet" />
    <style>
        .search-portal-page--client .min-height-300 {
            background: linear-gradient(125deg, #5e72e4 0%, #324cdd 42%, #172b4d 100%) !important;
            opacity: 1;
        }
        .search-portal-page .navbar-main,
        .search-portal-page .navbar-main.blur,
        .search-portal-page #navbarBlur {
            background: transparent !important;
            backdrop-filter: none !important;
            border: none !important;
            box-shadow: none !important;
        }
        .search-portal-page--client .navbar-main {
            margin-top: 20px;
        }
        .search-portal-page .navbar-main .breadcrumb-item,
        .search-portal-page .navbar-main .breadcrumb-item a,
        .search-portal-page .navbar-main h5,
        .search-portal-page .navbar-main .nav-link {
            color: #fff !important;
        }
        .search-portal-page .navbar-main .breadcrumb-item a {
            opacity: 0.9;
        }
        .search-portal-page .navbar-main .breadcrumb-item.active {
            opacity: 1;
        }
        .search-portal-page .navbar-main .sidenav-toggler-line {
            background-color: #fff !important;
        }
        .search-portal-page .search-hero {
            border-radius: 1.15rem;
            background: linear-gradient(135deg, rgba(94, 114, 228, 0.96) 0%, rgba(50, 76, 221, 0.98) 50%, rgba(23, 43, 77, 1) 100%);
            box-shadow: 0 1rem 2.25rem rgba(23, 43, 77, 0.16);
            border: none;
        }
        .search-portal-page--lawyer .search-hero,
        .search-portal-page--admin .search-hero {
            background: linear-gradient(135deg, rgba(94, 114, 228, 0.88) 0%, rgba(30, 42, 88, 0.95) 100%);
        }
        .search-portal-page .search-hero-query {
            display: flex;
            align-items: stretch;
            gap: 0.75rem;
        }
        .search-portal-page .search-hero-field {
            flex: 1;
            min-width: 0;
            box-shadow: 0 0.35rem 1rem rgba(0, 0, 0, 0.12);
            border-radius: 0.65rem;
            background: #fff;
            overflow: visible;
        }
        .search-portal-page .search-hero-field .input-group-text {
            border: none;
            min-width: auto;
            padding: 0.65rem 0.5rem;
            flex-shrink: 0;
            z-index: 2;
            background: #fff !important;
            color: #67748e !important;
        }
        .search-portal-page .search-hero-field .input-group-text i {
            opacity: 1;
            font-size: 0.95rem;
        }
        .search-portal-page .search-hero-field .form-control,
        .search-portal-page .search-hero-field input[type="search"].form-control {
            border: none;
            padding-left: 0 !important;
            background: #fff;
            box-shadow: none;
            -webkit-appearance: none;
            appearance: none;
        }
        .search-portal-page .search-hero-field input[type="search"].form-control::-webkit-search-decoration,
        .search-portal-page .search-hero-field input[type="search"].form-control::-webkit-search-cancel-button {
            -webkit-appearance: none;
            appearance: none;
            margin: 0;
        }
        .search-portal-page .search-hero-field .form-control:focus {
            box-shadow: none;
        }
        .search-portal-page .search-hero-query .btn-search-submit {
            flex-shrink: 0;
            border-radius: 0.65rem !important;
            box-shadow: 0 0.35rem 1rem rgba(0, 0, 0, 0.12);
        }
        .search-portal-page .search-panel {
            border-radius: 1.15rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 0.25rem 1.1rem rgba(52, 71, 103, 0.07);
        }
        .search-portal-page .search-panel .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 1.1rem 1.25rem 0.85rem;
        }
        .search-portal-page .search-result-row {
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 0.75rem;
            transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
        }
        .search-portal-page .search-result-row:hover {
            border-color: rgba(94, 114, 228, 0.35);
            background: rgba(94, 114, 228, 0.04);
            box-shadow: 0 0.35rem 1rem rgba(94, 114, 228, 0.08);
        }
        .search-portal-page .search-result-row .flex-grow-1 { min-width: 0; }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 <?php echo h($bodyExtra); ?>">
    <div class="min-height-300 <?php echo h($stripClass); ?> position-absolute w-100"></div>

    <?php echo $sidebarHtml; ?>

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" <?php echo $navbarBlurAttr; ?>>
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm">
                            <a class="<?php echo h($navBreadcrumbMuted); ?>" href="<?php echo h($dashboardHref); ?>"><?php echo h($portalTitle); ?></a>
                        </li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Search</li>
                    </ol>
                    <h5 class="<?php echo h($navHeadingClass); ?>">Search</h5>
                </nav>
                <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4 justify-content-end" id="navbar">
                    <ul class="navbar-nav justify-content-end">
                        <li class="nav-item d-flex align-items-center">
                            <span class="nav-link font-weight-bold px-0 <?php echo h($navUserClass); ?>">
                                <i class="fa fa-user me-sm-1"></i>
                                <span class="d-sm-inline d-none">Welcome, <?php echo $un; ?></span>
                            </span>
                        </li>
                        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                            <a href="javascript:;" class="nav-link p-0 <?php echo h($navUserClass); ?>" id="iconNavbarSidenav">
                                <div class="sidenav-toggler-inner">
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                    <i class="sidenav-toggler-line"></i>
                                </div>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-radius-lg" role="alert">
                    <?php echo h($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card search-hero text-white mb-4">
                <div class="card-body p-4 p-lg-4">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-5">
                            <p class="text-xs text-uppercase font-weight-bold mb-1" style="letter-spacing: 0.12em; opacity: 0.85;">LegalPro search</p>
                            <h4 class="text-white font-weight-bolder mb-2">Find cases instantly</h4>
                            <p class="text-sm mb-0" style="opacity: 0.88; line-height: 1.55;">Use a title, client name, category, status, or a case number like <strong>C-0001</strong>.</p>
                        </div>
                        <div class="col-lg-7">
                            <form method="get" action="search.php" class="mb-0" role="search">
                                <label class="form-label text-white text-xs mb-1 d-block">Search query</label>
                                <div class="search-hero-query">
                                    <div class="input-group input-group-lg search-hero-field">
                                        <span class="input-group-text"><i class="fas fa-search" aria-hidden="true"></i></span>
                                        <input type="search" name="q" class="form-control" placeholder="Try a keyword or case number…" value="<?php echo $qDisp; ?>" autocomplete="off" maxlength="200" aria-label="Search">
                                    </div>
                                    <button class="btn btn-white btn-lg mb-0 px-4 font-weight-bold btn-search-submit" type="submit">Search</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($q === ''): ?>
                <div class="card search-panel border-0">
                    <div class="card-body p-4 p-lg-5 text-center">
                        <div class="icon icon-shape icon-lg bg-gradient-primary shadow mx-auto mb-3 border-radius-lg">
                            <i class="ni ni-zoom-split-in text-white text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                        <h5 class="font-weight-bolder mb-2">Start typing above</h5>
                        <p class="text-sm text-muted mb-0 mx-auto" style="max-width: 28rem;">Enter a term in the search box above. Results stay scoped to your account.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
                    <div>
                        <h5 class="font-weight-bolder text-dark mb-1">Results</h5>
                        <p class="text-sm text-muted mb-0">Showing matches for <strong class="text-dark">“<?php echo $qDisp; ?>”</strong></p>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="badge rounded-pill bg-gradient-primary"><?php echo (int) $caseCount; ?> case<?php echo $caseCount === 1 ? '' : 's'; ?></span>
                        <?php if ($portal === 'client'): ?>
                            <span class="badge rounded-pill bg-gradient-info"><?php echo (int) $aptCount; ?> appointment<?php echo $aptCount === 1 ? '' : 's'; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card search-panel mb-4">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="mb-0 font-weight-bolder">Cases</h6>
                            <p class="text-xs text-muted mb-0 mt-1">Open a matter to view full details.</p>
                        </div>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($cases)): ?>
                            <div class="text-center text-muted py-5 px-2">
                                <p class="text-sm font-weight-bold mb-1">No matching cases</p>
                                <p class="text-xs mb-0">Try another keyword or case number.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($cases as $row): ?>
                                <?php
                                $cid = (int) $row['id'];
                                $num = 'C-' . str_pad((string) $cid, 4, '0', STR_PAD_LEFT);
                                $clientCell = '';
                                if ($portal !== 'client' && isset($row['first_name'])) {
                                    $clientCell = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
                                }
                                ?>
                                <a href="<?php echo h($caseViewHref); ?>?id=<?php echo $cid; ?>" class="search-result-row d-block text-decoration-none text-reset mb-2 p-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div class="flex-grow-1">
                                            <p class="text-xs font-weight-bold text-primary mb-1"><?php echo h($num); ?></p>
                                            <h6 class="text-sm font-weight-bold text-dark mb-1 text-truncate"><?php echo h($row['title']); ?></h6>
                                            <p class="text-xs text-muted mb-0">
                                                <?php if ($clientCell !== ''): ?>
                                                    <strong>Client:</strong> <?php echo h($clientCell); ?> ·
                                                <?php endif; ?>
                                                <strong>Updated:</strong> <?php echo h(date('M j, Y', strtotime($row['updated_at']))); ?>
                                            </p>
                                        </div>
                                        <div class="d-flex flex-column align-items-end gap-2 flex-shrink-0">
                                            <?php echo legalpro_search_status_badge($row['status']); ?>
                                            <span class="text-xs text-primary font-weight-bold">Open <i class="ni ni-bold-right ms-1" aria-hidden="true"></i></span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($portal === 'client'): ?>
                    <div class="card search-panel mb-4">
                        <div class="card-header">
                            <h6 class="mb-0 font-weight-bolder">Appointments</h6>
                            <p class="text-xs text-muted mb-0 mt-1">Matches on notes, linked case title, or reference.</p>
                        </div>
                        <div class="card-body p-3">
                            <?php if (empty($appointments)): ?>
                                <div class="text-center text-muted py-5 px-2">
                                    <p class="text-sm font-weight-bold mb-1">No matching appointments</p>
                                    <p class="text-xs mb-3">Adjust your search or browse the calendar.</p>
                                    <a href="client-appointments.php" class="btn btn-sm btn-primary mb-0">Go to appointments</a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($appointments as $a): ?>
                                    <a href="client-appointments.php" class="search-result-row d-block text-decoration-none text-reset mb-2 p-3">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div class="flex-grow-1">
                                                <h6 class="text-sm font-weight-bold text-dark mb-1"><?php echo h($a['case_title'] ?: 'General appointment'); ?></h6>
                                                <p class="text-xs text-muted mb-0">
                                                    <strong>When:</strong> <?php echo h(date('M j, Y g:i A', strtotime($a['starts_at']))); ?>
                                                </p>
                                            </div>
                                            <div class="d-flex flex-column align-items-end gap-2 flex-shrink-0">
                                                <?php echo legalpro_search_status_badge($a['status']); ?>
                                                <span class="text-xs text-primary font-weight-bold">Calendar <i class="ni ni-bold-right ms-1" aria-hidden="true"></i></span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
