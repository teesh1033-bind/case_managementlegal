<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin-layout.php';
require_once __DIR__ . '/../inc/password-validation.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$adminId = (int) $_SESSION['admin_id'];
$message = '';
$messageType = '';

$profile = [
    'username' => '',
    'email' => '',
    'role' => '',
    'created_at' => '',
];

$passwordErrorHtml = '';
$confirmErrorHtml = '';
$passwordInvalidClass = '';
$confirmInvalidClass = '';

function loadAdminProfile(PDO $pdo, int $adminId): array
{
    $stmt = $pdo->prepare('SELECT id, username, email, role, password, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        $current = loadAdminProfile($pdo, $adminId);
        if (!$current) {
            throw new RuntimeException('Administrator account not found.');
        }

        if (!in_array($current['role'], ['admin', 'staff'], true)) {
            throw new RuntimeException('This account is not an administrator.');
        }

        if ($action === 'update_profile') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));

            if ($username === '') {
                $message = 'Username is required.';
                $messageType = 'danger';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Please enter a valid email address.';
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $stmt->execute([$username, $adminId]);
                if ($stmt->fetch()) {
                    $message = 'That username is already in use.';
                    $messageType = 'danger';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
                    $stmt->execute([$username, $email !== '' ? $email : null, $adminId]);

                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_name'] = $username;

                    $message = 'Profile updated successfully.';
                    $messageType = 'success';
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (!password_verify($currentPassword, (string) ($current['password'] ?? ''))) {
                $message = 'Current password is incorrect.';
                $messageType = 'danger';
            } else {
                $passwordCheck = legalpro_validate_password_pair($newPassword, $confirmPassword);
                if (!$passwordCheck['valid']) {
                    $message = legalpro_password_form_message($passwordCheck);
                    $messageType = 'danger';
                    $passwordErrorHtml = legalpro_password_field_error_html($passwordCheck['password_errors']);
                    $confirmErrorHtml = legalpro_password_field_error_html($passwordCheck['confirm_error']);
                    $passwordInvalidClass = legalpro_password_input_invalid_class($passwordCheck['password_errors']);
                    $confirmInvalidClass = legalpro_password_input_invalid_class($passwordCheck['confirm_error']);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $adminId]);
                    $message = 'Password updated successfully.';
                    $messageType = 'success';
                }
            }
        }
    } catch (Throwable $e) {
        $message = 'Error: ' . htmlspecialchars($e->getMessage());
        $messageType = 'danger';
    }
}

try {
    $row = loadAdminProfile($pdo, $adminId);
    if (!$row) {
        throw new RuntimeException('Administrator account not found.');
    }
    if (!in_array($row['role'], ['admin', 'staff'], true)) {
        header('Location: login.php');
        exit;
    }

    $profile['username'] = (string) ($row['username'] ?? '');
    $profile['email'] = (string) ($row['email'] ?? '');
    $profile['role'] = (string) ($row['role'] ?? 'admin');
    $profile['created_at'] = !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : '';
} catch (Throwable $e) {
    $message = 'Error loading profile: ' . htmlspecialchars($e->getMessage());
    $messageType = 'danger';
}

$displayName = $profile['username'] !== '' ? $profile['username'] : legalpro_admin_display_name();
$initials = legalpro_admin_initials($displayName);
$roleLabel = ucfirst($profile['role'] !== '' ? $profile['role'] : 'admin');

