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
    <link href="../assets/css/app-font-montserrat.css?v=7" rel="stylesheet" />
    {CLIENT_PORTAL_HEAD}
    <style>
        .client-settings-page .settings-theme-mode {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .client-settings-page .settings-theme-mode__option {
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
        .client-settings-page .settings-theme-mode__option:has(input:checked) {
            border-color: var(--legalpro-theme-primary, #5e72e4);
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.08);
        }
        .client-settings-page .settings-theme-mode__option input {
            margin: 0;
        }
        body.legalpro-dark-mode.client-settings-page .settings-theme-mode__option {
            background: var(--lp-dark-input-bg, #2f3547);
            border-color: var(--lp-dark-border-strong, rgba(255, 255, 255, 0.16));
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode.client-settings-page .form-select {
            background-color: var(--lp-dark-input-bg, #2f3547);
            border-color: var(--lp-dark-border-strong, rgba(255, 255, 255, 0.16));
            color: var(--lp-dark-text, #f8f9fc);
        }
        .client-settings-page .cs-hero {
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
        .client-settings-page .cs-hero__kicker {
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.75;
            margin-bottom: 0.35rem;
        }
        .client-settings-page .cs-hero__title {
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 0.35rem;
        }
        .client-settings-page .cs-hero__sub {
            font-size: 0.875rem;
            opacity: 0.85;
            margin: 0;
            max-width: 36rem;
        }
        .client-settings-page .cs-hero__meta {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            font-size: 0.8rem;
            opacity: 0.9;
        }
        .client-settings-page .cs-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.65rem;
        }
        .client-settings-page .cs-stat {
            background: #f8fafc;
            border: 1px solid #e9ecf3;
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
            text-align: center;
        }
        .client-settings-page .cs-stat__num {
            display: block;
            font-size: 1.05rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
        }
        .client-settings-page .cs-stat__lbl {
            display: block;
            font-size: 0.68rem;
            color: #94a3b8;
            margin-top: 0.15rem;
        }
        .client-settings-page .cs-quick-links {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem;
        }
        .client-settings-page .cs-quick-link {
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
        .client-settings-page .cs-quick-link:hover {
            background: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.06);
            border-color: rgba(var(--legalpro-theme-primary-rgb, 94, 114, 228), 0.25);
            color: var(--legalpro-theme-primary, #5e72e4);
        }
        .client-settings-page .cs-quick-link__icon {
            display: inline-flex;
            color: var(--legalpro-theme-primary, #5e72e4);
        }
        .client-settings-page .cs-quick-link__icon .lp-icon svg {
            width: 1rem;
            height: 1rem;
        }
        .client-settings-page .cs-account-dl {
            margin: 0;
        }
        .client-settings-page .cs-account-dl dt {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            margin-bottom: 0.1rem;
        }
        .client-settings-page .cs-account-dl dd {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.75rem;
        }
        .client-settings-page .cs-tip-list {
            padding-left: 1.15rem;
            margin: 0;
        }
        .client-settings-page .cs-tip-list li + li {
            margin-top: 0.35rem;
        }
        body.legalpro-dark-mode.client-settings-page .cs-stat {
            background: var(--lp-dark-surface-raised, #2f3547);
            border-color: rgba(255, 255, 255, 0.08);
        }
        body.legalpro-dark-mode.client-settings-page .cs-stat__num,
        body.legalpro-dark-mode.client-settings-page .cs-account-dl dd {
            color: var(--lp-dark-text, #f8f9fc);
        }
        body.legalpro-dark-mode.client-settings-page .cs-quick-link {
            background: var(--lp-dark-surface-raised, #2f3547);
            border-color: rgba(255, 255, 255, 0.08);
            color: var(--lp-dark-text, #f8f9fc);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-settings-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}

        <div class="container-fluid py-4">
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