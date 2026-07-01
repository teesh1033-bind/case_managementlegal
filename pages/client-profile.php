<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/password-validation.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';
require_once __DIR__ . '/../lib/client-portal-page-ui.php';
require_once __DIR__ . '/../lib/client-locale.php';
require_once __DIR__ . '/../lib/client-portal-i18n.php';
require_once __DIR__ . '/../inc/legalpro-icons.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['client_id'];
$clientName = (string) ($_SESSION['client_name'] ?? 'Client');
$message = '';
$messageType = '';

$profile = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'username' => '',
];

function loadClientProfile(PDO $pdo, int $clientId): array
{
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.user_id,
            c.first_name,
            c.last_name,
            c.email,
            c.phone,
            c.address,
            u.username,
            u.password AS user_password_hash
        FROM clients c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

$createPasswordErrorHtml = '';
$createConfirmErrorHtml = '';
$createPasswordInvalidClass = '';
$createConfirmInvalidClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        $current = loadClientProfile($pdo, $clientId);
        if (!$current) {
            throw new RuntimeException('Client profile not found.');
        }

        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                $message = client_t('profile.name_required');
                $messageType = 'danger';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = client_t('profile.email_invalid');
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE clients
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?
                    WHERE id = ?
                ");
                $stmt->execute([$firstName, $lastName, $email, $phone, $address !== '' ? $address : null, $clientId]);

                if (!empty($current['user_id']) && $email !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $current['user_id']]);
                }

                $_SESSION['client_name'] = trim($firstName . ' ' . $lastName);
                $clientName = $_SESSION['client_name'];
                $message = client_t('profile.updated');
                $messageType = 'success';
            }
        } elseif ($action === 'change_password') {
            if (empty($current['user_id'])) {
                $message = client_t('profile.no_account');
                $messageType = 'danger';
            } else {
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if (!password_verify($currentPassword, (string) ($current['user_password_hash'] ?? ''))) {
                    $message = client_t('profile.password_incorrect');
                    $messageType = 'danger';
                } else {
                    $passwordCheck = legalpro_validate_password_pair($newPassword, $confirmPassword);
                    if (!$passwordCheck['valid']) {
                        $message = legalpro_password_form_message($passwordCheck);
                        $messageType = 'danger';
                        $createPasswordErrorHtml = legalpro_password_field_error_html($passwordCheck['password_errors']);
                        $createConfirmErrorHtml = legalpro_password_field_error_html($passwordCheck['confirm_error']);
                        $createPasswordInvalidClass = legalpro_password_input_invalid_class($passwordCheck['password_errors']);
                        $createConfirmInvalidClass = legalpro_password_input_invalid_class($passwordCheck['confirm_error']);
                    } else {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$newPasswordHash, $current['user_id']]);
                        $message = client_t('profile.password_updated');
                        $messageType = 'success';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $message = client_t('profile.update_error');
        $messageType = 'danger';
    }
}

try {
    $row = loadClientProfile($pdo, $clientId);
    if (!$row) {
        throw new RuntimeException('Client profile not found.');
    }
    $profile['first_name'] = (string) ($row['first_name'] ?? '');
    $profile['last_name'] = (string) ($row['last_name'] ?? '');
    $profile['email'] = (string) ($row['email'] ?? '');
    $profile['phone'] = (string) ($row['phone'] ?? '');
    $profile['address'] = (string) ($row['address'] ?? '');
    $profile['username'] = (string) ($row['username'] ?? '');
} catch (Throwable $e) {
    $message = client_t('profile.load_error');
    $messageType = 'danger';
}

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . htmlspecialchars(client_t('common.close')) . '"></button>'
    . '</div>'
    : '';

$usernameDisplay = $profile['username'] !== '' ? $profile['username'] : client_t('pdf.na');
$displayName = trim($clientName) !== '' ? $clientName : client_t('header.client');

$heroHtml = client_portal_render_hero([
    'kicker' => client_t('profile.kicker'),
    'title' => client_t('profile.title'),
    'subtitle' => client_t('profile.subtitle'),
    'meta' => client_t('profile.meta_signed_in', ['name' => $displayName]),
    'show_date' => true,
    'aria_label' => client_t('profile.aria'),
    'stats' => [
        ['num' => $profile['email'] !== '' ? '✓' : '—', 'lbl' => client_t('profile.email')],
        ['num' => $profile['phone'] !== '' ? '✓' : '—', 'lbl' => client_t('profile.phone')],
        ['num' => $profile['address'] !== '' ? '✓' : '—', 'lbl' => client_t('profile.address')],
    ],
    'actions' => [
        ['url' => 'client-settings.php', 'label' => client_t('nav.settings'), 'icon' => 'settings'],
        ['url' => 'client-dashboard.php', 'label' => client_t('nav.dashboard'), 'primary' => true, 'icon' => 'layout-dashboard'],
    ],
]);

