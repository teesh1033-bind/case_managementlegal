<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$message = '';
$messageType = '';

require_once __DIR__ . '/../lib/client-portal-features.php';
require_once __DIR__ . '/../lib/portal-theme.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_preferences') {
    $themeMode = isset($_POST['theme_mode']) ? (string) $_POST['theme_mode'] : 'light';
    $locale = isset($_POST['locale']) ? (string) $_POST['locale'] : 'en';
    $emailDigest = isset($_POST['email_digest']) ? (string) $_POST['email_digest'] : 'none';
    $themeResult = saveClientPortalThemeMode($clientId, $themeMode);
    $localeResult = saveClientPortalLocale($clientId, $locale);
    $digestResult = saveClientEmailDigest($clientId, $emailDigest);

    if (!$themeResult['ok']) {
        $message = $themeResult['message'];
        $messageType = 'danger';
    } elseif (!$localeResult['ok']) {
        $message = $localeResult['message'];
        $messageType = 'danger';
    } elseif (!$digestResult['ok']) {
        $message = $digestResult['message'];
        $messageType = 'danger';
    } else {
        header('Location: client-settings.php?msg=' . urlencode(client_t('settings.saved')) . '&type=success');
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

$preferencesHtml = renderClientPortalSettingsFullHtml($pdo, $clientId);
$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('settings.title'),
    client_t('settings.title'),
    '',
    ['include_search' => false]
);

ob_start();
include __DIR__ . '/../inc/client-portal-head.php';
$clientPortalHead = ob_get_clean();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="{HTML_LANG}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - {PAGE_TITLE}</title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    {CLIENT_PORTAL_HEAD}
    <link href="../assets/css/client-portal-pages.css?v=2" rel="stylesheet" />
    <link href="../assets/css/client-account-pages.css?v=1" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-settings-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}

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

$html = str_replace('{CLIENT_PORTAL_HEAD}', $clientPortalHead, $html);
$html = str_replace('{HTML_LANG}', client_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars(client_t('settings.title')), $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{PREFERENCES_HTML}', $preferencesHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
