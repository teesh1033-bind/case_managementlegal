<?php
session_start();
require_once __DIR__ . '/../inc/db.php';

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

$appearanceHtml = renderLawyerPortalThemeSettingsHtml($lawyerId);

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
        }
        .lawyer-settings-page .settings-theme-mode__option:has(input:checked) {
            border-color: var(--legalpro-theme-primary, #5e72e4);
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
        }
        .lawyer-settings-page .settings-theme-mode__option input {
            margin: 0;
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
            <div class="row">
                <div class="col-lg-8 col-xl-6">
                    {MESSAGE}
                    {APPEARANCE_HTML}
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

$html = str_replace(
    ['{NAVIGATION}', '{MESSAGE}', '{APPEARANCE_HTML}'],
    [$navHtml, $messageHtml, $appearanceHtml],
    $html
);

echo $html;
