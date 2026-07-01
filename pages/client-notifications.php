<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/client-portal-features.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    legalpro_client_mark_all_notifications_read($pdo, $clientId);
    header('Location: client-notifications.php?msg=' . urlencode(client_t('notifications.mark_all_success')) . '&type=success');
    exit;
}

if (isset($_GET['msg'])) {
    $message = (string) $_GET['msg'];
    $messageType = isset($_GET['type']) ? (string) $_GET['type'] : 'success';
}

$notifications = legalpro_client_get_notifications($pdo, $clientId, 100);
$listHtml = '';
$unreadHint = function_exists('client_t') ? client_t('notifications.unread_hint') : legalpro_notification_unread_hint();

if (empty($notifications)) {
    $listHtml = '<div class="legalpro-notif-panel__empty py-5">'
        . legalpro_icon('bell', 'legalpro-notif-panel__empty-icon')
        . '<p>' . htmlspecialchars(client_t('notifications.empty_page')) . '</p>'
        . '</div>';
} else {
    $listHtml = '<div class="legalpro-notif-list-page">';
    foreach ($notifications as $item) {
        $icon = htmlspecialchars((string) ($item['icon'] ?? 'bell'), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string) ($item['type'] ?? 'default'), ENT_QUOTES, 'UTF-8');
        $isUnread = empty($item['is_read']);
        $time = legalpro_client_notification_time_parts((string) ($item['created_at'] ?? ''));
        $timeLabel = htmlspecialchars($time['label'] !== '' ? $time['label'] : $time['ago'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars(legalpro_client_resolve_notification_link($item), ENT_QUOTES, 'UTF-8');
        $notifId = (int) ($item['id'] ?? 0);

        $listHtml .= '<a href="' . $href . '" class="legalpro-notif-item' . ($isUnread ? ' is-unread' : '') . '"'
            . ($notifId > 0 ? ' data-notif-id="' . $notifId . '"' : '')
            . ($isUnread ? ' title="' . htmlspecialchars($unreadHint, ENT_QUOTES, 'UTF-8') . '"' : '')
            . '>'
            . '<span class="legalpro-notif-item__icon legalpro-notif-item__icon--' . $type . '">'
            . legalpro_icon($icon)
            . '</span>'
            . '<span class="legalpro-notif-item__body">'
            . '<span class="legalpro-notif-item__title">' . htmlspecialchars(legalpro_client_notification_title($item)) . '</span>'
            . '<span class="legalpro-notif-item__message">' . htmlspecialchars(legalpro_client_notification_body($item)) . '</span>'
            . '<span class="legalpro-notif-item__time">' . $timeLabel . '</span>'
            . '</span>';
        if ($isUnread) {
            $listHtml .= '<span class="legalpro-notif-item__hover-caption" role="tooltip">'
                . htmlspecialchars($unreadHint, ENT_QUOTES, 'UTF-8')
                . '</span>';
        }
        $listHtml .= '</a>';
    }
    $listHtml .= '</div>';
}

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . htmlspecialchars(client_t('common.close')) . '"></button>'
    . '</div>'
    : '';

$hasUnread = false;
foreach ($notifications as $item) {
    if (empty($item['is_read'])) {
        $hasUnread = true;
        break;
    }
}

$markAllBtn = $hasUnread
    ? '<form method="post" class="d-inline">'
        . '<input type="hidden" name="action" value="mark_all_read">'
        . '<button type="submit" class="btn btn-sm btn-outline-primary mb-0">' . htmlspecialchars(client_t('notifications.mark_all_read')) . '</button>'
        . '</form>'
    : '';

$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('notifications.title'),
    client_t('notifications.title'),
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
    <style>
        .legalpro-notif-list-page {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .client-notifications-page .legalpro-notif-item {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            background: #fff;
        }
        body.legalpro-dark-mode.client-notifications-page .legalpro-notif-item {
            border-color: var(--lp-dark-border, rgba(255, 255, 255, 0.1));
            background: var(--lp-dark-surface-raised, #3d455c);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-notifications-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}

        <div class="container-fluid py-4 px-4">
            {MESSAGE}
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6 class="mb-0">{PAGE_HEADING}</h6>
                                <p class="text-sm text-muted mb-0">{PAGE_SUBTITLE}</p>
                            </div>
                            {MARK_ALL_BTN}
                        </div>
                        <div class="card-body">
                            {NOTIFICATION_LIST}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="../assets/js/core/popper.min.js"></script>
    <script src="../assets/js/core/bootstrap.min.js"></script>
    <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
    <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
    <script src="../assets/js/legalpro-sidenav-bootstrap.js?v=1"></script>
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.legalpro-notif-item[data-notif-id]').forEach(function (item) {
            item.addEventListener('click', function () {
                var id = item.getAttribute('data-notif-id');
                if (!id) return;
                var body = new URLSearchParams();
                body.set('action', 'mark_read');
                body.set('id', id);
                fetch('client-notifications-api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
            });
        });
    });
    </script>
</body>
</html>
HTML;

$html = str_replace('{CLIENT_PORTAL_HEAD}', $clientPortalHead, $html);
$html = str_replace('{HTML_LANG}', client_portal_html_lang(), $html);
$html = str_replace('{PAGE_TITLE}', htmlspecialchars(client_t('notifications.title')), $html);
$html = str_replace('{PAGE_HEADING}', htmlspecialchars(client_t('notifications.title')), $html);
$html = str_replace('{PAGE_SUBTITLE}', htmlspecialchars(client_t('notifications.page_subtitle')), $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{MARK_ALL_BTN}', $markAllBtn, $html);
$html = str_replace('{NOTIFICATION_LIST}', $listHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
