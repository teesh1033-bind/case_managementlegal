<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/portal-theme.php';

if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = (int) $_SESSION['lawyer_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_appearance') {
    $themeMode = isset($_POST['theme_mode']) ? (string) $_POST['theme_mode'] : 'light';
    $result = saveLawyerPortalThemeMode($lawyerId, $themeMode);

    if (!$result['ok']) {
        $message = $result['message'];
        $messageType = 'danger';
    } else {
        header('Location: lawyer-settings.php?msg=' . urlencode($result['message']) . '&type=success');
        exit;
    }
}

if (isset($_GET['msg'])) {
    $message = (string) $_GET['msg'];
    $messageType = isset($_GET['type']) ? (string) $_GET['type'] : 'success';
}

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
    . '</div>'
    : '';

$appearanceHtml = renderLawyerPortalSettingsFullHtml($pdo, $lawyerId);

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=3" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
    <style>
        .lawyer-settings-page .settings-theme-mode {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .lawyer-settings-page .settings-theme-mode__option {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1rem;
            border: 1px solid #e9ecef;
            border-radius: 0.65rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            margin: 0;
            background: #fff;
            color: #1e293b;
        }
        .lawyer-settings-page .settings-theme-mode__option:has(input:checked) {
            border-color: var(--legalpro-theme-primary, #5e72e4);
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
        }
        .lawyer-settings-page .settings-theme-mode__option input {
            margin: 0;
        }
        body.legalpro-dark-mode.lawyer-settings-page .settings-theme-mode__option {
            background: var(--lp-dark-input-bg, #2f3547);
            border-color: var(--lp-dark-border-strong, rgba(255, 255, 255, 0.16));
            color: var(--lp-dark-text, #f8f9fc);
        }
        .lawyer-settings-page .cs-hero {
            background: var(--legalpro-theme-gradient, linear-gradient(135deg, #5e72e4, #825ee4));
            border-radius: 18px;
            padding: 1.75rem 2rem;
            color: #fff;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-end;
        }
        .lawyer-settings-page .cs-hero__kicker {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.75;
            margin-bottom: 0.35rem;
            color: #fff !important;
        }
        .lawyer-settings-page .cs-hero__title {
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 0.35rem;
            color: #fff !important;
        }
        .lawyer-settings-page .cs-hero__sub {
            font-size: 0.875rem;
            opacity: 0.85;
            margin: 0;
            max-width: 36rem;
            color: #fff !important;
        }
        .lawyer-settings-page .cs-hero__meta {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            font-size: 0.8rem;
            opacity: 0.9;
            color: #fff !important;
        }
        .lawyer-settings-page .cs-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.65rem;
        }
        .lawyer-settings-page .cs-stat {
            background: #f8fafc;
            border: 1px solid #e9ecf3;
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
            text-align: center;
        }
        .lawyer-settings-page .cs-stat__num {
            display: block;
            font-size: 1.05rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
        }
        .lawyer-settings-page .cs-stat__lbl {
            display: block;
            font-size: 0.68rem;
            color: #94a3b8;
            margin-top: 0.15rem;
        }
        .lawyer-settings-page .cs-quick-links {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem;
        }
        .lawyer-settings-page .cs-quick-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 0.65rem;
            border: 1px solid #e9ecf3;
            border-radius: 10px;
            text-decoration: none;
            color: #334155;
            font-size: 0.8rem;
            font-weight: 600;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .lawyer-settings-page .cs-quick-link:hover {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.06);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.25);
            color: var(--legalpro-theme-primary, #5e72e4);
        }
        .lawyer-settings-page .cs-quick-link__icon {
            display: inline-flex;
            color: var(--legalpro-theme-primary, #5e72e4);
        }
        .lawyer-settings-page .cs-quick-link__icon .lp-icon svg {
            width: 1rem;
            height: 1rem;
        }
        .lawyer-settings-page .cs-account-dl {
            margin: 0;
        }
        .lawyer-settings-page .cs-account-dl dt {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            margin-bottom: 0.1rem;
        }
        .lawyer-settings-page .cs-account-dl dd {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.75rem;
        }
        .lawyer-settings-page .cs-tip-list {
            padding-left: 1.15rem;
            margin: 0;
        }
        .lawyer-settings-page .cs-tip-list li + li {
            margin-top: 0.35rem;
        }
        body.legalpro-dark-mode.lawyer-settings-page .cs-stat {
            background: var(--lp-dark-surface-raised, #2f3547);
            border-color: rgba(255, 255, 255, 0.08);
        }
        body.legalpro-dark-mode.lawyer-settings-page .cs-stat__num,
        body.legalpro-dark-mode.lawyer-settings-page .cs-account-dl dd {
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode.lawyer-settings-page .cs-quick-link {
            background: var(--lp-dark-surface-raised, #2f3547);
            border-color: rgba(255, 255, 255, 0.08);
            color: var(--lp-dark-text, #f8f9fc);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-settings-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="lawyer-dashboard.php">Lawyer Portal</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Settings</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Settings</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4">
            {MESSAGE}
            {APPEARANCE_HTML}
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
HTML;

$html = str_replace(
    ['{NAVIGATION}', '{MESSAGE}', '{APPEARANCE_HTML}'],
    [$navHtml, $messageHtml, $appearanceHtml],
    $html
);

echo $html;