$messageHtml = $message !== ''
    ? '<div class="alert alert-' . htmlspecialchars($messageType ?: 'info') . ' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($message)
        . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
    . '</div>'
    : '';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <title>LegalPro - My Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-icons.css" rel="stylesheet" />
    <link href="https://demos.creative-tim.com/argon-dashboard-pro/assets/css/nucleo-svg.css" rel="stylesheet" />
    <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
    <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.1.0" rel="stylesheet" />
    <link href="../assets/css/app-font-montserrat.css?v=1" rel="stylesheet" />
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-admin-portal">
    <div class="min-height-300 bg-legalpro-admin position-absolute w-100"></div>
    <aside class="sidenav bg-white navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-4" id="sidenav-main"></aside>

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" data-scroll="false">
            <div class="container-fluid py-1 px-3">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                        <li class="breadcrumb-item text-sm"><a class="opacity-5 text-white" href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item text-sm text-white active" aria-current="page">Profile</li>
                    </ol>
                    <h6 class="font-weight-bolder text-white mb-0">My Profile</h6>
                </nav>
            </div>
        </nav>

        <div class="container-fluid py-4">
            {MESSAGE}

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card overflow-hidden">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center flex-wrap gap-3">
                                <div class="avatar avatar-xl rounded-circle bg-gradient-dark text-white d-flex align-items-center justify-content-center shadow" style="width:72px;height:72px;font-size:1.5rem;font-weight:700;">
                                    {INITIALS}
                                </div>
                                <div>
                                    <h5 class="mb-1">{DISPLAY_NAME}</h5>
                                    <p class="text-sm text-muted mb-0">{ROLE_LABEL} · Member since {MEMBER_SINCE}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Account details</h6>
                            <p class="text-sm text-muted mb-0">Update your sign-in username and contact email.</p>
                        </div>
                        <div class="card-body">
                            <form method="post" autocomplete="off">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="form-control-label">Username</label>
                                            <input class="form-control" type="text" name="username" value="{USERNAME}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="form-control-label">Role</label>
                                            <input class="form-control bg-light" type="text" value="{ROLE_LABEL}" readonly tabindex="-1">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-control-label">Email</label>
                                    <input class="form-control" type="email" name="email" value="{EMAIL}" placeholder="you@firm.com">
                                </div>
                                <button type="submit" class="btn btn-dark mb-0">Save changes</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Change password</h6>
                            <p class="text-sm text-muted mb-0">Use a strong password for your admin account.</p>
                        </div>
                        <div class="card-body">
                            <form method="post" autocomplete="off">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-group mb-3">
                                    <label class="form-control-label">Current password</label>
                                    <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-control-label">New password</label>
                                    <input class="form-control{NEW_PASSWORD_INVALID_CLASS}" type="password" name="new_password" required autocomplete="new-password" minlength="8" maxlength="128">
                                    {NEW_PASSWORD_ERROR}
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-control-label">Confirm new password</label>
                                    <input class="form-control{CONFIRM_PASSWORD_INVALID_CLASS}" type="password" name="confirm_password" required autocomplete="new-password" minlength="8" maxlength="128">
                                    {CONFIRM_PASSWORD_ERROR}
                                </div>
                                <p class="text-xs text-muted mb-3">At least 8 characters with uppercase and lowercase letters.</p>
                                <button type="submit" class="btn btn-outline-dark mb-0">Update password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{INITIALS}', htmlspecialchars($initials), $html);
$html = str_replace('{DISPLAY_NAME}', htmlspecialchars($displayName), $html);
$html = str_replace('{ROLE_LABEL}', htmlspecialchars($roleLabel), $html);
$html = str_replace('{MEMBER_SINCE}', htmlspecialchars($profile['created_at'] !== '' ? $profile['created_at'] : '—'), $html);
$html = str_replace('{USERNAME}', htmlspecialchars($profile['username']), $html);
$html = str_replace('{EMAIL}', htmlspecialchars($profile['email']), $html);
$html = str_replace('{NEW_PASSWORD_INVALID_CLASS}', $passwordInvalidClass, $html);
$html = str_replace('{CONFIRM_PASSWORD_INVALID_CLASS}', $confirmInvalidClass, $html);
$html = str_replace('{NEW_PASSWORD_ERROR}', $passwordErrorHtml, $html);
$html = str_replace('{CONFIRM_PASSWORD_ERROR}', $confirmErrorHtml, $html);

ob_start();
include __DIR__ . '/../inc/menunav.php';
$sidebar = ob_get_clean();
$html = preg_replace('/<aside[\s\S]*?<\/aside>/', $sidebar, $html, 1);

ob_start();
include __DIR__ . '/../inc/footer.php';
$footer = ob_get_clean();
$html = preg_replace('/<\/body>\s*<\/html>$/i', $footer . "\n</body>\n</html>", $html);

echo legalpro_apply_copyright_line($html);
