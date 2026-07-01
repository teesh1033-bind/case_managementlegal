<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/lawyer-locale.php';
require_once __DIR__ . '/../lib/portal-theme.php';
require_once __DIR__ . '/../inc/lawyer-portal-navbar.php';

if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = (int) $_SESSION['lawyer_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
    $themeMode = isset($_POST['theme_mode']) ? (string) $_POST['theme_mode'] : 'light';
    $locale = isset($_POST['locale']) ? (string) $_POST['locale'] : 'en';
    $themeResult = saveLawyerPortalThemeMode($lawyerId, $themeMode);
    $localeResult = saveLawyerPortalLocale($lawyerId, $locale);

    if (!$themeResult['ok']) {
        $message = $themeResult['message'];
        $messageType = 'danger';
    } elseif (!$localeResult['ok']) {
        $message = $localeResult['message'];
        $messageType = 'danger';
    } else {
        header('Location: lawyer-settings.php?msg=' . urlencode(lawyer_t('settings.saved')) . '&type=success');
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

$preferencesHtml = renderLawyerPortalSettingsFullHtml($pdo, $lawyerId);
$lawyerPageNavbar = legalpro_render_lawyer_page_navbar(
    lawyer_t('settings.title'),
    lawyer_t('settings.title'),
    ['include_search' => false]
);

ob_start();
include __DIR__ . '/../inc/lawyer-portal-head.php';
$lawyerPortalHead = ob_get_clean();

ob_start();
include __DIR__ . '/../inc/lawyer-menunav.php';
$navHtml = ob_get_clean();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=3" rel="stylesheet" />
    {LAWYER_PORTAL_HEAD}
    <link href="../assets/css/client-portal-pages.css?v=3" rel="stylesheet" />
    <link href="../assets/css/client-account-pages.css?v=3" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-settings-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>

    {NAVIGATION}

    <main class="main-content position-relative border-radius-lg">
        {LAWYER_NAVBAR}

        <div class="container-fluid py-4 px-4">
            {MESSAGE}
            {PREFERENCES_HTML}
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

$html = str_replace('{LAWYER_PORTAL_HEAD}', $lawyerPortalHead, $html);
$html = str_replace('{HTML_LANG}', lawyer_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars(lawyer_t('settings.title')), $html);
$html = str_replace('{LAWYER_NAVBAR}', $lawyerPageNavbar, $html);
$html = str_replace('{NAVIGATION}', $navHtml, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{PREFERENCES_HTML}', $preferencesHtml, $html);
$html = str_replace('{PORTAL_THEME_BODY_CLASS}', legalpro_portal_theme_body_class(), $html);

echo $html;
