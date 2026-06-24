<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../inc/admin-layout.php';

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
                SELECT a.id, a.starts_at, a.ends_at, a.status, a.notes, c.title AS case_title
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

$un = h($userLabel);

if ($portal === 'admin') {
    ob_start();
    include __DIR__ . '/../inc/menunav.php';
    $sidebarHtml = ob_get_clean();
} elseif ($portal === 'lawyer') {
    ob_start();
    include __DIR__ . '/../inc/lawyer-menunav.php';
    $sidebarHtml = ob_get_clean();
} else {
    ob_start();
    include __DIR__ . '/../inc/client-menunav.php';
    $sidebarHtml = ob_get_clean();
}

$portalTitle = $portal === 'client' ? 'Client' : ($portal === 'lawyer' ? 'Lawyer' : 'Admin');
$stripClass = $portal === 'admin' ? 'bg-legalpro-admin' : ($portal === 'lawyer' ? 'bg-legalpro-lawyer' : 'bg-legalpro-client');
$bodyExtra = 'search-portal-page search-portal-page--' . $portal;
if ($portal === 'client') {
    $bodyExtra .= ' legalpro-client-portal client-portal-page';
} elseif ($portal === 'lawyer') {
    $bodyExtra .= ' legalpro-lawyer-portal';
} elseif ($portal === 'admin') {
    $bodyExtra .= ' legalpro-admin-portal';
}
$navBreadcrumbMuted = 'opacity-6 text-white';
$navHeadingClass = 'font-weight-bolder text-white mb-0';
$navUserClass = 'text-white';
$navbarBlurAttr = $portal === 'client' ? 'navbar-scroll="true"' : 'data-scroll="false"';
$caseCount = count($cases);
$aptCount = count($appointments);
$iconSearchCases = legalpro_icon('briefcase');
$iconSearchAppts = legalpro_icon('calendar');

$heroCardClass = $portal === 'client'
    ? 'search-hero cd-hero-card mb-4'
    : 'card search-hero text-white mb-4';
$heroInnerClass = $portal === 'client' ? 'cd-hero-inner' : 'card-body';
$heroKickerClass = $portal === 'client'
    ? 'cd-hero-kicker mb-2'
    : 'text-xs text-uppercase font-weight-bold mb-1';
$heroKickerStyle = $portal === 'client' ? '' : ' style="letter-spacing: 0.12em; opacity: 0.85;"';
$heroTitleClass = $portal === 'client'
    ? 'cd-hero-title mb-2'
    : 'text-white font-weight-bolder mb-2';
$heroTextClass = $portal === 'client'
    ? 'cd-hero-text text-sm mb-0'
    : 'text-sm mb-0';
$heroTextStyle = $portal === 'client' ? ' style="line-height: 1.55; opacity: 0.88;"' : ' style="opacity: 0.88; line-height: 1.55;"';
$heroLabelClass = $portal === 'client'
    ? 'form-label text-xs mb-1 d-block search-hero-label'
    : 'form-label text-white text-xs mb-1 d-block';
$heroSubmitClass = $portal === 'client'
    ? 'btn btn-lg mb-0 px-4 font-weight-bold btn-search-submit search-hero-submit btn-primary-solid'
    : 'btn btn-white btn-lg mb-0 px-4 font-weight-bold btn-search-submit';
