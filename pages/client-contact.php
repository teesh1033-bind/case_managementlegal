<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';
require_once __DIR__ . '/../lib/client-self-service.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';
require_once __DIR__ . '/../lib/branding.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$clientName = (string) ($_SESSION['client_name'] ?? 'Client');
$message = '';
$messageType = '';

legalpro_client_requests_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['message'] ?? ''));
    if ($body === '') {
        $message = client_t('contact.message_required');
        $messageType = 'danger';
    } else {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO client_requests (client_id, case_id, request_type, subject, message, status)
                VALUES (?, NULL, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $clientId,
                'contact',
                $subject !== '' ? $subject : client_t('contact.default_subject'),
                $body,
                'open',
            ]);
            $message = client_t('contact.sent_success');
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = client_t('contact.sent_error');
            $messageType = 'danger';
        }
    }
}

$firm = getCompanyBranding();
$firmName = htmlspecialchars($firm['name']);
$firmDetails = trim((string) $firm['details']);
$firmDetailsHtml = $firmDetails !== ''
    ? nl2br(htmlspecialchars($firmDetails))
    : htmlspecialchars(client_t('contact.services_default'));

$officeRows = ''
    . '<div class="cc-contact-row">' . legalpro_icon('building-2') . '<div><span class="cc-contact-row__label">' . htmlspecialchars(client_t('contact.company')) . '</span><strong>' . $firmName . '</strong></div></div>'
    . '<div class="cc-contact-row">' . legalpro_icon('briefcase') . '<div><span class="cc-contact-row__label">' . htmlspecialchars(client_t('contact.services')) . '</span><span>' . $firmDetailsHtml . '</span></div></div>'
    . '<div class="cc-contact-row">' . legalpro_icon('mail') . '<div><span class="cc-contact-row__label">' . htmlspecialchars(client_t('contact.email_us')) . '</span><a href="mailto:' . htmlspecialchars(getSetting('mail_from_address', 'info@example.com')) . '">' . htmlspecialchars(getSetting('mail_from_address', 'info@example.com')) . '</a></div></div>';

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'
    : '';

$formHtml = '<form method="post" class="cc-contact-form">'
    . '<input type="hidden" name="action" value="send_message">'
    . '<div class="mb-3"><label class="form-label" for="contactSubject">' . htmlspecialchars(client_t('contact.subject')) . '</label>'
    . '<input type="text" class="form-control" id="contactSubject" name="subject" placeholder="' . htmlspecialchars(client_t('contact.subject_placeholder')) . '"></div>'
    . '<div class="mb-3"><label class="form-label" for="contactMessage">' . htmlspecialchars(client_t('contact.message')) . '</label>'
    . '<textarea class="form-control" id="contactMessage" name="message" rows="6" required placeholder="' . htmlspecialchars(client_t('contact.message_placeholder')) . '"></textarea></div>'
    . '<button type="submit" class="btn btn-primary">' . legalpro_icon('send') . ' ' . htmlspecialchars(client_t('contact.send')) . '</button>'
    . '</form>';

$clientPageNavbar = legalpro_render_client_page_navbar(
    client_t('contact.title'),
    '',
    '',
    ['subtitle' => client_t('contact.subtitle'), 'client_name' => $clientName]
);

ob_start();
include __DIR__ . '/../inc/client-portal-head.php';
$clientPortalHead = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(client_portal_html_lang()) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars(client_t('contact.page_title')) ?> — LegalPro</title>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <?= $clientPortalHead ?>
    <link href="../assets/css/client-portal-pages.css?v=7" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-contact-page<?= legalpro_portal_theme_body_class() ?>">
<div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
<?php include __DIR__ . '/../inc/client-menunav.php'; ?>
<main class="main-content position-relative border-radius-lg">
    <?= $clientPageNavbar ?>
    <div class="container-fluid py-4 px-4">
        <div class="cp-page">
            <?= $messageHtml ?>
            <div class="cc-contact-grid">
                <section class="cp-panel">
                    <?= client_portal_render_panel_header(['title' => client_t('contact.office_info'), 'subtitle' => client_t('contact.office_sub'), 'icon' => 'building-2']) ?>
                    <div class="cp-panel-body cc-contact-info"><?= $officeRows ?></div>
                </section>
                <section class="cp-panel">
                    <?= client_portal_render_panel_header(['title' => client_t('contact.send_message'), 'subtitle' => client_t('contact.send_sub'), 'icon' => 'send']) ?>
                    <div class="cp-panel-body"><?= $formHtml ?></div>
                </section>
            </div>
        </div>
    </div>
</main>
<script src="../assets/js/core/popper.min.js"></script>
<script src="../assets/js/core/bootstrap.min.js"></script>
<script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
