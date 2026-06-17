<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/password-validation.php';
require_once __DIR__ . '/../inc/client-portal-navbar.php';

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
                $message = 'First name and last name are required.';
                $messageType = 'danger';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Please enter a valid email address.';
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
                $message = 'Profile updated successfully.';
                $messageType = 'success';
            }
        } elseif ($action === 'change_password') {
            if (empty($current['user_id'])) {
                $message = 'No linked portal account found for this client.';
                $messageType = 'danger';
            } else {
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if (!password_verify($currentPassword, (string) ($current['user_password_hash'] ?? ''))) {
                    $message = 'Current password is incorrect.';
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
                        $message = 'Password updated successfully.';
                        $messageType = 'success';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $message = 'Error updating profile: ' . htmlspecialchars($e->getMessage());
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
    $message = 'Error loading profile: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
    . '</div>'
    : '';

$clientPageNavbar = legalpro_render_client_page_navbar('My Profile', 'Profile', '', [
    'include_search' => false,
]);

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - My Profile</title>
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=7" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-client-portal client-profile-page{PORTAL_THEME_BODY_CLASS}">
    <div class="min-height-300 bg-legalpro-client position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/client-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        {CLIENT_NAVBAR}

        <div class="container-fluid py-4">
            {MESSAGE}
            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Personal information</h6>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">First name</label>
                                            <input class="form-control" type="text" name="first_name" value="{FIRST_NAME}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">Last name</label>
                                            <input class="form-control" type="text" name="last_name" value="{LAST_NAME}" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">Email</label>
                                            <input class="form-control" type="email" name="email" value="{EMAIL}">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">Phone</label>
                                            <input class="form-control" type="text" name="phone" value="{PHONE}">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">Address</label>
                                    <textarea class="form-control" rows="3" name="address">{ADDRESS}</textarea>
                                </div>
                                <button class="btn bg-gradient-primary mb-0">Save profile</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Security</h6>
                        </div>
                        <div class="card-body">
                            <p class="text-sm text-muted mb-2">Username: <strong>{USERNAME}</strong></p>
                            <hr class="horizontal dark mt-0">
                            <form method="post">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-group">
                                    <label class="form-control-label">Current password</label>
                                    <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">New password</label>
                                    <input class="form-control{NEW_PASSWORD_INVALID_CLASS}" type="password" name="new_password" required autocomplete="new-password" minlength="8" maxlength="128">
                                    {NEW_PASSWORD_ERROR}
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">Confirm new password</label>
                                    <input class="form-control{CONFIRM_PASSWORD_INVALID_CLASS}" type="password" name="confirm_password" required autocomplete="new-password" minlength="8" maxlength="128">
                                    {CONFIRM_PASSWORD_ERROR}
                                </div>
                                <div class="text-xs text-muted mb-3">Password rules: at least 8 chars, one uppercase and one lowercase letter.</div>
                                <button class="btn btn-dark mb-0">Change password</button>
                            </form>
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
</body>
</html>
HTML;

$html = str_replace('{CLIENT_NAVBAR}', $clientPageNavbar, $html);
$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{FIRST_NAME}', htmlspecialchars($profile['first_name']), $html);
$html = str_replace('{LAST_NAME}', htmlspecialchars($profile['last_name']), $html);
$html = str_replace('{EMAIL}', htmlspecialchars($profile['email']), $html);
$html = str_replace('{PHONE}', htmlspecialchars($profile['phone']), $html);
$html = str_replace('{ADDRESS}', htmlspecialchars($profile['address']), $html);
$html = str_replace('{USERNAME}', htmlspecialchars($profile['username'] !== '' ? $profile['username'] : 'N/A'), $html);
$html = str_replace('{NEW_PASSWORD_INVALID_CLASS}', $createPasswordInvalidClass, $html);
$html = str_replace('{CONFIRM_PASSWORD_INVALID_CLASS}', $createConfirmInvalidClass, $html);
$html = str_replace('{NEW_PASSWORD_ERROR}', $createPasswordErrorHtml, $html);
$html = str_replace('{CONFIRM_PASSWORD_ERROR}', $createConfirmErrorHtml, $html);

require_once __DIR__ . '/../inc/client-sidebar.php';
$html = inject_client_sidebar($html);

echo $html;