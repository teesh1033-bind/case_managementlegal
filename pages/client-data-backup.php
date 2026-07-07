<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';
require_once __DIR__ . '/../lib/client-data-export.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$clientName = (string) ($_SESSION['client_name'] ?? 'Client');
$message = '';
$messageType = '';

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $payload = legalpro_client_build_data_export($pdo, $clientId);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="client-backup-' . date('Y-m-d') . '.json"');
    echo $json !== false ? $json : '{}';
    exit;
}

$clientEmail = '';
try {
    $stmt = $pdo->prepare('SELECT email FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $clientEmail = trim((string) ($stmt->fetchColumn() ?: ''));
} catch (PDOException $e) {
    $clientEmail = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_backup') {
    $to = $clientEmail !== '' ? $clientEmail : trim((string) ($_POST['email'] ?? ''));
    $result = legalpro_client_send_data_export_email($pdo, $clientId, $to);
    $message = (string) ($result['message'] ?? '');
    $messageType = !empty($result['ok']) ? 'success' : 'danger';
}

$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('backup.title'),
    '',
    '',
    ['subtitle' => client_t('backup.subtitle'), 'client_name' => $clientName]
);

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
    : '';

$includedList = ''
    . '<li>' . legalpro_icon('user') . '<span>' . htmlspecialchars(client_t('backup.include_profile')) . '</span></li>'
    . '<li>' . legalpro_icon('briefcase') . '<span>' . htmlspecialchars(client_t('backup.include_cases')) . '</span></li>'
    . '<li>' . legalpro_icon('file-text') . '<span>' . htmlspecialchars(client_t('backup.include_invoices')) . '</span></li>'
    . '<li>' . legalpro_icon('credit-card') . '<span>' . htmlspecialchars(client_t('backup.include_payments')) . '</span></li>'
    . '<li>' . legalpro_icon('calendar') . '<span>' . htmlspecialchars(client_t('backup.include_appointments')) . '</span></li>';

$emailHint = $clientEmail !== ''
    ? client_t('backup.email_hint', ['email' => $clientEmail])
    : client_t('backup.email_hint_generic');

ob_start();
include __DIR__ . '/../inc/client-portal-head.php';
$clientPortalHead = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(client_portal_html_lang()) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars(client_t('backup.page_title')) ?> — LegalPro</title>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <?= $clientPortalHead ?>
    <link href="../assets/css/client-portal-pages.css?v=7" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-data-backup-page<?= legalpro_portal_theme_body_class() ?>">
<div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
<?php include __DIR__ . '/../inc/client-menunav.php'; ?>
<main class="main-content position-relative border-radius-lg">
    <?= $clientPageNavbar ?>
    <div class="container-fluid py-4 px-4">
        <div class="cp-page">
            <?= $messageHtml ?>
            <section class="cp-panel cp-panel--banner-top">
                <?= client_portal_render_panel_banner([
                    'title' => client_t('backup.banner_title'),
                    'subtitle' => client_t('backup.banner_sub'),
                ]) ?>
            </section>
            <div class="cb-backup-grid">
                <section class="cp-panel cb-backup-card">
                    <div class="cp-panel-body cb-backup-card__body">
                        <div class="cb-backup-card__icon cb-backup-card__icon--blue"><?= legalpro_icon('download') ?></div>
                        <h5><?= htmlspecialchars(client_t('backup.download_title')) ?></h5>
                        <p class="text-muted"><?= htmlspecialchars(client_t('backup.download_desc')) ?></p>
                        <a href="client-data-backup.php?download=1" class="btn btn-primary"><?= htmlspecialchars(client_t('backup.download_btn')) ?></a>
                    </div>
                </section>
                <section class="cp-panel cb-backup-card">
                    <div class="cp-panel-body cb-backup-card__body">
                        <div class="cb-backup-card__icon cb-backup-card__icon--purple"><?= legalpro_icon('mail') ?></div>
                        <h5><?= htmlspecialchars(client_t('backup.email_title')) ?></h5>
                        <p class="text-muted"><?= htmlspecialchars($emailHint) ?></p>
                        <form method="post">
                            <input type="hidden" name="action" value="email_backup">
                            <?php if ($clientEmail === ''): ?>
                            <input type="email" name="email" class="form-control mb-2" placeholder="<?= htmlspecialchars(client_t('backup.email_placeholder')) ?>" required>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-outline-primary"><?= htmlspecialchars(client_t('backup.email_btn')) ?></button>
                        </form>
                    </div>
                </section>
            </div>
            <section class="cp-panel">
                <?= client_portal_render_panel_header(['title' => client_t('backup.included_title'), 'subtitle' => client_t('backup.included_sub'), 'icon' => 'info']) ?>
                <div class="cp-panel-body"><ul class="cb-included-list"><?= $includedList ?></ul></div>
            </section>
        </div>
    </div>
</main>
<script src="../assets/js/core/popper.min.js"></script>
<script src="../assets/js/core/bootstrap.min.js"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
