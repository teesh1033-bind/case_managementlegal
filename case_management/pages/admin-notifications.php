<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../lib/portal_notifications.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    $items = legalpro_fetch_admin_notifications($pdo, 100);
    legalpro_mark_all_portal_notifications_read($pdo, 'admin', $adminId, $items);
    header('Location: admin-notifications.php?msg=' . urlencode('All notifications marked as read.') . '&type=success');
    exit;
}

if (isset($_GET['msg'])) {
    $message = (string) $_GET['msg'];
    $messageType = isset($_GET['type']) ? (string) $_GET['type'] : 'success';
}

$notifications = legalpro_fetch_admin_notifications($pdo, 100);
$listHtml = '';

if (empty($notifications)) {
    $listHtml = '<div class="legalpro-notif-panel__empty py-5">'
        . legalpro_icon('bell', 'legalpro-notif-panel__empty-icon')
        . '<p>No new notifications</p>'
        . '<span>You are all caught up.</span>'
        . '</div>';
} else {
    $listHtml = '<div class="legalpro-notif-list-page">';
    foreach ($notifications as $item) {
        $icon = htmlspecialchars((string) ($item['icon'] ?? 'bell'), ENT_QUOTES, 'UTF-8');
        $notifKey = (string) ($item['key'] ?? '');
        $destinationUrl = (string) ($item['url'] ?? '');
        $clickUrl = $notifKey !== ''
            ? legalpro_notification_click_url($notifKey, $destinationUrl)
            : $destinationUrl;
        $unreadHint = legalpro_notification_unread_hint();
        $listHtml .= '<a href="' . htmlspecialchars($clickUrl, ENT_QUOTES, 'UTF-8') . '" class="legalpro-notif-item is-unread"'
            . ($notifKey !== '' ? ' data-notif-key="' . htmlspecialchars($notifKey, ENT_QUOTES, 'UTF-8') . '"' : '')
            . ' title="' . htmlspecialchars($unreadHint, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="legalpro-notif-item__icon legalpro-notif-item__icon--' . htmlspecialchars((string) ($item['type'] ?? 'default'), ENT_QUOTES, 'UTF-8') . '">'
            . legalpro_icon($icon)
            . '</span>'
            . '<span class="legalpro-notif-item__body">'
            . '<span class="legalpro-notif-item__title">' . htmlspecialchars((string) $item['title']) . '</span>'
            . '<span class="legalpro-notif-item__message">' . htmlspecialchars((string) $item['message']) . '</span>'
            . '<span class="legalpro-notif-item__time">' . htmlspecialchars((string) ($item['time'] ?? '')) . '</span>'
            . '</span>'
            . legalpro_notification_unread_caption_html()
            . '</a>';
    }
    $listHtml .= '</div>';
}

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
    . '</div>'
    : '';

$markAllBtn = !empty($notifications)
    ? '<form method="post" class="d-inline">'
        . '<input type="hidden" name="action" value="mark_all_read">'
        . '<button type="submit" class="btn btn-sm btn-outline-primary mb-0">Mark all read</button>'
        . '</form>'
    : '';

ob_start();
include __DIR__ . '/../inc/menunav.php';
$navHtml = ob_get_clean();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - Notifications</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=3" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/admin-portal-head.php'; ?>
    <style>
        .legalpro-notif-list-page {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .admin-notifications-page .legalpro-notif-item {
            border-radius: 12px;
            border: 1px solid #e9ecef;
            background: #fff;
        }
        body.legalpro-dark-mode.admin-notifications-page .legalpro-notif-item {
            border-color: var(--lp-dark-border, rgba(255, 255, 255, 0.1));
            background: var(--lp-dark-surface-raised, #3d455c);
        }
    </style>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal admin-notifications-page<?php echo legalpro_portal_theme_body_class(); ?>">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    {NAVIGATION}
    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Notifications</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">Notifications</h6>
                </nav>
            </div>
        </nav>
        <div class="container-fluid py-4">
            {MESSAGE_HTML}
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h6 class="mb-0">All notifications</h6>
                                <p class="text-sm text-muted mb-0">Unread updates across cases, appointments, clients, and billing</p>
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
        <footer class="footer pt-3">
            <div class="container-fluid">
                <div class="copyright text-center text-sm text-muted">
                    {COPYRIGHT_LINE}
                </div>
            </div>
        </footer>
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
    [
        '{NAVIGATION}',
        '{MESSAGE_HTML}',
        '{MARK_ALL_BTN}',
        '{NOTIFICATION_LIST}',
    ],
    [
        $navHtml,
        $messageHtml,
        $markAllBtn,
        $listHtml,
    ],
    $html
);

echo legalpro_apply_copyright_line($html);