$profileFormBody = '
    <form method="post">
        <input type="hidden" name="action" value="update_profile">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">' . htmlspecialchars(client_t('profile.first_name')) . '</label>
                <input class="form-control" type="text" name="first_name" value="' . htmlspecialchars($profile['first_name']) . '" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">' . htmlspecialchars(client_t('profile.last_name')) . '</label>
                <input class="form-control" type="text" name="last_name" value="' . htmlspecialchars($profile['last_name']) . '" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">' . htmlspecialchars(client_t('profile.email')) . '</label>
                <input class="form-control" type="email" name="email" value="' . htmlspecialchars($profile['email']) . '">
            </div>
            <div class="col-md-6">
                <label class="form-label">' . htmlspecialchars(client_t('profile.phone')) . '</label>
                <input class="form-control" type="text" name="phone" value="' . htmlspecialchars($profile['phone']) . '">
            </div>
            <div class="col-12">
                <label class="form-label">' . htmlspecialchars(client_t('profile.address')) . '</label>
                <textarea class="form-control" rows="3" name="address">' . htmlspecialchars($profile['address']) . '</textarea>
            </div>
        </div>
        <div class="cp-form-actions">
            <button type="submit" class="btn btn-primary">' . htmlspecialchars(client_t('profile.save_profile')) . '</button>
        </div>
    </form>';

$securityFormBody = '
    <div class="cp-profile-username">' . legalpro_icon('user') . '<span>' . htmlspecialchars(client_t('profile.username_label', ['name' => $usernameDisplay])) . '</span></div>
    <form method="post">
        <input type="hidden" name="action" value="change_password">
        <div class="mb-3">
            <label class="form-label">' . htmlspecialchars(client_t('profile.current_password')) . '</label>
            <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label class="form-label">' . htmlspecialchars(client_t('profile.new_password')) . '</label>
            <input class="form-control' . $createPasswordInvalidClass . '" type="password" name="new_password" required autocomplete="new-password" minlength="8" maxlength="128">
            ' . $createPasswordErrorHtml . '
        </div>
        <div class="mb-3">
            <label class="form-label">' . htmlspecialchars(client_t('profile.confirm_password')) . '</label>
            <input class="form-control' . $createConfirmInvalidClass . '" type="password" name="confirm_password" required autocomplete="new-password" minlength="8" maxlength="128">
            ' . $createConfirmErrorHtml . '
        </div>
        <ul class="cp-help-list mb-3">
            <li>' . htmlspecialchars(client_t('profile.password_help_1')) . '</li>
            <li>' . htmlspecialchars(client_t('profile.password_help_2')) . '</li>
        </ul>
        <div class="cp-form-actions">
            <button type="submit" class="btn btn-dark">' . htmlspecialchars(client_t('profile.change_password')) . '</button>
        </div>
    </form>';

$profilePanelHtml = client_portal_render_panel([
    'title' => client_t('profile.panel_personal'),
    'subtitle' => client_t('profile.panel_personal_sub'),
    'icon' => 'contact',
], $profileFormBody);

$securityPanelHtml = client_portal_render_panel([
    'title' => client_t('profile.panel_security'),
    'subtitle' => client_t('profile.panel_security_sub'),
    'icon' => 'lock',
], $securityFormBody);

$clientPageNavbar = legalpro_render_client_page_navbar(client_t('profile.title'), client_t('profile.navbar'), '', [
    'include_search' => false,
]);

ob_start();
include __DIR__ . '/../inc/client-portal-head.php';
$clientPortalHead = ob_get_clean();

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(client_portal_html_lang()) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - <?= htmlspecialchars(client_t('profile.page_title')) ?></title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    {CLIENT_PORTAL_HEAD}
    <link href="../assets/css/client-portal-pages.css?v=2" rel="stylesheet" />
    <link href="../assets/css/client-account-pages.css?v=1" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-profile-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}

        <div class="container-fluid py-4 px-4">
            <div class="cp-page">
                {MESSAGE}
                {HERO}
                <div class="cp-profile-layout">
                    {PROFILE_PANEL}
                    {SECURITY_PANEL}
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
</body>
</html>
HTML;

$html = str_replace('{CLIENT_PORTAL_HEAD}', $clientPortalHead, $html);
$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{HERO}', $heroHtml, $html);
$html = str_replace('{PROFILE_PANEL}', $profilePanelHtml, $html);
$html = str_replace('{SECURITY_PANEL}', $securityPanelHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;
