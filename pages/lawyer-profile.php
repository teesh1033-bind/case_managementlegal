<?php
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/password-validation.php';

if (!isset($_SESSION['lawyer_id'])) {
    header('Location: lawyer-login.php');
    exit;
}

$lawyerId = (int) $_SESSION['lawyer_id'];
$lawyerName = (string) ($_SESSION['lawyer_name'] ?? 'Lawyer');
$message = '';
$messageType = '';

$profile = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'license_number' => '',
    'specialization' => '',
    'experience_years' => '',
    'bio' => '',
    'office_address' => '',
    'username' => '',
];

function loadLawyerProfile(PDO $pdo, int $lawyerId): array
{
    $stmt = $pdo->prepare("
        SELECT
            l.id,
            l.user_id,
            l.first_name,
            l.last_name,
            l.email,
            l.phone,
            l.license_number,
            l.specialization,
            l.experience_years,
            l.bio,
            l.office_address,
            u.username,
            u.password AS user_password_hash
        FROM lawyers l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->execute([$lawyerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

$passwordErrorHtml = '';
$confirmErrorHtml = '';
$passwordInvalidClass = '';
$confirmInvalidClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        $current = loadLawyerProfile($pdo, $lawyerId);
        if (!$current) {
            throw new RuntimeException('Lawyer profile not found.');
        }

        if ($action === 'update_profile') {
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $licenseNumber = trim((string) ($_POST['license_number'] ?? ''));
            $specialization = trim((string) ($_POST['specialization'] ?? ''));
            $experienceYears = (int) ($_POST['experience_years'] ?? 0);
            $bio = trim((string) ($_POST['bio'] ?? ''));
            $officeAddress = trim((string) ($_POST['office_address'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                $message = 'First name and last name are required.';
                $messageType = 'danger';
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Please enter a valid email address.';
                $messageType = 'danger';
            } elseif ($experienceYears < 0 || $experienceYears > 80) {
                $message = 'Experience years must be between 0 and 80.';
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE lawyers
                    SET first_name = ?, last_name = ?, email = ?, phone = ?,
                        license_number = ?, specialization = ?, experience_years = ?,
                        bio = ?, office_address = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $firstName,
                    $lastName,
                    $email,
                    $phone !== '' ? $phone : null,
                    $licenseNumber !== '' ? $licenseNumber : null,
                    $specialization !== '' ? $specialization : null,
                    $experienceYears,
                    $bio !== '' ? $bio : null,
                    $officeAddress !== '' ? $officeAddress : null,
                    $lawyerId,
                ]);

                if (!empty($current['user_id'])) {
                    $stmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
                    $stmt->execute([$email, $current['user_id']]);
                }

                $_SESSION['lawyer_name'] = trim($firstName . ' ' . $lastName);
                $lawyerName = $_SESSION['lawyer_name'];
                $message = 'Profile updated successfully.';
                $messageType = 'success';
            }
        } elseif ($action === 'change_password') {
            if (empty($current['user_id'])) {
                $message = 'No linked portal account found for this lawyer.';
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
                        $passwordErrorHtml = legalpro_password_field_error_html($passwordCheck['password_errors']);
                        $confirmErrorHtml = legalpro_password_field_error_html($passwordCheck['confirm_error']);
                        $passwordInvalidClass = legalpro_password_input_invalid_class($passwordCheck['password_errors']);
                        $confirmInvalidClass = legalpro_password_input_invalid_class($passwordCheck['confirm_error']);
                    } else {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
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
    $row = loadLawyerProfile($pdo, $lawyerId);
    if (!$row) {
        throw new RuntimeException('Lawyer profile not found.');
    }
    $profile['first_name'] = (string) ($row['first_name'] ?? '');
    $profile['last_name'] = (string) ($row['last_name'] ?? '');
    $profile['email'] = (string) ($row['email'] ?? '');
    $profile['phone'] = (string) ($row['phone'] ?? '');
    $profile['license_number'] = (string) ($row['license_number'] ?? '');
    $profile['specialization'] = (string) ($row['specialization'] ?? '');
    $profile['experience_years'] = (string) ($row['experience_years'] ?? '0');
    $profile['bio'] = (string) ($row['bio'] ?? '');
    $profile['office_address'] = (string) ($row['office_address'] ?? '');
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
    <link href="../assets/css/app-font-montserrat.css?v=3" rel="stylesheet" />
    <?php include __DIR__ . '/../inc/lawyer-portal-head.php'; ?>
</head>
<body class="g-sidenav-show bg-gray-100 legalpro-lawyer-portal lawyer-profile-page">
    <div class="min-height-300 bg-legalpro-lawyer position-absolute w-100"></div>
    <?php include __DIR__ . '/../inc/lawyer-menunav.php'; ?>

    <main class="main-content position-relative border-radius-lg">
        <nav class="navbar navbar-main navbar-expand-lg px-0 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
            <div class="container-fluid py-1 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <a href="javascript:;" class="nav-link text-body p-0 d-xl-none" id="iconNavbarSidenav">
                        <div class="sidenav-toggler-inner">
                            <i class="sidenav-toggler-line"></i>
                            <i class="sidenav-toggler-line"></i>
                            <i class="sidenav-toggler-line"></i>
                        </div>
                    </a>
                    <div>
                        <h6 class="font-weight-bolder mb-0">My Profile</h6>
                        <p class="dashboard-welcome-sub mb-0 mt-1">Manage your professional details</p>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">
            {MESSAGE}
            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Professional information</h6>
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
                                            <input class="form-control" type="email" name="email" value="{EMAIL}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">Phone</label>
                                            <input class="form-control" type="text" name="phone" value="{PHONE}">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">License number</label>
                                            <input class="form-control" type="text" name="license_number" value="{LICENSE_NUMBER}">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-control-label">Specialization</label>
                                            <input class="form-control" type="text" name="specialization" value="{SPECIALIZATION}">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">Years of experience</label>
                                    <input class="form-control" type="number" name="experience_years" value="{EXPERIENCE_YEARS}" min="0" max="80">
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">Office address</label>
                                    <textarea class="form-control" rows="2" name="office_address">{OFFICE_ADDRESS}</textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-control-label">Bio</label>
                                    <textarea class="form-control" rows="3" name="bio">{BIO}</textarea>
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
    <script src="../assets/js/argon-dashboard.min.js?v=2.1.0"></script>
</body>
</html>
HTML;

$html = str_replace('{MESSAGE}', $messageHtml, $html);
$html = str_replace('{FIRST_NAME}', htmlspecialchars($profile['first_name']), $html);
$html = str_replace('{LAST_NAME}', htmlspecialchars($profile['last_name']), $html);
$html = str_replace('{EMAIL}', htmlspecialchars($profile['email']), $html);
$html = str_replace('{PHONE}', htmlspecialchars($profile['phone']), $html);
$html = str_replace('{LICENSE_NUMBER}', htmlspecialchars($profile['license_number']), $html);
$html = str_replace('{SPECIALIZATION}', htmlspecialchars($profile['specialization']), $html);
$html = str_replace('{EXPERIENCE_YEARS}', htmlspecialchars($profile['experience_years']), $html);
$html = str_replace('{BIO}', htmlspecialchars($profile['bio']), $html);
$html = str_replace('{OFFICE_ADDRESS}', htmlspecialchars($profile['office_address']), $html);
$html = str_replace('{USERNAME}', htmlspecialchars($profile['username'] !== '' ? $profile['username'] : 'N/A'), $html);
$html = str_replace('{NEW_PASSWORD_INVALID_CLASS}', $passwordInvalidClass, $html);
$html = str_replace('{CONFIRM_PASSWORD_INVALID_CLASS}', $confirmInvalidClass, $html);
$html = str_replace('{NEW_PASSWORD_ERROR}', $passwordErrorHtml, $html);
$html = str_replace('{CONFIRM_PASSWORD_ERROR}', $confirmErrorHtml, $html);

require_once __DIR__ . '/../inc/lawyer-sidebar.php';
$html = inject_lawyer_portal_head($html);
$html = inject_lawyer_sidebar($html);

echo $html;