$resultsTitleClass = 'font-weight-bolder mb-1 mt-5 search-results-title';
$resultsSummaryClass = 'text-sm mb-0 search-results-summary';
$resultsSummaryStyle = '';
$resultsQueryClass = 'search-results-query';

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
    <?php if ($portal === 'client'): ?>
    <?php include __DIR__ . '/../inc/client-portal-head.php'; ?>
    <?php else: ?>
    <link href="../assets/css/app-font-montserrat.css?v=7" rel="stylesheet" />
    <link href="../assets/css/dashboard-enhancements.css?v=12" rel="stylesheet" />
    <?php legalpro_icons_asset_links(); ?>
    <?php if ($portal === 'lawyer'): ?>
    <?php include __DIR__ . '/../inc/lawyer-portal-badges-css.php'; ?>
    <?php endif; ?>
    <?php endif; ?>
    <style>
        .search-portal-page--lawyer .navbar-main,
        .search-portal-page--lawyer .navbar-main.blur,
        .search-portal-page--lawyer #navbarBlur,
        .search-portal-page--admin .navbar-main,
        .search-portal-page--admin .navbar-main.blur,
        .search-portal-page--admin #navbarBlur {
            background: transparent !important;
            backdrop-filter: none !important;
            border: none !important;
            box-shadow: none !important;
        }
        body.legalpro-dark-mode.search-portal-page--lawyer .navbar-main .breadcrumb-item,
        body.legalpro-dark-mode.search-portal-page--lawyer .navbar-main .breadcrumb-item a,
        body.legalpro-dark-mode.search-portal-page--lawyer .navbar-main h5,
        body.legalpro-dark-mode.search-portal-page--lawyer .navbar-main .nav-link,
        body.legalpro-dark-mode.search-portal-page--admin .navbar-main .breadcrumb-item,
        body.legalpro-dark-mode.search-portal-page--admin .navbar-main .breadcrumb-item a,
        body.legalpro-dark-mode.search-portal-page--admin .navbar-main h5,
        body.legalpro-dark-mode.search-portal-page--admin .navbar-main .nav-link {
            color: #fff !important;
        }
        body.legalpro-dark-mode.search-portal-page--lawyer .navbar-main .breadcrumb-item a,
        body.legalpro-dark-mode.search-portal-page--admin .navbar-main .breadcrumb-item a {
            opacity: 0.9;
        }
        body.legalpro-dark-mode.search-portal-page--lawyer .navbar-main .sidenav-toggler-line,
        body.legalpro-dark-mode.search-portal-page--admin .navbar-main .sidenav-toggler-line {
            background-color: #fff !important;
        }
        .search-portal-page--lawyer:not(.legalpro-dark-mode) .navbar-main .breadcrumb-item,
        .search-portal-page--lawyer:not(.legalpro-dark-mode) .navbar-main .breadcrumb-item a,
        .search-portal-page--lawyer:not(.legalpro-dark-mode) .navbar-main h5,
        .search-portal-page--lawyer:not(.legalpro-dark-mode) .navbar-main .nav-link,
        .search-portal-page--admin:not(.legalpro-dark-mode) .navbar-main .breadcrumb-item,
        .search-portal-page--admin:not(.legalpro-dark-mode) .navbar-main .breadcrumb-item a,
        .search-portal-page--admin:not(.legalpro-dark-mode) .navbar-main h5,
        .search-portal-page--admin:not(.legalpro-dark-mode) .navbar-main .nav-link {
            color: #344767 !important;
            opacity: 1 !important;
        }
        .search-portal-page--lawyer:not(.legalpro-dark-mode) .navbar-main .sidenav-toggler-line,
        .search-portal-page--admin:not(.legalpro-dark-mode) .navbar-main .sidenav-toggler-line {
            background-color: #344767 !important;
        }
        .search-portal-page--lawyer .search-hero,
        .search-portal-page--admin .search-hero {
            border-radius: 1.15rem;
            background: linear-gradient(135deg, rgba(94, 114, 228, 0.88) 0%, rgba(30, 42, 88, 0.95) 100%);
            box-shadow: 0 1rem 2.25rem rgba(23, 43, 77, 0.16);
            border: none;
        }
        body.search-portal-page--client {
            --cs-primary: var(--legalpro-theme-primary, #5e72e4);
            --cs-gradient: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
        }
        .search-portal-page--client .search-hero.cd-hero-card {
            background: var(--cs-gradient);
            border-radius: 20px;
            padding: 2rem 2.5rem;
            color: #fff;
            position: relative;
            overflow: hidden;
            border: none;
            box-shadow: 0 4px 20px rgba(15, 20, 35, 0.12);
        }
        .search-portal-page--client .search-hero.cd-hero-card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }
        .search-portal-page--client .search-hero.cd-hero-card .cd-hero-inner {
            position: relative;
            z-index: 1;
            padding: 0;
        }
        .search-portal-page--client .search-hero.cd-hero-card .cd-hero-kicker {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.75;
        }
        .search-portal-page--client .search-hero.cd-hero-card .cd-hero-title {
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }
        .search-portal-page--client .search-hero.cd-hero-card .cd-hero-text strong {
            color: #fff;
        }
        .search-portal-page--client .search-hero-label {
            color: rgba(255, 255, 255, 0.85);
            font-weight: 600;
        }
        .search-portal-page--client .search-hero.cd-hero-card .search-hero-submit,
        .search-portal-page--client .search-hero.cd-hero-card .btn-primary-solid {
            background: #fff !important;
            color: var(--legalpro-theme-primary, #5e72e4) !important;
            border: none !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
        }
        .search-portal-page--client .search-hero.cd-hero-card .search-hero-submit:hover,
        .search-portal-page--client .search-hero.cd-hero-card .btn-primary-solid:hover {
            opacity: 0.92;
            background: #fff !important;
            color: var(--legalpro-theme-primary, #5e72e4) !important;
        }
        body.legalpro-dark-mode.search-portal-page--client .search-hero.cd-hero-card .search-hero-submit,
        body.legalpro-dark-mode.search-portal-page--client .search-hero.cd-hero-card .btn-primary-solid {
            color: var(--lp-dark-surface, #464f68) !important;
        }
        body.legalpro-dark-mode.search-portal-page--client .search-hero.cd-hero-card .search-hero-submit:hover,
        body.legalpro-dark-mode.search-portal-page--client .search-hero.cd-hero-card .btn-primary-solid:hover {
            color: var(--lp-dark-surface, #464f68) !important;
        }
        .search-portal-page .search-hero-field {
            flex: 1;
            min-width: 0;
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
        .search-portal-page .search-results-title {
            color: #344767;
        }
        .search-portal-page .search-results-summary {
            color: #67748e;
        }
        .search-portal-page .search-results-query {
            color: #344767;
        }
        body.legalpro-dark-mode.search-portal-page .search-results-title,
        body.legalpro-dark-mode.search-portal-page .search-results-query {
            color: var(--lp-dark-text, #f8f9fc) !important;
        }
        body.legalpro-dark-mode.search-portal-page .search-results-summary {
            color: var(--lp-dark-text-muted, #c5cede) !important;
        }
        .search-portal-page .search-result-counts {
            gap: 0.65rem;
        }
        .search-portal-page .search-result-count {
            cursor: default;
            padding: 0.65rem 0.95rem;
            min-width: 8.5rem;
        }
        .search-portal-page .search-result-count:hover {
            transform: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        body.legalpro-dark-mode.search-portal-page .search-result-count {
            background: var(--lp-dark-surface, #2d3748);
            border-color: var(--lp-dark-border, rgba(255, 255, 255, 0.08));
        }
        body.legalpro-dark-mode.search-portal-page .search-result-count .dashboard-glance__value {
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode.search-portal-page .search-result-count .dashboard-glance__label {
            color: var(--lp-dark-text-muted, #c5cede);
        }
        .search-portal-page .search-result-row .ca-status-pill {
            flex-shrink: 0;
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 <?php echo h($bodyExtra); ?><?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 <?php echo h($stripClass); ?> position-absolute w-100"></div>

    <?php echo $sidebarHtml; ?>

    <main class="main-content position-relative border-radius-lg">
        <?php if ($portal === 'client'): ?>
            <?php
            require_once __DIR__ . '/../inc/client-portal-navbar.php';
            echo legalpro_render_client_page_navbar('Search', 'Search', 'Search cases…', [
                'client_name' => $userLabel,
                'search_value' => $q,
            ]);
            ?>
        <?php else: ?>
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
        <?php endif; ?>

        <div class="container-fluid py-4">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-radius-lg" role="alert">
                    <?php echo h($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="<?php echo h($heroCardClass); ?>">
                <div class="<?php echo h($heroInnerClass); ?><?php echo $portal === 'client' ? '' : ' p-4 p-lg-5'; ?>">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-5">
                            <p class="<?php echo h($heroKickerClass); ?>"<?php echo $heroKickerStyle; ?>>LegalPro search</p>
                            <h4 class="<?php echo h($heroTitleClass); ?>">Find cases instantly</h4>
                            <p class="<?php echo h($heroTextClass); ?>"<?php echo $heroTextStyle; ?>>Use a title, client name, category, status, or a case number like <strong>C-0001</strong>.</p>
                        </div>
                        <div class="col-lg-7">
                            <form method="get" action="search.php" class="mb-0" role="search">
                                <label class="<?php echo h($heroLabelClass); ?>">Search query</label>
                                <div class="search-hero-query">
                                    <div class="input-group input-group-lg search-hero-field legalpro-search-input-group">
                                        <span class="input-group-text"><i class="fas fa-search" aria-hidden="true"></i></span>
                                        <input type="search" name="q" class="form-control" placeholder="Try a keyword or case number…" value="<?php echo $qDisp; ?>" autocomplete="off" maxlength="200" aria-label="Search">
                                    </div>
                                    <button class="<?php echo h($heroSubmitClass); ?>" type="submit">Search</button>
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
                        <h5 class="<?php echo h($resultsTitleClass); ?>">Results</h5>
                        <p class="<?php echo h($resultsSummaryClass); ?>"<?php echo $resultsSummaryStyle; ?>>Showing matches for <strong class="<?php echo h($resultsQueryClass); ?>">“<?php echo $qDisp; ?>”</strong></p>
                    </div>
                    <div class="d-flex flex-wrap search-result-counts">
                        <div class="dashboard-glance__item search-result-count">
                            <div class="dashboard-glance-icon-wrap dashboard-glance-icon-wrap--primary"><?php echo $iconSearchCases; ?></div>
                            <div>
                                <div class="dashboard-glance__value"><?php echo (int) $caseCount; ?></div>
                                <div class="dashboard-glance__label"><?php echo $caseCount === 1 ? 'Case' : 'Cases'; ?></div>
                            </div>
                        </div>
                        <?php if ($portal === 'client'): ?>
                            <div class="dashboard-glance__item search-result-count">
                                <div class="dashboard-glance-icon-wrap dashboard-glance-icon-wrap--info"><?php echo $iconSearchAppts; ?></div>
                                <div>
                                    <div class="dashboard-glance__value"><?php echo (int) $aptCount; ?></div>
                                    <div class="dashboard-glance__label"><?php echo $aptCount === 1 ? 'Appointment' : 'Appointments'; ?></div>
                                </div>
                            </div>
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
                                            <?php echo client_case_status_badge((string) ($row['status'] ?? '')); ?>
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
                                                <?php echo client_appointment_status_badge($a); ?>
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
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
